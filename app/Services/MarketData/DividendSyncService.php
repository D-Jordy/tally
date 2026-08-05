<?php

namespace App\Services\MarketData;

use App\Actions\ProjectDividends;
use App\Models\Dividend;
use App\Models\Instrument;
use App\Services\Import\CurrencyNormaliser;
use Illuminate\Support\Facades\DB;

class DividendSyncService
{
    // Always re-fetch this whole window. Long enough to infer payment cadence, and
    // re-fetching keeps split-adjusted amounts and provider corrections in sync.
    private const LOOKBACK_YEARS = 5;

    public function __construct(
        private YahooFinanceAdapter $yahoo,
        private ProjectDividends $projector,
    ) {}

    /**
     * Sync historical dividends for one instrument. Returns number of rows upserted.
     * Idempotent: the unique (instrument_id, ex_date) index means re-runs overwrite
     * the amount/currency rather than duplicating.
     */
    public function syncInstrument(Instrument $instrument): int
    {
        if (! $instrument->yahoo_symbol) {
            return 0;
        }

        // Fetch before deleting anything: dividends() throws on any Yahoo error, and a
        // transient failure must not cost us rows.
        $rows = $this->yahoo->dividends(
            $instrument->yahoo_symbol,
            now()->subYears(self::LOOKBACK_YEARS)->toDateString()
        );

        // Drop confirmed rows whose ex-date has passed: their amount was only an
        // estimate, and the real payment lands below as a historical row — possibly a
        // day or two off Yahoo's forecast, which would leave a phantom extra payment.
        Dividend::where('instrument_id', $instrument->id)
            ->where('confirmed', true)
            ->where('ex_date', '<', now()->toDateString())
            ->delete();

        $now = now();
        $records = [];

        foreach ($rows as $row) {
            // Rule #1: pence (GBp/GBX) dividends are divided by 100, same as prices.
            [$amount, $currency] = CurrencyNormaliser::normalise(
                (string) $row['amount'],
                $row['currency']
            );

            $records[] = [
                'instrument_id' => $instrument->id,
                'ex_date' => $row['ex_date'],
                'pay_date' => null,
                'amount_per_share' => $amount,
                'currency' => $currency,
                // A real payment overwrites the estimate that was standing in for it —
                // whether that estimate was a confirmed row or one of our projections.
                'confirmed' => false,
                'projected' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($records, 500) as $chunk) {
            DB::table('dividends')->upsert(
                $chunk,
                ['instrument_id', 'ex_date'],
                ['amount_per_share', 'currency', 'confirmed', 'projected', 'updated_at']
            );
        }

        // Runs even when the history fetch came back empty, so the upcoming ex/pay
        // dates keep refreshing for instruments Yahoo has no dividend history for.
        $this->syncConfirmedUpcoming($instrument);

        // New history means a new cadence, so the projections are rebuilt in the same
        // pass. Hanging this off the service rather than the job covers the CLI too.
        $this->projector->forInstrument($instrument);

        return count($records);
    }

    /**
     * Fetch the next confirmed ex-date from Yahoo calendarEvents and upsert one
     * confirmed=true row using the most recent historical amount.
     */
    private function syncConfirmedUpcoming(Instrument $instrument): void
    {
        $upcoming = $this->yahoo->upcomingDividend($instrument->yahoo_symbol);

        if (! $upcoming) {
            return;
        }

        // Amount = median of recent historical rows (already normalised at ingest
        // time). Median, not latest, so a one-off special dividend doesn't become
        // the projected upcoming amount.
        // Real payments only: our own projections are future-dated and would sort to
        // the top here, making the estimate a median of earlier estimates.
        $recent = Dividend::where('instrument_id', $instrument->id)
            ->where('confirmed', false)
            ->where('projected', false)
            ->orderByDesc('ex_date')
            ->limit(8)
            ->get(['amount_per_share', 'currency']);

        if ($recent->isEmpty()) {
            return;
        }

        $amount = $recent->pluck('amount_per_share')->map(fn ($value) => (float) $value)->median();
        $currency = $recent->first()->currency;

        $now = now();

        DB::table('dividends')->upsert(
            [[
                'instrument_id' => $instrument->id,
                'ex_date' => $upcoming['ex_date'],
                'pay_date' => $upcoming['pay_date'],
                'amount_per_share' => $amount,
                'currency' => $currency,
                'confirmed' => true,
                'projected' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['instrument_id', 'ex_date'],
            ['pay_date', 'amount_per_share', 'currency', 'confirmed', 'projected', 'updated_at']
        );
    }
}

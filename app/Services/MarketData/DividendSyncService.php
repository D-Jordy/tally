<?php

namespace App\Services\MarketData;

use App\Models\Dividend;
use App\Models\Instrument;
use App\Models\Transaction;
use App\Services\Import\CurrencyNormaliser;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DividendSyncService
{
    public function __construct(private YahooFinanceAdapter $yahoo) {}

    /**
     * Sync historical dividends for one instrument. Returns number of rows upserted.
     * Idempotent: the unique (instrument_id, ex_date) index means re-runs overwrite
     * the amount/currency rather than duplicating.
     */
    public function syncInstrument(Instrument $instrument): int
    {
        if (!$instrument->yahoo_symbol) {
            return 0;
        }

        $fromDate = $this->instrumentFromDate($instrument);

        $rows = $this->yahoo->dividends($instrument->yahoo_symbol, $fromDate);

        if (empty($rows)) {
            return 0;
        }

        $now = now();
        $records = [];

        foreach ($rows as $row) {
            // Rule #1: pence (GBp/GBX) dividends are divided by 100, same as prices.
            [$amount, $currency] = CurrencyNormaliser::normalise(
                (string) $row['amount'],
                $row['currency']
            );

            $records[] = [
                'instrument_id'    => $instrument->id,
                'ex_date'          => $row['ex_date'],
                'pay_date'         => null,
                'amount_per_share' => $amount,
                'currency'         => $currency,
                'created_at'       => $now,
                'updated_at'       => $now,
            ];
        }

        foreach (array_chunk($records, 500) as $chunk) {
            DB::table('dividends')->upsert(
                $chunk,
                ['instrument_id', 'ex_date'],
                ['amount_per_share', 'currency', 'updated_at']
            );
        }

        $this->syncConfirmedUpcoming($instrument);

        return count($records);
    }

    /**
     * Fetch the next confirmed ex-date from Yahoo calendarEvents and upsert one
     * confirmed=true row using the most recent historical amount.
     */
    private function syncConfirmedUpcoming(Instrument $instrument): void
    {
        $upcoming = $this->yahoo->upcomingDividend($instrument->yahoo_symbol);

        if (!$upcoming) {
            return;
        }

        // Amount from most recent historical row (already normalised at ingest time).
        $latest = Dividend::where('instrument_id', $instrument->id)
            ->where('confirmed', false)
            ->orderByDesc('ex_date')
            ->first();

        if (!$latest) {
            return;
        }

        $now = now();

        DB::table('dividends')->upsert(
            [[
                'instrument_id'    => $instrument->id,
                'ex_date'          => $upcoming['ex_date'],
                'pay_date'         => $upcoming['pay_date'],
                'amount_per_share' => $latest->amount_per_share,
                'currency'         => $latest->currency,
                'confirmed'        => true,
                'created_at'       => $now,
                'updated_at'       => $now,
            ]],
            ['instrument_id', 'ex_date'],
            ['pay_date', 'amount_per_share', 'currency', 'confirmed', 'updated_at']
        );
    }

    /**
     * Earliest date to fetch dividends from. Resume after the latest stored ex_date;
     * otherwise look back from the first transaction. A long lookback is needed so the
     * forecast can infer payment cadence — fall back five years when there are no trades.
     */
    private function instrumentFromDate(Instrument $instrument): string
    {
        $latest = Dividend::where('instrument_id', $instrument->id)->max('ex_date');

        if ($latest) {
            return Carbon::parse($latest)->addDay()->toDateString();
        }

        $first = Transaction::where('instrument_id', $instrument->id)
            ->orderBy('executed_at')
            ->value('executed_at');

        return $first
            ? Carbon::parse($first)->toDateString()
            : now()->subYears(5)->toDateString();
    }
}

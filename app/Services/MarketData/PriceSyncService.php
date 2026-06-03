<?php

namespace App\Services\MarketData;

use App\Models\FxRate;
use App\Models\Instrument;
use App\Models\PriceHistory;
use App\Models\Transaction;
use App\Services\Import\CurrencyNormaliser;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PriceSyncService
{
    public function __construct(private YahooFinanceAdapter $yahoo) {}

    /**
     * Sync price history for one instrument. Returns number of rows upserted.
     */
    public function syncInstrument(Instrument $instrument): int
    {
        if (!$instrument->yahoo_symbol) {
            return 0;
        }

        $fromDate = $this->instrumentFromDate($instrument);
        if (!$fromDate) {
            return 0;
        }

        $rows = $this->yahoo->history($instrument->yahoo_symbol, $fromDate);

        if (empty($rows)) {
            return 0;
        }

        $now = now();
        $records = [];

        foreach ($rows as $row) {
            [$close, $currency] = CurrencyNormaliser::normalise(
                (string) $row['close'],
                $row['currency']
            );

            $records[] = [
                'instrument_id' => $instrument->id,
                'date'          => $row['date'],
                'close'         => $close,
                'currency'      => $currency,
                'created_at'    => $now,
                'updated_at'    => $now,
            ];
        }

        foreach (array_chunk($records, 500) as $chunk) {
            DB::table('price_history')->upsert(
                $chunk,
                ['instrument_id', 'date'],
                ['close', 'currency', 'updated_at']
            );
        }

        return count($records);
    }

    /**
     * Sync historical FX rate for a non-EUR currency. Returns rows upserted.
     * Stored as "1 CURRENCY = X EUR" (rule #2: always multiply).
     */
    public function syncFxRate(string $currency): int
    {
        if ($currency === 'EUR') {
            return 0;
        }

        $fromDate = $this->fxFromDate($currency);
        $rows = $this->yahoo->fxHistory($currency, $fromDate);

        if (empty($rows)) {
            return 0;
        }

        $now = now();
        $records = [];

        foreach ($rows as $row) {
            $records[] = [
                'date'        => $row['date'],
                'currency'    => $currency,
                'rate_to_eur' => (string) $row['rate'],
                'created_at'  => $now,
                'updated_at'  => $now,
            ];
        }

        foreach (array_chunk($records, 500) as $chunk) {
            DB::table('fx_rates')->upsert(
                $chunk,
                ['date', 'currency'],
                ['rate_to_eur', 'updated_at']
            );
        }

        return count($records);
    }

    /**
     * Sync FX rates for every non-EUR currency present in price_history.
     * Returns ['CURRENCY' => rowsUpserted, ...]
     */
    public function syncAllFxRates(): array
    {
        $currencies = PriceHistory::query()
            ->select('currency')
            ->distinct()
            ->where('currency', '!=', 'EUR')
            ->pluck('currency')
            ->all();

        $results = [];

        foreach ($currencies as $currency) {
            try {
                $results[$currency] = $this->syncFxRate($currency);
            } catch (\Throwable $e) {
                Log::warning("FX sync failed for {$currency}: {$e->getMessage()}");
                $results[$currency] = 0;
            }
        }

        return $results;
    }

    private function instrumentFromDate(Instrument $instrument): ?string
    {
        $latest = PriceHistory::where('instrument_id', $instrument->id)->max('date');

        if ($latest) {
            return Carbon::parse($latest)->addDay()->toDateString();
        }

        $first = Transaction::where('instrument_id', $instrument->id)
            ->orderBy('executed_at')
            ->value('executed_at');

        return $first ? Carbon::parse($first)->toDateString() : null;
    }

    private function fxFromDate(string $currency): string
    {
        $latest = FxRate::where('currency', $currency)->max('date');

        if ($latest) {
            return Carbon::parse($latest)->addDay()->toDateString();
        }

        $earliest = Transaction::orderBy('executed_at')->value('executed_at');

        return $earliest
            ? Carbon::parse($earliest)->toDateString()
            : now()->subYears(5)->toDateString();
    }
}

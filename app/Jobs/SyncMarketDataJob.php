<?php

namespace App\Jobs;

use App\Models\Instrument;
use App\Services\MarketData\DividendSyncService;
use App\Services\MarketData\PriceSyncService;
use App\Services\MarketData\YahooFinanceAdapter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncMarketDataJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public function handle(PriceSyncService $sync, DividendSyncService $dividendSync, YahooFinanceAdapter $yahoo): void
    {
        $instruments = Instrument::whereNotNull('yahoo_symbol')->get();

        foreach ($instruments as $instrument) {
            try {
                $rows = $sync->syncInstrument($instrument);
                Log::info("SyncMarketData: {$instrument->yahoo_symbol} — {$rows} rows");
            } catch (\Throwable $e) {
                Log::error("SyncMarketData: {$instrument->yahoo_symbol} failed", [
                    'error' => $e->getMessage(),
                ]);
            }

            try {
                $divRows = $dividendSync->syncInstrument($instrument);
                Log::info("SyncMarketData dividends: {$instrument->yahoo_symbol} — {$divRows} rows");
            } catch (\Throwable $e) {
                Log::error("SyncMarketData dividends: {$instrument->yahoo_symbol} failed", [
                    'error' => $e->getMessage(),
                ]);
            }

            try {
                $analyst = $yahoo->analystData($instrument->yahoo_symbol);
                $instrument->update([
                    'analyst_target_price' => $analyst['target_price'],
                    'analyst_rating'       => $analyst['rating'],
                ]);
            } catch (\Throwable $e) {
                Log::error("SyncMarketData analyst: {$instrument->yahoo_symbol} failed", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $fxResults = $sync->syncAllFxRates();

        foreach ($fxResults as $currency => $rows) {
            Log::info("SyncMarketData FX: {$currency}/EUR — {$rows} rows");
        }
    }
}

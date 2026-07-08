<?php

namespace App\Jobs;

use App\Models\Instrument;
use App\Services\MarketData\YahooFinanceAdapter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ResolveInstrumentSymbolsJob implements ShouldQueue
{
    use Queueable;

    public function handle(YahooFinanceAdapter $yahoo): void
    {
        // Pick up instruments still missing a symbol, plus resolved ones whose sector
        // was never filled (real DEGIRO imports leave it null; only the seeder sets it).
        $instruments = Instrument::whereNull('yahoo_symbol')
            ->orWhereNull('sector')
            ->get();

        foreach ($instruments as $instrument) {
            try {
                if ($instrument->yahoo_symbol === null) {
                    $symbol = $yahoo->searchByIsin($instrument->isin, $instrument->exchange);

                    if ($symbol) {
                        $instrument->update(['yahoo_symbol' => $symbol]);
                        Log::info("ResolveSymbols: {$instrument->isin} → {$symbol}");
                    } else {
                        Log::warning("ResolveSymbols: no match for {$instrument->isin} ({$instrument->name})");
                    }
                }

                // ETFs return no sector → stays null, retried on next refresh (rare, cheap).
                if ($instrument->yahoo_symbol !== null && $instrument->sector === null) {
                    $sector = $yahoo->sector($instrument->yahoo_symbol);

                    if ($sector) {
                        $instrument->update(['sector' => $sector]);
                    }
                }
            } catch (\Throwable $e) {
                Log::error("ResolveSymbols: {$instrument->isin} failed", ['error' => $e->getMessage()]);
            }
        }
    }
}

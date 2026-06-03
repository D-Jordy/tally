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
        $instruments = Instrument::whereNull('yahoo_symbol')->get();

        foreach ($instruments as $instrument) {
            try {
                $symbol = $yahoo->searchByIsin($instrument->isin, $instrument->exchange);

                if ($symbol) {
                    $instrument->update(['yahoo_symbol' => $symbol]);
                    Log::info("ResolveSymbols: {$instrument->isin} → {$symbol}");
                } else {
                    Log::warning("ResolveSymbols: no match for {$instrument->isin} ({$instrument->name})");
                }
            } catch (\Throwable $e) {
                Log::error("ResolveSymbols: {$instrument->isin} failed", ['error' => $e->getMessage()]);
            }
        }
    }
}

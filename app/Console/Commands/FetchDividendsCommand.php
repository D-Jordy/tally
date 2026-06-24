<?php

namespace App\Console\Commands;

use App\Models\Instrument;
use App\Services\MarketData\DividendSyncService;
use Illuminate\Console\Command;

class FetchDividendsCommand extends Command
{
    protected $signature   = 'dividends:fetch';
    protected $description = 'Backfill historical dividends from Yahoo Finance for all known instruments';

    public function handle(DividendSyncService $sync): int
    {
        $instruments = Instrument::whereNotNull('yahoo_symbol')->get();

        if ($instruments->isEmpty()) {
            $this->info('No instruments with a Yahoo symbol found.');
            return self::SUCCESS;
        }

        $total = 0;

        foreach ($instruments as $instrument) {
            try {
                $rows = $sync->syncInstrument($instrument);
                $this->line("{$instrument->yahoo_symbol}: {$rows} rows");
                $total += $rows;
            } catch (\Throwable $e) {
                $this->error("{$instrument->yahoo_symbol}: {$e->getMessage()}");
            }
        }

        $this->info("Done — {$total} dividend rows upserted.");
        return self::SUCCESS;
    }
}

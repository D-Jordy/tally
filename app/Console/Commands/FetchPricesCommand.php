<?php

namespace App\Console\Commands;

use App\Jobs\SyncMarketDataJob;
use Illuminate\Console\Command;

class FetchPricesCommand extends Command
{
    protected $signature   = 'prices:fetch';
    protected $description = 'Sync market prices and FX rates (runs SyncMarketDataJob synchronously)';

    public function handle(): int
    {
        dispatch_sync(new SyncMarketDataJob());
        return self::SUCCESS;
    }
}

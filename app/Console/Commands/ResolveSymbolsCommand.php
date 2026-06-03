<?php

namespace App\Console\Commands;

use App\Jobs\ResolveInstrumentSymbolsJob;
use Illuminate\Console\Command;

class ResolveSymbolsCommand extends Command
{
    protected $signature   = 'instruments:resolve-symbols';
    protected $description = 'Resolve yahoo_symbol for instruments that are missing one (runs ResolveInstrumentSymbolsJob synchronously)';

    public function handle(): int
    {
        dispatch_sync(new ResolveInstrumentSymbolsJob());
        return self::SUCCESS;
    }
}

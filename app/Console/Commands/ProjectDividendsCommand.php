<?php

namespace App\Console\Commands;

use App\Actions\ProjectDividends;
use App\Models\Instrument;
use Illuminate\Console\Command;

/**
 * The dividend sync rebuilds projections on its own; this is for filling them in
 * without waiting for it — after a deploy, or after importing history by hand.
 * Reads nothing from the network.
 */
class ProjectDividendsCommand extends Command
{
    protected $signature = 'dividends:project';

    protected $description = 'Rebuild the projected dividend rows for every instrument';

    public function handle(ProjectDividends $projector): int
    {
        $total = 0;

        // Same set the sync job walks: without a symbol there is no history to read.
        foreach (Instrument::whereNotNull('yahoo_symbol')->get() as $instrument) {
            $total += $projector->forInstrument($instrument);
        }

        $this->info("Done — {$total} projected dividend rows.");

        return self::SUCCESS;
    }
}

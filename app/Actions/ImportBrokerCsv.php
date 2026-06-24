<?php

namespace App\Actions;

use App\Models\Account;
use App\Services\Import\AccountImporter;
use App\Services\Import\ImportResult;
use App\Services\Import\TransactionImporter;
use Illuminate\Support\Facades\Storage;

/**
 * Runs a DEGIRO CSV through the existing importers.
 *
 * Thin orchestration around the Services\Import importers so the same logic
 * is shared by the Filament panel and the (legacy) HTTP controller. The
 * importers expect an absolute path on the local disk.
 */
class ImportBrokerCsv
{
    public function transactions(Account $account, string $storedPath): ImportResult
    {
        return (new TransactionImporter)->import($account, $this->absolutePath($storedPath));
    }

    public function account(Account $account, string $storedPath): ImportResult
    {
        return (new AccountImporter)->import($account, $this->absolutePath($storedPath));
    }

    private function absolutePath(string $storedPath): string
    {
        return Storage::disk('local')->path($storedPath);
    }
}

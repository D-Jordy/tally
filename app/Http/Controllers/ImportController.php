<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Services\Import\AccountImporter;
use App\Services\Import\TransactionImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\File;
use Inertia\Inertia;
use Inertia\Response;

class ImportController extends Controller
{
    public function show(Account $account): Response
    {
        Gate::authorize('view', $account);

        return Inertia::render('Import/Show', [
            'account'   => $account,
            'watermark' => $account->import_watermark?->toDateString(),
        ]);
    }

    public function transactions(Request $request, Account $account): RedirectResponse
    {
        Gate::authorize('update', $account);

        $request->validate([
            'csv' => ['required', File::types(['csv', 'text/csv', 'text/plain'])->max('10mb')],
        ]);

        $path   = $request->file('csv')->store('imports/transactions');
        $result = (new TransactionImporter())->import($account, storage_path("app/private/{$path}"));

        return redirect()->route('accounts.import.show', $account)
            ->with('import_result', $result->toArray());
    }

    public function account(Request $request, Account $account): RedirectResponse
    {
        Gate::authorize('update', $account);

        $request->validate([
            'csv' => ['required', File::types(['csv', 'text/csv', 'text/plain'])->max('10mb')],
        ]);

        $path   = $request->file('csv')->store('imports/account');
        $result = (new AccountImporter())->import($account, storage_path("app/private/{$path}"));

        return redirect()->route('accounts.import.show', $account)
            ->with('import_result', $result->toArray());
    }
}

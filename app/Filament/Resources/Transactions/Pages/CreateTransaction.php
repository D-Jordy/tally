<?php

namespace App\Filament\Resources\Transactions\Pages;

use App\Filament\Resources\Transactions\TransactionResource;
use App\Models\Transaction;
use Carbon\Carbon;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateTransaction extends CreateRecord
{
    protected static string $resource = TransactionResource::class;

    /**
     * Anything entered here outranks the CSV — see TransactionImporter.
     *
     * The hash is computed up front so a trade that is already on file surfaces as
     * a field error instead of the unique index throwing a 500. Trashed rows count:
     * they still claim their hash, and silently reviving one would undo a delete.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $hash = Transaction::makeDedupeHash(
            Carbon::parse($data['executed_at']),
            (int) $data['instrument_id'],
            $data['type'],
            $data['quantity'],
            $data['price'],
            $data['fee'] ?? 0,
        );

        $clash = Transaction::withTrashed()
            ->where('account_id', $data['account_id'])
            ->where('dedupe_hash', $hash)
            ->first();

        if ($clash !== null) {
            throw ValidationException::withMessages([
                'data.executed_at' => __($clash->trashed()
                    ? 'transactions.validation.duplicate_deleted'
                    : 'transactions.validation.duplicate'),
            ]);
        }

        return [...$data, 'source' => 'manual', 'dedupe_hash' => $hash];
    }
}

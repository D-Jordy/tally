<?php

namespace App\Filament\Resources\Accounts\RelationManagers;

use App\Filament\Resources\Accounts\Schemas\TransactionForm;
use App\Filament\Resources\Accounts\Tables\TransactionsTable;
use App\Models\Transaction;
use Carbon\Carbon;
use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Validation\ValidationException;

class TransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'transactions';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('transactions.model_plural');
    }

    public function form(Schema $schema): Schema
    {
        return TransactionForm::configure($schema);
    }

    public function table(Table $table): Table
    {
        return TransactionsTable::configure($table)
            // Soft-deleted rows stay reachable so the trashed filter can surface
            // them; the filter itself hides them by default.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withoutGlobalScopes([SoftDeletingScope::class]))
            ->headerActions([
                CreateAction::make()
                    ->mutateDataUsing(fn (array $data): array => $this->markManual($data)),
            ]);
    }

    /**
     * Anything entered here outranks the CSV — see TransactionImporter.
     *
     * The hash is computed up front so a trade that is already on file surfaces as
     * a field error instead of the unique index throwing a 500. Trashed rows count:
     * they still claim their hash, and silently reviving one would undo a delete.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function markManual(array $data): array
    {
        $accountId = $this->getOwnerRecord()->getKey();

        $hash = Transaction::makeDedupeHash(
            Carbon::parse($data['executed_at']),
            (int) $data['instrument_id'],
            $data['type'],
            $data['quantity'],
            $data['price'],
            $data['fee'] ?? 0,
        );

        $clash = Transaction::withTrashed()
            ->where('account_id', $accountId)
            ->where('dedupe_hash', $hash)
            ->first();

        if ($clash !== null) {
            // The action's form lives under the mounted action, not at the component
            // root, so the error key has to carry that path or the message lands in
            // a bag nothing renders.
            $statePath = 'mountedActions.'.(count($this->mountedActions) - 1).'.data';

            throw ValidationException::withMessages([
                "{$statePath}.executed_at" => __($clash->trashed()
                    ? 'transactions.validation.duplicate_deleted'
                    : 'transactions.validation.duplicate'),
            ]);
        }

        return [...$data, 'source' => 'manual', 'dedupe_hash' => $hash];
    }
}

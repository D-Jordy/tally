<?php

namespace App\Filament\Resources\Transactions\Pages;

use App\Filament\Resources\Transactions\TransactionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTransaction extends EditRecord
{
    protected static string $resource = TransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * Saving the form is the deliberate correction the importer must not undo, so
     * the row flips to 'manual'. Its dedupe_hash stays frozen (see Transaction),
     * which is what keeps a re-import matching this row instead of duplicating it.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return [...$data, 'source' => 'manual'];
    }
}

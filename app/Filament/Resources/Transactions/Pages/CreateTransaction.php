<?php

namespace App\Filament\Resources\Transactions\Pages;

use App\Filament\Resources\Transactions\TransactionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTransaction extends CreateRecord
{
    protected static string $resource = TransactionResource::class;

    /**
     * Anything entered here outranks the CSV — see TransactionImporter.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return [...$data, 'source' => 'manual'];
    }
}

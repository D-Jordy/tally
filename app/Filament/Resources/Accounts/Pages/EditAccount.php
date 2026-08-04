<?php

namespace App\Filament\Resources\Accounts\Pages;

use App\Filament\Concerns\ImportsBrokerCsv;
use App\Filament\Resources\Accounts\AccountResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAccount extends EditRecord
{
    use ImportsBrokerCsv;

    protected static string $resource = AccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            static::importAction(),
            DeleteAction::make(),
        ];
    }
}

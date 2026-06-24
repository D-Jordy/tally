<?php

namespace App\Filament\Resources\Accounts\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class AccountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Naam')
                    ->required()
                    ->maxLength(100),
                Select::make('broker')
                    ->label('Broker')
                    ->options(['degiro' => 'DEGIRO'])
                    ->default('degiro')
                    ->required(),
            ]);
    }
}

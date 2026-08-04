<?php

namespace App\Filament\Resources\Accounts\Tables;

use App\Filament\Concerns\ImportsBrokerCsv;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AccountsTable
{
    use ImportsBrokerCsv;

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('accounts.fields.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('broker')
                    ->label(__('accounts.fields.broker'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => strtoupper($state)),
                TextColumn::make('import_watermark')
                    ->label(__('accounts.fields.last_import'))
                    ->date('d-m-Y')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->recordActions([
                self::importAction(),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}

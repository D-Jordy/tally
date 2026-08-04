<?php

namespace App\Filament\Resources\Instruments\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'transactions';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('instruments.relations.transactions');
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('executed_at', 'desc')
            // Instruments are shared across users; the transactions on them are not.
            // The Account global scope turns this into the auth filter.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereHas('account'))
            ->columns([
                TextColumn::make('executed_at')
                    ->label(__('transactions.fields.executed_at'))
                    ->dateTime('d-m-Y H:i')
                    ->sortable(),
                TextColumn::make('account.name')
                    ->label(__('transactions.fields.account')),
                TextColumn::make('type')
                    ->label(__('transactions.fields.type'))
                    ->badge()
                    ->color(fn (string $state): string => $state === 'buy' ? 'success' : 'danger')
                    ->formatStateUsing(fn (string $state): string => __("transactions.types.{$state}")),
                TextColumn::make('quantity')
                    ->label(__('transactions.fields.quantity'))
                    ->numeric(decimalPlaces: 4)
                    ->alignEnd(),
                TextColumn::make('price')
                    ->label(__('transactions.fields.price'))
                    ->numeric(decimalPlaces: 4)
                    ->alignEnd(),
                TextColumn::make('total_eur')
                    ->label(__('transactions.fields.total_eur'))
                    ->money('EUR')
                    ->alignEnd(),
            ]);
    }
}

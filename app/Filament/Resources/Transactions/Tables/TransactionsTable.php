<?php

namespace App\Filament\Resources\Transactions\Tables;

use App\Models\Instrument;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TransactionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('executed_at', 'desc')
            ->columns([
                TextColumn::make('executed_at')
                    ->label(__('transactions.fields.executed_at'))
                    ->dateTime('d-m-Y H:i')
                    ->sortable(),
                TextColumn::make('account.name')
                    ->label(__('transactions.fields.account'))
                    ->toggleable(),
                TextColumn::make('instrument.name')
                    ->label(__('transactions.fields.instrument'))
                    ->searchable()
                    ->wrap(),
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
                TextColumn::make('fee')
                    ->label(__('transactions.fields.fee'))
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd()
                    ->toggleable(),
                TextColumn::make('total_eur')
                    ->label(__('transactions.fields.total_eur'))
                    ->money('EUR')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('trade_currency')
                    ->label(__('transactions.fields.trade_currency'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('source')
                    ->label(__('transactions.fields.source'))
                    ->badge()
                    ->color(fn (string $state): string => $state === 'manual' ? 'warning' : 'gray')
                    ->formatStateUsing(fn (string $state): string => __("transactions.sources.{$state}")),
            ])
            ->filters([
                SelectFilter::make('account')
                    ->label(__('transactions.fields.account'))
                    ->relationship('account', 'name'),
                SelectFilter::make('instrument_id')
                    ->label(__('transactions.fields.instrument'))
                    ->options(fn (): array => Instrument::query()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable(),
                SelectFilter::make('type')
                    ->label(__('transactions.fields.type'))
                    ->options([
                        'buy' => __('transactions.types.buy'),
                        'sell' => __('transactions.types.sell'),
                    ]),
                TrashedFilter::make(),
                Filter::make('executed_at')
                    ->schema([
                        DatePicker::make('from')->label(__('transactions.filters.from')),
                        DatePicker::make('until')->label(__('transactions.filters.until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('executed_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('executed_at', '<=', $date))),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
                ForceDeleteAction::make(),
            ]);
    }
}

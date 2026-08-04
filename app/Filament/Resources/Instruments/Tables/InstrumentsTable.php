<?php

namespace App\Filament\Resources\Instruments\Tables;

use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InstrumentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('instruments.fields.name'))
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('isin')
                    ->label(__('instruments.fields.isin'))
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('yahoo_symbol')
                    ->label(__('instruments.fields.yahoo_symbol'))
                    ->searchable()
                    ->placeholder('—')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('exchange')
                    ->label(__('instruments.fields.exchange'))
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('sector')
                    ->label(__('instruments.fields.sector'))
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('quote_currency')
                    ->label(__('instruments.fields.quote_currency'))
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('dividend_yield')
                    ->label(__('instruments.fields.dividend_yield'))
                    ->formatStateUsing(fn (?string $state): string => $state === null
                        ? '—'
                        : number_format((float) $state * 100, 2).'%')
                    ->alignEnd()
                    ->sortable(),
            ])
            ->filters([
                Filter::make('missing_symbol')
                    ->label(__('instruments.filters.missing_symbol'))
                    ->query(fn (Builder $query): Builder => $query->whereNull('yahoo_symbol')),
                Filter::make('missing_sector')
                    ->label(__('instruments.filters.missing_sector'))
                    ->query(fn (Builder $query): Builder => $query->whereNull('sector')),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}

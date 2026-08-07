<?php

namespace App\Filament\Resources\Instruments\Tables;

use App\Filament\Resources\Instruments\InstrumentResource;
use App\Models\Instrument;
use App\Support\NumberFormat;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;

class InstrumentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Filament's own money()/numeric() read config('app.locale'), which follows
            // Accept-Language — the one thing our numbers must not do. See NumberFormat.
            ->defaultNumberLocale(NumberFormat::LOCALE)
            ->defaultSort('name')
            ->recordUrl(fn (Instrument $record): string => InstrumentResource::getUrl('view', ['record' => $record]))
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
                        : Number::percentage((float) $state * 100, maxPrecision: NumberFormat::DECIMALS))
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
                // Priced in a currency none of your fills settled in — usually a sibling
                // listing of the right fund on the wrong exchange, which costs an FX leg
                // and reports none of the dividends. Correct the symbol by hand.
                //
                // Goes through transactions.account like the resource's own scoping does:
                // transactions carry no user_id, so matching on the relation directly would
                // let another holder's fill in the quote currency hide your mismatch.
                Filter::make('currency_mismatch')
                    ->label(__('instruments.filters.currency_mismatch'))
                    ->query(fn (Builder $query): Builder => $query
                        ->whereNotNull('quote_currency')
                        ->whereDoesntHave('transactions.account', fn (Builder $query): Builder => $query
                            ->whereColumn('transactions.trade_currency', 'instruments.quote_currency'))),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}

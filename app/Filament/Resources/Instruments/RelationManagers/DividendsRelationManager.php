<?php

namespace App\Filament\Resources\Instruments\RelationManagers;

use App\Models\Dividend;
use App\Support\NumberFormat;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class DividendsRelationManager extends RelationManager
{
    protected static string $relationship = 'dividends';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('instruments.relations.dividends');
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('ex_date', 'desc')
            ->columns([
                TextColumn::make('ex_date')
                    ->label(__('instruments.dividends.ex_date'))
                    ->date('d-m-Y')
                    ->sortable(),
                TextColumn::make('pay_date')
                    ->label(__('instruments.dividends.pay_date'))
                    ->date('d-m-Y')
                    ->placeholder('—'),
                TextColumn::make('amount_per_share')
                    ->label(__('instruments.dividends.amount_per_share'))
                    ->numeric(maxDecimalPlaces: NumberFormat::MAX_DECIMALS)
                    ->alignEnd(),
                TextColumn::make('currency')
                    ->label(__('instruments.dividends.currency')),
                // Three kinds of row live here: payments that happened, the provider's
                // announced next one, and our own cadence projections.
                TextColumn::make('status')
                    ->label(__('instruments.dividends.status'))
                    ->badge()
                    ->state(fn (Dividend $record): string => match (true) {
                        $record->projected => 'projected',
                        $record->confirmed => 'confirmed',
                        default => 'paid',
                    })
                    ->formatStateUsing(fn (string $state): string => __("instruments.dividends.statuses.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        'projected' => 'gray',
                        'confirmed' => 'success',
                        default => 'info',
                    }),
            ]);
    }
}

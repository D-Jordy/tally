<?php

namespace App\Filament\Resources\Instruments\RelationManagers;

use App\Support\NumberFormat;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
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
                    ->numeric(decimalPlaces: NumberFormat::DECIMALS)
                    ->alignEnd(),
                TextColumn::make('currency')
                    ->label(__('instruments.dividends.currency')),
                IconColumn::make('confirmed')
                    ->label(__('instruments.dividends.confirmed'))
                    ->boolean(),
            ]);
    }
}

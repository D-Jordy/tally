<?php

namespace App\Filament\Resources\Instruments\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class InstrumentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('instruments.sections.identity'))
                    ->columns(2)
                    ->components([
                        TextInput::make('name')
                            ->label(__('instruments.fields.name'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('isin')
                            ->label(__('instruments.fields.isin'))
                            ->disabled()
                            ->helperText(__('instruments.fields.isin_hint')),
                    ]),

                Section::make(__('instruments.sections.market_data'))
                    ->description(__('instruments.sections.market_data_hint'))
                    ->columns(2)
                    ->components([
                        TextInput::make('yahoo_symbol')
                            ->label(__('instruments.fields.yahoo_symbol'))
                            ->helperText(__('instruments.fields.yahoo_symbol_hint'))
                            ->maxLength(50),
                        TextInput::make('sector')
                            ->label(__('instruments.fields.sector'))
                            ->helperText(__('instruments.fields.sector_hint'))
                            ->maxLength(100),
                        TextInput::make('symbol')
                            ->label(__('instruments.fields.symbol'))
                            ->maxLength(50),
                        TextInput::make('exchange')
                            ->label(__('instruments.fields.exchange'))
                            ->maxLength(50),
                        TextInput::make('quote_currency')
                            ->label(__('instruments.fields.quote_currency'))
                            ->maxLength(10),
                        TextInput::make('country')
                            ->label(__('instruments.fields.country'))
                            ->maxLength(100),
                    ]),
            ]);
    }
}

<?php

namespace App\Filament\Resources\Transactions\Schemas;

use App\Models\Instrument;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TransactionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('transactions.sections.trade'))
                    ->columns(3)
                    ->components([
                        Select::make('account_id')
                            ->label(__('transactions.fields.account'))
                            ->relationship('account', 'name')
                            ->required(),
                        // Instruments live in one table shared by every user, so search
                        // is narrowed to the ones you traded (through the Account global
                        // scope) rather than listing everybody's holdings by name.
                        // getSearchResultsUsing instead of options() on purpose: options()
                        // would also add an `in:` rule over that same narrow set, which
                        // rejects an instrument added through createOptionForm below —
                        // and a trade that never came from a DEGIRO export needs that way in.
                        Select::make('instrument_id')
                            ->label(__('transactions.fields.instrument'))
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => Instrument::query()
                                ->whereHas('transactions.account')
                                ->whereLike('name', "%{$search}%")
                                ->orderBy('name')
                                ->limit(50)
                                ->pluck('name', 'id')
                                ->all())
                            ->getOptionLabelUsing(fn (mixed $value): ?string => Instrument::find($value)?->name)
                            ->required()
                            ->createOptionForm([
                                TextInput::make('name')
                                    ->label(__('transactions.fields.instrument_name'))
                                    ->required()
                                    ->maxLength(255),
                                TextInput::make('isin')
                                    ->label(__('transactions.fields.instrument_isin'))
                                    ->required()
                                    ->maxLength(12)
                                    ->unique('instruments', 'isin'),
                            ])
                            ->createOptionUsing(fn (array $data): int => Instrument::create($data)->id),
                        DateTimePicker::make('executed_at')
                            ->label(__('transactions.fields.executed_at'))
                            ->seconds(false)
                            ->required(),
                        Select::make('type')
                            ->label(__('transactions.fields.type'))
                            ->options([
                                'buy' => __('transactions.types.buy'),
                                'sell' => __('transactions.types.sell'),
                            ])
                            ->required(),
                        TextInput::make('quantity')
                            ->label(__('transactions.fields.quantity'))
                            ->numeric()
                            ->required(),
                        TextInput::make('price')
                            ->label(__('transactions.fields.price'))
                            ->numeric()
                            ->required(),
                    ]),

                Section::make(__('transactions.sections.amounts'))
                    ->description(__('transactions.sections.amounts_hint'))
                    ->columns(3)
                    ->components([
                        TextInput::make('price_currency')
                            ->label(__('transactions.fields.price_currency'))
                            ->required()
                            ->maxLength(10),
                        TextInput::make('trade_currency')
                            ->label(__('transactions.fields.trade_currency'))
                            ->required()
                            ->maxLength(10),
                        TextInput::make('fx_rate_to_eur')
                            ->label(__('transactions.fields.fx_rate_to_eur'))
                            ->helperText(__('transactions.fields.fx_rate_to_eur_hint'))
                            ->numeric(),
                        TextInput::make('fee')
                            ->label(__('transactions.fields.fee'))
                            ->numeric()
                            ->default(0)
                            ->required(),
                        TextInput::make('local_value')
                            ->label(__('transactions.fields.local_value'))
                            ->numeric(),
                        TextInput::make('value_eur')
                            ->label(__('transactions.fields.value_eur'))
                            ->numeric(),
                        TextInput::make('total_eur')
                            ->label(__('transactions.fields.total_eur'))
                            ->helperText(__('transactions.fields.total_eur_hint'))
                            ->numeric(),
                    ]),
            ]);
    }
}

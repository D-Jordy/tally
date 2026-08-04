<?php

namespace App\Filament\Resources\Instruments\Schemas;

use App\Actions\ComputeIncomingDividends;
use App\Actions\ComputePortfolio;
use App\Models\Instrument;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class InstrumentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('instruments.sections.identity'))
                    ->columns(3)
                    ->components([
                        TextEntry::make('isin')->label(__('instruments.fields.isin')),
                        TextEntry::make('yahoo_symbol')
                            ->label(__('instruments.fields.yahoo_symbol'))
                            ->placeholder('—'),
                        TextEntry::make('exchange')
                            ->label(__('instruments.fields.exchange'))
                            ->placeholder('—'),
                        TextEntry::make('sector')
                            ->label(__('instruments.fields.sector'))
                            ->placeholder('—'),
                        TextEntry::make('quote_currency')
                            ->label(__('instruments.fields.quote_currency'))
                            ->placeholder('—'),
                        TextEntry::make('country')
                            ->label(__('instruments.fields.country'))
                            ->placeholder('—'),
                    ]),

                Section::make(__('instruments.sections.position'))
                    ->description(__('instruments.sections.position_hint'))
                    ->columns(4)
                    ->components([
                        TextEntry::make('position_quantity')
                            ->label(__('instruments.position.quantity'))
                            ->placeholder('—')
                            ->state(fn (Instrument $record): ?string => self::number($record, 'quantity', 4)),
                        TextEntry::make('position_avg_cost')
                            ->label(__('instruments.position.avg_cost'))
                            ->placeholder('—')
                            ->state(fn (Instrument $record): ?string => self::number($record, 'avg_cost_per_share', 4)),
                        TextEntry::make('position_value')
                            ->label(__('instruments.position.current_value'))
                            ->placeholder('—')
                            ->state(fn (Instrument $record): ?string => self::money($record, 'current_value_eur')),
                        TextEntry::make('position_unrealized')
                            ->label(__('instruments.position.unrealized'))
                            ->placeholder('—')
                            ->state(fn (Instrument $record): ?string => self::money($record, 'unrealized_gain_eur')),
                        TextEntry::make('position_dividends')
                            ->label(__('instruments.position.dividends'))
                            ->placeholder('—')
                            ->state(fn (Instrument $record): ?string => self::money($record, 'dividend_eur')),
                        TextEntry::make('position_yield')
                            ->label(__('instruments.position.yield'))
                            ->placeholder('—')
                            ->state(fn (Instrument $record): ?string => self::percentage($record, 'yield')),
                        TextEntry::make('position_yield_on_cost')
                            ->label(__('instruments.position.yield_on_cost'))
                            ->placeholder('—')
                            ->state(fn (Instrument $record): ?string => self::percentage($record, 'yield_on_cost')),
                    ]),

                Section::make(__('instruments.sections.analysts'))
                    ->columns(3)
                    ->components([
                        TextEntry::make('analyst_target_price')
                            ->label(__('instruments.fields.analyst_target_price'))
                            ->placeholder('—')
                            ->numeric(decimalPlaces: 2),
                        TextEntry::make('analyst_rating')
                            ->label(__('instruments.fields.analyst_rating'))
                            ->placeholder('—')
                            ->badge(),
                        TextEntry::make('dividend_yield')
                            ->label(__('instruments.fields.dividend_yield'))
                            ->placeholder('—')
                            ->formatStateUsing(fn (string $state): string => number_format((float) $state * 100, 2).'%'),
                    ]),
            ]);
    }

    /**
     * Memoised per instrument, so the seven entries above share one computation
     * without a second record on the same page silently reading the first one's
     * figures. Request-scoped: a static property lives exactly as long as needed.
     *
     * @var array<string, array<int, array<string, mixed>>>
     */
    private static array $memo = [];

    /**
     * The open position for this instrument, from the same action the portfolio
     * page uses. Empty array when the position is closed or never held.
     *
     * @return array<string, mixed>
     */
    private static function position(Instrument $record): array
    {
        return self::$memo['position'][$record->id] ??= collect(
            app(ComputePortfolio::class)->forUser(auth()->user())['positions']
        )->firstWhere('instrument_id', $record->id) ?? [];
    }

    /**
     * Yield and yield-on-cost come from the dividend forecast rather than being
     * recomputed here, so the detail page agrees with the dividends page.
     *
     * @return array<string, mixed>
     */
    private static function dividendFigures(Instrument $record): array
    {
        return self::$memo['dividends'][$record->id] ??= collect(
            app(ComputeIncomingDividends::class)->forUser(auth()->user())['by_instrument']
        )->firstWhere('instrument_id', $record->id) ?? [];
    }

    private static function number(Instrument $record, string $key, int $decimals): ?string
    {
        $value = self::position($record)[$key] ?? null;

        return $value === null ? null : number_format((float) $value, $decimals);
    }

    private static function money(Instrument $record, string $key): ?string
    {
        $value = self::position($record)[$key] ?? null;

        return $value === null ? null : '€ '.number_format((float) $value, 2);
    }

    private static function percentage(Instrument $record, string $key): ?string
    {
        $value = self::dividendFigures($record)[$key] ?? null;

        return $value === null ? null : number_format((float) $value * 100, 2).'%';
    }
}

<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Instruments\InstrumentResource;
use App\Models\Dividend;
use App\Support\NumberFormat;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;

/**
 * Confirmed and projected payments on one chronological line.
 *
 * The rows are real `dividends` records — projections included, materialised by
 * ProjectDividends — so this is a plain Eloquent table. Only the expected amount is
 * per user (quantity × FX), and that arrives keyed by row id from
 * ComputeIncomingDividends rather than being recomputed here.
 */
class DividendCalendarTable extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    /** @var array<int, float|null> expected EUR per dividend row id */
    public array $expectedEur = [];

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Dividend::query()
                    ->with('instrument')
                    ->whereIn('id', array_keys($this->expectedEur))
                    // "When does the money arrive": pay date where the provider gave us
                    // one, ex-date for everything else.
                    ->orderByRaw('COALESCE(pay_date, ex_date)')
            )
            ->heading(__('dividends.sections.calendar'))
            ->emptyStateHeading(__('dividends.empty.calendar'))
            ->paginated(false)
            ->columns([
                TextColumn::make('instrument.name')
                    ->label(__('dividends.table.instrument'))
                    ->weight(FontWeight::SemiBold)
                    ->searchable()
                    ->url(fn (Dividend $record): string => InstrumentResource::getUrl('view', [
                        'record' => $record->instrument,
                    ])),
                TextColumn::make('ex_date')
                    ->label(__('dividends.table.ex_date'))
                    ->date('d-m-Y')
                    ->sortable(),
                TextColumn::make('pay_date')
                    ->label(__('dividends.table.pay_date'))
                    ->date('d-m-Y')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('amount_per_share')
                    ->label(__('dividends.table.per_share'))
                    ->formatStateUsing(fn (string $state, Dividend $record): string => Number::format((float) $state, maxPrecision: NumberFormat::MAX_DECIMALS).' '.$record->currency)
                    ->alignEnd(),
                TextColumn::make('expected_eur')
                    ->label(__('dividends.table.expected'))
                    ->state(fn (Dividend $record): ?float => $this->expectedEur[$record->id] ?? null)
                    ->money('EUR')
                    ->placeholder('—')
                    // Estimates read softer than the confirmed rows, as they did before.
                    ->color(fn (Dividend $record): ?string => $record->confirmed ? null : 'gray')
                    ->alignEnd(),
                TextColumn::make('confirmed')
                    ->label('')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? __('dividends.badge.confirmed') : __('dividends.badge.estimate'))
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray')
                    ->alignEnd(),
            ])
            ->filters([
                SelectFilter::make('confirmed')
                    ->label(__('dividends.table.certainty'))
                    ->options([
                        '1' => __('dividends.badge.confirmed'),
                        '0' => __('dividends.badge.estimate'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['value'] !== null && $data['value'] !== '', fn (Builder $query): Builder => $query->where('confirmed', (bool) $data['value']))),
            ]);
    }
}

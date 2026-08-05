<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Instruments\InstrumentResource;
use App\Models\Dividend;
use App\Support\NumberFormat;
use Carbon\Carbon;
use Closure;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
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

    /** @var array<string, float>|null memo for one render */
    private ?array $monthTotals = null;

    public function table(Table $table): Table
    {
        return $table
            // Filament's own money()/numeric() read config('app.locale'), which follows
            // Accept-Language — the one thing our numbers must not do. See NumberFormat.
            ->defaultNumberLocale(NumberFormat::LOCALE)
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
            // Grouped per month with the month's income on the header — the question
            // the page exists to answer. Possible again now the rows are a query.
            ->defaultGroup(
                Group::make('month')
                    ->titlePrefixedWithLabel(false)
                    ->getKeyFromRecordUsing(fn (Dividend $record): string => $this->arrivesOn($record)->format('Y-m'))
                    ->getTitleFromRecordUsing(fn (Dividend $record): string => $this->arrivesOn($record)->translatedFormat('F Y'))
                    ->getDescriptionFromRecordUsing(fn (Dividend $record): string => Number::currency($this->monthTotals()[$this->arrivesOn($record)->format('Y-m')] ?? 0, 'EUR'))
                    ->orderQueryUsing(fn (Builder $query, string $direction): Builder => $query->orderByRaw("COALESCE(pay_date, ex_date) {$direction}"))
            )
            ->columns([
                TextColumn::make('confirmed')
                    ->label('')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? __('dividends.badge.confirmed') : __('dividends.badge.estimate'))
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                TextColumn::make('instrument.name')
                    ->label(__('dividends.table.instrument'))
                    ->weight(FontWeight::SemiBold)
                    ->searchable()
                    ->url(fn (Dividend $record): string => InstrumentResource::getUrl('view', [
                        'record' => $record->instrument,
                    ]))
                    ->extraAttributes($this->fadeEstimates()),
                TextColumn::make('ex_date')
                    ->label(__('dividends.table.ex_date'))
                    ->date('d-m-Y')
                    ->sortable()
                    ->extraAttributes($this->fadeEstimates()),
                TextColumn::make('pay_date')
                    ->label(__('dividends.table.pay_date'))
                    ->date('d-m-Y')
                    ->placeholder('—')
                    ->sortable()
                    ->extraAttributes($this->fadeEstimates()),
                TextColumn::make('amount_per_share')
                    ->label(__('dividends.table.per_share'))
                    ->formatStateUsing(fn (string $state, Dividend $record): string => NumberFormat::symbol($record->currency).' '.Number::format((float) $state, maxPrecision: NumberFormat::MAX_DECIMALS))
                    ->alignEnd()
                    ->extraAttributes($this->fadeEstimates()),
                TextColumn::make('expected_eur')
                    ->label(__('dividends.table.amount'))
                    ->state(fn (Dividend $record): ?float => $this->expectedEur[$record->id] ?? null)
                    ->money('EUR')
                    ->placeholder('—')
                    ->alignEnd()
                    ->extraAttributes($this->fadeEstimates()),
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

    /**
     * Estimates sit lighter in the table than the confirmed rows; the badge on the
     * left says which is which, the fade keeps the eye on what is certain.
     *
     * @return Closure(Dividend): array<string, string>
     */
    private function fadeEstimates(): Closure
    {
        return fn (Dividend $record): array => $record->confirmed ? [] : ['style' => 'opacity:.5'];
    }

    /** The date the money lands: pay date where the provider gave one, else ex-date. */
    private function arrivesOn(Dividend $record): Carbon
    {
        return $record->pay_date ?? $record->ex_date;
    }

    /**
     * Expected EUR per month, summed from the per-user amounts. Filters do not touch
     * it: a month header answers "what does this month bring in", not "what is shown".
     *
     * @return array<string, float>
     */
    private function monthTotals(): array
    {
        return $this->monthTotals ??= Dividend::whereIn('id', array_keys($this->expectedEur))
            ->get(['id', 'ex_date', 'pay_date'])
            ->groupBy(fn (Dividend $record): string => $this->arrivesOn($record)->format('Y-m'))
            ->map(fn (Collection $rows): float => round((float) $rows->sum(fn (Dividend $record): float => (float) ($this->expectedEur[$record->id] ?? 0)), 2))
            ->all();
    }
}

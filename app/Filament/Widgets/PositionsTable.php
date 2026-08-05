<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\HasArrayRecords;
use App\Filament\Resources\Instruments\InstrumentResource;
use App\Support\NumberFormat;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Number;

/**
 * Open positions. Computed per user by ComputePortfolio from transactions, prices
 * and FX, so these rows are arrays rather than records.
 */
class PositionsTable extends TableWidget
{
    use HasArrayRecords;

    protected int|string|array $columnSpan = 'full';

    /** @var array<int, array<string, mixed>> */
    public array $rows = [];

    public function table(Table $table): Table
    {
        return $table
            ->records($this->arrayRecords($this->rows))
            ->heading(__('portfolio.sections.positions'))
            ->paginated(false)
            ->columns([
                TextColumn::make('name')
                    ->label(__('portfolio.table.instrument'))
                    ->weight(FontWeight::SemiBold)
                    ->url(fn (array $record): string => InstrumentResource::getUrl('view', [
                        // Ticker as the route key, id when the symbol was never resolved.
                        'record' => $record['yahoo_symbol'] ?: $record['instrument_id'],
                    ]))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('quantity')
                    ->label(__('portfolio.table.quantity'))
                    ->formatStateUsing(fn (float $state): string => Number::format($state, maxPrecision: NumberFormat::MAX_DECIMALS))
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('avg_cost_per_share')
                    ->label(__('portfolio.table.avg_cost'))
                    ->formatStateUsing(fn (?float $state, array $record): string => $this->price($state, $record['price_currency']))
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('latest_price')
                    ->label(__('portfolio.table.price'))
                    ->formatStateUsing(fn (?float $state, array $record): string => $this->price($state, $record['latest_price_currency']))
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('current_value_eur')
                    ->label(__('portfolio.table.value'))
                    ->money('EUR')
                    ->placeholder('—')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('unrealized_gain_eur')
                    ->label(__('portfolio.table.unrealized'))
                    ->money('EUR')
                    ->placeholder('—')
                    ->suffix(fn (array $record): ?HtmlString => $this->percentageSuffix($record['unrealized_gain_pct']))
                    ->color(fn (?float $state): string => $this->signColor($state))
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('dividend_eur')
                    ->label(__('portfolio.table.dividend'))
                    ->money('EUR')
                    ->color(fn (?float $state): ?string => (float) $state > 0 ? 'success' : 'gray')
                    ->alignEnd()
                    ->sortable(),
            ]);
    }

    /** Quantities and prices keep the precision the instrument needs, in their own currency. */
    private function price(?float $value, ?string $currency): string
    {
        return $value === null
            ? '—'
            : NumberFormat::symbol($currency).' '.Number::format($value, maxPrecision: NumberFormat::MAX_DECIMALS);
    }

    /** The percentage rides along on the same line, in muted grey between brackets. */
    private function percentageSuffix(?float $value): ?HtmlString
    {
        if ($value === null) {
            return null;
        }

        // Negatives already carry their minus; the plus is on us.
        $percentage = ($value >= 0 ? '+' : '').Number::percentage($value * 100, maxPrecision: 1);

        return new HtmlString('<span style="margin-left:.4rem;color:var(--divio-muted,#9a9488);">('.e($percentage).')</span>');
    }

    private function signColor(?float $value): string
    {
        return match (true) {
            $value === null => 'gray',
            $value >= 0 => 'success',
            default => 'danger',
        };
    }
}

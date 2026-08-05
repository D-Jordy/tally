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
 * Positions that have been sold off entirely: what they returned, dividends included.
 */
class ClosedPositionsTable extends TableWidget
{
    use HasArrayRecords;

    protected int|string|array $columnSpan = 'full';

    /** @var array<int, array<string, mixed>> */
    public array $rows = [];

    public function table(Table $table): Table
    {
        return $table
            // Filament's own money()/numeric() read config('app.locale'), which follows
            // Accept-Language — the one thing our numbers must not do. See NumberFormat.
            ->defaultNumberLocale(NumberFormat::LOCALE)
            ->records($this->arrayRecords($this->rows))
            ->heading(__('portfolio.closed.title'))
            ->emptyStateHeading(__('portfolio.closed.empty'))
            ->paginated(false)
            ->columns([
                TextColumn::make('name')
                    ->label(__('portfolio.table.instrument'))
                    ->weight(FontWeight::SemiBold)
                    ->url(fn (array $record): string => InstrumentResource::getUrl('view', [
                        'record' => $record['yahoo_symbol'] ?: $record['instrument_id'],
                    ]))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('opened_at')
                    ->label(__('portfolio.closed.opened'))
                    ->date('d-m-Y')
                    ->sortable(),
                TextColumn::make('closed_at')
                    ->label(__('portfolio.closed.closed'))
                    ->date('d-m-Y')
                    ->sortable(),
                TextColumn::make('deployed_eur')
                    ->label(__('portfolio.closed.deployed'))
                    ->money('EUR')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('realized_gain_eur')
                    ->label(__('portfolio.kpi.realized'))
                    ->money('EUR')
                    ->suffix(fn (array $record): ?HtmlString => $this->percentageSuffix($record['realized_gain_pct']))
                    ->color(fn (float $state): string => $state >= 0 ? 'success' : 'danger')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('dividend_eur')
                    ->label(__('portfolio.table.dividend'))
                    ->money('EUR')
                    ->color(fn (?float $state): string => (float) $state > 0 ? 'success' : 'gray')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('total_gain_eur')
                    ->label(__('portfolio.closed.total'))
                    ->money('EUR')
                    // No bold: against the green/red it reads as a third colour.
                    ->suffix(fn (array $record): ?HtmlString => $this->percentageSuffix($record['total_gain_pct']))
                    ->color(fn (float $state): string => $state >= 0 ? 'success' : 'danger')
                    ->alignEnd()
                    ->sortable(),
            ]);
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
}

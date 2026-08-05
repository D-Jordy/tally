<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\HasArrayRecords;
use App\Filament\Resources\Instruments\InstrumentResource;
use App\Support\NumberFormat;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Number;

/**
 * Yield vs yield on cost per paying position. Computed per user by
 * ComputeIncomingDividends, so these rows are arrays, not records.
 */
class DividendPositionsTable extends TableWidget
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
            ->heading(__('dividends.sections.positions'))
            ->paginated(false)
            ->columns([
                TextColumn::make('name')
                    ->label(__('dividends.table.instrument'))
                    ->weight(FontWeight::SemiBold)
                    ->url(fn (array $record): string => InstrumentResource::getUrl('view', [
                        // Ticker as the route key, id when the symbol was never resolved.
                        'record' => $record['yahoo_symbol'] ?: $record['instrument_id'],
                    ]))
                    ->sortable(),
                TextColumn::make('current_value_eur')
                    ->label(__('dividends.table.value'))
                    ->money('EUR')
                    ->placeholder('—')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('yield')
                    ->label(__('dividends.table.yield'))
                    ->formatStateUsing(fn (?float $state): string => $this->percentage($state))
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('yield_on_cost')
                    ->label(__('dividends.table.yoc'))
                    ->formatStateUsing(fn (?float $state): string => $this->percentage($state))
                    ->color('success')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('forward_12m_eur')
                    ->label(__('dividends.table.forward_12m'))
                    ->money('EUR')
                    ->alignEnd()
                    ->sortable(),
            ]);
    }

    /** Yields are stored as fractions; euro notation is pinned app-wide. */
    private function percentage(?float $value): string
    {
        return $value === null
            ? '—'
            : Number::percentage($value * 100, maxPrecision: NumberFormat::DECIMALS);
    }
}

<?php

namespace App\Filament\Resources\Accounts\RelationManagers;

use App\Actions\ComputeCashLedger;
use App\Models\CashMovement;
use App\Support\NumberFormat;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Number;

/**
 * Read-only: cash rows come from the CSV import, corrections go through the
 * transactions resource. Sits next to Transactions because that is where you
 * already are when a figure looks off.
 */
class CashMovementsRelationManager extends RelationManager
{
    protected static string $relationship = 'cashMovements';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('accounts.cash.heading');
    }

    public function table(Table $table): Table
    {
        // The EUR figures come from the ledger, not from the broker's own balance
        // column, so the totals agree with ComputePortfolio. Computed over the whole
        // account once per render — the header totals are deliberately unfiltered.
        // ponytail: loads every movement of the account; page it if that ever hurts.
        $ledger = app(ComputeCashLedger::class)->forAccount($this->getOwnerRecord());
        $eurByMovement = collect($ledger['rows'])->pluck('amount_eur', 'id');

        return $table
            ->defaultSort('occurred_at', 'desc')
            ->header(view('filament.tables.cash-totals', ['ledger' => $ledger]))
            ->emptyStateHeading(__('accounts.cash.empty'))
            ->columns([
                TextColumn::make('occurred_at')
                    ->label(__('accounts.cash.table.date'))
                    ->date('d-m-Y')
                    ->sortable(),
                TextColumn::make('type')
                    ->label(__('accounts.cash.table.type'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state): string => __("accounts.cash.types.{$state}")),
                TextColumn::make('description')
                    ->label(__('accounts.cash.table.description'))
                    ->wrap()
                    ->searchable(),
                TextColumn::make('amount')
                    ->label(__('accounts.cash.table.amount'))
                    ->formatStateUsing(fn (string $state, CashMovement $record): string => Number::format((float) $state, precision: NumberFormat::DECIMALS).' '.$record->currency)
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('amount_eur')
                    ->label(__('accounts.cash.table.amount_eur'))
                    ->state(fn (CashMovement $record): ?float => $eurByMovement->get($record->id))
                    ->money('EUR')
                    ->placeholder('—')
                    ->alignEnd(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label(__('accounts.cash.table.type'))
                    ->options(fn (): array => __('accounts.cash.types'))
                    ->multiple(),
                Filter::make('occurred_at')
                    ->schema([
                        DatePicker::make('from')->label(__('transactions.filters.from')),
                        DatePicker::make('until')->label(__('transactions.filters.until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('occurred_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('occurred_at', '<=', $date))),
            ]);
    }
}

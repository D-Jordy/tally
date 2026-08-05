<?php

namespace App\Filament\Resources\Accounts\Tables;

use App\Actions\ComputeCashLedger;
use App\Filament\Concerns\ImportsBrokerCsv;
use App\Models\Account;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;

class AccountsTable
{
    use ImportsBrokerCsv;

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('accounts.fields.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('broker')
                    ->label(__('accounts.fields.broker'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => strtoupper($state)),
                TextColumn::make('import_watermark')
                    ->label(__('accounts.fields.last_import'))
                    ->date('d-m-Y')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->recordActions([
                self::cashMovementsAction(),
                self::importAction(),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    /**
     * Read-only cash ledger. A modal rather than a page: you look at this when a
     * figure seems off, which is not often enough to earn nav space.
     */
    private static function cashMovementsAction(): Action
    {
        return Action::make('cashMovements')
            ->label(__('accounts.cash.label'))
            ->icon(Heroicon::OutlinedBanknotes)
            ->modalHeading(__('accounts.cash.heading'))
            ->modalWidth(Width::FiveExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('accounts.cash.close'))
            ->modalContent(fn (Account $record): View => view('filament.modals.cash-movements', [
                'ledger' => app(ComputeCashLedger::class)->forAccount($record),
            ]));
    }
}

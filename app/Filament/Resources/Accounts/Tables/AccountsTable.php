<?php

namespace App\Filament\Resources\Accounts\Tables;

use App\Actions\ImportBrokerCsv;
use App\Models\Account;
use App\Services\Import\ImportResult;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Naam')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('broker')
                    ->label('Broker')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => strtoupper($state)),
                TextColumn::make('import_watermark')
                    ->label('Laatste import')
                    ->date('d-m-Y')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->recordActions([
                self::importAction(),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    private static function importAction(): Action
    {
        return Action::make('import')
            ->label('Importeren')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->modalHeading('CSV importeren')
            ->modalDescription('Upload je DEGIRO transactie-export en/of het account-overzicht (ledger).')
            ->modalSubmitActionLabel('Importeren')
            ->schema([
                FileUpload::make('transactions_csv')
                    ->label('Transacties (DEGIRO)')
                    ->disk('local')
                    ->directory('imports/transactions')
                    ->acceptedFileTypes(['text/csv', 'text/plain']),
                FileUpload::make('account_csv')
                    ->label('Account-overzicht (ledger)')
                    ->disk('local')
                    ->directory('imports/account')
                    ->acceptedFileTypes(['text/csv', 'text/plain']),
            ])
            ->action(function (Account $record, array $data): void {
                $importer = new ImportBrokerCsv;

                /** @var array<string, ImportResult> $results */
                $results = [];

                if (filled($data['transactions_csv'] ?? null)) {
                    $results['Transacties'] = $importer->transactions($record, $data['transactions_csv']);
                }

                if (filled($data['account_csv'] ?? null)) {
                    $results['Account'] = $importer->account($record, $data['account_csv']);
                }

                self::notify($results);
            });
    }

    /**
     * @param  array<string, ImportResult>  $results
     */
    private static function notify(array $results): void
    {
        if ($results === []) {
            Notification::make()
                ->title('Geen bestand geüpload')
                ->warning()
                ->send();

            return;
        }

        $lines = collect($results)->map(
            fn (ImportResult $result, string $label): string => "{$label}: {$result->inserted} toegevoegd, {$result->skipped} overgeslagen"
        );

        $hasErrors = collect($results)->contains(fn (ImportResult $result): bool => $result->hasErrors());

        Notification::make()
            ->title($hasErrors ? 'Import voltooid met fouten' : 'Import voltooid')
            ->body($lines->implode("\n"))
            ->status($hasErrors ? 'warning' : 'success')
            ->send();
    }
}

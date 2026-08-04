<?php

namespace App\Filament\Resources\Instruments;

use App\Filament\Resources\Instruments\Pages\EditInstrument;
use App\Filament\Resources\Instruments\Pages\ListInstruments;
use App\Filament\Resources\Instruments\Pages\ViewInstrument;
use App\Filament\Resources\Instruments\RelationManagers\DividendsRelationManager;
use App\Filament\Resources\Instruments\RelationManagers\TransactionsRelationManager;
use App\Filament\Resources\Instruments\Schemas\InstrumentForm;
use App\Filament\Resources\Instruments\Schemas\InstrumentInfolist;
use App\Filament\Resources\Instruments\Tables\InstrumentsTable;
use App\Models\Instrument;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InstrumentResource extends Resource
{
    protected static ?string $model = Instrument::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 20;

    public static function getNavigationLabel(): string
    {
        return __('instruments.nav');
    }

    public static function getModelLabel(): string
    {
        return __('instruments.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('instruments.model_plural');
    }

    /**
     * The instruments table is shared across users, so the list is narrowed to the
     * ones you actually traded — nested through the Account global scope, which is
     * what does the auth filtering. Also keeps other users' holdings out of sight.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereHas('transactions.account');
    }

    public static function form(Schema $schema): Schema
    {
        return InstrumentForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return InstrumentInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InstrumentsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            TransactionsRelationManager::class,
            DividendsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInstruments::route('/'),
            'view' => ViewInstrument::route('/{record}'),
            'edit' => EditInstrument::route('/{record}/edit'),
        ];
    }
}

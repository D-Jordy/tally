<?php

namespace App\Filament\Resources\Instruments\Pages;

use App\Filament\Resources\Instruments\InstrumentResource;
use App\Filament\Widgets\InstrumentPriceChart;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewInstrument extends ViewRecord
{
    protected static string $resource = InstrumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }

    protected function getFooterWidgets(): array
    {
        return [
            InstrumentPriceChart::class,
        ];
    }
}

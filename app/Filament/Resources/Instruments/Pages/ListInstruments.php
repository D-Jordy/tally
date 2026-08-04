<?php

namespace App\Filament\Resources\Instruments\Pages;

use App\Filament\Concerns\RefreshesMarketData;
use App\Filament\Resources\Instruments\InstrumentResource;
use Filament\Resources\Pages\ListRecords;

class ListInstruments extends ListRecords
{
    use RefreshesMarketData;

    protected static string $resource = InstrumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->refreshMarketDataAction(),
        ];
    }
}

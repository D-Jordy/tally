<?php

namespace App\Filament\Widgets;

class SectorAllocationChart extends AllocationDonutChart
{
    protected static ?string $chartId = 'sectorAllocationChart';

    protected function getHeading(): ?string
    {
        return __('insights.allocation.sector_heading');
    }
}

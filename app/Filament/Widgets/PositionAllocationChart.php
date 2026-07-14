<?php

namespace App\Filament\Widgets;

class PositionAllocationChart extends AllocationDonutChart
{
    protected static ?string $chartId = 'positionAllocationChart';

    protected function getHeading(): ?string
    {
        return __('insights.allocation.positions_heading');
    }
}

<?php

namespace App\Filament\Widgets;

use App\Models\Instrument;
use App\Support\ChartTheme;
use Leandrocfe\FilamentApexCharts\Widgets\ApexChartWidget;

class InstrumentPriceChart extends ApexChartWidget
{
    protected static ?string $chartId = 'instrumentPriceChart';

    // Injected by the resource page via getWidgetData().
    public ?Instrument $record = null;

    protected function getHeading(): ?string
    {
        return __('instruments.chart.heading');
    }

    protected function getOptions(): array
    {
        $history = $this->record
            ?->priceHistory()
            ->orderBy('date')
            ->get(['date', 'close'])
            ?? collect();

        return [
            'chart' => ChartTheme::chart('area'),
            'series' => [
                [
                    'name' => __('instruments.chart.close'),
                    'data' => $history->map(fn ($row): float => round((float) $row->close, 4))->all(),
                ],
            ],
            'xaxis' => [
                ...ChartTheme::xaxis(),
                'categories' => $history->map(fn ($row): string => $row->date->toDateString())->all(),
                'type' => 'datetime',
            ],
            'yaxis' => ChartTheme::yaxis(),
            'colors' => [ChartTheme::INK],
            'stroke' => ['curve' => 'smooth', 'width' => 2.5],
            'legend' => ['show' => false],
            'fill' => ChartTheme::areaFill(),
            'grid' => ChartTheme::grid(),
            'dataLabels' => ['enabled' => false],
        ];
    }
}

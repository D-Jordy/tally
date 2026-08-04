<?php

namespace App\Filament\Widgets;

use App\Models\Instrument;
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
            'chart' => [
                'type' => 'area',
                'height' => 300,
                'toolbar' => ['show' => false],
                'fontFamily' => 'IBM Plex Mono, monospace',
            ],
            'series' => [
                [
                    'name' => __('instruments.chart.close'),
                    'data' => $history->map(fn ($row): float => round((float) $row->close, 4))->all(),
                ],
            ],
            'xaxis' => [
                'categories' => $history->map(fn ($row): string => $row->date->toDateString())->all(),
                'type' => 'datetime',
                'labels' => ['style' => ['colors' => '#9a9488', 'fontFamily' => 'IBM Plex Mono, monospace']],
                'axisBorder' => ['show' => false],
                'axisTicks' => ['show' => false],
            ],
            'yaxis' => [
                'labels' => ['style' => ['colors' => '#9a9488', 'fontFamily' => 'IBM Plex Mono, monospace']],
            ],
            'colors' => ['#1a1a1a'],
            'stroke' => ['curve' => 'smooth', 'width' => 2.5],
            'legend' => ['show' => false],
            'fill' => [
                'type' => 'gradient',
                'gradient' => ['shadeIntensity' => 1, 'opacityFrom' => 0.12, 'opacityTo' => 0, 'stops' => [0, 100]],
            ],
            'grid' => ['borderColor' => '#ece9e0', 'strokeDashArray' => 0],
            'dataLabels' => ['enabled' => false],
        ];
    }
}

<?php

namespace App\Filament\Widgets;

use Filament\Support\RawJs;
use Leandrocfe\FilamentApexCharts\Widgets\ApexChartWidget;

class ProjectionsGrowthChart extends ApexChartWidget
{
    protected static ?string $chartId = 'projectionsGrowthChart';

    /**
     * Value series from ComputeProjections, passed in by the page and refreshed
     * via a :key bound to the horizon + contribution so the widget re-mounts.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $series = [];

    protected function getHeading(): ?string
    {
        return __('projections.chart.heading');
    }

    protected function getOptions(): array
    {
        $series = collect($this->series);

        return [
            'chart' => [
                'type' => 'area',
                'height' => 300,
                'toolbar' => ['show' => false],
                'fontFamily' => 'IBM Plex Mono, monospace',
            ],
            'series' => [[
                'name' => __('projections.chart.value'),
                'data' => $series->map(fn (array $point): float => (float) $point['projected_value_eur'])->all(),
            ]],
            'xaxis' => [
                'categories' => $series->map(fn (array $point): string => (int) $point['year'] === 0 ? __('projections.chart.now') : (string) $point['year'])->all(),
                'title' => ['text' => __('projections.chart.years_axis'), 'style' => ['color' => '#9a9488', 'fontFamily' => 'IBM Plex Mono, monospace']],
                'labels' => ['style' => ['colors' => '#9a9488', 'fontFamily' => 'IBM Plex Mono, monospace']],
                'axisBorder' => ['show' => false],
                'axisTicks' => ['show' => false],
            ],
            'yaxis' => [
                'labels' => ['style' => ['colors' => '#9a9488', 'fontFamily' => 'IBM Plex Mono, monospace']],
            ],
            'colors' => ['#1a1a1a'],
            'stroke' => ['curve' => 'smooth', 'width' => 2.5],
            'fill' => [
                'type' => 'gradient',
                'gradient' => ['shadeIntensity' => 1, 'opacityFrom' => 0.12, 'opacityTo' => 0, 'stops' => [0, 100]],
            ],
            'grid' => ['borderColor' => '#ece9e0'],
            'dataLabels' => ['enabled' => false],
        ];
    }

    protected function extraJsOptions(): ?RawJs
    {
        $jsLocale = app()->getLocale() === 'nl' ? 'nl-NL' : 'en-US';

        return RawJs::make(<<<JS
        {
            yaxis: {
                labels: {
                    formatter: function (value) {
                        return '€' + Math.round(value / 1000) + 'k';
                    },
                },
            },
            tooltip: {
                y: {
                    formatter: function (value) {
                        return new Intl.NumberFormat('{$jsLocale}', {
                            style: 'currency', currency: 'EUR', maximumFractionDigits: 0,
                        }).format(value);
                    },
                },
            },
        }
        JS);
    }
}

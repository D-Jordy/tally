<?php

namespace App\Filament\Widgets;

use Filament\Support\RawJs;
use Leandrocfe\FilamentApexCharts\Widgets\ApexChartWidget;

class SectorAllocationChart extends ApexChartWidget
{
    protected static ?string $chartId = 'sectorAllocationChart';

    /**
     * Sector rows from the Insights page: [['sector' => .., 'value_eur' => .., 'weight' => ..], ..].
     *
     * @var array<int, array<string, mixed>>
     */
    public array $sectors = [];

    protected function getHeading(): ?string
    {
        return __('insights.allocation.sector_heading');
    }

    protected function getOptions(): array
    {
        $sectors = collect($this->sectors);

        return [
            'chart' => [
                'type' => 'donut',
                'height' => 300,
                'fontFamily' => 'IBM Plex Mono, monospace',
            ],
            'series' => $sectors->map(fn (array $row): float => (float) $row['value_eur'])->all(),
            'labels' => $sectors->pluck('sector')->all(),
            // On-brand muted categorical palette; ApexCharts cycles it if sectors exceed the list.
            'colors' => ['#1a1a1a', '#2f7d52', '#8a8474', '#a89c86', '#c0392b', '#c4bfb3', '#d8d2c4', '#9a9488'],
            'stroke' => ['width' => 1, 'colors' => ['#fcfbf8']],
            'legend' => [
                'position' => 'bottom',
                'fontFamily' => 'IBM Plex Mono, monospace',
                'labels' => ['colors' => '#9a9488'],
            ],
            'dataLabels' => ['enabled' => false],
        ];
    }

    protected function extraJsOptions(): ?RawJs
    {
        $jsLocale = app()->getLocale() === 'nl' ? 'nl-NL' : 'en-US';

        return RawJs::make(<<<JS
        {
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

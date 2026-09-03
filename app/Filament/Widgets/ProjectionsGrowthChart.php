<?php

namespace App\Filament\Widgets;

use App\Support\ChartTheme;
use App\Support\NumberFormat;
use Filament\Support\RawJs;
use Illuminate\Support\Collection;
use Leandrocfe\FilamentApexCharts\Widgets\ApexChartWidget;

class ProjectionsGrowthChart extends ApexChartWidget
{
    protected static ?string $chartId = 'projectionsGrowthChart';

    /**
     * Monthly value series from ComputeProjections, passed in by the page and refreshed
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
            'chart' => ChartTheme::chart('line'),
            'series' => [
                [
                    'name' => __('projections.chart.value'),
                    'type' => 'area',
                    'data' => $this->column($series, 'projected_value_eur'),
                ],
                // The gap between the two lines is the growth — the rest is your own money.
                [
                    'name' => __('projections.chart.contributed'),
                    'type' => 'line',
                    'data' => $this->column($series, 'contributed_eur'),
                ],
            ],
            'xaxis' => [
                ...ChartTheme::xaxis(),
                'categories' => $series->pluck('date')->all(),
                'type' => 'datetime',
            ],
            'yaxis' => ChartTheme::yaxis(),
            'colors' => [ChartTheme::INK, ChartTheme::MUTED],
            'stroke' => ['curve' => 'smooth', 'width' => [2.5, 1.5], 'dashArray' => [0, 4]],
            'legend' => [...ChartTheme::legend(), 'show' => true],
            'fill' => [...ChartTheme::areaFill(), 'type' => ['gradient', 'solid']],
            'grid' => ChartTheme::grid(),
            'dataLabels' => ['enabled' => false],
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $series
     * @return array<int, float>
     */
    private function column(Collection $series, string $key): array
    {
        return $series->map(fn (array $point): float => (float) $point[$key])->all();
    }

    protected function extraJsOptions(): ?RawJs
    {
        $jsLocale = NumberFormat::js();

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
                x: { format: 'MMM yyyy' },
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

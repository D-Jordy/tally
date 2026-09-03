<?php

namespace App\Filament\Widgets;

use App\Support\ChartTheme;
use App\Support\NumberFormat;
use Filament\Support\RawJs;
use Leandrocfe\FilamentApexCharts\Widgets\ApexChartWidget;

class ContributionsBarChart extends ApexChartWidget
{
    protected static ?string $chartId = 'contributionsBarChart';

    /**
     * Deposits per month from ComputeProjections, passed in by the page.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $history = [];

    /** The estimate the projection runs on, drawn as a reference line over the bars. */
    public float $average = 0;

    protected function getHeading(): ?string
    {
        return __('projections.contributions.heading');
    }

    protected function getOptions(): array
    {
        $history = collect($this->history);

        return [
            'chart' => ChartTheme::chart('bar'),
            'series' => [[
                'name' => __('projections.contributions.deposited'),
                'data' => $history->map(fn (array $month): float => (float) $month['deposited_eur'])->all(),
            ]],
            'xaxis' => [
                ...ChartTheme::xaxis(),
                'categories' => $history->map(fn (array $month): string => $this->label($month['month']))->all(),
            ],
            'yaxis' => ChartTheme::yaxis(),
            'colors' => [ChartTheme::POSITIVE],
            'plotOptions' => ['bar' => ['columnWidth' => '52%', 'borderRadius' => 2]],
            'annotations' => ['yaxis' => [[
                'y' => round($this->average, 2),
                'borderColor' => ChartTheme::INK,
                'strokeDashArray' => 4,
                'label' => [
                    'text' => __('projections.contributions.average', ['amount' => NumberFormat::money($this->average, 'EUR')]),
                    'position' => 'left',
                    'textAnchor' => 'start',
                    'borderColor' => 'transparent',
                    'style' => ['background' => ChartTheme::CARD, 'color' => ChartTheme::MUTED, 'fontFamily' => ChartTheme::MONO],
                ],
            ]]],
            'grid' => ChartTheme::grid(),
            'legend' => ['show' => false],
            'dataLabels' => ['enabled' => false],
        ];
    }

    /** 'Y-m' is not a label; '03/25' is. */
    private function label(string $month): string
    {
        return substr($month, 5).'/'.substr($month, 2, 2);
    }

    protected function extraJsOptions(): ?RawJs
    {
        $jsLocale = NumberFormat::js();

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

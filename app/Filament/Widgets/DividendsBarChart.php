<?php

namespace App\Filament\Widgets;

use App\Actions\ComputeIncomingDividends;
use App\Support\ChartTheme;
use App\Support\NumberFormat;
use Filament\Support\RawJs;
use Illuminate\Support\Collection;
use Leandrocfe\FilamentApexCharts\Widgets\ApexChartWidget;

class DividendsBarChart extends ApexChartWidget
{
    protected static ?string $chartId = 'dividendsBarChart';

    protected function getHeading(): ?string
    {
        return __('dividends.chart.heading');
    }

    protected function getOptions(): array
    {
        ['confirmed' => $confirmed, 'events' => $events] = app(ComputeIncomingDividends::class)->forUser(auth()->user());

        $months = collect(range(0, 11))->map(fn (int $offset): string => now()->addMonths($offset)->format('Y-m'));

        return [
            'chart' => [...ChartTheme::chart('bar'), 'stacked' => true],
            'series' => [
                ['name' => __('dividends.chart.confirmed'), 'data' => $this->bucket($confirmed, $months)],
                ['name' => __('dividends.chart.expected'), 'data' => $this->bucket($events, $months)],
            ],
            'xaxis' => [
                ...ChartTheme::xaxis(),
                'categories' => $months->map(fn (string $month): string => substr($month, 5).'/'.substr($month, 2, 2))->all(),
            ],
            'yaxis' => ChartTheme::yaxis(),
            'colors' => [ChartTheme::POSITIVE, ChartTheme::SOFT],
            'plotOptions' => ['bar' => ['columnWidth' => '52%', 'borderRadius' => 2]],
            'grid' => ChartTheme::grid(),
            'legend' => [...ChartTheme::legend(), 'position' => 'top', 'horizontalAlign' => 'right'],
            'dataLabels' => ['enabled' => false],
        ];
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

    /**
     * Sum expected_eur per month bucket for the given events.
     *
     * @param  array<int, array<string, mixed>>  $events
     * @param  Collection<int, string>  $months
     * @return array<int, float>
     */
    private function bucket(array $events, Collection $months): array
    {
        $totals = collect($events)
            ->groupBy(fn (array $event): string => substr((string) $event['ex_date'], 0, 7))
            ->map(fn (Collection $group): float => round((float) $group->sum(fn (array $event): float => (float) ($event['expected_eur'] ?? 0)), 2));

        return $months->map(fn (string $month): float => (float) ($totals[$month] ?? 0))->all();
    }
}

<?php

namespace App\Filament\Widgets;

use App\Actions\ComputePortfolioHistory;
use Filament\Support\RawJs;
use Illuminate\Support\Collection;
use Leandrocfe\FilamentApexCharts\Widgets\ApexChartWidget;

class PortfolioValueChart extends ApexChartWidget
{
    protected static ?string $chartId = 'portfolioValueChart';

    // Range is driven by the page's underlined toggle, passed in via :key.
    public string $range = 'ALL';

    protected function getHeading(): ?string
    {
        return 'Portefeuillewaarde';
    }

    protected function getOptions(): array
    {
        $history = $this->window(
            collect(app(ComputePortfolioHistory::class)->forUser(auth()->user()))
        );

        return [
            'chart' => [
                'type' => 'area',
                'height' => 300,
                'toolbar' => ['show' => false],
                'fontFamily' => 'IBM Plex Mono, monospace',
            ],
            'series' => [[
                'name' => 'Waarde',
                'data' => $history->pluck('total_value_eur')->map(fn ($value): float => (float) $value)->all(),
            ]],
            'xaxis' => [
                'categories' => $history->pluck('date')->all(),
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
            'fill' => [
                'type' => 'gradient',
                'gradient' => ['shadeIntensity' => 1, 'opacityFrom' => 0.12, 'opacityTo' => 0, 'stops' => [0, 100]],
            ],
            'grid' => ['borderColor' => '#ece9e0', 'strokeDashArray' => 0],
            'dataLabels' => ['enabled' => false],
        ];
    }

    protected function extraJsOptions(): ?RawJs
    {
        return RawJs::make(<<<'JS'
        {
            yaxis: {
                labels: {
                    formatter: function (value) {
                        return '€' + Math.round(value / 1000) + 'k';
                    },
                },
            },
            tooltip: {
                x: { format: 'dd MMM yyyy' },
                y: {
                    formatter: function (value) {
                        return new Intl.NumberFormat('nl-NL', {
                            style: 'currency', currency: 'EUR', maximumFractionDigits: 0,
                        }).format(value);
                    },
                },
            },
        }
        JS);
    }

    /**
     * Window the history to the active range filter.
     *
     * @param  Collection<int, array<string, mixed>>  $history
     * @return Collection<int, array<string, mixed>>
     */
    private function window(Collection $history): Collection
    {
        $cutoff = match ($this->range) {
            '1M' => now()->subMonth(),
            '6M' => now()->subMonths(6),
            '1Y' => now()->subYear(),
            default => null,
        };

        if ($cutoff === null) {
            return $history->values();
        }

        return $history
            ->filter(fn (array $point): bool => $point['date'] >= $cutoff->toDateString())
            ->values();
    }
}

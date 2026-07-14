<?php

namespace App\Filament\Widgets;

use App\Actions\ComputePortfolioHistory;
use Filament\Support\RawJs;
use Illuminate\Support\Collection;
use Leandrocfe\FilamentApexCharts\Widgets\ApexChartWidget;

class PortfolioValueChart extends ApexChartWidget
{
    protected static ?string $chartId = 'portfolioValueChart';

    // Range and mode are driven by the page's underlined toggles, passed in via :key.
    public string $range = '1Y';

    public string $mode = 'value';

    protected function getHeading(): ?string
    {
        return $this->mode === 'value'
            ? __('portfolio.chart.heading')
            : __('portfolio.chart.mode.'.$this->mode);
    }

    protected function getOptions(): array
    {
        $history = $this->window(
            collect(app(ComputePortfolioHistory::class)->forUser(auth()->user()))
        );

        if ($this->mode !== 'value') {
            return $this->derivedOptions($history);
        }

        return [
            'chart' => [
                'type' => 'line',
                'height' => 300,
                'toolbar' => ['show' => false],
                'fontFamily' => 'IBM Plex Mono, monospace',
            ],
            'series' => [
                [
                    'name' => __('portfolio.chart.value'),
                    'type' => 'area',
                    'data' => $history->pluck('total_value_eur')->map(fn ($value): float => (float) $value)->all(),
                ],
                [
                    'name' => __('portfolio.chart.invested'),
                    'type' => 'line',
                    'data' => $history->pluck('invested_eur')->map(fn ($value): float => (float) $value)->all(),
                ],
                [
                    'name' => __('portfolio.chart.dividends'),
                    'type' => 'line',
                    'data' => $history->pluck('cumulative_dividends_eur')->map(fn ($value): float => (float) $value)->all(),
                ],
            ],
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
            'colors' => ['#1a1a1a', '#9a9488', '#5a8f6d'],
            'stroke' => ['curve' => 'smooth', 'width' => [2.5, 1.5, 1.5], 'dashArray' => [0, 4, 0]],
            'legend' => ['show' => true, 'fontFamily' => 'IBM Plex Mono, monospace', 'labels' => ['colors' => '#9a9488']],
            'fill' => [
                'type' => ['gradient', 'solid', 'solid'],
                'gradient' => ['shadeIntensity' => 1, 'opacityFrom' => 0.12, 'opacityTo' => 0, 'stops' => [0, 100]],
            ],
            'grid' => ['borderColor' => '#ece9e0', 'strokeDashArray' => 0],
            'dataLabels' => ['enabled' => false],
        ];
    }

    /**
     * P/L (€) or RoI (%) as a single zero-crossing area — makes the gap between
     * value and invested explicit instead of two lines you eyeball apart.
     *
     * @param  Collection<int, array<string, mixed>>  $history
     * @return array<string, mixed>
     */
    private function derivedOptions(Collection $history): array
    {
        $isPl = $this->mode === 'pl';

        $data = $history->map(function (array $point) use ($isPl): float {
            $pl = (float) $point['total_value_eur'] - (float) $point['invested_eur'];

            if ($isPl) {
                return round($pl, 2);
            }

            $invested = (float) $point['invested_eur'];

            return $invested > 0 ? round($pl / $invested * 100, 2) : 0.0;
        })->all();

        return [
            'chart' => [
                'type' => 'area',
                'height' => 300,
                'toolbar' => ['show' => false],
                'fontFamily' => 'IBM Plex Mono, monospace',
            ],
            'series' => [
                ['name' => __('portfolio.chart.mode.'.$this->mode), 'data' => $data],
            ],
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
            'legend' => ['show' => false],
            'fill' => [
                'type' => 'gradient',
                'gradient' => ['shadeIntensity' => 1, 'opacityFrom' => 0.12, 'opacityTo' => 0, 'stops' => [0, 100]],
            ],
            'annotations' => [
                'yaxis' => [['y' => 0, 'borderColor' => '#c4bfb3', 'strokeDashArray' => 4]],
            ],
            'grid' => ['borderColor' => '#ece9e0', 'strokeDashArray' => 0],
            'dataLabels' => ['enabled' => false],
        ];
    }

    protected function extraJsOptions(): ?RawJs
    {
        $jsLocale = app()->getLocale() === 'nl' ? 'nl-NL' : 'en-US';
        $plLabel = __('portfolio.chart.pl');

        // Single-series modes: built-in tooltip + matching y-axis formatter is plenty.
        if ($this->mode !== 'value') {
            $formatter = $this->mode === 'roi'
                ? "function (value) { return value.toFixed(1) + '%'; }"
                : "function (value) { return new Intl.NumberFormat('{$jsLocale}', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 }).format(value); }";

            return RawJs::make(<<<JS
            {
                yaxis: { labels: { formatter: {$formatter} } },
                tooltip: { y: { formatter: {$formatter} } },
            }
            JS);
        }

        // The package injects this raw into a double-quoted x-data="..." attribute, so it must
        // contain zero double-quote chars (they'd close the attribute). HTML strings use
        // backticks with single-quoted style attrs; no `$` template interpolation is used, so
        // the PHP heredoc leaves the JS untouched — only {$jsLocale}/{$plLabel} interpolate.
        return RawJs::make(<<<JS
        {
            chart: {
                events: {
                    // Persist which series the user toggled off, keyed in localStorage.
                    legendClick: function (ctx, seriesIndex) {
                        var hidden = JSON.parse(localStorage.getItem('tally.pv.hidden') || '[]');
                        var name = ctx.w.globals.seriesNames[seriesIndex];
                        var at = hidden.indexOf(name);
                        if (at === -1) { hidden.push(name); } else { hidden.splice(at, 1); }
                        localStorage.setItem('tally.pv.hidden', JSON.stringify(hidden));
                    },
                    // Re-hide them after every (re)mount — survives the range :key remount.
                    mounted: function (ctx) {
                        JSON.parse(localStorage.getItem('tally.pv.hidden') || '[]')
                            .forEach(function (name) { ctx.hideSeries(name); });
                    },
                },
            },
            yaxis: {
                labels: {
                    formatter: function (value) {
                        return '€' + Math.round(value / 1000) + 'k';
                    },
                },
            },
            tooltip: {
                custom: function (ctx) {
                    var g = ctx.w.globals;
                    var i = ctx.dataPointIndex;
                    var fmt = function (value) {
                        return new Intl.NumberFormat('{$jsLocale}', {
                            style: 'currency', currency: 'EUR', maximumFractionDigits: 0,
                        }).format(value);
                    };
                    var date = new Date(g.seriesX[0][i]).toLocaleDateString('{$jsLocale}', {
                        day: '2-digit', month: 'short', year: 'numeric',
                    });
                    var rows = '';
                    for (var s = 0; s < g.seriesNames.length; s++) {
                        // Collapsed series have an empty data array → skip, else fmt() reads NaN.
                        var value = g.series[s][i];
                        if (value === undefined || value === null) { continue; }
                        rows += `<div style='display:flex;justify-content:space-between;gap:16px;'>`
                            + `<span style='color:` + g.colors[s] + `;'>` + g.seriesNames[s] + `</span>`
                            + `<span style='font-variant-numeric:tabular-nums;'>` + fmt(value) + `</span></div>`;
                    }
                    // P/L needs both value and invested visible.
                    if (g.series[0][i] != null && g.series[1][i] != null) {
                        var pl = g.series[0][i] - g.series[1][i];
                        rows += `<div style='display:flex;justify-content:space-between;gap:16px;border-top:1px solid #ece9e0;margin-top:4px;padding-top:4px;font-weight:600;'>`
                            + `<span>{$plLabel}</span>`
                            + `<span style='font-variant-numeric:tabular-nums;color:` + (pl >= 0 ? `#5a8f6d` : `#b06a5f`) + `;'>` + fmt(pl) + `</span></div>`;
                    }
                    return `<div style='padding:8px 12px;font-family:IBM Plex Mono,monospace;font-size:12px;'>`
                        + `<div style='color:#9a9488;margin-bottom:4px;'>` + date + `</div>` + rows + `</div>`;
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

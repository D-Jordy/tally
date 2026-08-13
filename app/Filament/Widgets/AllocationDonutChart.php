<?php

namespace App\Filament\Widgets;

use App\Support\ChartTheme;
use App\Support\NumberFormat;
use Filament\Support\RawJs;
use Leandrocfe\FilamentApexCharts\Widgets\ApexChartWidget;

/**
 * Shared donut for the Insights allocation view. Subclasses only supply the
 * heading + a unique chart id; each is fed `slices` ([['label' => .., 'value' => ..], ..]).
 */
abstract class AllocationDonutChart extends ApexChartWidget
{
    /** @var array<int, array{label: string, title: string, value: float, holdings?: array<int, string>}> */
    public array $slices = [];

    /**
     * Washed-out paper tints: light enough that the ink-coloured on-slice labels
     * read cleanly, distinct enough by hue to tell the slices apart.
     */
    private const PALETTE = ['#c9d6c4', '#e2c9b0', '#d3ccb4', '#dbc3c0', '#c3ccd6', '#e6dab2', '#cbc4b7', '#d8d2c4'];

    protected function getOptions(): array
    {
        $slices = collect($this->slices);

        return [
            // Instrument/sector names use the sans face everywhere (tables, legends, slices),
            // so this is the one chart that overrides the mono chrome font.
            'chart' => [...ChartTheme::chart('donut'), 'fontFamily' => ChartTheme::SANS],
            'series' => $slices->map(fn (array $slice): float => (float) $slice['value'])->all(),
            'labels' => $slices->pluck('label')->all(),
            'colors' => self::PALETTE,
            'stroke' => ['width' => 1, 'colors' => [ChartTheme::CARD]],
            // Pale slices swallow the default hover tint, so darken hard on hover.
            'states' => [
                'hover' => ['filter' => ['type' => 'darken', 'value' => 0.75]],
                'active' => ['filter' => ['type' => 'darken', 'value' => 0.65]],
            ],
            'legend' => [
                ...ChartTheme::legend(),
                'position' => 'bottom',
                'fontFamily' => ChartTheme::SANS,
            ],
            // Ink label colour lives here (PHP side): arrays inside extraJsOptions
            // have broken this donut's render before, so keep them out of the RawJs.
            'dataLabels' => [
                'enabled' => true,
                'style' => ['colors' => [ChartTheme::INK]],
            ],
        ];
    }

    protected function extraJsOptions(): ?RawJs
    {
        $jsLocale = NumberFormat::js();
        $sans = ChartTheme::SANS;

        // Inlined *inside* the formatter on purpose: an array sitting in the options
        // object gets deep-merged by the package and silently kills the donut render.
        // Single-quoted, because this JS ends up in a double-quoted x-data attribute.
        $quote = fn (string $value): string => "'".addcslashes($value, "'\\")."'";

        $titles = collect($this->slices)->pluck('title')->map($quote)->implode(', ');

        // Tooltip values are injected as innerHTML: escape each name, then stack them
        // one per line — side by side a broad sector blows the tooltip up.
        $holdings = collect($this->slices)
            ->map(fn (array $slice): string => $quote(collect($slice['holdings'] ?? [])->map(fn (string $name): string => e($name))->implode('<br>')))
            ->implode(', ');

        return RawJs::make(<<<JS
        {
            dataLabels: {
                enabled: true,
                // Slices carry the short label (ticker); the full name is on hover.
                formatter: function (value, opts) {
                    var label = opts.w.globals.labels[opts.seriesIndex];
                    if (label.length > 18) { label = label.slice(0, 17) + '…'; }
                    return label + '  ' + value.toFixed(0) + '%';
                },
                style: {
                    fontFamily: '{$sans}',
                    fontSize: '10px',
                    fontWeight: 600,
                },
                dropShadow: { enabled: false },
            },
            tooltip: {
                y: {
                    title: {
                        formatter: function (label, opts) {
                            var titles = [{$titles}];
                            var full = opts ? titles[opts.seriesIndex] : null;
                            return (full || label) + ':';
                        },
                    },
                    formatter: function (value, opts) {
                        var amount = new Intl.NumberFormat('{$jsLocale}', {
                            style: 'currency', currency: 'EUR', maximumFractionDigits: 0,
                        }).format(value);
                        // Sector slices list the holdings they bundle; position slices have none.
                        var holdings = [{$holdings}];
                        var list = opts ? holdings[opts.seriesIndex] : '';

                        // Backticks + single-quoted attribute: this JS lives in a double-quoted
                        // x-data attribute, so one double quote anywhere kills the whole chart.
                        return list
                            ? amount + `<span style='display:block;margin-top:4px;line-height:1.4;opacity:0.65'>` + list + `</span>`
                            : amount;
                    },
                },
            },
        }
        JS);
    }
}

<?php

namespace App\Filament\Widgets;

use Filament\Support\RawJs;
use Leandrocfe\FilamentApexCharts\Widgets\ApexChartWidget;

/**
 * Shared donut for the Insights allocation view. Subclasses only supply the
 * heading + a unique chart id; each is fed `slices` ([['label' => .., 'value' => ..], ..]).
 */
abstract class AllocationDonutChart extends ApexChartWidget
{
    /** @var array<int, array{label: string, title: string, value: float}> */
    public array $slices = [];

    /**
     * Washed-out paper tints: light enough that the ink-coloured on-slice labels
     * read cleanly, distinct enough by hue to tell the slices apart.
     */
    private const PALETTE = ['#c9d6c4', '#e2c9b0', '#d3ccb4', '#dbc3c0', '#c3ccd6', '#e6dab2', '#cbc4b7', '#d8d2c4'];

    /** Instrument/sector names use the sans face everywhere (tables, legends, slices). */
    private const FONT = 'Inter, sans-serif';

    protected function getOptions(): array
    {
        $slices = collect($this->slices);

        return [
            'chart' => [
                'type' => 'donut',
                'height' => 300,
                'fontFamily' => self::FONT,
            ],
            'series' => $slices->map(fn (array $slice): float => (float) $slice['value'])->all(),
            'labels' => $slices->pluck('label')->all(),
            'colors' => self::PALETTE,
            'stroke' => ['width' => 1, 'colors' => ['#fcfbf8']],
            // Pale slices swallow the default hover tint, so darken hard on hover.
            'states' => [
                'hover' => ['filter' => ['type' => 'darken', 'value' => 0.75]],
                'active' => ['filter' => ['type' => 'darken', 'value' => 0.65]],
            ],
            'legend' => [
                'position' => 'bottom',
                'fontFamily' => self::FONT,
                'labels' => ['colors' => '#8a8474'],
            ],
            // Ink label colour lives here (PHP side): arrays inside extraJsOptions
            // have broken this donut's render before, so keep them out of the RawJs.
            'dataLabels' => [
                'enabled' => true,
                'style' => ['colors' => ['#1a1a1a']],
            ],
        ];
    }

    protected function extraJsOptions(): ?RawJs
    {
        $jsLocale = app()->getLocale() === 'nl' ? 'nl-NL' : 'en-US';

        // Inlined *inside* the formatter on purpose: an array sitting in the options
        // object gets deep-merged by the package and silently kills the donut render.
        // Single-quoted, because this JS ends up in a double-quoted x-data attribute.
        $titles = collect($this->slices)
            ->pluck('title')
            ->map(fn (string $title): string => "'".addcslashes($title, "'\\")."'")
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
                    fontFamily: 'Inter, sans-serif',
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

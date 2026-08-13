<?php

namespace App\Support;

/**
 * The divio tokens, in PHP, for the ApexCharts widgets.
 *
 * Apex paints SVG attributes, so it cannot read the `--divio-*` custom properties
 * from theme.css — the values have to be literal. Keeping them here (instead of
 * inline per widget) is what stops the five charts drifting apart; mirror any
 * change in `resources/css/filament/app/theme.css`.
 */
final class ChartTheme
{
    /** Chart chrome (axes, legends, tooltips) is mono; names of things are sans. */
    public const MONO = 'IBM Plex Mono, monospace';

    public const SANS = 'Inter, sans-serif';

    public const INK = '#1a1a1a';          // --divio-ink

    public const MUTED = '#787367';        // --divio-muted

    public const FAINT = '#c4bfb3';        // --divio-faint

    public const SOFT = '#d8d2c4';         // --divio-dashed

    public const GRID = '#ece9e0';         // --divio-row-divider

    public const CARD = '#fcfbf8';         // --divio-card

    public const POSITIVE = '#5a8f6d';     // --divio-positive

    public const NEGATIVE = '#b06a5f';     // --divio-negative

    /**
     * @return array<string, mixed>
     */
    public static function chart(string $type): array
    {
        return [
            'type' => $type,
            'height' => 300,
            'toolbar' => ['show' => false],
            'fontFamily' => self::MONO,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function axisLabels(): array
    {
        return ['style' => ['colors' => self::MUTED, 'fontFamily' => self::MONO]];
    }

    /**
     * Spread this, then add `categories` / `type` per chart.
     *
     * @return array<string, mixed>
     */
    public static function xaxis(): array
    {
        return [
            'labels' => self::axisLabels(),
            'axisBorder' => ['show' => false],
            'axisTicks' => ['show' => false],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function yaxis(): array
    {
        return ['labels' => self::axisLabels()];
    }

    /**
     * @return array<string, mixed>
     */
    public static function grid(): array
    {
        return ['borderColor' => self::GRID, 'strokeDashArray' => 0];
    }

    /**
     * @return array<string, mixed>
     */
    public static function legend(): array
    {
        return ['fontFamily' => self::MONO, 'labels' => ['colors' => self::MUTED]];
    }

    /**
     * The faded wash under every area series.
     *
     * @return array<string, mixed>
     */
    public static function areaFill(): array
    {
        return [
            'type' => 'gradient',
            'gradient' => ['shadeIntensity' => 1, 'opacityFrom' => 0.12, 'opacityTo' => 0, 'stops' => [0, 100]],
        ];
    }
}

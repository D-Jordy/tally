<?php

namespace App\Filament\Concerns;

use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

/**
 * Builds divio-styled KPI cards on top of Filament's stock Stat component.
 * The divio theme restyles `.fi-wi-stats-overview-stat`; here we only supply
 * the per-stat top-rule colour and (optionally) an override figure colour via
 * the `--divio-stat-value` custom property the theme reads.
 */
trait BuildsStats
{
    protected function eur(float|int|string|null $value): string
    {
        return Number::currency((float) $value, 'EUR', app()->getLocale());
    }

    protected function pct(float|int|string|null $value): ?string
    {
        return $value === null ? null : Number::percentage((float) $value * 100, maxPrecision: 1, locale: app()->getLocale());
    }

    protected function signColor(float|int|string $value): string
    {
        return (float) $value >= 0 ? 'var(--divio-positive,#2f7d52)' : 'var(--divio-negative,#c0392b)';
    }

    protected function stat(string $label, string|int $value, ?string $rule = null, ?string $sub = null, ?string $color = null): Stat
    {
        $ruleColor = match ($rule) {
            'ink' => 'var(--divio-ink,#1a1a1a)',
            'positive' => 'var(--divio-positive,#2f7d52)',
            'neutral' => '#cdc8ba',
            default => null,
        };

        $style = collect([
            $ruleColor ? "border-top:2px solid {$ruleColor}" : null,
            $color ? "--divio-stat-value:{$color}" : null,
        ])->filter()->implode(';');

        return Stat::make($label, $value)
            ->description($sub)
            ->extraAttributes($style === '' ? [] : ['style' => $style]);
    }
}

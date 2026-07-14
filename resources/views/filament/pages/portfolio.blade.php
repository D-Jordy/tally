@php
    use Illuminate\Support\Number;

    $locale = app()->getLocale();
    $eur = fn ($value) => Number::currency((float) $value, 'EUR', $locale);
    $pct = fn ($value) => $value === null ? null : Number::percentage((float) $value * 100, maxPrecision: 1, locale: $locale);
    $signColor = fn ($value) => (float) $value >= 0 ? 'var(--divio-positive,#2f7d52)' : 'var(--divio-negative,#c0392b)';
@endphp

<x-filament-panels::page>
    {{-- KPI rows (stock Filament stats, divio-themed) --}}
    {{ $this->summaryStats }}
    {{ $this->returnsStats }}

    {{-- Toggles: mode (left) + range (right). ToggleButtons skinned as underlined
         text links (theme: .divio-linkbar); localStorage-remembered, drive chart :key remount. --}}
    <div
        x-data
        x-init="
            const range = localStorage.getItem('tally.pv.range');
            if (range && range !== $wire.range) { $wire.set('range', range); }
            const mode = localStorage.getItem('tally.pv.mode');
            if (mode && mode !== $wire.mode) { $wire.set('mode', mode); }
            $wire.$watch('range', (value) => localStorage.setItem('tally.pv.range', value));
            $wire.$watch('mode', (value) => localStorage.setItem('tally.pv.mode', value));
        "
        style="display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:-8px;"
    >
        {{ $this->modeControl }}
        {{ $this->rangeControl }}
    </div>

    {{-- Chart (re-mounts on range/mode change via :key) --}}
    @livewire(
        \App\Filament\Widgets\PortfolioValueChart::class,
        ['range' => $this->range, 'mode' => $this->mode],
        key('pv-'.$this->range.'-'.$this->mode)
    )

    {{-- Positions --}}
    @if ($this->hasPositions())
        <div style="background:var(--divio-card,#fcfbf8);border:1px solid var(--divio-hairline,#e6e3da);border-radius:8px;overflow:hidden;">
            <table style="width:100%;border-collapse:collapse;font-family:'IBM Plex Mono',monospace;font-size:13px;">
                @php
                    $head = 'font-size:10px;letter-spacing:.06em;text-transform:uppercase;color:var(--divio-muted,#9a9488);padding:10px 16px;font-weight:500;';
                    $cell = 'padding:10px 16px;color:var(--divio-body,#2a2a2a);font-variant-numeric:tabular-nums;';
                @endphp
                <thead>
                    <tr style="border-bottom:2px solid var(--divio-ink,#1a1a1a);">
                        <th style="{{ $head }}text-align:left;">{{ __('portfolio.table.instrument') }}</th>
                        <th style="{{ $head }}text-align:right;">{{ __('portfolio.table.quantity') }}</th>
                        <th style="{{ $head }}text-align:right;">{{ __('portfolio.table.avg_cost') }}</th>
                        <th style="{{ $head }}text-align:right;">{{ __('portfolio.table.price') }}</th>
                        <th style="{{ $head }}text-align:right;">{{ __('portfolio.table.value') }}</th>
                        <th style="{{ $head }}text-align:right;">{{ __('portfolio.table.unrealized') }}</th>
                        <th style="{{ $head }}text-align:right;">{{ __('portfolio.table.dividend') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->positions as $position)
                        <tr style="border-top:1px solid var(--divio-row-divider,#ece9e0);">
                            <td style="padding:10px 16px;text-align:left;font-family:'Inter',sans-serif;font-weight:600;color:var(--divio-ink,#1a1a1a);">
                                {{ $position['name'] }}
                            </td>
                            <td style="{{ $cell }}text-align:right;">{{ Number::format((float) $position['quantity'], maxPrecision: 4, locale: $locale) }}</td>
                            <td style="{{ $cell }}text-align:right;">
                                {{ $position['avg_cost_per_share'] !== null ? Number::format((float) $position['avg_cost_per_share'], maxPrecision: 2, locale: $locale).' '.$position['price_currency'] : '—' }}
                            </td>
                            <td style="{{ $cell }}text-align:right;">
                                {{ $position['latest_price'] !== null ? Number::format((float) $position['latest_price'], maxPrecision: 2, locale: $locale).' '.$position['latest_price_currency'] : '—' }}
                            </td>
                            <td style="{{ $cell }}text-align:right;">{{ $position['current_value_eur'] !== null ? $eur($position['current_value_eur']) : '—' }}</td>
                            <td style="{{ $cell }}text-align:right;color:{{ $position['unrealized_gain_eur'] !== null ? $signColor($position['unrealized_gain_eur']) : 'var(--divio-faint,#c4bfb3)' }};">
                                @if ($position['unrealized_gain_eur'] !== null)
                                    {{ $eur($position['unrealized_gain_eur']) }}@if ($position['unrealized_gain_pct'] !== null) <span style="color:var(--divio-muted,#9a9488);">({{ $pct($position['unrealized_gain_pct']) }})</span>@endif
                                @else
                                    —
                                @endif
                            </td>
                            <td style="{{ $cell }}text-align:right;color:{{ (float) $position['dividend_eur'] > 0 ? 'var(--divio-positive,#2f7d52)' : 'var(--divio-faint,#c4bfb3)' }};">
                                {{ (float) $position['dividend_eur'] > 0 ? $eur($position['dividend_eur']) : '—' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <div style="border:1px dashed var(--divio-dashed,#d8d2c4);background:#faf8f2;border-radius:8px;padding:40px;text-align:center;">
            <div style="display:inline-flex;align-items:center;justify-content:center;width:44px;height:44px;border-radius:8px;background:var(--divio-estimate-bg,#efe9dc);font-family:'Spectral',serif;font-size:24px;color:var(--divio-estimate-text,#a89c86);">+</div>
            <div style="margin-top:14px;font-family:'Spectral',serif;font-weight:600;font-size:18px;color:var(--divio-ink,#1a1a1a);">{{ __('portfolio.empty.title') }}</div>
            <div style="margin-top:6px;font-family:'Inter',sans-serif;font-size:13px;color:var(--divio-muted-nav,#8a8474);">{{ __('portfolio.empty.subtitle') }}</div>
            <div style="margin-top:16px;">
                <x-filament::button tag="a" :href="\App\Filament\Resources\Accounts\AccountResource::getUrl('index')">
                    {{ __('portfolio.empty.import') }}
                </x-filament::button>
            </div>
        </div>
    @endif
</x-filament-panels::page>

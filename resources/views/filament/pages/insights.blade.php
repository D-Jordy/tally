@php
    use Illuminate\Support\Number;

    $locale = app()->getLocale();
    $eur = fn ($value) => Number::currency((float) $value, 'EUR', $locale);
    $pct = fn ($value) => Number::percentage((float) $value * 100, maxPrecision: 1, locale: $locale);

    $data = $this->data;
    $valueSeries = $data['value_series'] ?? [];
    $dividendSeries = $data['dividend_series'] ?? [];
    $projectedValue = $valueSeries === [] ? 0 : end($valueSeries)['projected_value_eur'];
    $projectedDividends = $dividendSeries === [] ? 0 : end($dividendSeries)['projected_dividends_eur'];

    $allocation = $this->allocation;
    $sectionHeading = "font-family:'Spectral',serif;font-weight:600;font-size:18px;color:var(--divio-ink,#1a1a1a);margin-bottom:14px;";
@endphp

<x-filament-panels::page>
    {{-- Allocation (current composition) --}}
    @if (($allocation['total_eur'] ?? 0) > 0)
        <div style="{{ $sectionHeading }}">{{ __('insights.allocation.section') }}</div>
        <div style="display:grid;grid-template-columns:minmax(280px,1fr) 1.4fr;gap:14px;align-items:start;">
            {{-- Sector donut --}}
            @livewire(\App\Filament\Widgets\SectorAllocationChart::class, ['sectors' => $allocation['sectors']])

            {{-- Position sizes as ranked bars --}}
            <div style="background:var(--divio-card,#fcfbf8);border:1px solid var(--divio-hairline,#e6e3da);border-radius:8px;padding:14px 18px;">
                <div style="font-family:'IBM Plex Mono',monospace;font-size:10px;letter-spacing:.07em;text-transform:uppercase;color:var(--divio-muted,#9a9488);margin-bottom:14px;">{{ __('insights.allocation.positions_heading') }}</div>
                @foreach ($allocation['positions'] as $position)
                    <div style="margin-bottom:12px;">
                        <div style="display:flex;justify-content:space-between;gap:12px;font-family:'IBM Plex Mono',monospace;font-size:13px;margin-bottom:4px;">
                            <span style="font-family:'Inter',sans-serif;font-weight:600;color:var(--divio-ink,#1a1a1a);">{{ $position['name'] }}</span>
                            <span style="color:var(--divio-body,#2a2a2a);font-variant-numeric:tabular-nums;white-space:nowrap;">{{ $pct($position['weight']) }} · {{ $eur($position['value_eur']) }}</span>
                        </div>
                        <div style="height:6px;border-radius:3px;background:var(--divio-row-divider,#ece9e0);overflow:hidden;">
                            <div style="height:100%;width:{{ round($position['weight'] * 100, 2) }}%;background:var(--divio-ink,#1a1a1a);border-radius:3px;"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div style="border-top:1px solid var(--divio-hairline,#e6e3da);margin:8px 0;"></div>
    @endif

    {{-- Projections (future) --}}
    <div style="{{ $sectionHeading }}">{{ __('insights.projections.section') }}</div>

    {{-- Controls --}}
    <div style="display:flex;flex-wrap:wrap;align-items:flex-end;gap:24px;">
        <div>
            <div style="font-family:'IBM Plex Mono',monospace;font-size:10px;letter-spacing:.07em;text-transform:uppercase;color:var(--divio-muted,#9a9488);margin-bottom:6px;">{{ __('projections.horizon') }}</div>
            <div style="display:inline-flex;border:1px solid var(--divio-hairline,#e6e3da);border-radius:7px;overflow:hidden;">
                @foreach ([1, 3, 5, 10] as $option)
                    @php $active = $this->horizon === $option; @endphp
                    <button
                        type="button"
                        wire:click="$set('horizon', {{ $option }})"
                        style="font-family:'IBM Plex Mono',monospace;font-size:13px;padding:7px 14px;border:none;cursor:pointer;background:{{ $active ? 'var(--divio-ink,#1a1a1a)' : 'transparent' }};color:{{ $active ? 'var(--divio-card,#fcfbf8)' : 'var(--divio-muted-nav,#8a8474)' }};"
                    >{{ $option }}{{ __('projections.year_suffix') }}</button>
                @endforeach
            </div>
        </div>

        <div>
            <div style="font-family:'IBM Plex Mono',monospace;font-size:10px;letter-spacing:.07em;text-transform:uppercase;color:var(--divio-muted,#9a9488);margin-bottom:6px;">{{ __('projections.annual_contribution') }}</div>
            <div style="display:inline-flex;align-items:center;border:1px solid var(--divio-dashed,#d8d2c4);border-radius:7px;background:#fff;padding:0 12px;">
                <span style="font-family:'IBM Plex Mono',monospace;color:var(--divio-muted,#9a9488);">€</span>
                <input
                    type="number"
                    min="0"
                    step="100"
                    wire:model.live.debounce.600ms="annualContribution"
                    style="border:none;outline:none;background:transparent;font-family:'IBM Plex Mono',monospace;font-size:13px;padding:9px 6px;width:120px;color:var(--divio-ink,#1a1a1a);"
                />
            </div>
        </div>
    </div>

    {{-- KPI row --}}
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px;">
        <x-divio.kpi :label="__('projections.kpi.current')" :value="$eur($data['starting_value_eur'] ?? 0)" rule="neutral" />
        <x-divio.kpi :label="__('projections.kpi.expected', ['years' => $this->horizon])" :value="$eur($projectedValue)" rule="ink" />
        <x-divio.kpi :label="__('projections.kpi.growth_rate')" :value="$pct($data['growth_rate'] ?? 0)" rule="positive" valueColor="var(--divio-positive,#2f7d52)" />
        <x-divio.kpi :label="__('projections.kpi.dividends', ['years' => $this->horizon])" :value="$eur($projectedDividends)" rule="neutral" />
    </div>

    {{-- Growth chart --}}
    @livewire(
        \App\Filament\Widgets\ProjectionsGrowthChart::class,
        ['series' => $valueSeries],
        key('proj-'.$this->horizon.'-'.$this->annualContribution)
    )
</x-filament-panels::page>

@php
    use Illuminate\Support\Number;

    $eur = fn ($value) => Number::currency((float) $value, 'EUR', 'nl');
    $pct = fn ($value) => Number::percentage((float) $value * 100, maxPrecision: 1, locale: 'nl');

    $data = $this->data;
    $valueSeries = $data['value_series'] ?? [];
    $dividendSeries = $data['dividend_series'] ?? [];
    $projectedValue = $valueSeries === [] ? 0 : end($valueSeries)['projected_value_eur'];
    $projectedDividends = $dividendSeries === [] ? 0 : end($dividendSeries)['projected_dividends_eur'];
@endphp

<x-filament-panels::page>
    {{-- Controls --}}
    <div style="display:flex;flex-wrap:wrap;align-items:flex-end;gap:24px;">
        <div>
            <div style="font-family:'IBM Plex Mono',monospace;font-size:10px;letter-spacing:.07em;text-transform:uppercase;color:var(--divio-muted,#9a9488);margin-bottom:6px;">Horizon</div>
            <div style="display:inline-flex;border:1px solid var(--divio-hairline,#e6e3da);border-radius:7px;overflow:hidden;">
                @foreach ([1, 3, 5, 10] as $option)
                    @php $active = $this->horizon === $option; @endphp
                    <button
                        type="button"
                        wire:click="$set('horizon', {{ $option }})"
                        style="font-family:'IBM Plex Mono',monospace;font-size:13px;padding:7px 14px;border:none;cursor:pointer;background:{{ $active ? '#1a1a1a' : 'transparent' }};color:{{ $active ? '#fcfbf8' : 'var(--divio-muted-nav,#8a8474)' }};"
                    >{{ $option }}J</button>
                @endforeach
            </div>
        </div>

        <div>
            <div style="font-family:'IBM Plex Mono',monospace;font-size:10px;letter-spacing:.07em;text-transform:uppercase;color:var(--divio-muted,#9a9488);margin-bottom:6px;">Jaarlijkse inleg</div>
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
        <x-divio.kpi label="Huidige portefeuille" :value="$eur($data['starting_value_eur'] ?? 0)" rule="neutral" />
        <x-divio.kpi label="Verwacht over {{ $this->horizon }} jaar" :value="$eur($projectedValue)" inverted />
        <x-divio.kpi label="Gemengde groeivoet" :value="$pct($data['growth_rate'] ?? 0)" rule="positive" valueColor="var(--divio-positive,#2f7d52)" />
        <x-divio.kpi label="Dividenden over {{ $this->horizon }} jaar" :value="$eur($projectedDividends)" rule="neutral" />
    </div>

    {{-- Growth chart --}}
    @livewire(
        \App\Filament\Widgets\ProjectionsGrowthChart::class,
        ['series' => $valueSeries],
        key('proj-'.$this->horizon.'-'.$this->annualContribution)
    )
</x-filament-panels::page>

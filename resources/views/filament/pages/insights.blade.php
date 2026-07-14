@php
    $valueSeries = $this->valueSeries();
    $allocation = $this->allocation;

    // `label` is what the slice/legend shows (ticker), `title` what the tooltip shows.
    $toSlices = fn (array $rows, string $labelKey, string $titleKey) => collect($rows)
        ->map(fn (array $row): array => [
            'label' => $row[$labelKey],
            'title' => $row[$titleKey],
            'value' => $row['value_eur'],
        ])
        ->all();
@endphp

<x-filament-panels::page>
    {{-- Allocation (current composition): sector split + position sizes, both as donuts. --}}
    @if (($allocation['total_eur'] ?? 0) > 0)
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;align-items:start;">
            @livewire(\App\Filament\Widgets\SectorAllocationChart::class, ['slices' => $toSlices($allocation['sectors'], 'sector', 'sector')])
            @livewire(\App\Filament\Widgets\PositionAllocationChart::class, ['slices' => $toSlices($allocation['positions'], 'symbol', 'name')])
        </div>
    @endif

    {{-- Projections: KPI stats first, then the controls that drive them, then the chart. --}}
    {{ $this->projectionStats }}

    {{ $this->controls }}

    @livewire(
        \App\Filament\Widgets\ProjectionsGrowthChart::class,
        ['series' => $valueSeries],
        key('proj-'.$this->horizon.'-'.$this->annualContribution)
    )
</x-filament-panels::page>

@php
    $allocation = $this->allocation;

    // `label` is what the slice/legend shows (ticker), `title` what the tooltip shows.
    $toSlices = fn (array $rows, string $labelKey, string $titleKey) => collect($rows)
        ->map(fn (array $row): array => [
            'label' => $row[$labelKey],
            'title' => $row[$titleKey],
            'value' => $row['value_eur'],
            'holdings' => $row['holdings'] ?? [],
        ])
        ->all();
@endphp

<x-filament-panels::page>
    {{-- Nothing held yet: zeroed KPIs and a flat projection say less than nothing. --}}
    @unless ($this->hasPositions())
        <x-divio.empty-state
            :title="__('insights.empty.title')"
            :subtitle="__('insights.empty.subtitle')"
            :action="\App\Filament\Resources\Accounts\AccountResource::getUrl('index')"
            :action-label="__('portfolio.empty.import')"
        />
    @else
        {{-- Allocation (current composition): sector split + position sizes, both as donuts. --}}
        <div class="divio-split">
            @livewire(\App\Filament\Widgets\SectorAllocationChart::class, ['slices' => $toSlices($allocation['sectors'], 'sector', 'sector')])
            @livewire(\App\Filament\Widgets\PositionAllocationChart::class, ['slices' => $toSlices($allocation['positions'], 'symbol', 'name')])
        </div>

        {{-- Projections: KPI stats first, then the controls that drive them, then the chart. --}}
        {{ $this->projectionStats }}

        {{ $this->controls }}

        {{-- Every input that changes the series must be in the key, or the chart keeps the old one. --}}
        @livewire(
            \App\Filament\Widgets\ProjectionsGrowthChart::class,
            ['series' => $this->valueSeries()],
            key('proj-'.$this->horizon.'-'.$this->monthlyContribution.'-'.(int) $this->reinvestDividends)
        )

        {{-- Where the contribution estimate comes from: what you actually deposited. --}}
        @livewire(
            \App\Filament\Widgets\ContributionsBarChart::class,
            ['history' => $this->depositHistory(), 'average' => $this->estimatedContribution()],
        )
    @endunless
</x-filament-panels::page>

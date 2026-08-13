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
        class="divio-toolbar"
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
        @livewire(\App\Filament\Widgets\PositionsTable::class, ['rows' => $this->positions])
    @else
        <x-divio.empty-state
            :title="__('portfolio.empty.title')"
            :subtitle="__('portfolio.empty.subtitle')"
            :action="\App\Filament\Resources\Accounts\AccountResource::getUrl('index')"
            :action-label="__('portfolio.empty.import')"
        />
    @endif

    {{-- Closed positions — its own table so the open ones stay the headline. --}}
    @if ($this->hasPositions() || $this->hasClosedPositions())
        @livewire(\App\Filament\Widgets\ClosedPositionsTable::class, ['rows' => $this->closedPositions])
    @endif
</x-filament-panels::page>

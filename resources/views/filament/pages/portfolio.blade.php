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
        @livewire(\App\Filament\Widgets\PositionsTable::class, ['rows' => $this->positions])
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

    {{-- Closed positions — its own table so the open ones stay the headline. --}}
    @if ($this->hasPositions() || $this->hasClosedPositions())
        @livewire(\App\Filament\Widgets\ClosedPositionsTable::class, ['rows' => $this->closedPositions])
    @endif
</x-filament-panels::page>

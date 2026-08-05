<x-filament-panels::page>
    {{-- KPI row (stock Filament stats, divio-themed) --}}
    {{ $this->summaryStats }}

    @livewire(\App\Filament\Widgets\DividendPositionsTable::class, ['rows' => $this->byInstrument])

    @livewire(\App\Filament\Widgets\DividendsBarChart::class)

    @livewire(\App\Filament\Widgets\DividendCalendarTable::class, ['expectedEur' => $this->expectedEur])
</x-filament-panels::page>

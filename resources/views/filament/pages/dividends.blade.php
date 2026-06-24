@php
    use Illuminate\Support\Number;

    $eur = fn ($value) => Number::currency((float) $value, 'EUR', 'nl');
    $exDate = fn ($date) => \Illuminate\Support\Carbon::parse($date)->translatedFormat('d M Y');
    $perShare = fn ($row) => Number::format((float) $row['amount_per_share'], maxPrecision: 4, locale: 'nl').' '.$row['currency'];

    $summary = $this->summary;
    $head = 'font-size:10px;letter-spacing:.06em;text-transform:uppercase;color:var(--divio-muted,#9a9488);padding:10px 16px;font-weight:500;';
@endphp

<x-filament-panels::page>
    {{-- KPI row --}}
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;">
        <x-divio.kpi label="Verwacht komende 12 mnd" :value="$eur($summary['next_12m_total_eur'])" rule="ink" />
        <x-divio.kpi label="Ontvangen laatste 12 mnd" :value="$eur($summary['trailing_12m_received_eur'])" rule="positive" valueColor="var(--divio-positive,#2f7d52)" />
        <x-divio.kpi label="Dividend-betalende posities" :value="$summary['instrument_count']" rule="neutral" />
    </div>

    {{-- Stacked bar --}}
    @livewire(\App\Filament\Widgets\DividendsBarChart::class)

    {{-- Two tables side by side --}}
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;align-items:start;">
        {{-- Upcoming (confirmed) --}}
        <div style="background:var(--divio-card,#fcfbf8);border:1px solid var(--divio-hairline,#e6e3da);border-radius:8px;overflow:hidden;">
            <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:2px solid var(--divio-ink,#1a1a1a);">
                <span style="font-family:'Spectral',serif;font-weight:600;font-size:16px;color:var(--divio-ink,#1a1a1a);">Aankomend</span>
                <x-divio.badge type="confirmed" />
            </div>
            @if ($this->confirmed === [])
                <div style="padding:24px 16px;font-family:'Inter',sans-serif;font-size:13px;color:var(--divio-muted-nav,#8a8474);">Geen bevestigde dividenden.</div>
            @else
                <table style="width:100%;border-collapse:collapse;font-family:'IBM Plex Mono',monospace;font-size:13px;">
                    <thead><tr>
                        <th style="{{ $head }}text-align:left;">Instrument</th>
                        <th style="{{ $head }}text-align:right;">Ex-datum</th>
                        <th style="{{ $head }}text-align:right;">/aandeel</th>
                        <th style="{{ $head }}text-align:right;">Verwacht</th>
                    </tr></thead>
                    <tbody>
                        @foreach ($this->confirmed as $row)
                            <tr style="border-top:1px solid var(--divio-row-divider,#ece9e0);">
                                <td style="padding:10px 16px;font-family:'Inter',sans-serif;font-weight:600;color:var(--divio-ink,#1a1a1a);">{{ $row['name'] }}</td>
                                <td style="padding:10px 16px;text-align:right;color:var(--divio-body,#2a2a2a);font-variant-numeric:tabular-nums;">{{ $exDate($row['ex_date']) }}</td>
                                <td style="padding:10px 16px;text-align:right;color:var(--divio-body,#2a2a2a);font-variant-numeric:tabular-nums;">{{ $perShare($row) }}</td>
                                <td style="padding:10px 16px;text-align:right;color:var(--divio-body,#2a2a2a);font-variant-numeric:tabular-nums;">{{ $row['expected_eur'] !== null ? $eur($row['expected_eur']) : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        {{-- Projected (estimate, dashed + italic) --}}
        <div style="background:#faf8f2;border:1px dashed var(--divio-dashed,#d8d2c4);border-radius:8px;overflow:hidden;">
            <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:2px solid var(--divio-dashed,#d8d2c4);">
                <span style="font-family:'Spectral',serif;font-weight:600;font-size:16px;color:var(--divio-estimate-text,#a89c86);">Geprojecteerd</span>
                <x-divio.badge type="estimate" />
            </div>
            @if ($this->projected === [])
                <div style="padding:24px 16px;font-family:'Inter',sans-serif;font-size:13px;color:var(--divio-muted-nav,#8a8474);">Geen projecties beschikbaar.</div>
            @else
                <table style="width:100%;border-collapse:collapse;font-family:'IBM Plex Mono',monospace;font-size:13px;font-style:italic;color:var(--divio-estimate-text,#a89c86);">
                    <thead><tr>
                        <th style="{{ $head }}text-align:left;">Instrument</th>
                        <th style="{{ $head }}text-align:right;">Ex-datum</th>
                        <th style="{{ $head }}text-align:right;">/aandeel</th>
                        <th style="{{ $head }}text-align:right;">Verwacht</th>
                    </tr></thead>
                    <tbody>
                        @foreach ($this->projected as $row)
                            <tr style="border-top:1px solid var(--divio-dashed,#d8d2c4);">
                                <td style="padding:10px 16px;font-family:'Inter',sans-serif;font-weight:600;font-style:italic;">{{ $row['name'] }}</td>
                                <td style="padding:10px 16px;text-align:right;font-variant-numeric:tabular-nums;">{{ $exDate($row['ex_date']) }}</td>
                                <td style="padding:10px 16px;text-align:right;font-variant-numeric:tabular-nums;">{{ $perShare($row) }}</td>
                                <td style="padding:10px 16px;text-align:right;font-variant-numeric:tabular-nums;">{{ $row['expected_eur'] !== null ? $eur($row['expected_eur']) : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
</x-filament-panels::page>

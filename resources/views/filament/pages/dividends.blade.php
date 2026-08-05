@php
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Number;

    // No locale argument on the numbers: Number::useLocale() pins euro notation
    // app-wide. Month names stay on the UI language, hence translatedFormat().
    $eur = fn ($value) => Number::currency((float) $value, 'EUR');
    $day = fn ($date) => Carbon::parse($date)->translatedFormat('d M');
    $monthName = fn ($month) => Carbon::parse($month.'-01')->translatedFormat('F Y');
    $perShare = fn ($row) => Number::format((float) $row['amount_per_share'], maxPrecision: \App\Support\NumberFormat::MAX_DECIMALS).' '.$row['currency'];
    $pct = fn ($value) => $value !== null ? Number::percentage((float) $value * 100, maxPrecision: \App\Support\NumberFormat::DECIMALS) : '—';

    $head = 'font-size:10px;letter-spacing:.06em;text-transform:uppercase;color:var(--divio-muted,#9a9488);padding:10px 16px;font-weight:500;';

    // Ticker as the route key, id when the symbol was never resolved — see Instrument::getRouteKey().
    $instrumentUrl = fn ($row) => \App\Filament\Resources\Instruments\InstrumentResource::getUrl('view', [
        'record' => $row['yahoo_symbol'] ?: $row['instrument_id'],
    ]);
    $link = 'text-decoration:none;border-bottom:1px solid var(--divio-row-divider,#ece9e0);';
@endphp

<x-filament-panels::page>
    {{-- KPI row (stock Filament stats, divio-themed) --}}
    {{ $this->summaryStats }}

    {{-- Dividend-paying positions: yield vs yield on cost --}}
    @if ($this->byInstrument !== [])
        <div style="background:var(--divio-card,#fcfbf8);border:1px solid var(--divio-hairline,#e6e3da);border-radius:8px;overflow:hidden;">
            <div style="padding:14px 16px;border-bottom:2px solid var(--divio-ink,#1a1a1a);">
                <span style="font-family:'Spectral',serif;font-weight:600;font-size:16px;color:var(--divio-ink,#1a1a1a);">{{ __('dividends.sections.positions') }}</span>
            </div>
            <table style="width:100%;border-collapse:collapse;font-family:'IBM Plex Mono',monospace;font-size:13px;">
                <thead><tr>
                    <th style="{{ $head }}text-align:left;">{{ __('dividends.table.instrument') }}</th>
                    <th style="{{ $head }}text-align:right;">{{ __('dividends.table.value') }}</th>
                    <th style="{{ $head }}text-align:right;">{{ __('dividends.table.yield') }}</th>
                    <th style="{{ $head }}text-align:right;">{{ __('dividends.table.yoc') }}</th>
                    <th style="{{ $head }}text-align:right;">{{ __('dividends.table.forward_12m') }}</th>
                </tr></thead>
                <tbody>
                    @foreach ($this->byInstrument as $row)
                        <tr style="border-top:1px solid var(--divio-row-divider,#ece9e0);">
                            <td style="padding:10px 16px;font-family:'Inter',sans-serif;font-weight:600;"><a href="{{ $instrumentUrl($row) }}" style="color:var(--divio-ink,#1a1a1a);{{ $link }}">{{ $row['name'] }}</a></td>
                            <td style="padding:10px 16px;text-align:right;color:var(--divio-body,#2a2a2a);font-variant-numeric:tabular-nums;">{{ $row['current_value_eur'] !== null ? $eur($row['current_value_eur']) : '—' }}</td>
                            <td style="padding:10px 16px;text-align:right;color:var(--divio-body,#2a2a2a);font-variant-numeric:tabular-nums;">{{ $pct($row['yield']) }}</td>
                            <td style="padding:10px 16px;text-align:right;color:var(--divio-positive,#2f7d52);font-variant-numeric:tabular-nums;">{{ $pct($row['yield_on_cost']) }}</td>
                            <td style="padding:10px 16px;text-align:right;color:var(--divio-body,#2a2a2a);font-variant-numeric:tabular-nums;">{{ $eur($row['forward_12m_eur']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
    {{-- Stacked bar --}}
    @livewire(\App\Filament\Widgets\DividendsBarChart::class)

    {{-- Payment calendar: confirmed and projected on one line, grouped per month.
         Estimates keep the dashed/italic/muted language of the old projected table. --}}
    <div style="background:var(--divio-card,#fcfbf8);border:1px solid var(--divio-hairline,#e6e3da);border-radius:8px;overflow:hidden;">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:2px solid var(--divio-ink,#1a1a1a);">
            <span style="font-family:'Spectral',serif;font-weight:600;font-size:16px;color:var(--divio-ink,#1a1a1a);">{{ __('dividends.sections.calendar') }}</span>
            <span style="font-family:'Inter',sans-serif;font-size:11px;color:var(--divio-muted,#9a9488);">{{ __('dividends.calendar.note') }}</span>
        </div>

        @if ($this->timeline === [])
            <div style="padding:24px 16px;font-family:'Inter',sans-serif;font-size:13px;color:var(--divio-muted-nav,#8a8474);">{{ __('dividends.empty.calendar') }}</div>
        @else
            <table style="width:100%;border-collapse:collapse;font-family:'IBM Plex Mono',monospace;font-size:13px;">
                <thead><tr>
                    <th style="{{ $head }}text-align:left;">{{ __('dividends.table.instrument') }}</th>
                    <th style="{{ $head }}text-align:left;">{{ __('dividends.table.date') }}</th>
                    <th style="{{ $head }}text-align:right;">{{ __('dividends.table.per_share') }}</th>
                    <th style="{{ $head }}text-align:right;">{{ __('dividends.table.expected') }}</th>
                    <th style="{{ $head }}"></th>
                </tr></thead>
                <tbody>
                    @foreach ($this->timeline as $month)
                        <tr style="background:#f5f2ea;border-top:1px solid var(--divio-hairline,#e6e3da);">
                            <td colspan="3" style="padding:8px 16px;font-family:'Inter',sans-serif;font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:var(--divio-ink,#1a1a1a);">{{ $monthName($month['month']) }}</td>
                            <td colspan="2" style="padding:8px 16px;text-align:right;font-weight:600;font-variant-numeric:tabular-nums;color:var(--divio-ink,#1a1a1a);">{{ $eur($month['total_eur']) }}</td>
                        </tr>

                        @foreach ($month['rows'] as $row)
                            @php $estimate = ! $row['confirmed']; @endphp
                            <tr style="border-top:1px {{ $estimate ? 'dashed var(--divio-dashed,#d8d2c4)' : 'solid var(--divio-row-divider,#ece9e0)' }};{{ $estimate ? 'font-style:italic;color:var(--divio-estimate-text,#a89c86);' : '' }}">
                                <td style="padding:10px 16px;font-family:'Inter',sans-serif;font-weight:600;">
                                    <a href="{{ $instrumentUrl($row) }}" style="color:{{ $estimate ? 'inherit' : 'var(--divio-ink,#1a1a1a)' }};{{ $link }}">{{ $row['name'] }}</a>
                                </td>
                                <td style="padding:10px 16px;text-align:left;white-space:nowrap;font-variant-numeric:tabular-nums;{{ $estimate ? '' : 'color:var(--divio-body,#2a2a2a);' }}">
                                    {{ $day($row['date']) }}
                                    @unless ($row['is_pay_date'])
                                        <span title="{{ __('dividends.calendar.ex_hint') }}" style="font-family:'Inter',sans-serif;font-size:9px;font-style:normal;color:var(--divio-muted,#9a9488);">{{ __('dividends.calendar.ex') }}</span>
                                    @endunless
                                </td>
                                <td style="padding:10px 16px;text-align:right;font-variant-numeric:tabular-nums;{{ $estimate ? '' : 'color:var(--divio-body,#2a2a2a);' }}">{{ $perShare($row) }}</td>
                                <td style="padding:10px 16px;text-align:right;font-variant-numeric:tabular-nums;{{ $estimate ? '' : 'color:var(--divio-body,#2a2a2a);' }}">{{ $row['expected_eur'] !== null ? $eur($row['expected_eur']) : '—' }}</td>
                                <td style="padding:10px 16px;text-align:right;"><x-divio.badge :type="$estimate ? 'estimate' : 'confirmed'" /></td>
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</x-filament-panels::page>

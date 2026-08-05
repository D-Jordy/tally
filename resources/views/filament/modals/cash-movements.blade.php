@php
    use Illuminate\Support\Number;

    // No locale argument: Number::useLocale() pins euro notation app-wide.
    $eur = fn ($value) => Number::currency((float) $value, 'EUR');
    $signColor = fn ($value) => (float) $value >= 0 ? 'var(--divio-positive,#2f7d52)' : 'var(--divio-negative,#c0392b)';

    $head = 'font-size:10px;letter-spacing:.06em;text-transform:uppercase;color:var(--divio-muted,#9a9488);padding:8px 12px;font-weight:500;';
    $cell = 'padding:8px 12px;color:var(--divio-body,#2a2a2a);font-variant-numeric:tabular-nums;';
@endphp

@if ($ledger['rows'] === [])
    <div style="border:1px dashed var(--divio-dashed,#d8d2c4);background:#faf8f2;border-radius:8px;padding:32px;text-align:center;font-family:'Inter',sans-serif;font-size:13px;color:var(--divio-muted-nav,#8a8474);">
        {{ __('accounts.cash.empty') }}
    </div>
@else
    {{-- Totals per type, then the ledger itself. --}}
    <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px;">
        @foreach ($ledger['totals'] as $type => $total)
            <div style="border:1px solid var(--divio-hairline,#e6e3da);border-radius:6px;padding:6px 10px;font-family:'Inter',sans-serif;font-size:12px;">
                <span style="color:var(--divio-muted,#9a9488);">{{ __('accounts.cash.types.'.$type) }}</span>
                <span style="margin-left:6px;font-family:'IBM Plex Mono',monospace;font-variant-numeric:tabular-nums;color:{{ $signColor($total) }};">{{ $eur($total) }}</span>
            </div>
        @endforeach
        <div style="border:1px solid var(--divio-ink,#1a1a1a);border-radius:6px;padding:6px 10px;font-family:'Inter',sans-serif;font-size:12px;font-weight:600;">
            <span>{{ __('accounts.cash.balance') }}</span>
            <span style="margin-left:6px;font-family:'IBM Plex Mono',monospace;font-variant-numeric:tabular-nums;">{{ $eur($ledger['total_eur']) }}</span>
        </div>
    </div>

    <div style="max-height:60vh;overflow:auto;border:1px solid var(--divio-hairline,#e6e3da);border-radius:8px;">
        <table style="width:100%;border-collapse:collapse;font-family:'IBM Plex Mono',monospace;font-size:12px;">
            <thead>
                <tr style="border-bottom:2px solid var(--divio-ink,#1a1a1a);">
                    <th style="{{ $head }}text-align:left;">{{ __('accounts.cash.table.date') }}</th>
                    <th style="{{ $head }}text-align:left;">{{ __('accounts.cash.table.type') }}</th>
                    <th style="{{ $head }}text-align:left;">{{ __('accounts.cash.table.description') }}</th>
                    <th style="{{ $head }}text-align:right;">{{ __('accounts.cash.table.amount') }}</th>
                    <th style="{{ $head }}text-align:right;">{{ __('accounts.cash.table.amount_eur') }}</th>
                    <th style="{{ $head }}text-align:right;">{{ __('accounts.cash.table.balance') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($ledger['rows'] as $row)
                    <tr style="border-top:1px solid var(--divio-row-divider,#ece9e0);">
                        <td style="{{ $cell }}text-align:left;white-space:nowrap;">{{ $row['occurred_at'] }}</td>
                        <td style="{{ $cell }}text-align:left;font-family:'Inter',sans-serif;white-space:nowrap;">{{ __('accounts.cash.types.'.$row['type']) }}</td>
                        <td style="{{ $cell }}text-align:left;font-family:'Inter',sans-serif;">{{ $row['description'] }}</td>
                        <td style="{{ $cell }}text-align:right;white-space:nowrap;color:{{ $signColor($row['amount']) }};">
                            {{ Number::format($row['amount'], precision: \App\Support\NumberFormat::DECIMALS) }} {{ $row['currency'] }}
                        </td>
                        <td style="{{ $cell }}text-align:right;white-space:nowrap;color:{{ $row['amount_eur'] === null ? 'var(--divio-faint,#c4bfb3)' : $signColor($row['amount_eur']) }};">
                            {{ $row['amount_eur'] === null ? '—' : $eur($row['amount_eur']) }}
                        </td>
                        <td style="{{ $cell }}text-align:right;white-space:nowrap;">{{ $eur($row['balance_eur']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

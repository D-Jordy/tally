@php
    use Illuminate\Support\Number;

    // No locale argument: Number::useLocale() pins euro notation app-wide.
    $eur = fn ($value) => Number::currency((float) $value, 'EUR');
    $signColor = fn ($value) => (float) $value >= 0 ? 'var(--divio-positive,#2f7d52)' : 'var(--divio-negative,#c0392b)';
@endphp

@if ($ledger['rows'] !== [])
    <div style="display:flex;flex-wrap:wrap;gap:8px;padding:12px 16px 0;">
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
@endif

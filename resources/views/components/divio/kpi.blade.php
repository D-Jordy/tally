@props([
    'label',
    'value',
    'rule' => null,      // top-rule colour: ink | positive | neutral | null (none)
    'sub' => null,       // optional sub-line (e.g. +27,1%)
    'valueColor' => null, // override the figure colour
    'inverted' => false,  // ink card with white serif number
])

@php
    $ruleColor = match ($rule) {
        'ink' => '#1a1a1a',
        'positive' => '#2f7d52',
        'neutral' => '#cdc8ba',
        default => null,
    };
@endphp

<div
    @style([
        'background:'.($inverted ? '#1a1a1a' : 'var(--divio-card,#fcfbf8)'),
        'border:1px solid '.($inverted ? '#1a1a1a' : 'var(--divio-hairline,#e6e3da)'),
        'border-top:2px solid '.$ruleColor => $ruleColor && ! $inverted,
        'border-radius:8px',
        'padding:16px',
    ])
>
    <div style="font-family:'IBM Plex Mono',monospace;font-size:10px;letter-spacing:.07em;text-transform:uppercase;color:{{ $inverted ? '#cdc8ba' : 'var(--divio-muted,#9a9488)' }};">
        {{ $label }}
    </div>
    <div style="margin-top:8px;font-family:'Spectral',serif;font-weight:600;font-size:24px;line-height:1.1;color:{{ $valueColor ?? ($inverted ? '#fcfbf8' : 'var(--divio-ink,#1a1a1a)') }};">
        {{ $value }}
    </div>
    @if ($sub)
        <div style="margin-top:4px;font-family:'IBM Plex Mono',monospace;font-size:12px;color:{{ $valueColor ?? 'var(--divio-muted,#9a9488)' }};">
            {{ $sub }}
        </div>
    @endif
</div>

@props([
    'type' => 'confirmed', // confirmed | estimate
])

@php
    [$bg, $color, $text] = match ($type) {
        'estimate' => ['var(--divio-estimate-bg)', 'var(--divio-estimate-text)', __('dividends.badge.estimate')],
        default => ['var(--divio-positive-bg)', 'var(--divio-positive)', __('dividends.badge.confirmed')],
    };
@endphp

<span style="display:inline-block;background:{{ $bg }};color:{{ $color }};font-family:var(--font-mono);font-size:9px;font-weight:600;letter-spacing:.05em;padding:2px 6px;border-radius:4px;">
    {{ $text }}
</span>

@props([
    'type' => 'confirmed', // confirmed | estimate
])

@php
    [$bg, $color, $text] = match ($type) {
        'estimate' => ['#efe9dc', '#a89c86', 'ESTIMATE'],
        default => ['#e6efe6', '#2f7d52', 'CONFIRMED'],
    };
@endphp

<span style="display:inline-block;background:{{ $bg }};color:{{ $color }};font-family:'IBM Plex Mono',monospace;font-size:9px;font-weight:600;letter-spacing:.05em;padding:2px 6px;border-radius:4px;">
    {{ $text }}
</span>

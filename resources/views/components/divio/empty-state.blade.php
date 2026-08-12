@props([
    'title',
    'subtitle',
    'action' => null,
    'actionLabel' => null,
])

{{-- Dashed paper card shown when a page has no data to draw yet. --}}
<div style="border:1px dashed var(--divio-dashed);background:var(--divio-surface);border-radius:8px;padding:40px;text-align:center;">
    <div style="display:inline-flex;align-items:center;justify-content:center;width:44px;height:44px;border-radius:8px;background:var(--divio-estimate-bg);font-family:var(--font-serif);font-size:24px;color:var(--divio-estimate-text);">+</div>
    <div style="margin-top:14px;font-family:var(--font-serif);font-weight:600;font-size:18px;color:var(--divio-ink);">{{ $title }}</div>
    <div style="margin-top:6px;font-family:var(--font-sans);font-size:13px;color:var(--divio-muted-nav);">{{ $subtitle }}</div>

    @if ($action)
        <div style="margin-top:16px;">
            <x-filament::button tag="a" :href="$action">{{ $actionLabel }}</x-filament::button>
        </div>
    @endif
</div>

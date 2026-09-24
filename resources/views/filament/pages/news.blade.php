@php
    $news = $this->news();

    // Broadest first: on most days the market and sector moves are the story, and
    // there is nothing holding-specific worth reading.
    $buckets = [
        'market' => __('news.bucket.market'),
        'sectors' => __('news.bucket.sectors'),
        'holdings' => __('news.bucket.holdings'),
    ];
@endphp

<x-filament-panels::page>
    @unless ($this->hasNews())
        <x-divio.empty-state
            :title="__('news.empty.title')"
            :subtitle="__('news.empty.subtitle')"
            :action="\App\Filament\Resources\Accounts\AccountResource::getUrl('index')"
            :action-label="__('portfolio.empty.import')"
        />
    @else
        @foreach ($buckets as $key => $heading)
            @continue ($news[$key] === [])

            <section>
                <h2 style="font-family:var(--font-serif);font-weight:600;font-size:18px;color:var(--divio-ink);margin-bottom:12px;">{{ $heading }}</h2>

                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:12px;">
                    @foreach ($news[$key] as $group)
                        <div style="border:1px solid var(--divio-dashed);background:var(--divio-card);border-radius:8px;padding:16px;">
                            <div style="display:flex;align-items:baseline;gap:8px;margin-bottom:10px;">
                                <span style="font-family:var(--font-serif);font-weight:600;font-size:14px;color:var(--divio-ink);">{{ $group['label'] }}</span>
                                <span style="font-family:var(--font-sans);font-size:11px;color:var(--divio-muted-nav);">{{ $group['symbol'] }}</span>
                            </div>

                            <ul style="display:flex;flex-direction:column;gap:10px;">
                                @foreach ($group['headlines'] as $headline)
                                    <li>
                                        <a
                                            href="{{ $headline['url'] }}"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            style="font-family:var(--font-sans);font-size:13px;line-height:1.4;color:var(--divio-ink);text-decoration:none;"
                                            class="hover:underline"
                                        >{{ $headline['title'] }}</a>

                                        @if ($headline['summary'] !== '')
                                            <div style="font-family:var(--font-sans);font-size:12px;line-height:1.4;color:var(--divio-muted-nav);margin-top:3px;">
                                                {{ \Illuminate\Support\Str::limit($headline['summary'], 130) }}
                                            </div>
                                        @endif

                                        <div style="font-family:var(--font-sans);font-size:11px;color:var(--divio-muted-nav);margin-top:3px;">
                                            {{ $headline['published_at']->diffForHumans() }}
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            </section>
        @endforeach
    @endunless
</x-filament-panels::page>

<?php

namespace Tests\Feature;

use App\Actions\ComputeNews;
use App\Filament\Pages\News;
use App\Models\Account;
use App\Models\Instrument;
use App\Models\PriceHistory;
use App\Models\Transaction;
use App\Models\User;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ComputeNewsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    /** @param array<int, array{0: string, 1: string, 2?: string}> $items [id, title, url] */
    private function feed(array $items): string
    {
        // Yahoo escapes its own feed; an unescaped "S&P 500" here would fail to parse
        // and quietly turn every assertion below into "no headlines".
        $entries = collect($items)
            ->map(fn (array $item): array => [...$item, 2 => $item[2] ?? "https://finance.yahoo.com/news/{$item[0]}.html"])
            ->map(fn (array $item): array => array_map(htmlspecialchars(...), $item))
            ->map(fn (array $item): string => <<<XML
                <item>
                    <guid isPermaLink="false">{$item[0]}</guid>
                    <title>{$item[1]}</title>
                    <description>Some snippet.</description>
                    <link>{$item[2]}</link>
                    <pubDate>Wed, 02 Sep 2026 21:10:00 +0000</pubDate>
                </item>
                XML)
            ->implode("\n");

        return '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel>'.$entries.'</channel></rss>';
    }

    /** @param array<string, string> $feedsBySymbol */
    private function fakeFeeds(array $feedsBySymbol): void
    {
        Http::fake(function ($request) use ($feedsBySymbol) {
            $symbol = $request->data()['s'] ?? '';

            return Http::response($feedsBySymbol[$symbol] ?? $this->feed([]));
        });
    }

    private function holder(string $symbol, ?string $sector): User
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $instrument = Instrument::factory()->create([
            'name' => $symbol.' NV',
            'yahoo_symbol' => $symbol,
            'sector' => $sector,
            'quote_currency' => 'EUR',
        ]);

        Transaction::create([
            'account_id' => $account->id,
            'instrument_id' => $instrument->id,
            'executed_at' => '2024-01-02 10:00:00',
            'type' => 'buy',
            'quantity' => 10,
            'price' => 100,
            'price_currency' => 'EUR',
            'fee' => 0,
            'trade_currency' => 'EUR',
            'local_value' => 1000,
            'value_eur' => 1000,
            'total_eur' => 1000,
            'source' => 'import',
            'external_id' => 'buy-'.$symbol,
        ]);

        PriceHistory::create(['instrument_id' => $instrument->id, 'date' => '2024-06-03', 'close' => 120, 'currency' => 'EUR']);

        return $user;
    }

    private function compute(User $user): array
    {
        return app(ComputeNews::class)->forUser($user);
    }

    public function test_it_buckets_headlines_into_holdings_sectors_and_market(): void
    {
        $this->fakeFeeds([
            'ASML.AS' => $this->feed([['holding-1', 'ASML lands a new order']]),
            'XLK' => $this->feed([['sector-1', 'Chip demand keeps climbing']]),
            '^GSPC' => $this->feed([['market-1', 'S&P closes at a record']]),
        ]);

        $news = $this->compute($this->holder('ASML.AS', 'Technology'));

        $this->assertSame('ASML.AS NV', $news['holdings'][0]['label']);
        $this->assertSame('ASML lands a new order', $news['holdings'][0]['headlines'][0]['title']);
        $this->assertSame('Technology', $news['sectors'][0]['label']);
        $this->assertSame('XLK', $news['sectors'][0]['symbol']);
        $this->assertSame('S&P 500', $news['market'][0]['label']);
    }

    /** Regression: a naive collect() over the feed node keeps only the last item. */
    public function test_every_item_in_a_feed_is_read(): void
    {
        $this->fakeFeeds(['ASML.AS' => $this->feed([
            ['a', 'First story'],
            ['b', 'Second story'],
            ['c', 'Third story'],
        ])]);

        $news = $this->compute($this->holder('ASML.AS', 'Technology'));

        $this->assertCount(3, $news['holdings'][0]['headlines']);
    }

    /** A story syndicated to both feeds belongs under the holding, not repeated under its sector. */
    public function test_a_story_shared_by_a_holding_and_its_sector_shows_once(): void
    {
        $shared = ['shared-1', 'ASML lands a new order'];

        $this->fakeFeeds([
            'ASML.AS' => $this->feed([$shared]),
            'XLK' => $this->feed([$shared, ['sector-2', 'Chip demand keeps climbing']]),
        ]);

        $news = $this->compute($this->holder('ASML.AS', 'Technology'));

        $this->assertCount(1, $news['holdings'][0]['headlines']);
        $this->assertSame(['Chip demand keeps climbing'], array_column($news['sectors'][0]['headlines'], 'title'));
    }

    /** Groups whose feed came back empty would otherwise render as bare headings. */
    public function test_groups_without_headlines_are_dropped(): void
    {
        $this->fakeFeeds(['^GSPC' => $this->feed([['market-1', 'S&P closes at a record']])]);

        $news = $this->compute($this->holder('ASML.AS', 'Technology'));

        $this->assertSame([], $news['holdings']);
        $this->assertSame([], $news['sectors']);
        $this->assertCount(1, $news['market']);
    }

    /** XLRE's feed reports on Financial stocks, so Real Estate reads Vanguard's VNQ. */
    public function test_real_estate_reads_vnq_rather_than_xlre(): void
    {
        $this->fakeFeeds(['VNQ' => $this->feed([['re', 'REITs are finally winning']])]);

        $news = $this->compute($this->holder('AGNC', 'Real Estate'));

        $this->assertSame('VNQ', $news['sectors'][0]['symbol']);
        $this->assertSame(['REITs are finally winning'], array_column($news['sectors'][0]['headlines'], 'title'));
        Http::assertNotSent(fn (Request $request): bool => ($request->data()['s'] ?? '') === 'XLRE');
    }

    /** An unmapped sector has no ETF to read, and must not become a "null" feed request. */
    public function test_an_unmapped_sector_is_skipped(): void
    {
        $this->fakeFeeds([]);

        $this->compute($this->holder('ASML.AS', 'Shell Companies'));

        Http::assertNotSent(fn ($request): bool => ($request->data()['s'] ?? '') === '');
    }

    public function test_a_second_read_is_served_from_cache(): void
    {
        $this->fakeFeeds(['ASML.AS' => $this->feed([['holding-1', 'ASML lands a new order']])]);

        $user = $this->holder('ASML.AS', 'Technology');
        $this->compute($user);
        $sentAfterFirst = count(Http::recorded());

        $this->compute($user);

        $this->assertSame($sentAfterFirst, count(Http::recorded()));
    }

    /** A Yahoo hiccup must not blank the tab for the full cache window. */
    public function test_a_failed_fetch_is_retried_within_minutes(): void
    {
        Http::fake(fn (): PromiseInterface => Http::response('', 503));

        $user = $this->holder('ASML.AS', 'Technology');
        $this->compute($user);
        $afterFirst = count(Http::recorded());

        $this->travel(5)->minutes();
        $this->compute($user);

        $this->assertGreaterThan($afterFirst, count(Http::recorded()));
    }

    /** Yahoo answers a malformed feed with a 200, so that has to count as a failure too. */
    public function test_an_unparseable_feed_is_retried_within_minutes(): void
    {
        Http::fake(fn (): PromiseInterface => Http::response('<rss><channel><item></rss>'));

        $user = $this->holder('ASML.AS', 'Technology');
        $this->compute($user);
        $afterFirst = count(Http::recorded());

        $this->travel(5)->minutes();
        $this->compute($user);

        $this->assertGreaterThan($afterFirst, count(Http::recorded()));
    }

    /** An empty feed is a real answer rather than a failure, and keeps the full window. */
    public function test_an_empty_feed_is_not_refetched(): void
    {
        $this->fakeFeeds([]);

        $user = $this->holder('ASML.AS', 'Technology');
        $this->compute($user);
        $afterFirst = count(Http::recorded());

        $this->travel(5)->minutes();
        $this->compute($user);

        $this->assertSame($afterFirst, count(Http::recorded()));
    }

    /** Feed links go straight into an href, where a javascript: URL would run on click. */
    public function test_a_link_that_is_not_a_web_url_is_dropped(): void
    {
        $this->fakeFeeds(['ASML.AS' => $this->feed([
            ['bad', 'Tap here', 'javascript:alert(1)'],
            ['good', 'ASML lands a new order'],
        ])]);

        $news = $this->compute($this->holder('ASML.AS', 'Technology'));

        $this->assertSame(['ASML lands a new order'], array_column($news['holdings'][0]['headlines'], 'title'));
    }

    /** The page renders over a real HTTP request, not just in Livewire::test. */
    public function test_the_news_page_renders_the_headlines(): void
    {
        $this->fakeFeeds(['ASML.AS' => $this->feed([['holding-1', 'ASML lands a new order']])]);

        $user = $this->holder('ASML.AS', 'Technology');

        Livewire::actingAs($user)
            ->test(News::class)
            ->assertSuccessful()
            ->assertSee('ASML lands a new order')
            ->assertSee('Some snippet.');

        $this->actingAs($user)->get(News::getUrl())->assertSuccessful();
    }
}

<?php

namespace Tests\Feature;

use App\Services\MarketData\YahooFinanceAdapter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class YahooFinanceAdapterTest extends TestCase
{
    public function test_quote_summary_authenticates_with_a_crumb(): void
    {
        Http::fake([
            'fc.yahoo.com*' => Http::response('', 200),
            '*/v1/test/getcrumb' => Http::response('abc123', 200),
            '*/v10/finance/quoteSummary/*' => Http::response([
                'quoteSummary' => ['result' => [['assetProfile' => ['sector' => 'Technology']]]],
            ]),
        ]);

        $this->assertSame('Technology', app(YahooFinanceAdapter::class)->sector('ASML.AS'));

        // Regression: called without a crumb, Yahoo answers 401 "Invalid Crumb" and every
        // sector / analyst / dividend-calendar lookup silently degraded to null.
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/v10/finance/quoteSummary/ASML.AS')
            && str_contains($request->url(), 'crumb=abc123'));
    }

    public function test_quote_summary_gives_up_when_no_crumb_can_be_fetched(): void
    {
        Http::fake([
            'fc.yahoo.com*' => Http::response('', 200),
            '*/v1/test/getcrumb' => Http::response('', 503),
            '*/v10/finance/quoteSummary/*' => Http::response([
                'quoteSummary' => ['result' => [['assetProfile' => ['sector' => 'Technology']]]],
            ]),
        ]);

        $this->assertNull(app(YahooFinanceAdapter::class)->sector('ASML.AS'));

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/v10/finance/quoteSummary'));
    }

    public function test_the_crumb_is_fetched_once_and_reused_across_lookups(): void
    {
        Http::fake([
            'fc.yahoo.com*' => Http::response('', 200),
            '*/v1/test/getcrumb' => Http::response('abc123', 200),
            '*/v10/finance/quoteSummary/*' => Http::response([
                'quoteSummary' => ['result' => [['assetProfile' => ['sector' => 'Technology']]]],
            ]),
        ]);

        $adapter = app(YahooFinanceAdapter::class);
        $adapter->sector('ASML.AS');
        $adapter->sector('NN.AS');

        Http::assertSentCount(4); // cookie + crumb once, then one quoteSummary per symbol
    }
}

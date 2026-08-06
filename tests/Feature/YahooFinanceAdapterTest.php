<?php

namespace Tests\Feature;

use App\Services\MarketData\YahooFinanceAdapter;
use GuzzleHttp\Promise\PromiseInterface;
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

    public function test_dividend_yield_reads_equity_and_etf_fields_and_falls_back_to_null(): void
    {
        $summaryDetail = fn (array $detail): PromiseInterface => Http::response([
            'quoteSummary' => ['result' => [['summaryDetail' => $detail]]],
        ]);

        Http::fake([
            'fc.yahoo.com*' => Http::response('', 200),
            '*/v1/test/getcrumb' => Http::response('abc123', 200),
            // Equities report dividendYield, ETFs report yield instead.
            '*/quoteSummary/NN.AS*' => $summaryDetail(['dividendYield' => ['raw' => 0.0485]]),
            '*/quoteSummary/IEDY.L*' => $summaryDetail(['yield' => ['raw' => 0.0528]]),
            // Yahoo has no figure for plenty of Amsterdam-listed ETFs — the caller must be
            // able to tell that apart from "pays nothing" and fall back to its own maths.
            '*/quoteSummary/VWRL.AS*' => $summaryDetail(['trailingAnnualDividendRate' => ['raw' => 0.0]]),
            // A reported zero is missing coverage, not a 0% payer, and must not override
            // a working projection with 0%.
            '*/quoteSummary/ZERO.AS*' => $summaryDetail(['dividendYield' => ['raw' => 0]]),
        ]);

        $adapter = app(YahooFinanceAdapter::class);

        $this->assertSame(0.0485, $adapter->dividendYield('NN.AS'));
        $this->assertSame(0.0528, $adapter->dividendYield('IEDY.L'));
        $this->assertNull($adapter->dividendYield('VWRL.AS'));
        $this->assertNull($adapter->dividendYield('ZERO.AS'));
    }

    public function test_a_from_date_past_today_skips_the_request_instead_of_erroring(): void
    {
        Http::fake(['*/v8/finance/chart/*' => Http::response('', 400)]);

        $adapter = app(YahooFinanceAdapter::class);

        // Regression: everything synced through today means fromDate is tomorrow, Yahoo 400s
        // on the backwards window and every scheduled run logged a fake FX/price failure.
        $this->assertSame([], $adapter->fxHistory('USD', now()->addDay()->toDateString()));
        $this->assertSame([], $adapter->history('ASML.AS', now()->addDay()->toDateString()));

        Http::assertNothingSent();
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

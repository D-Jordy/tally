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

    public function test_the_degiro_exchange_picks_the_matching_listing(): void
    {
        Http::fake(['*/v1/finance/search*' => Http::response(['quotes' => [
            ['symbol' => 'ASHR.L', 'quoteType' => 'ETF', 'score' => 20001],
            ['symbol' => 'RQFI.DE', 'quoteType' => 'ETF', 'score' => 20000],
        ]])]);

        // Yahoo has no Tradegate line of its own, so TDG resolves to the German listing.
        $this->assertSame('RQFI.DE', app(YahooFinanceAdapter::class)->searchByIsin('LU0875160326', 'TDG'));
    }

    public function test_a_listing_quoted_in_a_currency_you_never_paid_in_loses_to_a_sibling(): void
    {
        Http::fake([
            '*/v1/finance/search*' => Http::response(['quotes' => [
                ['symbol' => 'ASHR.L', 'quoteType' => 'ETF', 'score' => 20001],
                ['symbol' => 'RQFI.DE', 'quoteType' => 'ETF', 'score' => 20000],
            ]]),
            '*/chart/ASHR.L*' => $this->chartIn('USD', 13.265),
            '*/chart/RQFI.DE*' => $this->chartIn('EUR', 11.67),
        ]);

        // Regression: the highest-scored hit was the LSE line quoted in USD, which hung the
        // position on a needless FX leg and reported none of the fund's dividends.
        $this->assertSame('RQFI.DE', app(YahooFinanceAdapter::class)->searchByIsin('LU0875160326', null, 'EUR'));
    }

    public function test_a_matching_currency_is_accepted_without_widening_the_search(): void
    {
        Http::fake([
            '*/v1/finance/search*' => Http::response(['quotes' => [
                ['symbol' => 'NOVO-B.CO', 'quoteType' => 'EQUITY', 'score' => 20001],
            ]]),
            '*/chart/NOVO-B.CO*' => $this->chartIn('DKK', 412.5),
        ]);

        $this->assertSame('NOVO-B.CO', app(YahooFinanceAdapter::class)->searchByIsin('DK0062498333', 'OMK', 'DKK'));

        Http::assertSentCount(2); // the ISIN search plus the one currency probe
    }

    public function test_the_search_widens_by_name_when_the_isin_returns_the_wrong_listing_only(): void
    {
        Http::fake([
            '*/v1/finance/search*' => function (Request $request) {
                // Yahoo answers this ISIN with exactly one quote — the USD London line — so
                // the EUR listing that matches the fill is only reachable by name.
                if ($request['q'] === 'LU0875160326') {
                    return Http::response(['quotes' => [[
                        'symbol' => 'ASHR.L',
                        'quoteType' => 'ETF',
                        'score' => 20001,
                        'longname' => 'Xtrackers Harvest CSI300 UCITS ETF 1D',
                    ]]]);
                }

                return Http::response(['quotes' => [
                    // Same index, different issuer: a name search finds it, and picking it
                    // would repoint the instrument at the wrong fund.
                    ['symbol' => 'DECOY.DE', 'quoteType' => 'ETF', 'score' => 20001, 'longname' => 'Other CSI300 UCITS ETF'],
                    ['symbol' => 'RQFI.DE', 'quoteType' => 'ETF', 'score' => 20000, 'longname' => 'Xtrackers Harvest CSI300 UCITS ETF 1D'],
                ]]);
            },
            '*/chart/ASHR.L*' => $this->chartIn('USD', 13.265),
            '*/chart/DECOY.DE*' => $this->chartIn('EUR', 42.0),
            '*/chart/RQFI.DE*' => $this->chartIn('EUR', 11.67),
        ]);

        $this->assertSame('RQFI.DE', app(YahooFinanceAdapter::class)->searchByIsin('LU0875160326', 'TDG', 'EUR'));
    }

    public function test_a_matching_currency_with_no_price_history_loses_to_the_populated_listing(): void
    {
        Http::fake([
            '*/v1/finance/search*' => Http::response(['quotes' => [
                ['symbol' => 'GLDI.L', 'quoteType' => 'ETF', 'score' => 20001],
                ['symbol' => 'XS2852999775.SG', 'quoteType' => 'ETF', 'score' => 20000],
            ]]),
            '*/chart/GLDI.L*' => $this->chartIn('USD', 24.1),
            // Stuttgart quotes this ETP in EUR but returns no closes at all — trading a
            // currency mismatch for an empty chart is not an improvement.
            '*/chart/XS2852999775.SG*' => Http::response(['chart' => ['result' => [[
                'meta' => ['currency' => 'EUR'],
                'timestamp' => [],
                'indicators' => ['quote' => [['close' => []]]],
            ]]]]),
        ]);

        $this->assertSame('GLDI.L', app(YahooFinanceAdapter::class)->searchByIsin('XS2852999775', null, 'EUR'));
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

    private function chartIn(string $currency, float $close): PromiseInterface
    {
        return Http::response(['chart' => ['result' => [[
            'meta' => ['currency' => $currency],
            'timestamp' => [now()->subDay()->timestamp],
            'indicators' => ['quote' => [['close' => [$close]]]],
        ]]]]);
    }
}

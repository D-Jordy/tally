<?php

namespace App\Services\MarketData;

use App\Services\Import\CurrencyNormaliser;
use Carbon\Carbon;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Promises\LazyPromise;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use SimpleXMLElement;

class YahooFinanceAdapter
{
    private const CHART_URL = 'https://query2.finance.yahoo.com/v8/finance/chart';

    private const SEARCH_URL = 'https://query2.finance.yahoo.com/v1/finance/search';

    private const QUOTE_SUMMARY_URL = 'https://query2.finance.yahoo.com/v10/finance/quoteSummary';

    private const COOKIE_URL = 'https://fc.yahoo.com';

    private const CRUMB_URL = 'https://query2.finance.yahoo.com/v1/test/getcrumb';

    private const NEWS_URL = 'https://feeds.finance.yahoo.com/rss/2.0/headline';

    /**
     * quoteSummary is cookie+crumb gated — without these Yahoo answers 401 "Invalid Crumb"
     * and every sector / analyst-target / dividend-calendar lookup silently returns null.
     * Fetched once per adapter instance and reused for the whole sync run.
     */
    private ?CookieJar $cookies = null;

    private ?string $crumb = null;

    // DEGIRO exchange code → preferred Yahoo symbol suffix.
    // Yahoo has no line of its own for Tradegate, so TDG maps to the German listing.
    private const EXCHANGE_SUFFIX = [
        'EAM' => '.AS', 'AMS' => '.AS',
        'LSE' => '.L',
        'XET' => '.DE', 'GER' => '.DE', 'TDG' => '.DE',
        'MAD' => '.MC',
        'EPA' => '.PA',
        'OMK' => '.CO',
        'NDQ' => '',    'NYS' => '',
    ];

    /**
     * Fetch daily adjusted-close history from $fromDate onward.
     * Returns [['date' => 'YYYY-MM-DD', 'close' => float, 'currency' => string], ...]
     */
    public function history(string $symbol, string $fromDate): array
    {
        return $this->parseOhlc($this->chart($symbol, $fromDate));
    }

    /**
     * Fetch daily FX rate history for $base → EUR from $fromDate onward.
     * Returns [['date' => 'YYYY-MM-DD', 'rate' => float], ...]
     */
    public function fxHistory(string $base, string $fromDate): array
    {
        $rows = $this->parseOhlc($this->chart("{$base}EUR=X", $fromDate));

        return array_map(fn ($r) => ['date' => $r['date'], 'rate' => $r['close']], $rows);
    }

    /**
     * Fetch historical dividends from $fromDate onward.
     * Yahoo only returns dividends already gone ex — never future announced ones.
     * Returns [['ex_date' => 'YYYY-MM-DD', 'amount' => float, 'currency' => string], ...]
     * sorted by ex_date. Amounts are in the instrument's quote currency (e.g. GBp for LSE).
     */
    public function dividends(string $symbol, string $fromDate): array
    {
        $result = $this->chart($symbol, $fromDate);
        $events = $result['events']['dividends'] ?? [];
        $currency = $result['meta']['currency'] ?? '';

        $rows = [];

        foreach ($events as $event) {
            if (! isset($event['date'], $event['amount'])) {
                continue;
            }

            $rows[] = [
                'ex_date' => Carbon::createFromTimestamp($event['date'])->toDateString(),
                'amount' => (float) $event['amount'],
                'currency' => $currency,
            ];
        }

        usort($rows, fn ($a, $b) => $a['ex_date'] <=> $b['ex_date']);

        return $rows;
    }

    /**
     * Fetch the latest close. Returns ['symbol', 'price', 'currency', 'date']
     */
    public function quote(string $symbol): array
    {
        $rows = $this->history($symbol, now()->subDays(5)->toDateString());

        if (empty($rows)) {
            throw new \RuntimeException("No quote data for {$symbol}");
        }

        $last = end($rows);

        return [
            'symbol' => $symbol,
            'price' => $last['close'],
            'currency' => $last['currency'],
            'date' => $last['date'],
        ];
    }

    /**
     * Fetch the next confirmed ex-date and pay-date from Yahoo's calendarEvents module.
     * Returns ['ex_date' => 'YYYY-MM-DD', 'pay_date' => 'YYYY-MM-DD'|null] or null
     * when no future confirmed event is available or the request fails.
     */
    public function upcomingDividend(string $symbol): ?array
    {
        $data = $this->quoteSummary($symbol, 'calendarEvents');

        if ($data === null) {
            return null;
        }

        $calendar = $data['calendarEvents'] ?? null;

        if (! $calendar) {
            return null;
        }

        $exTs = $calendar['exDividendDate']['raw'] ?? null;
        $payTs = $calendar['dividendDate']['raw'] ?? null;

        if (! $exTs) {
            return null;
        }

        $exDate = Carbon::createFromTimestamp($exTs)->toDateString();

        if ($exDate < now()->toDateString()) {
            return null;
        }

        return [
            'ex_date' => $exDate,
            'pay_date' => $payTs ? Carbon::createFromTimestamp($payTs)->toDateString() : null,
        ];
    }

    /**
     * Headlines per symbol, fetched in one pool. Yahoo's search endpoint is not
     * symbol-scoped — asking it for ASRNL answers with Lundbeck and Rivian — so this
     * RSS feed is the only source that actually tracks the instrument. It wants the
     * suffixed symbol (ASRNL.AS), and a symbol it does not know yields an empty feed.
     *
     * @param  array<int, string>  $symbols
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function headlines(array $symbols): array
    {
        $responses = Http::pool(fn (Pool $pool): array => collect($symbols)
            ->map(fn (string $symbol): LazyPromise => $pool->as($symbol)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0'])
                ->timeout(15)
                ->get(self::NEWS_URL, ['s' => $symbol, 'region' => 'US', 'lang' => 'en-US']))
            ->all());

        return collect($symbols)
            ->mapWithKeys(fn (string $symbol): array => [$symbol => $this->parseHeadlines($responses[$symbol] ?? null)])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function parseHeadlines(mixed $response): array
    {
        if (! $response instanceof Response || ! $response->successful()) {
            return [];
        }

        // Yahoo answers a malformed feed with a 200, so a parse failure is a normal outcome.
        $feed = @simplexml_load_string($response->body());

        if ($feed === false) {
            return [];
        }

        // xpath, not ->channel->item: collecting that node collapses every sibling onto
        // the same 'item' key and silently leaves you with one headline.
        return collect($feed->xpath('//item') ?: [])
            ->map(fn (SimpleXMLElement $item): array => [
                'id' => (string) $item->guid ?: (string) $item->title,
                'title' => trim((string) $item->title),
                'summary' => trim((string) $item->description),
                'url' => trim((string) $item->link),
                // UTC ISO, not a Carbon: these rows get cached, and an unserialised Carbon
                // comes back as an incomplete object. Normalised so a string sort is chronological.
                'published_at' => Carbon::parse((string) $item->pubDate)->utc()->toIso8601String(),
            ])
            ->reject(fn (array $headline): bool => $headline['title'] === '' || $headline['url'] === '')
            ->values()
            ->all();
    }

    /**
     * Fetch analyst consensus target price and rating from Yahoo's financialData module.
     * Returns ['target_price' => float|null, 'rating' => string|null].
     */
    public function analystData(string $symbol): array
    {
        $data = $this->quoteSummary($symbol, 'financialData');

        if ($data === null) {
            return ['target_price' => null, 'rating' => null];
        }

        $fin = $data['financialData'] ?? [];

        $targetRaw = $fin['targetMeanPrice']['raw'] ?? null;
        $ratingRaw = $fin['recommendationKey'] ?? null;

        return [
            'target_price' => $targetRaw !== null ? (float) $targetRaw : null,
            'rating' => $ratingRaw ?: null,
        ];
    }

    /**
     * Fetch the provider's own dividend yield as a ratio (0.048 = 4.8%) from the
     * summaryDetail module. This is the figure other platforms quote, so it is what the
     * yield columns should agree with. Equities report `dividendYield`, ETFs report
     * `yield`. Returns null when Yahoo has no figure (plenty of Amsterdam-listed ETFs)
     * or the request fails, so callers can fall back to their own computation. Zero counts
     * as "no figure" too: it only ever shows up as missing coverage, and a real 0% yield
     * belongs to an instrument that has no dividends to report on in the first place.
     */
    public function dividendYield(string $symbol): ?float
    {
        $data = $this->quoteSummary($symbol, 'summaryDetail');

        if ($data === null) {
            return null;
        }

        $detail = $data['summaryDetail'] ?? [];
        $raw = (float) ($detail['dividendYield']['raw'] ?? $detail['yield']['raw'] ?? 0);

        return $raw > 0 ? $raw : null;
    }

    /**
     * Fetch the instrument's sector from Yahoo's assetProfile module.
     * Returns null for funds/ETFs (no single sector) or on failure.
     */
    public function sector(string $symbol): ?string
    {
        $data = $this->quoteSummary($symbol, 'assetProfile');

        return $data['assetProfile']['sector'] ?? null;
    }

    /**
     * Search Yahoo Finance for a symbol matching the given ISIN.
     * Returns the best-matching yahoo_symbol string, or null if nothing found.
     * Pass the DEGIRO exchange code to improve match quality, and the currency the trade
     * settled in to rule out a sibling listing quoted in a currency you never paid in.
     */
    public function searchByIsin(string $isin, ?string $degiroExchange = null, ?string $tradeCurrency = null): ?string
    {
        $quotes = $this->searchQuotes($isin);

        if ($quotes->isEmpty()) {
            return null;
        }

        // If we know the DEGIRO exchange, prefer the symbol with the matching suffix.
        $preferredSuffix = self::EXCHANGE_SUFFIX[strtoupper($degiroExchange ?? '')] ?? null;

        $best = $preferredSuffix === null
            ? null
            : $quotes->first(fn (array $quote) => str_ends_with($quote['symbol'], $preferredSuffix));

        // Fall back to highest-scored result.
        $best ??= $quotes->sortByDesc('score')->first();

        if ($tradeCurrency === null) {
            return $best['symbol'];
        }

        return $this->listingInTradeCurrency($quotes, $best, $tradeCurrency) ?? $best['symbol'];
    }

    /**
     * A listing of the same instrument quoted in the currency the trade settled in.
     *
     * Yahoo happily answers an ISIN with a sibling line of the same fund quoted in another
     * currency — an ETF bought on Tradegate in EUR resolved to ASHR.L in USD. That hangs the
     * position on a needless FX leg and, worse for a distributor, reports none of its
     * dividends. A listing quoted in a currency you never paid in is the wrong listing.
     *
     * The search response carries no currency, so candidates are probed for one. A candidate
     * with no closes loses to the mismatched-but-populated pick: trading a currency mismatch
     * for an empty chart is not an improvement.
     *
     * Returns null when no better listing exists, leaving the caller on its own pick.
     *
     * @param  Collection<int, array<string, mixed>>  $quotes
     * @param  array<string, mixed>  $best
     */
    private function listingInTradeCurrency(Collection $quotes, array $best, string $tradeCurrency): ?string
    {
        if ($this->quoteCurrency($best['symbol']) === $tradeCurrency) {
            return $best['symbol'];
        }

        // An ISIN search often returns the primary line only (exactly one quote for the
        // Tradegate case), so widen to the fund's other listings by name — accepting a
        // candidate only when Yahoo reports the very same long name, so we never repoint
        // at another issuer's tracker of the same index.
        $siblings = isset($best['longname'])
            ? $this->searchQuotes($best['longname'])->where('longname', $best['longname'])
            : collect();

        return $quotes->concat($siblings)
            ->pluck('symbol')
            ->unique()
            ->reject(fn (string $symbol) => $symbol === $best['symbol'])
            ->first(fn (string $symbol) => $this->quoteCurrency($symbol) === $tradeCurrency);
    }

    /**
     * The currency a listing is quoted in, normalised (LSE reports pence), or null when it
     * has no closes to fetch — a delisted line, or one Yahoo knows by name only.
     */
    private function quoteCurrency(string $symbol): ?string
    {
        try {
            $rows = $this->history($symbol, now()->subMonth()->toDateString());
        } catch (\Throwable) {
            return null;
        }

        if (empty($rows)) {
            return null;
        }

        $last = end($rows);

        return CurrencyNormaliser::normaliseCurrency($last['currency']);
    }

    /**
     * Tradeable quotes matching a free-text query (an ISIN or an instrument name).
     * Empty on failure, so callers degrade to "no match".
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function searchQuotes(string $query): Collection
    {
        $response = Http::withHeaders(['User-Agent' => 'Mozilla/5.0'])
            ->timeout(15)
            ->get(self::SEARCH_URL, [
                'q' => $query,
                'quotesCount' => 10,
                'newsCount' => 0,
                'listsCount' => 0,
            ]);

        if (! $response->successful()) {
            return collect();
        }

        return collect($response->json('quotes') ?? [])
            ->filter(fn ($quote) => isset($quote['symbol'])
                && in_array($quote['quoteType'] ?? '', ['EQUITY', 'ETF', 'MUTUALFUND']))
            ->values();
    }

    /**
     * Yahoo gates quoteSummary behind a session cookie plus a matching crumb. Grab a
     * cookie from fc.yahoo.com, trade it for a crumb, and reuse both for this instance.
     * Returns null if either step fails, so callers keep degrading to "no data".
     */
    private function crumb(): ?string
    {
        if ($this->crumb !== null) {
            return $this->crumb;
        }

        $this->cookies = new CookieJar;

        Http::withHeaders(['User-Agent' => 'Mozilla/5.0'])
            ->withOptions(['cookies' => $this->cookies])
            ->timeout(15)
            ->get(self::COOKIE_URL);

        $response = Http::withHeaders(['User-Agent' => 'Mozilla/5.0'])
            ->withOptions(['cookies' => $this->cookies])
            ->timeout(15)
            ->get(self::CRUMB_URL);

        if (! $response->successful()) {
            return null;
        }

        $crumb = trim($response->body());

        return $this->crumb = ($crumb === '' ? null : $crumb);
    }

    /**
     * Call a Yahoo Finance v10 quoteSummary module and return the first result's data,
     * or null on failure (so callers can treat missing data as non-fatal).
     */
    private function quoteSummary(string $symbol, string $module): ?array
    {
        $crumb = $this->crumb();

        if ($crumb === null) {
            return null;
        }

        $response = Http::withHeaders(['User-Agent' => 'Mozilla/5.0'])
            ->withOptions(['cookies' => $this->cookies])
            ->timeout(15)
            ->get(self::QUOTE_SUMMARY_URL."/{$symbol}", ['modules' => $module, 'crumb' => $crumb]);

        if (! $response->successful()) {
            return null;
        }

        $body = $response->json();

        if (! empty($body['quoteSummary']['error'])) {
            return null;
        }

        return $body['quoteSummary']['result'][0] ?? null;
    }

    private function chart(string $symbol, string $fromDate): array
    {
        // Callers ask from "latest stored date + 1", which is tomorrow once everything is
        // synced through today. Yahoo answers 400 on that backwards window, and the caller
        // logs a warning for what is really "nothing to fetch" — so don't ask.
        if (Carbon::parse($fromDate)->startOfDay()->isAfter(now())) {
            return [];
        }

        $response = Http::withHeaders(['User-Agent' => 'Mozilla/5.0'])
            ->timeout(30)
            ->get(self::CHART_URL."/{$symbol}", [
                'interval' => '1d',
                'period1' => Carbon::parse($fromDate)->startOfDay()->unix(),
                'period2' => now()->unix(),
                'events' => 'div',
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException(
                "Yahoo Finance HTTP {$response->status()} for {$symbol}"
            );
        }

        $body = $response->json();

        if (! empty($body['chart']['error'])) {
            throw new \RuntimeException(
                "Yahoo Finance error for {$symbol}: ".$body['chart']['error']['description']
            );
        }

        $result = $body['chart']['result'][0] ?? null;

        if (! $result) {
            throw new \RuntimeException("No chart data returned for {$symbol}");
        }

        return $result;
    }

    /**
     * Extracts daily rows from a v8 chart result.
     * Uses adjclose where available (split-adjusted), falls back to close.
     * Skips null entries (holidays/data gaps).
     */
    private function parseOhlc(array $result): array
    {
        $timestamps = $result['timestamp'] ?? [];
        $closes = $result['indicators']['adjclose'][0]['adjclose']
                   ?? $result['indicators']['quote'][0]['close']
                   ?? [];
        $currency = $result['meta']['currency'] ?? '';

        $rows = [];

        foreach ($timestamps as $i => $ts) {
            $close = $closes[$i] ?? null;

            if ($close === null) {
                continue;
            }

            $rows[] = [
                'date' => Carbon::createFromTimestamp($ts)->toDateString(),
                'close' => $close,
                'currency' => $currency,
            ];
        }

        return $rows;
    }
}

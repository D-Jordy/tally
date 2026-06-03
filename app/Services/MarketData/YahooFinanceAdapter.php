<?php

namespace App\Services\MarketData;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

class YahooFinanceAdapter
{
    private const CHART_URL  = 'https://query2.finance.yahoo.com/v8/finance/chart';
    private const SEARCH_URL = 'https://query2.finance.yahoo.com/v1/finance/search';

    // DEGIRO exchange code → preferred Yahoo symbol suffix
    private const EXCHANGE_SUFFIX = [
        'EAM' => '.AS', 'AMS' => '.AS',
        'LSE' => '.L',
        'XET' => '.DE', 'GER' => '.DE',
        'MAD' => '.MC',
        'EPA' => '.PA',
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

        return array_map(fn($r) => ['date' => $r['date'], 'rate' => $r['close']], $rows);
    }

    /**
     * Fetch historical dividends from $fromDate onward.
     * Yahoo only returns dividends already gone ex — never future announced ones.
     * Returns [['ex_date' => 'YYYY-MM-DD', 'amount' => float, 'currency' => string], ...]
     * sorted by ex_date. Amounts are in the instrument's quote currency (e.g. GBp for LSE).
     */
    public function dividends(string $symbol, string $fromDate): array
    {
        $result   = $this->chart($symbol, $fromDate);
        $events   = $result['events']['dividends'] ?? [];
        $currency = $result['meta']['currency'] ?? '';

        $rows = [];

        foreach ($events as $event) {
            if (!isset($event['date'], $event['amount'])) {
                continue;
            }

            $rows[] = [
                'ex_date'  => Carbon::createFromTimestamp($event['date'])->toDateString(),
                'amount'   => (float) $event['amount'],
                'currency' => $currency,
            ];
        }

        usort($rows, fn($a, $b) => $a['ex_date'] <=> $b['ex_date']);

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
            'symbol'   => $symbol,
            'price'    => $last['close'],
            'currency' => $last['currency'],
            'date'     => $last['date'],
        ];
    }

    /**
     * Search Yahoo Finance for a symbol matching the given ISIN.
     * Returns the best-matching yahoo_symbol string, or null if nothing found.
     * Pass the DEGIRO exchange code to improve match quality.
     */
    public function searchByIsin(string $isin, ?string $degiroExchange = null): ?string
    {
        $response = Http::withHeaders(['User-Agent' => 'Mozilla/5.0'])
            ->timeout(15)
            ->get(self::SEARCH_URL, [
                'q'           => $isin,
                'quotesCount' => 10,
                'newsCount'   => 0,
                'listsCount'  => 0,
            ]);

        if (!$response->successful()) {
            return null;
        }

        $quotes = collect($response->json('quotes') ?? [])
            ->filter(fn($q) => in_array($q['quoteType'] ?? '', ['EQUITY', 'ETF', 'MUTUALFUND']));

        if ($quotes->isEmpty()) {
            return null;
        }

        // If we know the DEGIRO exchange, prefer the symbol with the matching suffix.
        $preferredSuffix = self::EXCHANGE_SUFFIX[strtoupper($degiroExchange ?? '')] ?? null;

        if ($preferredSuffix !== null) {
            $match = $quotes->first(
                fn($q) => str_ends_with($q['symbol'], $preferredSuffix)
            );

            if ($match) {
                return $match['symbol'];
            }
        }

        // Fall back to highest-scored result.
        return $quotes->sortByDesc('score')->first()['symbol'] ?? null;
    }

    private function chart(string $symbol, string $fromDate): array
    {
        $response = Http::withHeaders(['User-Agent' => 'Mozilla/5.0'])
            ->timeout(30)
            ->get(self::CHART_URL . "/{$symbol}", [
                'interval' => '1d',
                'period1'  => Carbon::parse($fromDate)->startOfDay()->unix(),
                'period2'  => now()->unix(),
                'events'   => 'div',
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException(
                "Yahoo Finance HTTP {$response->status()} for {$symbol}"
            );
        }

        $body = $response->json();

        if (!empty($body['chart']['error'])) {
            throw new \RuntimeException(
                "Yahoo Finance error for {$symbol}: " . $body['chart']['error']['description']
            );
        }

        $result = $body['chart']['result'][0] ?? null;

        if (!$result) {
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
        $closes     = $result['indicators']['adjclose'][0]['adjclose']
                   ?? $result['indicators']['quote'][0]['close']
                   ?? [];
        $currency   = $result['meta']['currency'] ?? '';

        $rows = [];

        foreach ($timestamps as $i => $ts) {
            $close = $closes[$i] ?? null;

            if ($close === null) {
                continue;
            }

            $rows[] = [
                'date'     => Carbon::createFromTimestamp($ts)->toDateString(),
                'close'    => $close,
                'currency' => $currency,
            ];
        }

        return $rows;
    }
}

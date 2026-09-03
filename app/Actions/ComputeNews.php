<?php

namespace App\Actions;

use App\Models\User;
use App\Services\MarketData\YahooFinanceAdapter;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class ComputeNews
{
    private const TTL = 1800;

    private const PER_GROUP = 6;

    private const MARKET_SYMBOLS = [
        '^GSPC' => 'S&P 500',
        '^STOXX50E' => 'Euro Stoxx 50',
        '^AEX' => 'AEX',
    ];

    /**
     * Yahoo sector name → the SPDR sector ETF whose feed reports on it. Yahoo has no
     * feed for a sector as such, and the ETF is the closest thing to one that exists.
     */
    private const SECTOR_ETF = [
        'Technology' => 'XLK',
        'Financial Services' => 'XLF',
        'Healthcare' => 'XLV',
        'Industrials' => 'XLI',
        'Consumer Cyclical' => 'XLY',
        'Consumer Defensive' => 'XLP',
        'Energy' => 'XLE',
        'Basic Materials' => 'XLB',
        'Utilities' => 'XLU',
        'Real Estate' => 'XLRE',
        'Communication Services' => 'XLC',
    ];

    public function __construct(private readonly YahooFinanceAdapter $yahoo) {}

    /**
     * Headlines for what the user actually holds, in three buckets. Holdings are
     * ordered by position size, so the biggest exposure reads first.
     *
     * @return array{holdings: array<int, array<string, mixed>>, sectors: array<int, array<string, mixed>>, market: array<int, array<string, mixed>>}
     */
    public function forUser(User $user): array
    {
        $positions = collect(app(ComputePortfolio::class)->forUser($user)['positions'])
            ->filter(fn (array $position): bool => (float) ($position['current_value_eur'] ?? 0) > 0)
            ->sortByDesc('current_value_eur');

        $holdingSymbols = $positions->pluck('yahoo_symbol')->filter()->unique();
        $sectorEtfs = $positions
            ->pluck('sector')
            ->filter()
            ->unique()
            ->mapWithKeys(fn (string $sector): array => [$sector => self::SECTOR_ETF[$sector] ?? null])
            ->filter();

        $feeds = $this->fetch([
            ...$holdingSymbols->all(),
            ...$sectorEtfs->values()->all(),
            ...array_keys(self::MARKET_SYMBOLS),
        ]);

        // Seen ids carry across the three passes, so a story that reaches both a holding
        // and its sector shows up once, under the most specific bucket that has it.
        $seen = collect();

        $holdings = $positions
            ->filter(fn (array $position): bool => (bool) $position['yahoo_symbol'])
            ->map(fn (array $position): array => $this->group(
                $position['name'],
                $position['yahoo_symbol'],
                $feeds[$position['yahoo_symbol']] ?? [],
                $seen,
            ));

        $sectors = $sectorEtfs->map(fn (string $etf, string $sector): array => $this->group(
            $sector,
            $etf,
            $feeds[$etf] ?? [],
            $seen,
        ));

        $market = collect(self::MARKET_SYMBOLS)->map(fn (string $label, string $symbol): array => $this->group(
            $label,
            $symbol,
            $feeds[$symbol] ?? [],
            $seen,
        ));

        return [
            'holdings' => $this->nonEmpty($holdings),
            'sectors' => $this->nonEmpty($sectors),
            'market' => $this->nonEmpty($market),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $headlines
     * @param  Collection<int, string>  $seen
     * @return array<string, mixed>
     */
    private function group(string $label, string $symbol, array $headlines, Collection $seen): array
    {
        $fresh = collect($headlines)
            ->reject(fn (array $headline): bool => $seen->contains($headline['id']))
            ->sortByDesc('published_at')
            ->take(self::PER_GROUP)
            ->values();

        $seen->push(...$fresh->pluck('id'));

        $shown = $fresh
            ->map(fn (array $headline): array => [...$headline, 'published_at' => Carbon::parse($headline['published_at'])])
            ->all();

        return ['label' => $label, 'symbol' => $symbol, 'headlines' => $shown];
    }

    /**
     * @param  Collection<mixed, array<string, mixed>>  $groups
     * @return array<int, array<string, mixed>>
     */
    private function nonEmpty(Collection $groups): array
    {
        return $groups->reject(fn (array $group): bool => $group['headlines'] === [])->values()->all();
    }

    /**
     * One cache entry per symbol rather than per user, so the sector and index feeds
     * are fetched once for everyone instead of once per portfolio that touches them.
     *
     * @param  array<int, string>  $symbols
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function fetch(array $symbols): array
    {
        $feeds = collect($symbols)
            ->unique()
            ->mapWithKeys(fn (string $symbol): array => [$symbol => Cache::get($this->cacheKey($symbol))]);

        $missing = $feeds->filter(fn (?array $headlines): bool => $headlines === null)->keys()->all();

        foreach ($this->yahoo->headlines($missing) as $symbol => $headlines) {
            Cache::put($this->cacheKey($symbol), $headlines, self::TTL);
            $feeds[$symbol] = $headlines;
        }

        return $feeds->map(fn (?array $headlines): array => $headlines ?? [])->all();
    }

    private function cacheKey(string $symbol): string
    {
        return 'news:'.$symbol;
    }
}

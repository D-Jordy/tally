<?php

namespace App\Actions;

use App\Models\CashMovement;
use App\Models\Instrument;
use App\Models\User;
use App\Support\XirrCalculator;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ComputeProjections
{
    private const DEFAULT_GROWTH_RATE = 0.07;   // fallback, and what a short track record shrinks onto

    private const BLEND_ANALYST_WEIGHT = 0.25;  // analyst share in month 1

    private const ANALYST_HALF_LIFE_MONTHS = 12;

    private const PRIOR_RATE_MAX = 0.20;        // your own XIRR, however good, is not a forecast above this

    private const PRIOR_SHRINK_YEARS = 3.0;

    private const GROWTH_RATE_MIN = -0.50;

    private const GROWTH_RATE_MAX = 0.50;

    private const DEPOSIT_HISTORY_MONTHS = 24;

    private const CONTRIBUTION_WINDOW_MONTHS = 12;

    public function __construct(
        private ComputePortfolio $portfolio,
        private ComputePortfolioHistory $history,
        private ComputeIncomingDividends $dividends,
    ) {}

    /**
     * `$monthlyContribution` and `$reinvestDividends` override the stored settings, so a
     * caller holding a not-yet-persisted value (the Insights form) never projects on
     * stale input.
     */
    public function forUser(
        User $user,
        int $horizonYears = 5,
        ?float $monthlyContribution = null,
        ?bool $reinvestDividends = null,
    ): array {
        $horizonYears = max(1, min(10, $horizonYears));

        $portfolioData = $this->portfolio->forUser($user);
        $positions = $portfolioData['positions'];
        $totalValueEur = (float) ($portfolioData['summary']['total_value_eur'] ?? 0);

        $history = $this->history->forUser($user);
        $cashFlows = $this->depositCashFlows($history);

        $priorRate = $this->computePriorRate($cashFlows, $totalValueEur);
        $analystRate = $this->computeAnalystRate($positions, $priorRate);
        $monthlyRates = $this->monthlyRates($priorRate, $analystRate, $horizonYears * 12);

        $depositHistory = $this->monthlyDeposits($user);
        $estimatedContribution = $this->estimateFrom($depositHistory);

        $monthlyContribution = max(0.0, $monthlyContribution ?? $this->storedContribution($user) ?? $estimatedContribution);
        $reinvestDividends = $reinvestDividends ?? (bool) ($user->settings['reinvest_dividends'] ?? false);

        $dividendData = $this->dividends->forUser($user);
        $startingDividendEur = (float) ($dividendData['summary']['next_12m_total_eur'] ?? 0);

        $dividendYield = $totalValueEur > 0 ? $startingDividendEur / $totalValueEur : 0.0;
        $growthRate = $this->effectiveRate($monthlyRates, $dividendYield, $reinvestDividends);

        ['value' => $valueSeries, 'dividend' => $dividendSeries] = $this->buildSeries(
            $totalValueEur,
            $this->investedEur($history),
            $dividendYield,
            $monthlyRates,
            $monthlyContribution,
            $reinvestDividends,
        );

        return [
            'horizon_years' => $horizonYears,
            'growth_rate' => round($growthRate, 4),
            'prior_rate' => round($priorRate, 4),
            'analyst_rate' => round($analystRate, 4),
            'monthly_contribution_eur' => $monthlyContribution,
            'estimated_monthly_contribution_eur' => $estimatedContribution,
            'reinvest_dividends' => $reinvestDividends,
            'starting_value_eur' => round($totalValueEur, 2),
            'invested_eur' => round($this->investedEur($history), 2),
            'value_series' => $valueSeries,
            'dividend_series' => $dividendSeries,
            'deposit_history' => $depositHistory
                ->map(fn (float $amount, string $month): array => ['month' => $month, 'deposited_eur' => $amount])
                ->values()
                ->all(),
        ];
    }

    /** The monthly contribution to project on: your own setting, or what you actually deposit. */
    public function contributionFor(User $user): float
    {
        return max(0.0, $this->storedContribution($user) ?? $this->estimateFrom($this->monthlyDeposits($user)));
    }

    // -------------------------------------------------------------------------
    // Contributions
    // -------------------------------------------------------------------------

    /**
     * EUR deposits per calendar month, zero-filled so a month without a deposit still
     * plots (and still drags the average down).
     *
     * @return Collection<string, float> keyed 'Y-m', oldest first
     */
    private function monthlyDeposits(User $user, int $months = self::DEPOSIT_HISTORY_MONTHS): Collection
    {
        $accountIds = $user->accounts()->pluck('id');

        // Anchored to the 1st: stepping months off the 31st overflows (31 Mar -1 month is
        // 3 Mar), which collapses two buckets into one and drops a month entirely.
        $thisMonth = now()->startOfMonth();

        $deposited = CashMovement::whereIn('account_id', $accountIds)
            ->where('type', 'deposit')
            ->where('currency', 'EUR')
            ->where('amount', '>', 0)
            ->where('occurred_at', '>=', $thisMonth->copy()->subMonths($months - 1))
            ->get(['occurred_at', 'amount'])
            ->groupBy(fn (CashMovement $movement): string => $movement->occurred_at->format('Y-m'))
            ->map(fn (Collection $group): float => round((float) $group->sum('amount'), 2));

        return collect(range($months - 1, 0))
            ->mapWithKeys(fn (int $offset): array => [$thisMonth->copy()->subMonths($offset)->format('Y-m') => 0.0])
            ->merge($deposited);
    }

    /**
     * Averaging from the first funded month keeps a three-month-old account from being
     * divided by twelve.
     *
     * @param  Collection<string, float>  $deposits
     */
    private function estimateFrom(Collection $deposits): float
    {
        $funded = $deposits
            ->slice(-self::CONTRIBUTION_WINDOW_MONTHS)
            ->skipUntil(fn (float $amount): bool => $amount > 0);

        return $funded->isEmpty() ? 0.0 : round((float) $funded->avg(), 2);
    }

    private function storedContribution(User $user): ?float
    {
        $settings = $user->settings ?? [];

        if (isset($settings['monthly_contribution_eur'])) {
            return (float) $settings['monthly_contribution_eur'];
        }

        // Pre-monthly setting: a stored yearly amount still means the same money.
        return isset($settings['annual_contribution_eur'])
            ? (float) $settings['annual_contribution_eur'] / 12
            : null;
    }

    // -------------------------------------------------------------------------
    // Growth-rate components
    // -------------------------------------------------------------------------

    /**
     * Deposits derived from daily net_gain: cumDeposits = total_value_eur - net_gain_eur.
     *
     * @return array<int, array{amount: float, date: string}>
     */
    private function depositCashFlows(array $history): array
    {
        $cashFlows = [];
        $prevCumDeposits = 0.0;

        foreach ($history as $day) {
            $cumDeposits = (float) $day['total_value_eur'] - (float) $day['net_gain_eur'];
            $deposit = $cumDeposits - $prevCumDeposits;

            if ($deposit > 0.01) {
                $cashFlows[] = ['amount' => -$deposit, 'date' => $day['date']];
            }

            $prevCumDeposits = $cumDeposits;
        }

        return $cashFlows;
    }

    private function investedEur(array $history): float
    {
        if ($history === []) {
            return 0.0;
        }

        $last = end($history);

        return (float) $last['total_value_eur'] - (float) $last['net_gain_eur'];
    }

    /**
     * @param  array<int, array{amount: float, date: string}>  $cashFlows
     */
    private function computePriorRate(array $cashFlows, float $totalValueEur): float
    {
        if ($cashFlows === [] || $totalValueEur <= 0) {
            return self::DEFAULT_GROWTH_RATE;
        }

        $xirr = XirrCalculator::calculate([
            ...$cashFlows,
            ['amount' => $totalValueEur, 'date' => now()->toDateString()],
        ]);

        if ($xirr === null) {
            return self::DEFAULT_GROWTH_RATE;
        }

        $xirr = max(-self::PRIOR_RATE_MAX, min(self::PRIOR_RATE_MAX, $xirr));

        // A few months of history is a sample, not a track record: annualising it and
        // compounding that for a decade is how a good year becomes a 40% forecast. Shrink
        // onto the long-run default until PRIOR_SHRINK_YEARS of history back it up.
        $years = Carbon::parse($cashFlows[0]['date'])->diffInDays(now()) / 365.0;
        $confidence = min(1.0, $years / self::PRIOR_SHRINK_YEARS);

        return $confidence * $xirr + (1 - $confidence) * self::DEFAULT_GROWTH_RATE;
    }

    private function computeAnalystRate(array $positions, float $priorRate): float
    {
        if (empty($positions)) {
            return $priorRate;
        }

        $instrumentIds = array_column($positions, 'instrument_id');
        $analysts = Instrument::whereIn('id', $instrumentIds)
            ->whereNotNull('analyst_target_price')
            ->get(['id', 'analyst_target_price'])
            ->keyBy('id');

        $totalValue = array_sum(array_column($positions, 'current_value_eur'));

        if ($totalValue <= 0) {
            return $priorRate;
        }

        $weightedRate = 0.0;

        foreach ($positions as $position) {
            $weight = (float) ($position['current_value_eur'] ?? 0) / $totalValue;

            $analyst = $analysts->get($position['instrument_id']);
            $latestPrice = (float) ($position['latest_price'] ?? 0);

            if ($analyst && $latestPrice > 0.0001) {
                $target = (float) $analyst->analyst_target_price;
                $implied = ($target - $latestPrice) / $latestPrice;
                $weightedRate += $weight * max(self::GROWTH_RATE_MIN, min(self::GROWTH_RATE_MAX, $implied));
            } else {
                // No analyst data — this position contributes its weight at the prior rate.
                $weightedRate += $weight * $priorRate;
            }
        }

        return max(self::GROWTH_RATE_MIN, min(self::GROWTH_RATE_MAX, $weightedRate));
    }

    /**
     * Analyst figures are 12-month price targets, so carrying one as a perpetual growth
     * rate badly overstates the long run (a +50% target became +50% a year, for a decade).
     * The analyst share starts at BLEND_ANALYST_WEIGHT and halves every
     * ANALYST_HALF_LIFE_MONTHS, so the projection decays smoothly onto the portfolio's own
     * XIRR instead of stepping down once a year.
     *
     * @return array<int, float> monthly growth rate, keyed 1..$months
     */
    private function monthlyRates(float $priorRate, float $analystRate, int $months): array
    {
        $rates = [];

        for ($month = 1; $month <= $months; $month++) {
            $analystWeight = self::BLEND_ANALYST_WEIGHT * 0.5 ** (($month - 1) / self::ANALYST_HALF_LIFE_MONTHS);
            $annual = $analystWeight * $analystRate + (1 - $analystWeight) * $priorRate;
            $annual = max(self::GROWTH_RATE_MIN, min(self::GROWTH_RATE_MAX, $annual));

            $rates[$month] = (1 + $annual) ** (1 / 12) - 1;
        }

        return $rates;
    }

    /**
     * The single annual rate that, compounded over the horizon, reproduces the projection —
     * so the headline percentage always agrees with the figure shown next to it.
     *
     * The monthly rates are price growth only. When income is reinvested, capital compounds
     * at (1 + rate)(1 + yield/12) each month, so the headline has to carry the yield too.
     *
     * @param  array<int, float>  $monthlyRates
     */
    private function effectiveRate(array $monthlyRates, float $dividendYield, bool $reinvestDividends): float
    {
        if ($monthlyRates === []) {
            return 0.0;
        }

        $incomeFactor = $reinvestDividends ? 1 + $dividendYield / 12 : 1.0;

        $compounded = array_reduce(
            $monthlyRates,
            fn (float $carry, float $rate): float => $carry * (1 + $rate) * $incomeFactor,
            1.0,
        );

        return $compounded ** (12 / count($monthlyRates)) - 1;
    }

    // -------------------------------------------------------------------------
    // Projection series
    // -------------------------------------------------------------------------

    /**
     * Value, contributions and income are projected together because reinvested dividends
     * feed back into next month's capital, and `contributed_eur` is what makes the gap to
     * the value line readable as growth rather than deposits.
     *
     * `projected_dividends_eur` is the annualised run-rate at that month, not the month's
     * own payout, so the headline stays "income per year at this point in the projection".
     *
     * Adding dividends on top of the growth rate is not double counting: `total_value_eur`
     * is the market value of the holdings only (dividend cash is tracked separately), and
     * the analyst rate is a pure price target — so neither rate carries dividend return.
     *
     * @param  array<int, float>  $rates
     * @return array{value: array<int, array<string, mixed>>, dividend: array<int, array<string, mixed>>}
     */
    private function buildSeries(
        float $startValue,
        float $startInvested,
        float $dividendYield,
        array $rates,
        float $contribution,
        bool $reinvestDividends,
    ): array {
        $valueSeries = [$this->valuePoint(0, $startValue, $startInvested)];
        $dividendSeries = [$this->dividendPoint(0, $dividendYield * $startValue)];

        $value = $startValue;
        $invested = $startInvested;

        foreach ($rates as $month => $rate) {
            $value = $value * (1 + $rate) + $contribution;
            $invested += $contribution;

            $dividend = $dividendYield * $value;

            if ($reinvestDividends) {
                $value += $dividend / 12;
            }

            $valueSeries[] = $this->valuePoint($month, $value, $invested);
            $dividendSeries[] = $this->dividendPoint($month, $dividend);
        }

        return ['value' => $valueSeries, 'dividend' => $dividendSeries];
    }

    /** @return array<string, mixed> */
    private function valuePoint(int $month, float $value, float $invested): array
    {
        return [
            'month' => $month,
            'date' => $this->monthDate($month),
            'projected_value_eur' => round(max(0, $value), 2),
            'contributed_eur' => round(max(0, $invested), 2),
        ];
    }

    /** Anchored to the 1st, or adding months off a 31st skips February entirely. */
    private function monthDate(int $month): string
    {
        return now()->startOfMonth()->addMonths($month)->toDateString();
    }

    /** @return array<string, mixed> */
    private function dividendPoint(int $month, float $dividend): array
    {
        return [
            'month' => $month,
            'date' => $this->monthDate($month),
            'projected_dividends_eur' => round(max(0, $dividend), 2),
        ];
    }
}

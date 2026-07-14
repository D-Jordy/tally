<?php

namespace App\Actions;

use App\Models\Instrument;
use App\Models\User;
use App\Support\XirrCalculator;

class ComputeProjections
{
    private const DEFAULT_GROWTH_RATE = 0.07;   // fallback if XIRR cannot converge
    private const BLEND_ANALYST_WEIGHT = 0.5;   // analyst share in year 1
    private const ANALYST_DECAY        = 0.5;   // that share halves each year after
    private const GROWTH_RATE_MIN     = -0.50;
    private const GROWTH_RATE_MAX     = 0.50;

    public function __construct(
        private ComputePortfolio $portfolio,
        private ComputePortfolioHistory $history,
        private ComputeIncomingDividends $dividends,
    ) {}

    /**
     * Build the projection payload for a user.
     *
     * @return array{
     *   horizon_years: int,
     *   growth_rate: float,
     *   prior_rate: float,
     *   analyst_rate: float,
     *   annual_contribution_eur: float,
     *   starting_value_eur: float,
     *   value_series: array<int, array{year: int, projected_value_eur: float}>,
     *   dividend_series: array<int, array{year: int, projected_dividends_eur: float}>,
     * }
     */
    /**
     * `$annualContribution` and `$reinvestDividends` override the stored settings, so a
     * caller holding a not-yet-persisted value (the Insights form) never projects on
     * stale input.
     */
    public function forUser(
        User $user,
        int $horizonYears = 5,
        ?float $annualContribution = null,
        ?bool $reinvestDividends = null,
    ): array {
        $horizonYears = max(1, min(10, $horizonYears));

        $portfolioData   = $this->portfolio->forUser($user);
        $positions       = $portfolioData['positions'];
        $totalValueEur   = (float) ($portfolioData['summary']['total_value_eur'] ?? 0);

        $priorRate   = $this->computePriorRate($user, $totalValueEur);
        $analystRate = $this->computeAnalystRate($positions, $priorRate);
        $yearlyRates = $this->yearlyRates($priorRate, $analystRate, $horizonYears);

        $annualContribution = max(0.0, $annualContribution ?? (float) ($user->settings['annual_contribution_eur'] ?? 0));
        $reinvestDividends  = $reinvestDividends ?? (bool) ($user->settings['reinvest_dividends'] ?? false);

        $dividendData       = $this->dividends->forUser($user);
        $startingDividendEur = (float) ($dividendData['summary']['next_12m_total_eur'] ?? 0);

        $dividendYield = $totalValueEur > 0 ? $startingDividendEur / $totalValueEur : 0.0;
        $growthRate    = $this->effectiveRate($yearlyRates, $dividendYield, $reinvestDividends);

        ['value' => $valueSeries, 'dividend' => $dividendSeries] = $this->buildSeries(
            $totalValueEur,
            $dividendYield,
            $yearlyRates,
            $annualContribution,
            $reinvestDividends,
        );

        return [
            'horizon_years'          => $horizonYears,
            'growth_rate'            => round($growthRate, 4),
            'prior_rate'             => round($priorRate, 4),
            'analyst_rate'           => round($analystRate, 4),
            'annual_contribution_eur' => $annualContribution,
            'reinvest_dividends'     => $reinvestDividends,
            'starting_value_eur'     => round($totalValueEur, 2),
            'value_series'           => $valueSeries,
            'dividend_series'        => $dividendSeries,
        ];
    }

    // -------------------------------------------------------------------------
    // Growth-rate components
    // -------------------------------------------------------------------------

    private function computePriorRate(User $user, float $totalValueEur): float
    {
        $history = $this->history->forUser($user);

        if (empty($history)) {
            return self::DEFAULT_GROWTH_RATE;
        }

        // Derive deposits from daily net_gain: cumDeposits = total_value_eur - net_gain_eur
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

        if (empty($cashFlows)) {
            return self::DEFAULT_GROWTH_RATE;
        }

        // Final portfolio value as inflow.
        if ($totalValueEur > 0) {
            $cashFlows[] = ['amount' => $totalValueEur, 'date' => now()->toDateString()];
        }

        $xirr = XirrCalculator::calculate($cashFlows);

        if ($xirr === null) {
            return self::DEFAULT_GROWTH_RATE;
        }

        return max(self::GROWTH_RATE_MIN, min(self::GROWTH_RATE_MAX, $xirr));
    }

    private function computeAnalystRate(array $positions, float $priorRate): float
    {
        if (empty($positions)) {
            return $priorRate;
        }

        // Load analyst target prices for held instruments.
        $instrumentIds = array_column($positions, 'instrument_id');
        $analysts      = Instrument::whereIn('id', $instrumentIds)
            ->whereNotNull('analyst_target_price')
            ->get(['id', 'analyst_target_price'])
            ->keyBy('id');

        $totalValue = array_sum(array_column($positions, 'current_value_eur'));

        if ($totalValue <= 0) {
            return $priorRate;
        }

        $weightedRate = 0.0;

        foreach ($positions as $pos) {
            $posValue = (float) ($pos['current_value_eur'] ?? 0);
            $weight   = $posValue / $totalValue;

            $analyst  = $analysts->get($pos['instrument_id']);
            $latestPrice = (float) ($pos['latest_price'] ?? 0);

            if ($analyst && $latestPrice > 0.0001) {
                $target  = (float) $analyst->analyst_target_price;
                $implied = ($target - $latestPrice) / $latestPrice;
                $implied = max(self::GROWTH_RATE_MIN, min(self::GROWTH_RATE_MAX, $implied));
                $weightedRate += $weight * $implied;
            } else {
                // No analyst data — this position contributes its weight at the prior rate.
                $weightedRate += $weight * $priorRate;
            }
        }

        return max(self::GROWTH_RATE_MIN, min(self::GROWTH_RATE_MAX, $weightedRate));
    }

    /**
     * Analyst figures are 12-month price targets, so carrying one as a perpetual annual
     * growth rate badly overstates the long run (a +50% target became +50% a year, for a
     * decade). Weight the analyst rate at BLEND_ANALYST_WEIGHT in year 1 and halve that
     * share every year after, so the projection decays onto the portfolio's own XIRR.
     *
     * @return array<int, float> growth rate per year, keyed 1..$horizonYears
     */
    private function yearlyRates(float $priorRate, float $analystRate, int $horizonYears): array
    {
        $rates = [];

        for ($year = 1; $year <= $horizonYears; $year++) {
            $analystWeight = self::BLEND_ANALYST_WEIGHT * (self::ANALYST_DECAY ** ($year - 1));
            $rate          = $analystWeight * $analystRate + (1 - $analystWeight) * $priorRate;

            $rates[$year] = max(self::GROWTH_RATE_MIN, min(self::GROWTH_RATE_MAX, $rate));
        }

        return $rates;
    }

    /**
     * The single rate that, compounded over the horizon, reproduces the projection — so the
     * headline percentage always agrees with the figure shown next to it.
     *
     * The yearly rates are price growth only. When income is reinvested, capital compounds
     * at (1 + rate)(1 + yield) each year, so the headline has to carry the yield too —
     * otherwise it reports 14.4% while the money actually grows at 16.8%.
     *
     * @param  array<int, float>  $rates
     */
    private function effectiveRate(array $rates, float $dividendYield, bool $reinvestDividends): float
    {
        if ($rates === []) {
            return 0.0;
        }

        $incomeFactor = $reinvestDividends ? 1 + $dividendYield : 1.0;

        $compounded = array_reduce(
            $rates,
            fn (float $carry, float $rate): float => $carry * (1 + $rate) * $incomeFactor,
            1.0,
        );

        return $compounded ** (1 / count($rates)) - 1;
    }

    // -------------------------------------------------------------------------
    // Projection series
    // -------------------------------------------------------------------------

    /**
     * Value and income are projected together because reinvested dividends feed back into
     * next year's capital.
     *
     * Income is a constant yield on the projected value: it has to scale with the capital
     * actually invested, otherwise contributions grow the portfolio but never the income it
     * throws off. With no contributions and no reinvestment this collapses to the original
     * startDividend * Π(1 + rate).
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
        float $dividendYield,
        array $rates,
        float $contribution,
        bool $reinvestDividends,
    ): array {
        $valueSeries    = [['year' => 0, 'projected_value_eur' => round($startValue, 2)]];
        $dividendSeries = [['year' => 0, 'projected_dividends_eur' => round($dividendYield * $startValue, 2)]];

        $value = $startValue;

        foreach ($rates as $year => $rate) {
            // Contributions trickle in across the year rather than landing on 31 Dec, so
            // credit them roughly half a year of growth instead of none at all.
            $value = $value * (1 + $rate) + $contribution * (1 + $rate / 2);

            $dividend = $dividendYield * $value;

            if ($reinvestDividends) {
                $value += $dividend;
            }

            $valueSeries[]    = ['year' => $year, 'projected_value_eur' => round(max(0, $value), 2)];
            $dividendSeries[] = ['year' => $year, 'projected_dividends_eur' => round(max(0, $dividend), 2)];
        }

        return ['value' => $valueSeries, 'dividend' => $dividendSeries];
    }
}

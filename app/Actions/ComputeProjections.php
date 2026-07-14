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
     * `$annualContribution` overrides the stored setting, so a caller holding a
     * not-yet-persisted value (the Insights form) never projects on stale input.
     */
    public function forUser(User $user, int $horizonYears = 5, ?float $annualContribution = null): array
    {
        $horizonYears = max(1, min(10, $horizonYears));

        $portfolioData   = $this->portfolio->forUser($user);
        $positions       = $portfolioData['positions'];
        $totalValueEur   = (float) ($portfolioData['summary']['total_value_eur'] ?? 0);

        $priorRate   = $this->computePriorRate($user, $totalValueEur);
        $analystRate = $this->computeAnalystRate($positions, $priorRate);
        $yearlyRates = $this->yearlyRates($priorRate, $analystRate, $horizonYears);
        $growthRate  = $this->effectiveRate($yearlyRates);

        $annualContribution = max(0.0, $annualContribution ?? (float) ($user->settings['annual_contribution_eur'] ?? 0));

        $dividendData       = $this->dividends->forUser($user);
        $startingDividendEur = (float) ($dividendData['summary']['next_12m_total_eur'] ?? 0);

        $valueSeries    = $this->buildValueSeries($totalValueEur, $yearlyRates, $annualContribution);
        $dividendSeries = $this->buildDividendSeries($startingDividendEur, $yearlyRates);

        return [
            'horizon_years'          => $horizonYears,
            'growth_rate'            => round($growthRate, 4),
            'prior_rate'             => round($priorRate, 4),
            'analyst_rate'           => round($analystRate, 4),
            'annual_contribution_eur' => $annualContribution,
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
     * @param  array<int, float>  $rates
     */
    private function effectiveRate(array $rates): float
    {
        if ($rates === []) {
            return 0.0;
        }

        $compounded = array_reduce($rates, fn (float $carry, float $rate): float => $carry * (1 + $rate), 1.0);

        return $compounded ** (1 / count($rates)) - 1;
    }

    // -------------------------------------------------------------------------
    // Projection series
    // -------------------------------------------------------------------------

    /** @param array<int, float> $rates */
    private function buildValueSeries(float $startValue, array $rates, float $contribution): array
    {
        $series = [['year' => 0, 'projected_value_eur' => round($startValue, 2)]];
        $value  = $startValue;

        foreach ($rates as $year => $rate) {
            $value    = $value * (1 + $rate) + $contribution;
            $series[] = ['year' => $year, 'projected_value_eur' => round(max(0, $value), 2)];
        }

        return $series;
    }

    /** @param array<int, float> $rates */
    private function buildDividendSeries(float $startDividend, array $rates): array
    {
        $series   = [['year' => 0, 'projected_dividends_eur' => round($startDividend, 2)]];
        $dividend = $startDividend;

        foreach ($rates as $year => $rate) {
            $dividend  = $dividend * (1 + $rate);
            $series[]  = ['year' => $year, 'projected_dividends_eur' => round(max(0, $dividend), 2)];
        }

        return $series;
    }
}

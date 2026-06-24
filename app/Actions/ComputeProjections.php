<?php

namespace App\Actions;

use App\Models\Instrument;
use App\Models\User;
use App\Support\XirrCalculator;

class ComputeProjections
{
    private const DEFAULT_GROWTH_RATE = 0.07;   // fallback if XIRR cannot converge
    private const BLEND_PRIOR_WEIGHT  = 0.5;
    private const BLEND_ANALYST_WEIGHT = 0.5;
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
    public function forUser(User $user, int $horizonYears = 5): array
    {
        $horizonYears = max(1, min(10, $horizonYears));

        $portfolioData   = $this->portfolio->forUser($user);
        $positions       = $portfolioData['positions'];
        $totalValueEur   = (float) ($portfolioData['summary']['total_value_eur'] ?? 0);

        $priorRate   = $this->computePriorRate($user, $totalValueEur);
        $analystRate = $this->computeAnalystRate($positions, $priorRate);
        $growthRate  = $this->blendRates($priorRate, $analystRate);

        $annualContribution = (float) ($user->settings['annual_contribution_eur'] ?? 0);

        $dividendData       = $this->dividends->forUser($user);
        $startingDividendEur = (float) ($dividendData['summary']['next_12m_total_eur'] ?? 0);

        $valueSeries    = $this->buildValueSeries($totalValueEur, $growthRate, $annualContribution, $horizonYears);
        $dividendSeries = $this->buildDividendSeries($startingDividendEur, $growthRate, $horizonYears);

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

    private function blendRates(float $priorRate, float $analystRate): float
    {
        $blended = self::BLEND_PRIOR_WEIGHT * $priorRate + self::BLEND_ANALYST_WEIGHT * $analystRate;

        return max(self::GROWTH_RATE_MIN, min(self::GROWTH_RATE_MAX, $blended));
    }

    // -------------------------------------------------------------------------
    // Projection series
    // -------------------------------------------------------------------------

    private function buildValueSeries(float $startValue, float $growthRate, float $contribution, int $horizonYears): array
    {
        $series = [['year' => 0, 'projected_value_eur' => round($startValue, 2)]];
        $value  = $startValue;

        for ($y = 1; $y <= $horizonYears; $y++) {
            $value    = $value * (1 + $growthRate) + $contribution;
            $series[] = ['year' => $y, 'projected_value_eur' => round(max(0, $value), 2)];
        }

        return $series;
    }

    private function buildDividendSeries(float $startDividend, float $growthRate, int $horizonYears): array
    {
        $series   = [['year' => 0, 'projected_dividends_eur' => round($startDividend, 2)]];
        $dividend = $startDividend;

        for ($y = 1; $y <= $horizonYears; $y++) {
            $dividend  = $dividend * (1 + $growthRate);
            $series[]  = ['year' => $y, 'projected_dividends_eur' => round(max(0, $dividend), 2)];
        }

        return $series;
    }
}

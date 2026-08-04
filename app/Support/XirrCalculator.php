<?php

namespace App\Support;

use Carbon\Carbon;

class XirrCalculator
{
    /**
     * Compute annualised internal rate of return for irregular cash flows.
     *
     * @param  array  $cashFlows  [['amount' => float, 'date' => string|Carbon], ...]
     *                            Outflows are negative, inflows are positive.
     * @return float|null Annualised rate (0.12 = 12%), or null if it cannot converge.
     */
    public static function calculate(array $cashFlows): ?float
    {
        if (count($cashFlows) < 2) {
            return null;
        }

        $flows = array_map(fn ($cf) => [
            'amount' => (float) $cf['amount'],
            'date' => Carbon::parse($cf['date']),
        ], $cashFlows);

        $origin = $flows[0]['date'];

        $hasPositive = false;
        $hasNegative = false;
        $normalized = [];

        foreach ($flows as $f) {
            $days = $origin->diffInDays($f['date'], false);
            $normalized[] = ['amount' => $f['amount'], 'years' => $days / 365.0];

            if ($f['amount'] > 0) {
                $hasPositive = true;
            }
            if ($f['amount'] < 0) {
                $hasNegative = true;
            }
        }

        // XIRR requires at least one inflow and one outflow.
        if (! $hasPositive || ! $hasNegative) {
            return null;
        }

        $rate = 0.1;

        for ($i = 0; $i < 200; $i++) {
            $npv = 0.0;
            $dnpv = 0.0;

            foreach ($normalized as $f) {
                $base = 1.0 + $rate;
                $factor = $base ** $f['years'];
                $npv += $f['amount'] / $factor;
                $dnpv -= $f['years'] * $f['amount'] / ($base * $factor);
            }

            if (abs($dnpv) < 1e-12) {
                return null;
            }

            $next = $rate - $npv / $dnpv;

            if (abs($next - $rate) < 1e-7) {
                return $next > -1.0 ? $next : null;
            }

            // Keep rate from going below -100 % (undefined territory).
            $rate = max($next, -0.9999);
        }

        return null;
    }
}

<?php

namespace App\Actions;

use App\Models\CashMovement;
use App\Models\FxRate;
use App\Models\PriceHistory;
use App\Models\Transaction;
use App\Models\User;

class ComputePortfolioHistory
{
    public function forUser(User $user): array
    {
        $accountIds = $user->accounts()->pluck('id');
        if ($accountIds->isEmpty()) {
            return [];
        }

        $instrumentIds = Transaction::whereIn('account_id', $accountIds)
            ->distinct()
            ->pluck('instrument_id')
            ->all();

        if (empty($instrumentIds)) {
            return [];
        }

        $allDates = PriceHistory::whereIn('instrument_id', $instrumentIds)
            ->distinct()
            ->orderBy('date')
            ->pluck('date')
            ->map(fn ($d) => $d->toDateString())
            ->all();

        if (count($allDates) < 2) {
            return [];
        }

        // Prices per instrument, sorted by date asc
        $priceRows = PriceHistory::whereIn('instrument_id', $instrumentIds)
            ->orderBy('instrument_id')->orderBy('date')
            ->get(['instrument_id', 'date', 'close', 'currency']);

        $priceArrays = [];
        foreach ($priceRows->groupBy('instrument_id') as $instrId => $rows) {
            foreach ($rows as $row) {
                $priceArrays[$instrId][] = [$row->date->toDateString(), (float) $row->close, $row->currency];
            }
        }

        // Currencies needed for FX (prices + dividends)
        $priceCurrencies = $priceRows->pluck('currency')->unique()->filter(fn ($c) => $c !== 'EUR');
        $dividendCurrencies = CashMovement::whereIn('account_id', $accountIds)
            ->whereIn('type', ['dividend', 'withholding_tax'])
            ->where('currency', '!=', 'EUR')
            ->distinct()
            ->pluck('currency');
        $allCurrencies = $priceCurrencies->merge($dividendCurrencies)->unique()->values()->all();

        // FX rates per currency, sorted by date asc
        $fxArrays = [];
        if (! empty($allCurrencies)) {
            $fxRows = FxRate::whereIn('currency', $allCurrencies)
                ->orderBy('currency')->orderBy('date')
                ->get(['currency', 'date', 'rate_to_eur']);
            foreach ($fxRows->groupBy('currency') as $currency => $rows) {
                foreach ($rows as $row) {
                    $fxArrays[$currency][] = [$row->date->toDateString(), (float) $row->rate_to_eur];
                }
            }
        }

        // Transactions per instrument, sorted by executed_at asc
        $txnRows = Transaction::whereIn('account_id', $accountIds)
            ->whereIn('instrument_id', $instrumentIds)
            ->orderBy('executed_at')
            ->get(['instrument_id', 'type', 'quantity', 'executed_at']);

        $txnArrays = [];
        foreach ($txnRows as $row) {
            $txnArrays[$row->instrument_id][] = [
                $row->executed_at->toDateString(),
                $row->type,
                (float) $row->quantity,
            ];
        }

        // Cash movements for deposits, dividends, fees — sorted by occurred_at asc
        $cashArr = CashMovement::whereIn('account_id', $accountIds)
            ->whereIn('type', ['deposit', 'dividend', 'withholding_tax', 'fee', 'promo'])
            ->orderBy('occurred_at')
            ->get(['type', 'amount', 'currency', 'occurred_at'])
            ->all();

        // Forward-scan state
        $currentQtys = array_fill_keys($instrumentIds, 0.0);
        $currentPrices = []; // [instrId => [close, currency]]
        $currentFxRates = []; // [currency => rate_to_eur]

        $txnPtrs = array_fill_keys($instrumentIds, 0);
        $pricePtrs = array_fill_keys($instrumentIds, 0);
        $fxPtrs = array_fill_keys($allCurrencies, 0);
        $cashIdx = 0;

        $cumDeposits = 0.0;
        $cumDividends = 0.0;
        $cumFees = 0.0;

        $result = [];

        foreach ($allDates as $date) {
            // Advance transactions
            foreach ($instrumentIds as $instrId) {
                $txns = $txnArrays[$instrId] ?? [];
                while ($txnPtrs[$instrId] < count($txns) && $txns[$txnPtrs[$instrId]][0] <= $date) {
                    [, $type, $qty] = $txns[$txnPtrs[$instrId]];
                    // Let sells go negative (track shorts) instead of clamping at 0 —
                    // clamping drops the short, so a later buy leaves a phantom share and
                    // overvalues the portfolio vs ComputePortfolio. The value loop below
                    // skips qty <= 0, so shorts simply hold no positive market value.
                    $currentQtys[$instrId] = $type === 'buy'
                        ? $currentQtys[$instrId] + $qty
                        : $currentQtys[$instrId] - $qty;
                    $txnPtrs[$instrId]++;
                }
            }

            // Advance prices (carry-forward)
            foreach ($instrumentIds as $instrId) {
                $prices = $priceArrays[$instrId] ?? [];
                while ($pricePtrs[$instrId] < count($prices) && $prices[$pricePtrs[$instrId]][0] <= $date) {
                    $currentPrices[$instrId] = [$prices[$pricePtrs[$instrId]][1], $prices[$pricePtrs[$instrId]][2]];
                    $pricePtrs[$instrId]++;
                }
            }

            // Advance FX rates (carry-forward)
            foreach ($allCurrencies as $currency) {
                $rates = $fxArrays[$currency] ?? [];
                while ($fxPtrs[$currency] < count($rates) && $rates[$fxPtrs[$currency]][0] <= $date) {
                    $currentFxRates[$currency] = $rates[$fxPtrs[$currency]][1];
                    $fxPtrs[$currency]++;
                }
            }

            // Advance cash movements
            while ($cashIdx < count($cashArr)) {
                $cm = $cashArr[$cashIdx];
                if ($cm->occurred_at->toDateString() > $date) {
                    break;
                }

                $amount = (float) $cm->amount;

                if ($cm->type === 'deposit' && $cm->currency === 'EUR' && $amount > 0) {
                    $cumDeposits += $amount;
                } elseif ($cm->type === 'dividend' || $cm->type === 'withholding_tax') {
                    if ($cm->currency === 'EUR') {
                        $cumDividends += $amount;
                    } elseif (isset($currentFxRates[$cm->currency])) {
                        $cumDividends += $amount * $currentFxRates[$cm->currency];
                    }
                } elseif (($cm->type === 'fee' || $cm->type === 'promo') && $cm->currency === 'EUR') {
                    $cumFees += $amount;
                }

                $cashIdx++;
            }

            // Compute total portfolio value at this date
            $totalValue = 0.0;
            foreach ($instrumentIds as $instrId) {
                $qty = $currentQtys[$instrId];
                if ($qty < 0.0001 || ! isset($currentPrices[$instrId])) {
                    continue;
                }
                [$close, $currency] = $currentPrices[$instrId];
                $fxRate = $currency === 'EUR' ? 1.0 : ($currentFxRates[$currency] ?? null);
                if ($fxRate === null) {
                    continue;
                }
                $totalValue += $qty * $close * $fxRate;
            }

            $result[] = [
                'date' => $date,
                'total_value_eur' => round($totalValue, 2),
                'invested_eur' => round($cumDeposits, 2),
                'net_gain_eur' => round($totalValue - $cumDeposits, 2),
                'cumulative_dividends_eur' => round($cumDividends, 2),
                'cumulative_fees_eur' => round($cumFees, 2),
            ];
        }

        return $result;
    }
}

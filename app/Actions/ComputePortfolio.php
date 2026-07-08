<?php

namespace App\Actions;

use App\Models\CashMovement;
use App\Models\FxRate;
use App\Models\PriceHistory;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ComputePortfolio
{
    public function forUser(User $user): array
    {
        $accountIds = $user->accounts()->pluck('id');

        if ($accountIds->isEmpty()) {
            return ['positions' => [], 'summary' => $this->emptySummary()];
        }

        $transactions = Transaction::whereIn('account_id', $accountIds)
            ->with('instrument')
            ->orderBy('executed_at')
            ->get();

        if ($transactions->isEmpty()) {
            return ['positions' => [], 'summary' => $this->emptySummary()];
        }

        $byInstrument  = $transactions->groupBy('instrument_id');
        $instrumentIds = $byInstrument->keys()->all();

        $latestPrices = $this->latestPricesFor($instrumentIds);

        // Load FX rates for all relevant currencies: prices + dividend currencies.
        $dividendRows  = $this->rawDividendRows($accountIds, $instrumentIds);
        $allCurrencies = $latestPrices->pluck('currency')
            ->merge($dividendRows->pluck('currency'))
            ->unique()
            ->filter(fn($c) => $c && $c !== 'EUR')
            ->values();
        $latestFxRates = $this->latestFxRatesFor($allCurrencies);

        // Net dividend income per instrument in EUR.
        $dividendsByInstrument = $this->dividendsEurByInstrument($dividendRows, $latestFxRates);

        $today         = now()->startOfDay();
        $allResults    = [];
        $openPositions = [];

        foreach ($byInstrument as $instrumentId => $txns) {
            $result = $this->buildPosition(
                $txns->first()->instrument,
                $txns,
                $latestPrices->get($instrumentId),
                $latestFxRates,
                (float) ($dividendsByInstrument->get($instrumentId) ?? 0),
            );

            if ($result === null) {
                continue;
            }

            $allResults[] = $result;

            if ($result['quantity'] > 0) {
                $openPositions[] = $result;
            }
        }

        usort($openPositions, fn($a, $b) => ($b['current_value_eur'] ?? 0) <=> ($a['current_value_eur'] ?? 0));

        $deposited = $this->depositedEur($accountIds);
        $fees      = $this->feesEur($accountIds);

        return ['positions' => $openPositions, 'summary' => $this->summarise($allResults, $deposited, $fees)];
    }

    private function buildPosition(
        $instrument,
        Collection $txns,
        $latestPrice,
        Collection $fxRates,
        float $dividendEur,
    ): ?array {
        // Running WAC — two cost tracks:
        //   runningLocalCost  abs(local_value) in instrument's own currency, fee-free → avg price display
        //   runningEurCost    abs(total_eur) including all fees/AutoFX → actual EUR deployed
        //
        // Unrealised P&L uses the fee-free track: (current_price − avg_cost) × qty × fx_rate.
        // This matches DEGIRO's display.
        //
        // Short positions: opening sell books proceeds into realizedGain; closing buy deducts
        // its cost from realizedGain rather than inflating the long cost basis.
        $runningQty       = 0.0;
        $runningLocalCost = 0.0;
        $runningEurCost   = 0.0;
        $realizedGain     = 0.0;
        $priceCurrency    = null;
        $hasTransactions  = false;

        foreach ($txns->sortBy('executed_at') as $txn) {
            if ($txn->total_eur === null) {
                continue;
            }

            $hasTransactions = true;
            $qty      = (float) $txn->quantity;
            $totalEur = (float) $txn->total_eur;
            $localCost = $txn->local_value !== null
                ? abs((float) $txn->local_value)
                : $qty * (float) $txn->price;

            if ($txn->type === 'buy') {
                if ($runningQty < -0.0001) {
                    // Close (part of) short — deduct buy cost from realizedGain.
                    $closingQty    = min($qty, abs($runningQty));
                    $closingFrac   = $closingQty / $qty;
                    $realizedGain -= abs($totalEur) * $closingFrac;
                    $runningQty   += $closingQty;
                    $qty          -= $closingQty;
                }

                if ($qty > 0.0001) {
                    $priceCurrency     = $txn->price_currency;
                    $openFrac          = (float) $txn->quantity > 0.0001 ? $qty / (float) $txn->quantity : 1.0;
                    $runningLocalCost += $localCost * $openFrac;
                    $runningEurCost   += abs($totalEur) * $openFrac;
                    $runningQty       += $qty;
                }
            } else {
                $fraction         = $runningQty > 0.0001 ? min($qty, $runningQty) / $runningQty : 0.0;
                $eurSold          = $runningEurCost   * $fraction;
                $localSold        = $runningLocalCost * $fraction;
                $realizedGain    += $totalEur - $eurSold;
                $runningEurCost  -= $eurSold;
                $runningLocalCost -= $localSold;
                $runningQty      -= $qty;
            }

            if (abs($runningQty) < 0.0001) {
                $runningQty = $runningLocalCost = $runningEurCost = 0.0;
            }
        }

        if (!$hasTransactions) {
            return null;
        }

        $currentQty      = $runningQty;
        $avgCostPerShare = $currentQty > 0.0001 ? $runningLocalCost / $currentQty : null;

        // Closed position — return only what the summary needs.
        if ($currentQty < 0.0001) {
            return [
                'quantity'           => 0,
                'cost_basis_eur'     => 0,
                'current_value_eur'  => null,
                'unrealized_gain_eur'=> null,
                'unrealized_gain_pct'=> null,
                'realized_gain_eur'  => round($realizedGain, 2),
                'dividend_eur'       => round($dividendEur, 2),
            ];
        }

        [$currentValueEur, $fxRate] = $this->currentValueEur($currentQty, $latestPrice, $fxRates);

        // Fee-free unrealised: (current_price − avg_cost) × qty × fx_rate.
        $feeFreeBasicEur   = $avgCostPerShare !== null ? $avgCostPerShare * $currentQty * ($fxRate ?? 1.0) : null;
        $unrealizedGain    = ($currentValueEur !== null && $feeFreeBasicEur !== null)
            ? $currentValueEur - $feeFreeBasicEur
            : null;
        $unrealizedGainPct = ($feeFreeBasicEur > 0 && $unrealizedGain !== null)
            ? $unrealizedGain / $feeFreeBasicEur
            : null;

        return [
            'instrument_id'          => $instrument->id,
            'name'                   => $instrument->name,
            'isin'                   => $instrument->isin,
            'sector'                 => $instrument->sector,
            'yahoo_symbol'           => $instrument->yahoo_symbol,
            'quantity'               => round($currentQty, 4),
            'price_currency'         => $priceCurrency,
            'avg_cost_per_share'     => $avgCostPerShare !== null ? round($avgCostPerShare, 4) : null,
            'cost_basis_eur'         => round($runningEurCost, 2),
            'latest_price'           => $latestPrice ? round((float) $latestPrice->close, 4) : null,
            'latest_price_currency'  => $latestPrice?->currency,
            'latest_price_date'      => $latestPrice?->date->toDateString(),
            'current_value_eur'      => $currentValueEur !== null ? round($currentValueEur, 2) : null,
            'unrealized_gain_eur'    => $unrealizedGain !== null ? round($unrealizedGain, 2) : null,
            'unrealized_gain_pct'    => $unrealizedGainPct !== null ? round($unrealizedGainPct, 4) : null,
            'realized_gain_eur'      => round($realizedGain, 2),
            'dividend_eur'           => round($dividendEur, 2),
        ];
    }

    private function currentValueEur(float $qty, $latestPrice, Collection $fxRates): array
    {
        if (!$latestPrice) {
            return [null, null];
        }

        $currency = $latestPrice->currency;
        $fxRate   = 1.0;

        if ($currency !== 'EUR') {
            $fx = $fxRates->get($currency);

            if (!$fx) {
                return [null, null];
            }

            $fxRate = round((float) $fx->rate_to_eur, 6);
        }

        return [$qty * (float) $latestPrice->close * $fxRate, $fxRate];
    }

    private function rawDividendRows(mixed $accountIds, array $instrumentIds): Collection
    {
        return CashMovement::whereIn('account_id', $accountIds)
            ->whereIn('type', ['dividend', 'withholding_tax'])
            ->whereNotNull('instrument_id')
            ->whereIn('instrument_id', $instrumentIds)
            ->select('instrument_id', 'amount', 'currency')
            ->get();
    }

    private function dividendsEurByInstrument(Collection $rows, Collection $fxRates): Collection
    {
        return $rows->groupBy('instrument_id')->map(function ($entries) use ($fxRates) {
            $total = 0.0;

            foreach ($entries as $entry) {
                $amount = (float) $entry->amount;

                if ($entry->currency === 'EUR') {
                    $total += $amount;
                } else {
                    $fx = $fxRates->get($entry->currency);
                    $total += $fx ? $amount * (float) $fx->rate_to_eur : 0.0;
                }
            }

            return round($total, 2);
        });
    }

    private function latestPricesFor(array $instrumentIds): Collection
    {
        return PriceHistory::whereIn('instrument_id', $instrumentIds)
            ->select(DB::raw('DISTINCT ON (instrument_id) instrument_id, date, close, currency'))
            ->orderBy('instrument_id')
            ->orderByDesc('date')
            ->get()
            ->keyBy('instrument_id');
    }

    private function latestFxRatesFor(Collection $currencies): Collection
    {
        if ($currencies->isEmpty()) {
            return collect();
        }

        return FxRate::whereIn('currency', $currencies)
            ->select(DB::raw('DISTINCT ON (currency) currency, rate_to_eur'))
            ->orderBy('currency')
            ->orderByDesc('date')
            ->get()
            ->keyBy('currency');
    }

    private function depositedEur(mixed $accountIds): float
    {
        return (float) CashMovement::whereIn('account_id', $accountIds)
            ->where('type', 'deposit')
            ->where('currency', 'EUR')
            ->where('amount', '>', 0)
            ->sum('amount');
    }

    private function feesEur(mixed $accountIds): float
    {
        // Broker fees net of any promo/rebate credits DEGIRO issued.
        // Withholding tax is already netted inside dividend_eur, excluded here.
        return (float) CashMovement::whereIn('account_id', $accountIds)
            ->whereIn('type', ['fee', 'promo'])
            ->where('currency', 'EUR')
            ->sum('amount');
    }

    private function summarise(array $positions, float $deposited, float $fees): array
    {
        $totalValue    = array_sum(array_column($positions, 'current_value_eur'));
        $totalCost     = array_sum(array_column($positions, 'cost_basis_eur'));
        $totalUnreal   = array_sum(array_filter(array_column($positions, 'unrealized_gain_eur')));
        $totalRealized = array_sum(array_column($positions, 'realized_gain_eur'));
        $totalDividend = array_sum(array_column($positions, 'dividend_eur'));

        // Net gain = what the portfolio is actually worth minus what was deposited.
        // This is the single ground-truth number. The breakdown (unrealised + realised +
        // dividends) does not sum to it because broker fees on open positions are excluded
        // from the fee-free unrealised display.
        $netGain = $totalValue - $deposited;

        return [
            'total_value_eur'           => round($totalValue, 2),
            'deposited_eur'             => round($deposited, 2),
            'net_gain_eur'              => round($netGain, 2),
            'net_gain_pct'              => $deposited > 0 ? round($netGain / $deposited, 4) : null,
            'total_unrealized_gain_eur' => round($totalUnreal, 2),
            'total_unrealized_gain_pct' => $totalCost > 0 ? round($totalUnreal / $totalCost, 4) : null,
            'total_realized_gain_eur'   => round($totalRealized, 2),
            'total_dividend_eur'        => round($totalDividend, 2),
            'total_fees_eur'            => round($fees, 2),
        ];
    }

    private function emptySummary(): array
    {
        return [
            'total_value_eur'           => 0,
            'deposited_eur'             => 0,
            'net_gain_eur'              => 0,
            'net_gain_pct'              => null,
            'total_unrealized_gain_eur' => 0,
            'total_unrealized_gain_pct' => null,
            'total_realized_gain_eur'   => 0,
            'total_dividend_eur'        => 0,
            'total_fees_eur'            => 0,
        ];
    }
}

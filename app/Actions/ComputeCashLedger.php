<?php

namespace App\Actions;

use App\Models\Account;
use App\Models\CashMovement;
use App\Models\FxRate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ComputeCashLedger
{
    /**
     * The cash ledger for one account: every movement newest first, with a running
     * EUR balance and a total per type.
     *
     * EUR conversion uses the latest stored rate per currency — the same rule
     * ComputePortfolio applies to dividends, so the totals here agree with the
     * figures on the portfolio page rather than inventing a second convention.
     *
     * @return array{rows: array<int, array<string, mixed>>, totals: array<string, float>, total_eur: float}
     */
    public function forAccount(Account $account): array
    {
        $movements = CashMovement::where('account_id', $account->id)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        if ($movements->isEmpty()) {
            return ['rows' => [], 'totals' => [], 'total_eur' => 0.0];
        }

        $fxRates = $this->latestFxRatesFor(
            $movements->pluck('currency')->unique()->reject(fn (?string $currency): bool => ! $currency || $currency === 'EUR')->values()
        );

        $balance = 0.0;
        $totals = [];
        $rows = [];

        foreach ($movements as $movement) {
            $amountEur = $this->toEur((float) $movement->amount, $movement->currency, $fxRates);

            // A non-EUR row without a rate on file has no EUR value; it leaves the
            // running balance untouched rather than being counted as its face amount.
            if ($amountEur !== null) {
                $balance += $amountEur;
                $totals[$movement->type] = round(($totals[$movement->type] ?? 0.0) + $amountEur, 2);
            }

            $rows[] = [
                'id' => $movement->id,
                'occurred_at' => $movement->occurred_at->toDateString(),
                'type' => $movement->type,
                'description' => $movement->description,
                'amount' => round((float) $movement->amount, 2),
                'currency' => $movement->currency,
                'amount_eur' => $amountEur !== null ? round($amountEur, 2) : null,
                'balance_eur' => round($balance, 2),
            ];
        }

        return [
            'rows' => array_reverse($rows),
            'totals' => $totals,
            'total_eur' => round($balance, 2),
        ];
    }

    private function toEur(float $amount, ?string $currency, Collection $fxRates): ?float
    {
        if ($currency === 'EUR') {
            return $amount;
        }

        $fx = $fxRates->get($currency);

        return $fx ? $amount * (float) $fx->rate_to_eur : null;
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
}

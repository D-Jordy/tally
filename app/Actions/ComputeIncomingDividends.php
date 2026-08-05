<?php

namespace App\Actions;

use App\Models\CashMovement;
use App\Models\Dividend;
use App\Models\FxRate;
use App\Models\Instrument;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ComputeIncomingDividends
{
    public function __construct(private ComputePortfolio $portfolio) {}

    /**
     * Build the dividend income forecast for a user.
     *
     * @return array{
     *   confirmed: array<int, array>,
     *   events: array<int, array>,
     *   monthly: array<int, array{month: string, expected_eur: float}>,
     *   by_instrument: array<int, array>,
     *   summary: array{next_12m_total_eur: float, trailing_12m_received_eur: float, instrument_count: int, confirmed_count: int, yield_on_cost: float|null},
     * }
     */
    public function forUser(User $user): array
    {
        $accountIds = $user->accounts()->pluck('id');

        // Resolve current open positions: [instrument_id => position].
        $openPositions = collect($this->portfolio->forUser($user)['positions'])
            ->filter(fn (array $position): bool => ($position['quantity'] ?? 0) > 0)
            ->keyBy('instrument_id');

        $qtyByInstrument = $openPositions->map(fn (array $position): float => (float) $position['quantity']);

        if ($qtyByInstrument->isEmpty()) {
            return $this->emptyResult($accountIds);
        }

        $instrumentIds = $qtyByInstrument->keys()->all();

        $today = now()->startOfDay();
        $horizon = now()->addMonths(12)->endOfDay();

        // Both lists are plain reads now: confirmed rows come from the provider,
        // projections are materialised per instrument by ProjectDividends after every
        // dividend sync. Nothing here infers a cadence.
        $confirmed = $this->toEvents(
            Dividend::whereIn('instrument_id', $instrumentIds)
                ->where('confirmed', true)
                ->whereBetween('ex_date', [$today->toDateString(), $horizon->toDateString()])
                ->orderBy('ex_date')
                ->with('instrument')
                ->get(),
            $qtyByInstrument,
        );

        $events = $this->toEvents(
            Dividend::whereIn('instrument_id', $instrumentIds)
                ->where('projected', true)
                ->whereBetween('ex_date', [$today->toDateString(), $horizon->toDateString()])
                ->orderBy('ex_date')
                ->with('instrument')
                ->get(),
            $qtyByInstrument,
        );

        $currencies = collect($confirmed)->pluck('currency')->merge(collect($events)->pluck('currency'));

        // Pre-load trailing cash movements so we can include their currencies in the FX lookup.
        $trailingRows = $this->rawTrailingRows($accountIds);

        // Collect all currencies needed.
        $uniqueCurrencies = $currencies
            ->merge(collect($events)->pluck('currency'))
            ->merge(collect($confirmed)->pluck('currency'))
            ->merge($trailingRows->pluck('currency'))
            ->unique()
            ->filter(fn ($c) => $c !== 'EUR')
            ->values();
        $fxRates = $this->latestFxRatesFor($uniqueCurrencies);

        $next12mTotal = 0.0;

        // Convert confirmed events to EUR.
        foreach ($confirmed as &$event) {
            $event['expected_eur'] = $this->toEur(
                $event['quantity'] * (float) $event['amount_per_share'],
                $event['currency'],
                $fxRates
            );

            if ($event['expected_eur'] !== null) {
                $next12mTotal += $event['expected_eur'];
            }
        }
        unset($event);

        // Convert projected events to EUR.
        foreach ($events as &$event) {
            $event['expected_eur'] = $this->toEur(
                $event['quantity'] * (float) $event['amount_per_share'],
                $event['currency'],
                $fxRates
            );

            if ($event['expected_eur'] !== null) {
                $next12mTotal += $event['expected_eur'];
            }
        }
        unset($event);

        // Aggregate into zero-filled monthly buckets (next 12 months) — confirmed + projected.
        $monthly = $this->buildMonthlyBuckets(array_merge($confirmed, $events), $today);

        $trailing = $this->trailingReceivedEur($trailingRows, $fxRates);

        $allInstrumentIds = array_unique(array_merge(
            array_column($confirmed, 'instrument_id'),
            array_column($events, 'instrument_id')
        ));

        $providerYields = Instrument::whereIn('id', $instrumentIds)->pluck('dividend_yield', 'id');

        $byInstrument = $this->byInstrument(array_merge($confirmed, $events), $openPositions, $providerYields);

        // Portfolio YOC: annual income over the cost basis of the paying positions only —
        // both sides summed from the table rows, so the KPI cannot disagree with them.
        $payingCost = (float) collect($byInstrument)->sum('cost_basis_eur');
        $payingIncome = (float) collect($byInstrument)->sum('annual_income_eur');

        $summary = [
            'next_12m_total_eur' => round($next12mTotal, 2),
            'trailing_12m_received_eur' => round($trailing, 2),
            'instrument_count' => count($allInstrumentIds),
            'confirmed_count' => count($confirmed),
            'yield_on_cost' => $payingCost > 0 ? round($payingIncome / $payingCost, 4) : null,
        ];

        return [...compact('confirmed', 'events', 'monthly', 'summary'), 'by_instrument' => $byInstrument];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Turn dividend rows into events for the positions actually held, carrying the
     * quantity that decides what the payment is worth to this user.
     *
     * @param  Collection<int, Dividend>  $rows
     * @param  Collection<int, float>  $qtyByInstrument
     * @return array<int, array<string, mixed>>
     */
    private function toEvents(Collection $rows, Collection $qtyByInstrument): array
    {
        return $rows
            ->filter(fn (Dividend $row): bool => (bool) $qtyByInstrument->get($row->instrument_id))
            ->map(fn (Dividend $row): array => [
                // The row id lets the calendar table pair its Eloquent records back up
                // with the per-user EUR amount computed here.
                'id' => $row->id,
                'instrument_id' => $row->instrument->id,
                'name' => $row->instrument->name,
                'yahoo_symbol' => $row->instrument->yahoo_symbol,
                'ex_date' => $row->ex_date->toDateString(),
                'pay_date' => $row->pay_date?->toDateString(),
                'amount_per_share' => round((float) $row->amount_per_share, 8),
                'currency' => $row->currency,
                'quantity' => round((float) $qtyByInstrument->get($row->instrument_id), 4),
                'expected_eur' => null,
                'projected' => $row->projected,
                'confirmed' => $row->confirmed,
            ])
            ->values()
            ->all();
    }

    /**
     * Roll the forward 12-month events up per instrument, with both yields:
     * current yield on market value, and yield on cost (YOC) on the cost basis.
     *
     * Both yields prefer the provider's own figure, so they match what every other
     * platform quotes, and fall back to our cadence projection where Yahoo has none.
     * `forward_12m_eur` stays projection-derived either way — it has to line up with the
     * monthly calendar, which the provider's single annual number cannot produce.
     *
     * @param  array<int, array<string, mixed>>  $events  confirmed + projected
     * @param  Collection<int, array<string, mixed>>  $positions  keyed by instrument_id
     * @param  Collection<int, string|null>  $providerYields  dividend_yield keyed by instrument_id
     * @return array<int, array<string, mixed>>
     */
    private function byInstrument(array $events, Collection $positions, Collection $providerYields): array
    {
        return collect($events)
            ->groupBy('instrument_id')
            ->map(function (Collection $rows, int $instrumentId) use ($positions, $providerYields): array {
                $position = $positions->get($instrumentId, []);
                $forward = round((float) $rows->sum('expected_eur'), 2);
                $cost = (float) ($position['cost_basis_eur'] ?? 0);
                $value = (float) ($position['current_value_eur'] ?? 0);

                $providerYield = $providerYields->get($instrumentId);
                $providerYield = $providerYield !== null ? (float) $providerYield : null;

                // Annual income the yields are built on: the provider's yield restated in
                // EUR against this position's market value, else our projected 12m total.
                $annual = $providerYield !== null && $value > 0
                    ? $providerYield * $value
                    : $forward;

                return [
                    'instrument_id' => $instrumentId,
                    'name' => $rows->first()['name'],
                    'yahoo_symbol' => $rows->first()['yahoo_symbol'],
                    'quantity' => (float) ($position['quantity'] ?? 0),
                    'forward_12m_eur' => $forward,
                    'annual_income_eur' => round($annual, 2),
                    'cost_basis_eur' => round($cost, 2),
                    'current_value_eur' => $value > 0 ? round($value, 2) : null,
                    'yield' => $value > 0 ? round($annual / $value, 4) : null,
                    'yield_on_cost' => $cost > 0 ? round($annual / $cost, 4) : null,
                ];
            })
            ->sortByDesc('forward_12m_eur')
            ->values()
            ->all();
    }

    private function toEur(float $amount, string $currency, Collection $fxRates): ?float
    {
        if ($currency === 'EUR') {
            return round($amount, 2);
        }

        $fx = $fxRates->get($currency);

        if (! $fx) {
            return null;
        }

        return round($amount * (float) $fx->rate_to_eur, 2);
    }

    /** Latest FX rate per currency — uses a portable MAX(date) subquery (works with SQLite and Postgres). */
    private function latestFxRatesFor(Collection $currencies): Collection
    {
        if ($currencies->isEmpty()) {
            return collect();
        }

        $latest = FxRate::query()
            ->select('currency', DB::raw('MAX(date) as max_date'))
            ->whereIn('currency', $currencies)
            ->groupBy('currency');

        return FxRate::query()
            ->joinSub($latest, 'latest', function ($join) {
                $join->on('fx_rates.currency', '=', 'latest.currency')
                    ->on('fx_rates.date', '=', 'latest.max_date');
            })
            ->whereIn('fx_rates.currency', $currencies)
            ->select('fx_rates.currency', 'fx_rates.rate_to_eur')
            ->get()
            ->keyBy('currency');
    }

    /**
     * Zero-filled monthly buckets for the next 12 calendar months.
     */
    private function buildMonthlyBuckets(array $events, Carbon $today): array
    {
        $buckets = [];

        for ($i = 0; $i < 12; $i++) {
            $buckets[$today->copy()->addMonths($i)->format('Y-m')] = 0.0;
        }

        foreach ($events as $event) {
            $month = substr($event['ex_date'], 0, 7);

            if (array_key_exists($month, $buckets) && $event['expected_eur'] !== null) {
                $buckets[$month] += $event['expected_eur'];
            }
        }

        return array_map(
            fn ($month, $total) => ['month' => $month, 'expected_eur' => round($total, 2)],
            array_keys($buckets),
            array_values($buckets),
        );
    }

    private function rawTrailingRows(mixed $accountIds): Collection
    {
        if ($accountIds->isEmpty()) {
            return collect();
        }

        return CashMovement::whereIn('account_id', $accountIds)
            ->whereIn('type', ['dividend', 'withholding_tax'])
            ->where('occurred_at', '>=', now()->subYear())
            ->select('amount', 'currency')
            ->get();
    }

    private function trailingReceivedEur(Collection $rows, Collection $fxRates): float
    {
        $total = 0.0;

        foreach ($rows as $row) {
            $amount = (float) $row->amount;

            if ($row->currency === 'EUR') {
                $total += $amount;
            } else {
                $fx = $fxRates->get($row->currency);
                $total += $fx ? $amount * (float) $fx->rate_to_eur : 0.0;
            }
        }

        return $total;
    }

    private function emptyResult(mixed $accountIds): array
    {
        $trailingRows = $this->rawTrailingRows($accountIds);
        $trailingCurrencies = $trailingRows->pluck('currency')->unique()->filter(fn ($c) => $c !== 'EUR')->values();
        $fxRates = $this->latestFxRatesFor($trailingCurrencies);

        return [
            'confirmed' => [],
            'events' => [],
            'by_instrument' => [],
            'monthly' => array_map(
                fn ($i) => ['month' => now()->addMonths($i)->format('Y-m'), 'expected_eur' => 0.0],
                range(0, 11)
            ),
            'summary' => [
                'next_12m_total_eur' => 0.0,
                'trailing_12m_received_eur' => round($this->trailingReceivedEur($trailingRows, $fxRates), 2),
                'instrument_count' => 0,
                'confirmed_count' => 0,
                'yield_on_cost' => null,
            ],
        ];
    }
}

<?php

namespace App\Actions;

use App\Models\Dividend;
use App\Models\Instrument;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Materialise the cadence projection for one instrument.
 *
 * These rows are our own guess, not a provider fact, so they carry projected=true
 * and are rebuilt from scratch on every dividend sync. They live per instrument and
 * do not depend on prices, FX or who holds what — which is exactly why they can be
 * stored: the only thing that invalidates them is new dividend history, and the
 * sync that brings that in regenerates them in the same pass.
 */
class ProjectDividends
{
    // Projections this close to a confirmed ex-date are the same payment.
    private const CONFIRMED_OVERLAP_DAYS = 20;

    private const HORIZON_MONTHS = 12;

    // Cadence is read off the recent past only; older gaps say nothing about today.
    private const RECENT_PAYMENTS = 8;

    // How far past its own cadence a payer may drift before we stop projecting it.
    private const STALE_AFTER_INTERVALS = 1.5;

    /** Rebuild the projections for one instrument. Returns the number of rows written. */
    public function forInstrument(Instrument $instrument): int
    {
        // Wipe and rebuild as one step: a failed insert must not leave the instrument
        // without the projections it had.
        return DB::transaction(function () use ($instrument): int {
            Dividend::where('instrument_id', $instrument->id)
                ->where('projected', true)
                ->delete();

            // Only payments that have actually gone ex can describe a cadence, and only
            // real rows: a projection must never be projected from a projection.
            $history = Dividend::where('instrument_id', $instrument->id)
                ->where('projected', false)
                ->where('ex_date', '<=', now()->toDateString())
                ->orderBy('ex_date')
                ->get();

            if ($history->count() < 2) {
                return 0;
            }

            $rows = $this->buildRows($instrument, $history);

            if ($rows === []) {
                return 0;
            }

            Dividend::insert($rows);

            return count($rows);
        });
    }

    /**
     * @param  Collection<int, Dividend>  $history
     * @return array<int, array<string, mixed>>
     */
    private function buildRows(Instrument $instrument, Collection $history): array
    {
        $exDates = $history->pluck('ex_date')->map(fn ($date): Carbon => Carbon::parse($date))->values();

        $gaps = [];
        $recent = $exDates->slice(-self::RECENT_PAYMENTS)->values();

        for ($i = 1; $i < $recent->count(); $i++) {
            $gaps[] = $recent[$i - 1]->diffInDays($recent[$i]);
        }

        sort($gaps);

        if ($this->median($gaps) < 7) {
            return [];
        }

        // Frequency = payments actually made in the trailing 12 months, not the median
        // gap. Semi-annual EU payers (NN, Shell, …) space their two payments unevenly
        // (~90d then ~275d), so the median gap lands in the annual bucket and half the
        // income vanishes.
        // ponytail: a special dividend in the window inflates the count by one; the
        // median amount below already damps its value. Dedup near ex-dates if that bites.
        $latestEx = $exDates->last();
        $timesPerYear = max(1, $exDates->filter(fn (Carbon $date): bool => $date->gt($latestEx->copy()->subDays(365)))->count());
        $intervalDays = (int) round(365 / $timesPerYear);

        // A payer that missed its own cadence by half an interval has stopped paying,
        // been suspended, or we lost its history — either way the next twelve months
        // are not ours to invent. Stale cadences used to evaporate with the request;
        // now they would sit in the table as rows.
        if ($latestEx->lt(now()->subDays((int) round($intervalDays * self::STALE_AFTER_INTERVALS)))) {
            return [];
        }

        // Median of recent amounts, not the latest: a one-off special dividend is an
        // outlier that would otherwise be projected forward.
        $amount = (float) $history->slice(-self::RECENT_PAYMENTS)
            ->pluck('amount_per_share')
            ->map(fn ($value): float => (float) $value)
            ->median();

        $today = now()->startOfDay();
        $horizon = now()->addMonths(self::HORIZON_MONTHS)->endOfDay();

        // A confirmed row is the same payment announced for real; anything already on
        // file also owns its ex-date, and the unique index would reject a second one.
        $confirmedDates = Dividend::where('instrument_id', $instrument->id)
            ->where('confirmed', true)
            ->where('ex_date', '>=', $today->toDateString())
            ->pluck('ex_date')
            ->map(fn ($date): Carbon => Carbon::parse($date));

        $takenDates = Dividend::where('instrument_id', $instrument->id)
            ->pluck('ex_date')
            ->map(fn ($date): string => Carbon::parse($date)->toDateString());

        $now = now();
        $cursor = $latestEx->copy();
        $rows = [];

        while (true) {
            $cursor->addDays($intervalDays);

            if ($cursor->gt($horizon)) {
                break;
            }

            if ($cursor->lt($today) || $takenDates->contains($cursor->toDateString())) {
                continue;
            }

            $overlaps = $confirmedDates->contains(
                fn (Carbon $confirmed): bool => abs($cursor->diffInDays($confirmed)) <= self::CONFIRMED_OVERLAP_DAYS
            );

            if ($overlaps) {
                continue;
            }

            $rows[] = [
                'instrument_id' => $instrument->id,
                'ex_date' => $cursor->toDateString(),
                'pay_date' => null,
                'amount_per_share' => $amount,
                'currency' => $history->last()->currency,
                'confirmed' => false,
                'projected' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $rows;
    }

    /** @param  array<int, float|int>  $sorted */
    private function median(array $sorted): float
    {
        $count = count($sorted);

        if ($count === 0) {
            return 0.0;
        }

        $mid = (int) ($count / 2);

        return $count % 2 === 1
            ? (float) $sorted[$mid]
            : ($sorted[$mid - 1] + $sorted[$mid]) / 2.0;
    }
}

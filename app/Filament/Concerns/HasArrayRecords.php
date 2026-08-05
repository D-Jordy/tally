<?php

namespace App\Filament\Concerns;

use Closure;
use Illuminate\Support\Collection;

/**
 * Filament tables over computed rows.
 *
 * The compute actions return arrays, not an Eloquent query — projected dividends
 * and closed positions do not exist as rows anywhere — so sorting happens here
 * instead of in SQL. Filament keys the records by array index for us.
 */
trait HasArrayRecords
{
    /** @param  array<int, array<string, mixed>>  $rows */
    protected function arrayRecords(array $rows): Closure
    {
        return function (?string $sortColumn, ?string $sortDirection) use ($rows): Collection {
            $records = collect($rows);

            if ($sortColumn === null) {
                return $records;
            }

            return $records
                ->sortBy($sortColumn, SORT_REGULAR, $sortDirection === 'desc')
                ->values();
        };
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Instrument extends Model
{
    use HasFactory;

    protected $fillable = [
        'isin', 'name', 'symbol', 'yahoo_symbol',
        'quote_currency', 'sector', 'country', 'exchange',
        'analyst_target_price', 'analyst_rating', 'dividend_yield',
    ];

    protected $casts = [
        'analyst_target_price' => 'decimal:8',
        'dividend_yield' => 'decimal:6',
    ];

    /**
     * Instruments are addressed by their ticker: /instruments/ASML.AS reads better
     * than /instruments/7 and survives a reseed.
     *
     * Falls back to the id because yahoo_symbol is nullable — an unresolved
     * instrument is exactly the one you need to open in order to fix it.
     */
    public function getRouteKey(): string
    {
        return $this->yahoo_symbol ?: (string) $this->getKey();
    }

    /**
     * Filament resolves records through this, not resolveRouteBinding(), so the
     * override goes here or the resource's own scoping is lost.
     *
     * The id clause is only added for a numeric key: Postgres rejects comparing a
     * bigint column against 'ASML.AS' rather than just not matching. Nested so the
     * OR cannot escape whatever the resource already narrowed the query to.
     */
    public function resolveRouteBindingQuery($query, $value, $field = null): Builder
    {
        return $query->where(fn (Builder $query): Builder => $query
            ->where('yahoo_symbol', $value)
            ->when(is_numeric($value), fn (Builder $query): Builder => $query->orWhere($this->getKeyName(), $value)));
    }

    /**
     * Prices and dividends are only meaningful for the symbol they were fetched
     * under, so repointing an instrument at a different symbol drops them — the
     * next SyncMarketDataJob refills from the corrected symbol. Without this a
     * wrong resolution keeps poisoning the position after it has been fixed.
     */
    protected static function booted(): void
    {
        static::updated(function (self $instrument): void {
            if ($instrument->wasChanged('yahoo_symbol')) {
                $instrument->priceHistory()->delete();
                $instrument->dividends()->delete();
            }
        });
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function cashMovements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }

    public function dividends(): HasMany
    {
        return $this->hasMany(Dividend::class);
    }

    public function priceHistory(): HasMany
    {
        return $this->hasMany(PriceHistory::class);
    }
}

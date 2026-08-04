<?php

namespace App\Models;

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

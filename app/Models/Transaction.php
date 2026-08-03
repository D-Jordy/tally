<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    use HasFactory;

    /**
     * Stable idempotency key for a trade, from the normalised trade fields only.
     *
     * Deliberately NOT keyed on the broker UUID: one DEGIRO order id covers every
     * partial fill of that order, so keying on it collapses a split execution into
     * a single trade. The fee is what separates two fills that are otherwise
     * identical (same second, quantity and price, different execution venue).
     *
     * Every input is a stored column, so a row's hash is identical whether it is
     * computed from a CSV row or recomputed from the database.
     *
     * ponytail: two fills identical down to the fee would still collapse; add the
     * execution venue as a column if DEGIRO ever produces that.
     */
    public static function makeDedupeHash(
        Carbon $executedAt,
        int $instrumentId,
        string $type,
        int|float|string $quantity,
        int|float|string $price,
        int|float|string|null $fee = null,
    ): string {
        return hash('sha256', implode('|', [
            $executedAt->format('Y-m-d H:i'),
            $instrumentId,
            $type,
            number_format((float) $quantity, 8, '.', ''),
            number_format((float) $price, 8, '.', ''),
            number_format((float) $fee, 8, '.', ''),
        ]));
    }

    protected $fillable = [
        'account_id', 'instrument_id', 'executed_at', 'type',
        'quantity', 'price', 'price_currency', 'fee', 'trade_currency',
        'fx_rate_to_eur', 'local_value', 'value_eur', 'total_eur',
        'source', 'external_id', 'dedupe_hash',
    ];

    protected $casts = [
        'executed_at' => 'datetime',
        'quantity' => 'decimal:8',
        'price' => 'decimal:8',
        'fee' => 'decimal:8',
        'fx_rate_to_eur' => 'decimal:8',
        'local_value' => 'decimal:4',
        'value_eur' => 'decimal:4',
        'total_eur' => 'decimal:4',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class);
    }
}

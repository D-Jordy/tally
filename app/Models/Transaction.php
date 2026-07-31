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
     * Stable idempotency key for a trade. Prefers the broker's own UUID; DEGIRO
     * leaves that column blank on some rows, so fall back to the normalised trade
     * fields. Identical whether computed from a CSV row or from a stored row.
     */
    public static function makeDedupeHash(
        ?string $externalId,
        Carbon $executedAt,
        int $instrumentId,
        string $type,
        int|float|string $quantity,
        int|float|string $price,
    ): string {
        if (trim((string) $externalId) !== '') {
            return hash('sha256', 'external|'.trim((string) $externalId));
        }

        return hash('sha256', implode('|', [
            $executedAt->format('Y-m-d H:i'),
            $instrumentId,
            $type,
            number_format((float) $quantity, 8, '.', ''),
            number_format((float) $price, 8, '.', ''),
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

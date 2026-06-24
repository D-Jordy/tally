<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashMovement extends Model
{
    use HasFactory;

    protected $fillable = [
        'account_id', 'instrument_id', 'occurred_at', 'value_date',
        'type', 'amount', 'currency', 'fx_rate', 'balance_eur',
        'description', 'excluded_from_returns', 'source', 'dedupe_hash',
    ];

    protected $casts = [
        'occurred_at'           => 'datetime',
        'value_date'            => 'date',
        'amount'                => 'decimal:8',
        'fx_rate'               => 'decimal:8',
        'balance_eur'           => 'decimal:4',
        'excluded_from_returns' => 'boolean',
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

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    protected $fillable = [
        'account_id', 'instrument_id', 'executed_at', 'type',
        'quantity', 'price', 'price_currency', 'fee', 'trade_currency',
        'fx_rate_to_eur', 'local_value', 'value_eur', 'total_eur',
        'source', 'external_id',
    ];

    protected $casts = [
        'executed_at'    => 'datetime',
        'quantity'       => 'decimal:8',
        'price'          => 'decimal:8',
        'fee'            => 'decimal:8',
        'fx_rate_to_eur' => 'decimal:8',
        'local_value'    => 'decimal:4',
        'value_eur'      => 'decimal:4',
        'total_eur'      => 'decimal:4',
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

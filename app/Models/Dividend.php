<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Dividend extends Model
{
    protected $fillable = [
        'instrument_id', 'ex_date', 'pay_date', 'amount_per_share', 'currency',
    ];

    protected $casts = [
        'ex_date'          => 'date',
        'pay_date'         => 'date',
        'amount_per_share' => 'decimal:8',
    ];

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class);
    }
}

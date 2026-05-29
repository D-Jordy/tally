<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FxRate extends Model
{
    protected $fillable = ['date', 'currency', 'rate_to_eur'];

    protected $casts = [
        'date'        => 'date',
        'rate_to_eur' => 'decimal:8',
    ];
}

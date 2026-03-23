<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PuHistory extends Model
{
    protected $fillable = [
        'emission_id',
        'date',
        'unit_value',
    ];

    protected $casts = [
        'date' => 'date',
        'unit_value' => 'decimal:6',
    ];

    public function emission(): BelongsTo
    {
        return $this->belongsTo(Emission::class);
    }
}

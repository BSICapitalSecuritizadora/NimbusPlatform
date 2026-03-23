<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegralizationHistory extends Model
{
    protected $fillable = [
        'emission_id',
        'date',
        'quantity',
    ];

    protected $casts = [
        'date' => 'date',
        'quantity' => 'decimal:4',
    ];

    public function emission(): BelongsTo
    {
        return $this->belongsTo(Emission::class);
    }
}

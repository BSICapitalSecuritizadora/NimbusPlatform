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
        'unit_value',
        'financial_value',
        'investor_fund',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'quantity' => 'decimal:4',
            'unit_value' => 'decimal:6',
            'financial_value' => 'decimal:2',
        ];
    }

    public function emission(): BelongsTo
    {
        return $this->belongsTo(Emission::class);
    }
}

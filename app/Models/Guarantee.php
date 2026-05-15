<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Guarantee extends Model
{
    /** @use HasFactory<\Database\Factories\GuaranteeFactory> */
    use HasFactory;

    protected $fillable = [
        'emission_id',
        'guarantee_type',
        'minimum_value',
        'validity_start_date',
        'validity_end_date',
        'description',
        'evaluation_frequency',
    ];

    protected function casts(): array
    {
        return [
            'minimum_value' => 'decimal:2',
            'validity_start_date' => 'date',
            'validity_end_date' => 'date',
        ];
    }

    public function emission(): BelongsTo
    {
        return $this->belongsTo(Emission::class);
    }
}

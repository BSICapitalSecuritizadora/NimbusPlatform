<?php

namespace App\Models;

use Database\Factories\EmissionPuExternalValidationRowFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class EmissionPuExternalValidationRow extends Model
{
    /** @use HasFactory<EmissionPuExternalValidationRowFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        $immutable = function (): never {
            throw new LogicException('External PU comparison rows are immutable.');
        };

        static::updating($immutable);
        static::deleting($immutable);
    }

    protected $fillable = [
        'external_validation_id',
        'reference_date',
        'candidate_unit_value',
        'external_unit_value',
        'absolute_difference',
        'relative_difference_percentage',
        'classification',
    ];

    protected function casts(): array
    {
        return [
            'reference_date' => 'date',
        ];
    }

    public function externalValidation(): BelongsTo
    {
        return $this->belongsTo(EmissionPuExternalValidation::class, 'external_validation_id');
    }
}

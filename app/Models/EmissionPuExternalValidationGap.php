<?php

namespace App\Models;

use App\Domain\PuCalculator\Enums\PuExternalValidationGapType;
use Database\Factories\EmissionPuExternalValidationGapFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class EmissionPuExternalValidationGap extends Model
{
    /** @use HasFactory<EmissionPuExternalValidationGapFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        $immutable = function (): never {
            throw new LogicException('External PU comparison coverage gaps are immutable.');
        };

        static::updating($immutable);
        static::deleting($immutable);
    }

    protected $fillable = [
        'external_validation_id',
        'reference_date',
        'gap_type',
    ];

    protected function casts(): array
    {
        return [
            'reference_date' => 'date',
            'gap_type' => PuExternalValidationGapType::class,
        ];
    }

    public function externalValidation(): BelongsTo
    {
        return $this->belongsTo(EmissionPuExternalValidation::class, 'external_validation_id');
    }
}

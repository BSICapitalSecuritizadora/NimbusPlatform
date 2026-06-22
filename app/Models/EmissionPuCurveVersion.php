<?php

namespace App\Models;

use App\Domain\PuCalculator\Enums\PuCurveStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmissionPuCurveVersion extends Model
{
    /** @use HasFactory<\Database\Factories\EmissionPuCurveVersionFactory> */
    use HasFactory;

    protected $fillable = [
        'emission_id',
        'calculation_version',
        'batch_id',
        'status',
        'obsolete_reason',
        'engine_version',
        'parameters_snapshot',
        'rows_count',
        'error_message',
        'validation_summary',
        'generated_by',
        'validated_by',
        'homologated_by',
        'invalidated_by',
        'generated_at',
        'validated_at',
        'homologated_at',
        'invalidated_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PuCurveStatus::class,
            'parameters_snapshot' => 'array',
            'validation_summary' => 'array',
            'rows_count' => 'integer',
            'generated_at' => 'datetime',
            'validated_at' => 'datetime',
            'homologated_at' => 'datetime',
            'invalidated_at' => 'datetime',
        ];
    }

    public function emission(): BelongsTo
    {
        return $this->belongsTo(Emission::class);
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function validatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public function homologatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'homologated_by');
    }

    public function invalidatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invalidated_by');
    }

    /**
     * @param  Builder<EmissionPuCurveVersion>  $query
     * @return Builder<EmissionPuCurveVersion>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query
            ->where('status', '!=', PuCurveStatus::Obsolete->value)
            ->orderByDesc('id');
    }

    /**
     * @param  Builder<EmissionPuCurveVersion>  $query
     * @return Builder<EmissionPuCurveVersion>
     */
    public function scopeHomologated(Builder $query): Builder
    {
        return $query->where('status', PuCurveStatus::Homologated->value);
    }

    public function isProtected(): bool
    {
        return $this->status->isProtected();
    }
}

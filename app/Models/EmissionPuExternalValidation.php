<?php

namespace App\Models;

use App\Domain\PuCalculator\Enums\PuCurveExternalValidationStatus;
use App\Domain\PuCalculator\Enums\PuExternalValidationCoverageStatus;
use Database\Factories\EmissionPuExternalValidationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class EmissionPuExternalValidation extends Model
{
    /** @use HasFactory<EmissionPuExternalValidationFactory> */
    use HasFactory;

    /** @var list<string> */
    public const DECISION_MUTABLE_FIELDS = [
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_reason',
        'updated_at',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $validation): void {
            $originalStatus = PuCurveExternalValidationStatus::tryFrom(
                (string) $validation->getRawOriginal('status'),
            );

            if ($originalStatus !== PuCurveExternalValidationStatus::Pending) {
                throw new LogicException('A final external PU validation decision is immutable.');
            }

            if (array_diff(array_keys($validation->getDirty()), self::DECISION_MUTABLE_FIELDS) !== []) {
                throw new LogicException('External PU comparison provenance and rows are immutable.');
            }

            if (! in_array($validation->status, [
                PuCurveExternalValidationStatus::Validated,
                PuCurveExternalValidationStatus::Rejected,
            ], true)) {
                throw new LogicException('External PU validation only accepts a final decision.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('External PU validation dossiers cannot be deleted.');
        });
    }

    protected $fillable = [
        'candidate_curve_version_id',
        'benchmark_id',
        'candidate_checksum',
        'benchmark_dataset_sha256',
        'comparison_algorithm_version',
        'comparison_sha256',
        'coverage_status',
        'compared_rows',
        'candidate_dates_without_reference',
        'reference_dates_without_candidate',
        'status',
        'generated_by',
        'reviewed_by',
        'reviewed_at',
        'review_reason',
    ];

    protected $attributes = [
        'status' => PuCurveExternalValidationStatus::Pending->value,
    ];

    protected function casts(): array
    {
        return [
            'coverage_status' => PuExternalValidationCoverageStatus::class,
            'status' => PuCurveExternalValidationStatus::class,
            'compared_rows' => 'integer',
            'candidate_dates_without_reference' => 'integer',
            'reference_dates_without_candidate' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(EmissionPuCurveVersion::class, 'candidate_curve_version_id');
    }

    public function benchmark(): BelongsTo
    {
        return $this->belongsTo(EmissionPuExternalBenchmark::class, 'benchmark_id');
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(EmissionPuExternalValidationRow::class, 'external_validation_id');
    }

    public function gaps(): HasMany
    {
        return $this->hasMany(EmissionPuExternalValidationGap::class, 'external_validation_id');
    }
}

<?php

namespace App\Models;

use App\Domain\PuCalculator\Enums\PuCurveExternalValidationStatus;
use App\Domain\PuCalculator\Enums\PuCurveInternalValidationStatus;
use App\Domain\PuCalculator\Enums\PuCurveReviewStatus;
use App\Domain\PuCalculator\Enums\PuCurveRole;
use App\Domain\PuCalculator\Enums\PuCurveStatus;
use Database\Factories\EmissionPuCurveVersionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class EmissionPuCurveVersion extends Model
{
    /** @use HasFactory<EmissionPuCurveVersionFactory> */
    use HasFactory;

    /**
     * Únicos campos que o review maker-checker pode alterar numa candidate
     * persistida. `updated_at` entra na lista porque o Eloquent toca o timestamp
     * em qualquer save -- sem ele o guard bloquearia o próprio review legítimo.
     *
     * @var list<string>
     */
    public const REVIEW_MUTABLE_FIELDS = [
        'review_status',
        'reviewed_by',
        'reviewed_at',
        'review_reason',
        'updated_at',
    ];

    /**
     * Candidate persistida é artefato auditável: conteúdo financeiro, identidade
     * (role, asOf, fingerprint, checksum), provenance do maker e resultado da
     * validação interna são imutáveis; só a decisão de review pode ser gravada, e
     * apenas enquanto ela ainda não é final. Versões operacionais mantêm
     * exatamente o lifecycle anterior a esta fase.
     */
    protected static function booted(): void
    {
        static::updating(function (self $version): void {
            if ($version->getRawOriginal('curve_role') !== PuCurveRole::Candidate->value) {
                return;
            }

            if (PuCurveReviewStatus::tryFrom((string) $version->getRawOriginal('review_status'))?->isFinal()) {
                throw new LogicException('A reviewed PU candidate is immutable.');
            }

            if (array_diff(array_keys($version->getDirty()), self::REVIEW_MUTABLE_FIELDS) !== []) {
                throw new LogicException('Persisted PU candidate provenance and lifecycle fields are immutable.');
            }
        });

        static::deleting(function (self $version): void {
            if ($version->isCandidate()) {
                throw new LogicException('Persisted PU candidates cannot be deleted.');
            }
        });
    }

    protected $fillable = [
        'emission_id',
        'calculation_version',
        'curve_role',
        'candidate_as_of',
        'input_fingerprint',
        'curve_checksum',
        'internal_validation_status',
        'external_validation_status',
        'review_status',
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
        'reviewed_by',
        'generated_at',
        'validated_at',
        'homologated_at',
        'invalidated_at',
        'reviewed_at',
        'review_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => PuCurveStatus::class,
            'curve_role' => PuCurveRole::class,
            'review_status' => PuCurveReviewStatus::class,
            'internal_validation_status' => PuCurveInternalValidationStatus::class,
            'external_validation_status' => PuCurveExternalValidationStatus::class,
            'candidate_as_of' => 'date',
            'parameters_snapshot' => 'array',
            'validation_summary' => 'array',
            'rows_count' => 'integer',
            'generated_at' => 'datetime',
            'validated_at' => 'datetime',
            'homologated_at' => 'datetime',
            'invalidated_at' => 'datetime',
            'reviewed_at' => 'datetime',
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

    public function dailyCurves(): HasMany
    {
        return $this->hasMany(EmissionPuDailyCurve::class, 'curve_version_id');
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

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @param  Builder<EmissionPuCurveVersion>  $query
     * @return Builder<EmissionPuCurveVersion>
     */
    public function scopeOperational(Builder $query): Builder
    {
        return $query->where('curve_role', PuCurveRole::Operational->value);
    }

    /**
     * @param  Builder<EmissionPuCurveVersion>  $query
     * @return Builder<EmissionPuCurveVersion>
     */
    public function scopeCandidate(Builder $query): Builder
    {
        return $query->where('curve_role', PuCurveRole::Candidate->value);
    }

    /**
     * "Versão atual" é sempre a operacional viva: candidate nunca aparece aqui.
     *
     * @param  Builder<EmissionPuCurveVersion>  $query
     * @return Builder<EmissionPuCurveVersion>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query
            ->operational()
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

    public function isCandidate(): bool
    {
        return $this->curve_role === PuCurveRole::Candidate;
    }

    public function isOperational(): bool
    {
        return $this->curve_role === PuCurveRole::Operational;
    }
}

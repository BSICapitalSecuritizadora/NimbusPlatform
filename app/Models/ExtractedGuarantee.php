<?php

namespace App\Models;

use App\Enums\GuaranteeConfidenceLevel;
use App\Enums\GuaranteeDetectionStatus;
use App\Enums\GuaranteeEventType;
use App\Enums\GuaranteeEvidenceLevel;
use App\Enums\GuaranteeLegalStatus;
use App\Enums\GuaranteeMatchLevel;
use App\Enums\GuaranteeReconciliationOutcome;
use App\Enums\GuaranteeRequirementBase;
use App\Enums\GuaranteeRequirementBasis;
use App\Enums\GuaranteeType;
use App\Enums\LegalDocumentType;
use Database\Factories\ExtractedGuaranteeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Garantia identificada num documento e ainda pendente de revisão (§4 do escopo).
 *
 * Uma candidata nunca vira garantia sozinha: ela é uma proposta de cadastro que
 * só existe como garantia oficial depois que alguém confirma. Candidatas de
 * alteração/liberação apontam para a garantia afetada por `related_guarantee_id`,
 * o que evita que um aditamento crie uma duplicata em vez de alterar o original.
 */
class ExtractedGuarantee extends Model
{
    /** @use HasFactory<ExtractedGuaranteeFactory> */
    use HasFactory;

    protected $fillable = [
        'emission_id',
        'legal_instrument_id',
        'legal_instrument_document_id',
        'document_id',
        'guarantee_id',
        'related_guarantee_id',
        'status',
        'event_type',
        'type',
        'name',
        'description',
        'identification',
        'contracted_value',
        'documentary_value',
        'requirement_basis',
        'requirement_value',
        'requirement_percentage',
        'requirement_base',
        'requirement_multiplier',
        'requirement_formula',
        'requirement_conditions',
        'legal_status',
        'validity_start_date',
        'validity_end_date',
        'effective_date',
        'evaluation_frequency',
        'document_type',
        'document_date',
        'source_clause',
        'source_page',
        'source_excerpt',
        'confidence_score',
        'field_evidence',
        'field_confidences',
        'has_conflict',
        'conflict_reason',
        'reconciliation_outcome',
        'match_score',
        'match_level',
        'match_evidence',
        'review_notes',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => GuaranteeDetectionStatus::class,
            'event_type' => GuaranteeEventType::class,
            'type' => GuaranteeType::class,
            'legal_status' => GuaranteeLegalStatus::class,
            'requirement_basis' => GuaranteeRequirementBasis::class,
            'requirement_base' => GuaranteeRequirementBase::class,
            'document_type' => LegalDocumentType::class,
            'reconciliation_outcome' => GuaranteeReconciliationOutcome::class,
            'match_level' => GuaranteeMatchLevel::class,
            'match_evidence' => 'array',
            'match_score' => 'float',
            'identification' => 'array',
            'field_evidence' => 'array',
            'field_confidences' => 'array',
            'has_conflict' => 'boolean',
            'contracted_value' => 'decimal:2',
            'documentary_value' => 'decimal:2',
            'requirement_value' => 'decimal:2',
            'requirement_percentage' => 'decimal:6',
            'requirement_multiplier' => 'decimal:4',
            'confidence_score' => 'float',
            'source_page' => 'integer',
            'validity_start_date' => 'date',
            'validity_end_date' => 'date',
            'effective_date' => 'date',
            'document_date' => 'date',
            'reviewed_at' => 'datetime',
        ];
    }

    public function emission(): BelongsTo
    {
        return $this->belongsTo(Emission::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function legalInstrument(): BelongsTo
    {
        return $this->belongsTo(LegalInstrument::class);
    }

    public function legalInstrumentDocument(): BelongsTo
    {
        return $this->belongsTo(LegalInstrumentDocument::class, 'legal_instrument_document_id');
    }

    public function guarantee(): BelongsTo
    {
        return $this->belongsTo(Guarantee::class);
    }

    public function relatedGuarantee(): BelongsTo
    {
        return $this->belongsTo(Guarantee::class, 'related_guarantee_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function confidenceLevel(): ?GuaranteeConfidenceLevel
    {
        return GuaranteeConfidenceLevel::fromScore($this->confidence_score);
    }

    public function confidencePercent(): ?string
    {
        if ($this->confidence_score === null) {
            return null;
        }

        return round($this->confidence_score * 100).'%';
    }

    /**
     * Como o extrator obteve um campo específico. Ausência de registro é
     * tratada como "não localizada" — nunca como valor confirmado.
     */
    public function evidenceFor(string $field): GuaranteeEvidenceLevel
    {
        $value = ($this->field_evidence ?? [])[$field] ?? null;

        return GuaranteeEvidenceLevel::tryFrom((string) $value) ?? GuaranteeEvidenceLevel::NotFound;
    }

    public function confidenceFor(string $field): ?GuaranteeConfidenceLevel
    {
        $value = ($this->field_confidences ?? [])[$field] ?? null;

        if (is_numeric($value)) {
            return GuaranteeConfidenceLevel::fromScore((float) $value);
        }

        return GuaranteeConfidenceLevel::tryFrom((string) $value);
    }

    /**
     * Campos que a IA inferiu em vez de ler explicitamente. São o que a revisão
     * precisa destacar (§36 e §37).
     *
     * @return array<int, string>
     */
    public function inferredFields(): array
    {
        return array_keys(array_filter(
            $this->field_evidence ?? [],
            static fn (mixed $level): bool => $level === GuaranteeEvidenceLevel::Inferred->value,
        ));
    }

    public function isPending(): bool
    {
        return $this->status === GuaranteeDetectionStatus::Suggested;
    }

    /**
     * A candidata altera uma garantia já confirmada?
     *
     * Nesse caso a confirmação não cria registro novo: ela aplica o evento à
     * garantia existente e preserva a posição anterior no histórico.
     */
    public function amendsExistingGuarantee(): bool
    {
        return $this->related_guarantee_id !== null
            && $this->event_type !== GuaranteeEventType::Constitution;
    }

    /**
     * A candidata corresponde a uma garantia já cadastrada?
     *
     * Vale tanto para o aditamento que altera quanto para a constituição que
     * apenas documenta o que já existia — os dois enriquecem a garantia
     * existente em vez de criar uma segunda (§1 do escopo de consolidação).
     */
    public function matchesExistingGuarantee(): bool
    {
        return $this->related_guarantee_id !== null
            && ($this->reconciliation_outcome?->pointsToExistingGuarantee() ?? $this->amendsExistingGuarantee());
    }

    public function outcome(): GuaranteeReconciliationOutcome
    {
        return $this->reconciliation_outcome
            ?? ($this->has_conflict
                ? GuaranteeReconciliationOutcome::Conflict
                : GuaranteeReconciliationOutcome::NewGuarantee);
    }

    public function matchPercent(): ?string
    {
        return $this->match_score === null ? null : round($this->match_score * 100).'%';
    }

    /**
     * A candidata no formato que o comparador e o matcher consomem.
     *
     * O mesmo formato que a extração produz, para que detecção e revisão
     * percorram exatamente o mesmo código de comparação — se divergissem, o
     * impacto exibido na revisão não seria o impacto aplicado.
     *
     * @return array<string, mixed>
     */
    public function toProposalArray(): array
    {
        return [
            'type' => $this->type,
            'name' => $this->name,
            'description' => $this->description,
            'event_type' => $this->event_type,
            'identification' => $this->identification,
            'contracted_value' => $this->contracted_value,
            'documentary_value' => $this->documentary_value,
            'requirement_basis' => $this->requirement_basis,
            'requirement_value' => $this->requirement_value,
            'requirement_percentage' => $this->requirement_percentage,
            'requirement_base' => $this->requirement_base,
            'requirement_multiplier' => $this->requirement_multiplier,
            'requirement_formula' => $this->requirement_formula,
            'requirement_conditions' => $this->requirement_conditions,
            'validity_start_date' => $this->validity_start_date,
            'validity_end_date' => $this->validity_end_date,
            'evaluation_frequency' => $this->evaluation_frequency,
            'effective_date' => $this->effective_date,
            'document_date' => $this->document_date,
            'legal_instrument_id' => $this->legal_instrument_id,
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', GuaranteeDetectionStatus::Suggested->value);
    }
}

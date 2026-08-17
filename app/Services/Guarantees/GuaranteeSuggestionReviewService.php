<?php

namespace App\Services\Guarantees;

use App\Enums\AccessPermission;
use App\Enums\GuaranteeConfidenceLevel;
use App\Enums\GuaranteeDetectionStatus;
use App\Enums\GuaranteeDocumentReferenceType;
use App\Enums\GuaranteeEventType;
use App\Enums\GuaranteeLegalStatus;
use App\Models\ExtractedGuarantee;
use App\Models\Guarantee;
use App\Models\GuaranteeDocumentReference;
use App\Models\GuaranteeEvent;
use App\Models\User;
use App\Services\Obligations\ObligationSuggestionReviewService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Transforma uma garantia detectada em garantia oficial da emissão — e só
 * mediante confirmação humana (§4 do escopo).
 *
 * Segue o desenho de {@see ObligationSuggestionReviewService},
 * que já resolve o mesmo fluxo para obrigações, e acrescenta o que é próprio de
 * garantias: a confirmação pode criar uma garantia nova ou aplicar um evento
 * (alteração, reforço, substituição, liberação) a uma já existente, sempre
 * preservando a posição anterior no histórico jurídico.
 */
class GuaranteeSuggestionReviewService
{
    public const LOG_NAME = 'guarantee_suggestions';

    public const EVENT_APPROVED = 'guarantee_suggestion_approved';

    public const EVENT_REJECTED = 'guarantee_suggestion_rejected';

    public const TRANSITION_APPROVE = 'approve';

    public const TRANSITION_REJECT = 'reject';

    /**
     * Campos da garantia que um evento documental pode alterar. Um aditamento
     * que não menciona a matrícula não deve apagá-la, então só chaves presentes
     * na candidata entram na atualização.
     *
     * @var list<string>
     */
    private const AMENDABLE_FIELDS = [
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
        'validity_start_date',
        'validity_end_date',
        'evaluation_frequency',
    ];

    public function canUserReview(?User $user, string $transition): bool
    {
        if (! $this->canAccessReviewWorkspace($user)) {
            return false;
        }

        return $user?->can($this->permissionForTransition($transition)->value) ?? false;
    }

    public function canRunTransition(?User $user, ExtractedGuarantee $suggestion, string $transition): bool
    {
        if (! $this->canUserReview($user, $transition)) {
            return false;
        }

        return $suggestion->isPending();
    }

    public function permissionForTransition(string $transition): AccessPermission
    {
        return match ($transition) {
            self::TRANSITION_APPROVE => AccessPermission::GuaranteesApproveSuggestion,
            self::TRANSITION_REJECT => AccessPermission::GuaranteesRejectSuggestion,
            default => throw new InvalidArgumentException("Unsupported guarantee review transition [{$transition}]."),
        };
    }

    /**
     * Confirma a candidata, aplicando as correções feitas na revisão.
     *
     * @param  array<string, mixed>  $overrides  campos ajustados pelo revisor antes de confirmar
     */
    public function approve(
        ExtractedGuarantee $suggestion,
        User $actor,
        array $overrides = [],
        ?string $reviewNotes = null,
    ): Guarantee {
        $this->authorizeTransition($actor, self::TRANSITION_APPROVE);

        if (! $suggestion->isPending()) {
            $this->throwTransitionException('Esta garantia detectada não pode ser confirmada no status atual.');
        }

        $normalizedNotes = $this->normalizeText($reviewNotes);
        $reviewedAt = now();

        return DB::transaction(function () use ($suggestion, $actor, $overrides, $normalizedNotes, $reviewedAt): Guarantee {
            $suggestion->refresh();

            if (! $suggestion->isPending()) {
                $this->throwTransitionException('Esta garantia detectada já foi revisada.');
            }

            $attributes = $this->buildAttributes($suggestion, $overrides);

            $guarantee = $suggestion->amendsExistingGuarantee()
                ? $this->applyEventToExistingGuarantee($suggestion, $attributes, $actor)
                : $this->createGuarantee($suggestion, $attributes, $actor);

            $suggestion->forceFill([
                'status' => GuaranteeDetectionStatus::Approved,
                'guarantee_id' => $guarantee->getKey(),
                'review_notes' => $normalizedNotes,
                'reviewed_by' => $actor->id,
                'reviewed_at' => $reviewedAt,
            ])->save();

            $this->recordAudit(
                self::EVENT_APPROVED,
                'Garantia detectada confirmada',
                $suggestion,
                $actor,
                [
                    'old_status' => GuaranteeDetectionStatus::Suggested->value,
                    'new_status' => GuaranteeDetectionStatus::Approved->value,
                    'guarantee_id' => $guarantee->getKey(),
                    'event_type' => $suggestion->event_type?->value,
                    'confidence_score' => $suggestion->confidence_score,
                    'had_conflict' => $suggestion->has_conflict,
                    'overridden_fields' => array_keys($overrides),
                    'review_notes' => $normalizedNotes,
                    'reviewed_at' => $reviewedAt->toDateTimeString(),
                ],
            );

            return $guarantee;
        });
    }

    public function reject(ExtractedGuarantee $suggestion, User $actor, ?string $rejectionReason): ExtractedGuarantee
    {
        $this->authorizeTransition($actor, self::TRANSITION_REJECT);

        if (! $suggestion->isPending()) {
            $this->throwTransitionException('Esta garantia detectada não pode ser rejeitada no status atual.');
        }

        $normalizedReason = $this->normalizeText($rejectionReason);

        if ($normalizedReason === null) {
            throw ValidationException::withMessages([
                'review_notes' => 'Informe o motivo da rejeição.',
            ]);
        }

        $reviewedAt = now();

        return DB::transaction(function () use ($suggestion, $actor, $normalizedReason, $reviewedAt): ExtractedGuarantee {
            $suggestion->forceFill([
                'status' => GuaranteeDetectionStatus::Rejected,
                'review_notes' => $normalizedReason,
                'reviewed_by' => $actor->id,
                'reviewed_at' => $reviewedAt,
            ])->save();

            $this->recordAudit(
                self::EVENT_REJECTED,
                'Garantia detectada rejeitada',
                $suggestion,
                $actor,
                [
                    'old_status' => GuaranteeDetectionStatus::Suggested->value,
                    'new_status' => GuaranteeDetectionStatus::Rejected->value,
                    'review_notes' => $normalizedReason,
                    'reviewed_at' => $reviewedAt->toDateTimeString(),
                ],
            );

            return $suggestion->refresh();
        });
    }

    /**
     * Atributos da garantia a partir da candidata, com as correções do revisor
     * por cima. O que a extração não localizou permanece nulo.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function buildAttributes(ExtractedGuarantee $suggestion, array $overrides): array
    {
        $attributes = [
            'type' => $suggestion->type,
            'name' => $suggestion->name,
            'description' => $suggestion->description,
            'identification' => $suggestion->identification,
            'contracted_value' => $suggestion->contracted_value,
            'documentary_value' => $suggestion->documentary_value,
            'requirement_basis' => $suggestion->requirement_basis,
            'requirement_value' => $suggestion->requirement_value,
            'requirement_percentage' => $suggestion->requirement_percentage,
            'requirement_base' => $suggestion->requirement_base,
            'requirement_multiplier' => $suggestion->requirement_multiplier,
            'requirement_formula' => $suggestion->requirement_formula,
            'requirement_conditions' => $suggestion->requirement_conditions,
            'validity_start_date' => $suggestion->validity_start_date,
            'validity_end_date' => $suggestion->validity_end_date,
            'evaluation_frequency' => $suggestion->evaluation_frequency,
        ];

        return array_merge($attributes, $overrides);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createGuarantee(ExtractedGuarantee $suggestion, array $attributes, User $actor): Guarantee
    {
        /** @var Guarantee $guarantee */
        $guarantee = $suggestion->emission->guarantees()->create(array_merge($attributes, [
            // A garantia nasce pendurada no instrumento que a revelou, quando a
            // candidata veio de um dossiê (§14 do escopo).
            'legal_instrument_id' => $suggestion->legal_instrument_id,
            'legal_status' => $suggestion->legal_status ?? GuaranteeLegalStatus::Active,
            'constituted_at' => $suggestion->effective_date ?? $suggestion->document_date,
            'guarantee_type' => $attributes['name'] ?? null,
        ]));

        $reference = $this->createDocumentReference(
            $guarantee,
            $suggestion,
            GuaranteeDocumentReferenceType::Constitution,
            $actor,
        );

        $this->recordEvent(
            guarantee: $guarantee,
            reference: $reference,
            eventType: GuaranteeEventType::Constitution,
            effectiveDate: $suggestion->effective_date?->toDateString() ?? $suggestion->document_date?->toDateString(),
            title: 'Constituição',
            description: $suggestion->source_excerpt,
            previousValues: null,
            newValues: $this->comparableValues($guarantee),
            actor: $actor,
        );

        return $guarantee;
    }

    /**
     * Aplica alteração, reforço, substituição ou liberação a uma garantia já
     * confirmada, registrando de onde veio e o que mudou.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function applyEventToExistingGuarantee(
        ExtractedGuarantee $suggestion,
        array $attributes,
        User $actor,
    ): Guarantee {
        /** @var Guarantee $guarantee */
        $guarantee = $suggestion->relatedGuarantee()->lockForUpdate()->firstOrFail();

        $previousValues = $this->comparableValues($guarantee);
        $eventType = $suggestion->event_type ?? GuaranteeEventType::Amendment;
        $effectiveDate = $suggestion->effective_date ?? $suggestion->document_date;

        $changes = $this->resolveAmendableChanges($attributes);

        if ($resultingStatus = $eventType->resultingLegalStatus()) {
            $changes['legal_status'] = $resultingStatus;
        }

        if ($eventType === GuaranteeEventType::Release || $eventType === GuaranteeEventType::Substitution) {
            $changes['released_at'] = $effectiveDate;
        }

        $guarantee->fill($changes)->save();

        $reference = $this->createDocumentReference(
            $guarantee,
            $suggestion,
            $this->referenceTypeFor($eventType),
            $actor,
        );

        $this->recordEvent(
            guarantee: $guarantee,
            reference: $reference,
            eventType: $eventType,
            effectiveDate: $effectiveDate?->toDateString(),
            title: $eventType->label(),
            description: $suggestion->source_excerpt,
            previousValues: $previousValues,
            newValues: $this->comparableValues($guarantee->refresh()),
            actor: $actor,
        );

        return $guarantee;
    }

    /**
     * Só campos efetivamente trazidos pela candidata entram na alteração.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function resolveAmendableChanges(array $attributes): array
    {
        $changes = [];

        foreach (self::AMENDABLE_FIELDS as $field) {
            if (! array_key_exists($field, $attributes)) {
                continue;
            }

            if (blank($attributes[$field])) {
                continue;
            }

            $changes[$field] = $attributes[$field];
        }

        return $changes;
    }

    private function referenceTypeFor(GuaranteeEventType $eventType): GuaranteeDocumentReferenceType
    {
        return match ($eventType) {
            GuaranteeEventType::Constitution => GuaranteeDocumentReferenceType::Constitution,
            GuaranteeEventType::Reinforcement => GuaranteeDocumentReferenceType::Reinforcement,
            GuaranteeEventType::Substitution => GuaranteeDocumentReferenceType::Substitution,
            GuaranteeEventType::Release => GuaranteeDocumentReferenceType::Release,
            GuaranteeEventType::Registration => GuaranteeDocumentReferenceType::Registration,
            default => GuaranteeDocumentReferenceType::Amendment,
        };
    }

    private function createDocumentReference(
        Guarantee $guarantee,
        ExtractedGuarantee $suggestion,
        GuaranteeDocumentReferenceType $referenceType,
        User $actor,
    ): GuaranteeDocumentReference {
        return $guarantee->documentReferences()->create([
            'document_id' => $suggestion->document_id,
            'reference_type' => $referenceType,
            'document_title' => $suggestion->document?->title,
            'document_name' => $suggestion->document?->file_name,
            'document_type' => $suggestion->document_type,
            'document_date' => $suggestion->document_date,
            'page' => $suggestion->source_page,
            'clause' => $suggestion->source_clause,
            'excerpt' => $suggestion->source_excerpt,
            'confidence_level' => GuaranteeConfidenceLevel::fromScore($suggestion->confidence_score),
            'confidence_score' => $suggestion->confidence_score,
            'extraction_method' => 'gemini',
            'extracted_at' => $suggestion->created_at,
            'confirmed_by' => $actor->id,
            'confirmed_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $previousValues
     * @param  array<string, mixed>|null  $newValues
     */
    private function recordEvent(
        Guarantee $guarantee,
        GuaranteeDocumentReference $reference,
        GuaranteeEventType $eventType,
        ?string $effectiveDate,
        string $title,
        ?string $description,
        ?array $previousValues,
        ?array $newValues,
        User $actor,
    ): GuaranteeEvent {
        return $guarantee->events()->create([
            'guarantee_document_reference_id' => $reference->getKey(),
            'event_type' => $eventType,
            'effective_date' => $effectiveDate,
            'title' => $title,
            'description' => $description,
            'previous_values' => $previousValues,
            'new_values' => $newValues,
            'source' => GuaranteeEvent::SOURCE_DOCUMENT,
            'recorded_by' => $actor->id,
        ]);
    }

    /**
     * Recorte da garantia usado para montar o "de → para" do histórico.
     *
     * @return array<string, mixed>
     */
    private function comparableValues(Guarantee $guarantee): array
    {
        return [
            'name' => $guarantee->name,
            'identification' => $guarantee->identification,
            'contracted_value' => $guarantee->contracted_value === null ? null : (float) $guarantee->contracted_value,
            'requirement_basis' => $guarantee->requirement_basis?->value,
            'requirement_value' => $guarantee->requirement_value === null ? null : (float) $guarantee->requirement_value,
            'requirement_percentage' => $guarantee->requirement_percentage === null ? null : (float) $guarantee->requirement_percentage,
            'requirement_base' => $guarantee->requirement_base?->value,
            'legal_status' => $guarantee->legal_status?->value,
            'validity_end_date' => $guarantee->validity_end_date?->toDateString(),
        ];
    }

    private function authorizeTransition(User $actor, string $transition): void
    {
        if ($this->canUserReview($actor, $transition)) {
            return;
        }

        throw new AuthorizationException(match ($transition) {
            self::TRANSITION_APPROVE => 'Você não tem permissão para confirmar esta garantia detectada.',
            self::TRANSITION_REJECT => 'Você não tem permissão para rejeitar esta garantia detectada.',
            default => 'Você não tem permissão para revisar esta garantia detectada.',
        });
    }

    private function canAccessReviewWorkspace(?User $user): bool
    {
        return $user?->can(AccessPermission::GuaranteesReviewSuggestions->value) ?? false;
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function recordAudit(
        string $event,
        string $description,
        ExtractedGuarantee $suggestion,
        User $actor,
        array $properties,
    ): void {
        activity(self::LOG_NAME)
            ->causedBy($actor)
            ->performedOn($suggestion)
            ->event($event)
            ->withProperties(array_merge([
                'emission_id' => $suggestion->emission_id,
                'suggestion_id' => $suggestion->getKey(),
                'document_id' => $suggestion->document_id,
                'name' => $suggestion->name,
            ], $properties))
            ->log($description);
    }

    private function normalizeText(?string $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function throwTransitionException(string $message): never
    {
        throw ValidationException::withMessages([
            'guarantee_review' => $message,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Enums\PuBaselineEvidenceDocumentType;
use App\Domain\PuCalculator\Enums\PuBaselineEvidenceStatus;
use App\Domain\PuCalculator\Enums\PuBaselineEvidenceType;
use App\Domain\PuCalculator\Exceptions\PuMakerCheckerException;
use App\Enums\AccessPermission;
use App\Models\Document;
use App\Models\Emission;
use App\Models\EmissionPuBaselineEvidence;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PuBaselineEvidenceReviewService
{
    public const LOG_NAME = 'pu-baseline-evidence';

    /**
     * @param array{
     *   document_id:int,
     *   evidence_type:string,
     *   document_type:string,
     *   evidenced_value:string,
     *   reference?:?string,
     *   confidence:string,
     *   notes?:?string
     * } $data
     */
    public function create(Emission $emission, array $data, User $actor): EmissionPuBaselineEvidence
    {
        $this->authorize($actor, AccessPermission::PuParametersConfigure);
        $document = $emission->documents()->whereKey((int) $data['document_id'])->first();

        if (! $document instanceof Document) {
            throw ValidationException::withMessages([
                'document_id' => 'Selecione um documento já vinculado a esta emissão.',
            ]);
        }

        $evidenceType = PuBaselineEvidenceType::from($data['evidence_type']);
        $documentType = PuBaselineEvidenceDocumentType::from($data['document_type']);
        $value = trim($data['evidenced_value']);
        $confidence = trim($data['confidence']);
        $this->validateValue($evidenceType, $value);
        $this->validateDocumentType($evidenceType, $documentType);

        if (! in_array($confidence, ['high', 'medium', 'low'], true)) {
            throw ValidationException::withMessages([
                'confidence' => 'Informe confiança alta, média ou baixa.',
            ]);
        }

        $evidence = EmissionPuBaselineEvidence::query()->create([
            'emission_id' => $emission->id,
            'document_id' => $document->id,
            'evidence_type' => $evidenceType,
            'document_type' => $documentType,
            'evidenced_value' => $value,
            'reference' => $this->nullableTrim($data['reference'] ?? null),
            'confidence' => $confidence,
            'status' => PuBaselineEvidenceStatus::PendingReview,
            'notes' => $this->nullableTrim($data['notes'] ?? null),
            'created_by' => $actor->id,
        ]);

        activity(self::LOG_NAME)
            ->event('created_pending_review')
            ->performedOn($evidence)
            ->causedBy($actor)
            ->withProperties([
                'emission_id' => $emission->id,
                'document_id' => $document->id,
                'evidence_type' => $evidenceType->value,
                'evidenced_value' => $value,
                'status' => PuBaselineEvidenceStatus::PendingReview->value,
            ])
            ->log('Evidência de baseline criada para revisão.');

        return $evidence;
    }

    public function approve(
        EmissionPuBaselineEvidence $evidence,
        User $reviewer,
        ?string $reviewNotes,
    ): EmissionPuBaselineEvidence {
        $this->authorize($reviewer, AccessPermission::PuCalendarHomologationReview);

        if ($evidence->status === PuBaselineEvidenceStatus::Approved) {
            throw ValidationException::withMessages([
                'evidence' => 'Esta evidência já foi aprovada.',
            ]);
        }

        if ($evidence->status !== PuBaselineEvidenceStatus::PendingReview) {
            throw ValidationException::withMessages([
                'evidence' => 'Apenas evidências pendentes de revisão podem ser aprovadas.',
            ]);
        }

        $this->assertMakerChecker($evidence, $reviewer);

        return DB::transaction(function () use ($evidence, $reviewer, $reviewNotes): EmissionPuBaselineEvidence {
            $evidence->update([
                'status' => PuBaselineEvidenceStatus::Approved,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_notes' => $this->nullableTrim($reviewNotes),
            ]);

            activity(self::LOG_NAME)
                ->event('approved')
                ->performedOn($evidence)
                ->causedBy($reviewer)
                ->withProperties([
                    'emission_id' => $evidence->emission_id,
                    'document_id' => $evidence->document_id,
                    'evidence_type' => $evidence->evidence_type->value,
                    'evidenced_value' => $evidence->evidenced_value,
                    'previous_status' => PuBaselineEvidenceStatus::PendingReview->value,
                    'status' => PuBaselineEvidenceStatus::Approved->value,
                ])
                ->log('Evidência de baseline aprovada por reviewer autorizado.');

            return $evidence->fresh(['document', 'createdBy', 'reviewedBy']);
        });
    }

    public function reject(
        EmissionPuBaselineEvidence $evidence,
        User $reviewer,
        string $reviewNotes,
    ): EmissionPuBaselineEvidence {
        $this->authorize($reviewer, AccessPermission::PuCalendarHomologationReview);
        $normalizedNotes = trim($reviewNotes);

        if ($evidence->status !== PuBaselineEvidenceStatus::PendingReview) {
            throw ValidationException::withMessages([
                'evidence' => 'Apenas evidências pendentes de revisão podem ser rejeitadas.',
            ]);
        }

        $this->assertMakerChecker($evidence, $reviewer);

        if ($normalizedNotes === '') {
            throw ValidationException::withMessages([
                'review_notes' => 'Informe por que a evidência não comprova o valor declarado.',
            ]);
        }

        $evidence->update([
            'status' => PuBaselineEvidenceStatus::Rejected,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'review_notes' => $normalizedNotes,
        ]);

        activity(self::LOG_NAME)
            ->event('rejected')
            ->performedOn($evidence)
            ->causedBy($reviewer)
            ->withProperties([
                'emission_id' => $evidence->emission_id,
                'document_id' => $evidence->document_id,
                'evidence_type' => $evidence->evidence_type->value,
                'previous_status' => PuBaselineEvidenceStatus::PendingReview->value,
                'status' => PuBaselineEvidenceStatus::Rejected->value,
            ])
            ->log('Evidência de baseline rejeitada por reviewer autorizado.');

        return $evidence->fresh(['document', 'createdBy', 'reviewedBy']);
    }

    private function validateValue(PuBaselineEvidenceType $evidenceType, string $value): void
    {
        if ($evidenceType === PuBaselineEvidenceType::FirstIntegralizationDate) {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

            if ($date === null || $date->toDateString() !== $value) {
                throw ValidationException::withMessages([
                    'evidenced_value' => 'Informe a data comprovada no formato AAAA-MM-DD.',
                ]);
            }
        }

        if ($evidenceType === PuBaselineEvidenceType::IntegralizedQuantity
            && (! is_numeric($value) || (float) $value <= 0)) {
            throw ValidationException::withMessages([
                'evidenced_value' => 'Informe uma quantidade integralizada maior que zero.',
            ]);
        }

        if ($evidenceType === PuBaselineEvidenceType::ExternalPuReference
            && ! in_array($value, ['available_pending_comparison', 'matched', 'divergent'], true)) {
            throw ValidationException::withMessages([
                'evidenced_value' => 'Informe se o gabarito aguarda comparação, foi aderente ou divergiu.',
            ]);
        }
    }

    private function validateDocumentType(
        PuBaselineEvidenceType $evidenceType,
        PuBaselineEvidenceDocumentType $documentType,
    ): void {
        $isExternalReference = $evidenceType === PuBaselineEvidenceType::ExternalPuReference;
        $valid = $isExternalReference
            ? $documentType === PuBaselineEvidenceDocumentType::OfficialPuMemory
            : $documentType !== PuBaselineEvidenceDocumentType::OfficialPuMemory;

        if (! $valid) {
            throw ValidationException::withMessages([
                'document_type' => $isExternalReference
                    ? 'Use uma memória oficial de PU como gabarito independente.'
                    : 'A memória de PU não comprova integralização ou quantidade.',
            ]);
        }
    }

    private function authorize(User $actor, AccessPermission $permission): void
    {
        if (! $actor->isActive() || ! $actor->isApproved() || ! $actor->can($permission->value)) {
            throw new AuthorizationException('Você não possui permissão para esta etapa da evidência do baseline.');
        }
    }

    private function assertMakerChecker(EmissionPuBaselineEvidence $evidence, User $reviewer): void
    {
        if ($evidence->created_by !== null && (int) $evidence->created_by === (int) $reviewer->getKey()) {
            throw new PuMakerCheckerException(
                'A aprovação da evidência exige segregação maker/checker: o criador da proposta não pode ser o reviewer.',
            );
        }
    }

    private function nullableTrim(?string $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}

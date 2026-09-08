<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuCurvePromotionPlan;
use App\Domain\PuCalculator\Enums\PuCurveExternalValidationStatus;
use App\Domain\PuCalculator\Enums\PuCurvePromotionStatus;
use App\Domain\PuCalculator\Enums\PuCurveReviewStatus;
use App\Models\Emission;
use App\Models\EmissionPuCurvePromotion;
use App\Models\EmissionPuCurveVersion;

/**
 * Planner read-only da promoção operacional.
 *
 * Nada aqui escreve, nem chama engine, nem busca dado externo: o plano lê o
 * artefato persistido, recomputa a integridade do dossiê e captura o baseline
 * operacional vigente. É o mesmo cálculo que a execução repete dentro da
 * transação — a diferença é apenas quando ele roda.
 */
final class PuCurvePromotionPlanService
{
    public function __construct(
        private readonly PuCurvePromotionEligibilityService $eligibility,
    ) {}

    /**
     * Resolve o plano a partir da candidate explícita. Quando ela é omitida, a
     * candidate considerada é a mais recente já validada externamente.
     */
    public function plan(Emission $emission, ?EmissionPuCurveVersion $candidate = null): PuCurvePromotionPlan
    {
        $candidate ??= $this->latestExternallyValidatedCandidate($emission);
        $operational = ($emission->fresh() ?? $emission)->latestPuCurveVersion()->first();
        $eligibility = $this->eligibility->inspect($candidate);
        $promotion = $candidate instanceof EmissionPuCurveVersion
            ? $candidate->promotions()->latest('id')->first()
            : null;
        $action = $eligibility['action'];
        $reason = $eligibility['reason'];

        if ($promotion instanceof EmissionPuCurvePromotion) {
            // Uma promoção já existente é fato terminal para esta candidate: o
            // pedido é append-only e a decisão, uma vez final, não é reaberta.
            // Reportar o estado é o comportamento correto -- nunca criar outra.
            [$action, $reason] = match ($promotion->status) {
                PuCurvePromotionStatus::PendingReview => [
                    PuCurvePromotionPlan::ACTION_ALREADY_REQUESTED,
                    'A promotion request for this candidate is already pending independent review.',
                ],
                PuCurvePromotionStatus::Approved => [
                    PuCurvePromotionPlan::ACTION_ALREADY_APPROVED,
                    'This promotion is approved and awaiting an explicit execution.',
                ],
                PuCurvePromotionStatus::Rejected => [
                    PuCurvePromotionPlan::ACTION_ALREADY_REJECTED,
                    'This promotion was rejected; a new attempt requires a new governed candidate.',
                ],
                PuCurvePromotionStatus::Executed => [
                    PuCurvePromotionPlan::ACTION_ALREADY_EXECUTED,
                    'This candidate was already promoted to operational.',
                ],
                null => [$action, $reason],
            };
        } elseif ($eligibility['ready'] && $this->candidateIsOlderThanOperational($candidate, $operational)) {
            $action = PuCurvePromotionPlan::ACTION_BASELINE_CONFLICT;
            $reason = 'The candidate is older than the current operational version; promoting it would leave operational selection on the superseded version.';
        } elseif ($eligibility['ready']) {
            $action = PuCurvePromotionPlan::ACTION_READY_TO_REQUEST;
            $reason = 'The externally validated candidate is intact and ready for a promotion request.';
        }

        return new PuCurvePromotionPlan(
            action: $action,
            reason: $reason,
            emissionId: $emission->id,
            candidateVersionId: $candidate?->id,
            calculationVersion: $candidate?->calculation_version,
            candidateChecksum: $eligibility['candidateChecksum'],
            inputFingerprint: $eligibility['inputFingerprint'],
            rowsCount: $eligibility['rowsCount'],
            externalValidationId: $eligibility['externalValidation']?->id,
            benchmarkId: $eligibility['benchmarkId'],
            benchmarkChecksum: $eligibility['benchmarkChecksum'],
            comparisonChecksum: $eligibility['comparisonChecksum'],
            currentOperationalVersionId: $operational?->id,
            currentOperationalCalculationVersion: $operational?->calculation_version,
            promotionId: $promotion?->id,
            promotionStatus: $promotion?->status?->value,
        );
    }

    /**
     * Os consumidores operacionais elegem a curva vigente por `MAX(id)` entre as
     * versões `operational` e por `ORDER BY id DESC` entre as linhas diárias
     * operacionais. Uma candidate mais antiga que a operacional vigente, se
     * promovida, deixaria essa seleção apontando para a versão substituída -- e é,
     * por construção, uma candidate calculada sobre inputs anteriores. O pedido é
     * recusado na origem em vez de produzir uma promoção inexecutável.
     */
    private function candidateIsOlderThanOperational(
        ?EmissionPuCurveVersion $candidate,
        ?EmissionPuCurveVersion $operational,
    ): bool {
        return $candidate instanceof EmissionPuCurveVersion
            && $operational instanceof EmissionPuCurveVersion
            && $candidate->id <= $operational->id;
    }

    /**
     * Candidate mais recente com review interno aprovado e validação externa
     * concluída como `validated`. Nunca devolve uma versão operacional: promover
     * o que já é operacional não é uma operação existente.
     */
    public function latestExternallyValidatedCandidate(Emission $emission): ?EmissionPuCurveVersion
    {
        return EmissionPuCurveVersion::query()
            ->whereBelongsTo($emission)
            ->candidate()
            ->where('review_status', PuCurveReviewStatus::Approved->value)
            ->where('external_validation_status', PuCurveExternalValidationStatus::Validated->value)
            ->latest('id')
            ->first();
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuCurvePromotionPlan;
use App\Domain\PuCalculator\DTOs\PuCurvePromotionResult;
use App\Domain\PuCalculator\Enums\PuCurvePromotionStatus;
use App\Domain\PuCalculator\Enums\PuCurveRole;
use App\Models\Emission;
use App\Models\EmissionPuCurvePromotion;
use App\Models\EmissionPuCurveVersion;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Cria o pedido de promoção operacional. Nenhum efeito operacional: a candidate
 * continua candidate, a operacional vigente continua vigente e nenhuma linha
 * financeira é tocada.
 *
 * O pedido captura o baseline contra o qual a execução vai revalidar tudo:
 * a versão operacional vigente no momento, o checksum da candidate, o
 * fingerprint dos inputs e a identidade do dossiê externo. Sem essa captura não
 * existe detecção de TOCTOU entre pedido e execução.
 */
final class PuCurvePromotionRequestService
{
    public const ACTION_REQUESTED = 'promotion_requested';

    public const ACTION_ACTOR_REQUIRED = 'promotion_requester_required';

    public const ACTION_ACTOR_NOT_FOUND = 'promotion_requester_not_found';

    public const ACTION_ACTOR_INACTIVE = 'promotion_requester_inactive';

    public const ACTION_ACTOR_UNAPPROVED = 'promotion_requester_unapproved';

    public const ACTION_ACTOR_UNAUTHORIZED = 'promotion_requester_unauthorized';

    public const ACTION_STATE_CHANGED = 'promotion_state_changed';

    public function __construct(
        private readonly PuCurvePromotionPlanService $plans,
        private readonly PuCurvePromotionActorService $actors,
        private readonly PuAuditLogService $auditLog,
    ) {}

    public function inspect(
        Emission $emission,
        ?EmissionPuCurveVersion $candidate = null,
        ?string $requesterIdentifier = null,
    ): PuCurvePromotionResult {
        $plan = $this->plans->plan($emission, $candidate);

        if (trim((string) $requesterIdentifier) === '') {
            return $this->fromPlan($plan);
        }

        $resolution = $this->actors->resolve($requesterIdentifier);

        if (! $resolution->resolved() || $resolution->actor === null) {
            return $this->fromPlan(
                $plan,
                $this->actorAction($resolution->failure),
                $resolution->reason ?? 'An explicit authorized promotion requester is required.',
            );
        }

        return $this->fromPlan($plan, requesterId: $resolution->actor->id);
    }

    public function write(
        Emission $emission,
        ?EmissionPuCurveVersion $candidate,
        ?string $requesterIdentifier,
    ): PuCurvePromotionResult {
        $initialPlan = $this->plans->plan($emission, $candidate);

        if (trim((string) $requesterIdentifier) === '') {
            return $this->fromPlan(
                $initialPlan,
                self::ACTION_ACTOR_REQUIRED,
                'An explicit authorized promotion requester is required for writes.',
            );
        }

        if (! $initialPlan->readyToRequest()) {
            return $this->fromPlan($initialPlan);
        }

        try {
            return DB::transaction(function () use (
                $emission,
                $candidate,
                $requesterIdentifier,
                $initialPlan,
            ): PuCurvePromotionResult {
                // Mesma ordem de locks da execução: Emission primeiro, para
                // serializar toda a governança operacional da emissão.
                $lockedEmission = Emission::query()
                    ->whereKey($emission->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $resolution = $this->actors->resolve($requesterIdentifier, lockForUpdate: true);

                if (! $resolution->resolved() || $resolution->actor === null) {
                    return $this->fromPlan(
                        $initialPlan,
                        $this->actorAction($resolution->failure),
                        $resolution->reason ?? 'An explicit authorized promotion requester is required.',
                    );
                }

                $lockedCandidate = $initialPlan->candidateVersionId === null
                    ? null
                    : EmissionPuCurveVersion::query()
                        ->whereKey($initialPlan->candidateVersionId)
                        ->lockForUpdate()
                        ->first();
                $lockedPlan = $this->plans->plan($lockedEmission, $lockedCandidate ?? $candidate);

                if (! $lockedPlan->readyToRequest()
                    || ! $lockedCandidate instanceof EmissionPuCurveVersion) {
                    return $this->fromPlan($lockedPlan, requesterId: $resolution->actor->id);
                }

                if ($lockedPlan->currentOperationalVersionId !== $initialPlan->currentOperationalVersionId
                    || ! hash_equals(
                        (string) $initialPlan->candidateChecksum,
                        (string) $lockedPlan->candidateChecksum,
                    )) {
                    return $this->fromPlan(
                        $lockedPlan,
                        self::ACTION_STATE_CHANGED,
                        'The operational baseline or the candidate checksum changed while the request was being prepared; zero writes.',
                        requesterId: $resolution->actor->id,
                    );
                }

                $promotion = EmissionPuCurvePromotion::query()->create([
                    'emission_id' => $lockedEmission->id,
                    'candidate_curve_version_id' => $lockedPlan->candidateVersionId,
                    'previous_operational_curve_version_id' => $lockedPlan->currentOperationalVersionId,
                    'external_validation_id' => $lockedPlan->externalValidationId,
                    'calculation_version' => $lockedPlan->calculationVersion,
                    'candidate_checksum' => $lockedPlan->candidateChecksum,
                    'input_fingerprint' => $lockedPlan->inputFingerprint,
                    'benchmark_dataset_sha256' => $lockedPlan->benchmarkChecksum,
                    'comparison_sha256' => $lockedPlan->comparisonChecksum,
                    'rows_count' => $lockedPlan->rowsCount,
                    'status' => PuCurvePromotionStatus::PendingReview,
                    'requested_by' => $resolution->actor->id,
                    'requested_at' => now(),
                ]);
                $this->auditLog->logCurvePromotionRequested($promotion, $resolution->actor);

                return $this->fromPlan(
                    $lockedPlan,
                    self::ACTION_REQUESTED,
                    'The promotion request was recorded; the candidate remains candidate and the operational curve is unchanged.',
                    promotion: $promotion,
                    requesterId: $resolution->actor->id,
                    writes: 1,
                );
            });
        } catch (UniqueConstraintViolationException) {
            // Outro pedido para a mesma candidate venceu a corrida: o resultado
            // correto é reportar o pedido existente, nunca criar um segundo.
            return $this->fromPlan($this->plans->plan($emission, $candidate));
        }
    }

    private function actorAction(?string $failure): string
    {
        return match ($failure) {
            PuCurvePromotionActorService::ACTOR_NOT_FOUND => self::ACTION_ACTOR_NOT_FOUND,
            PuCurvePromotionActorService::ACTOR_INACTIVE => self::ACTION_ACTOR_INACTIVE,
            PuCurvePromotionActorService::ACTOR_UNAPPROVED => self::ACTION_ACTOR_UNAPPROVED,
            PuCurvePromotionActorService::ACTOR_UNAUTHORIZED => self::ACTION_ACTOR_UNAUTHORIZED,
            default => self::ACTION_ACTOR_REQUIRED,
        };
    }

    /**
     * `curve_role` chega castado para {@see PuCurveRole} pelo model, mas
     * `PuCurvePromotionResult` é um read-model escalar -- consumido por command,
     * saída de CLI e teste. A boundary correta é o backing value explícito do
     * enum, nunca o objeto: é o mesmo contrato já adotado por
     * `PuCandidateCurveReviewService`.
     */
    private function candidateRole(?int $candidateVersionId): ?string
    {
        if ($candidateVersionId === null) {
            return null;
        }

        $role = EmissionPuCurveVersion::query()
            ->whereKey($candidateVersionId)
            ->value('curve_role');

        return $role instanceof PuCurveRole ? $role->value : null;
    }

    private function fromPlan(
        PuCurvePromotionPlan $plan,
        ?string $action = null,
        ?string $reason = null,
        ?EmissionPuCurvePromotion $promotion = null,
        ?int $requesterId = null,
        int $writes = 0,
    ): PuCurvePromotionResult {
        return new PuCurvePromotionResult(
            action: $action ?? $plan->action,
            reason: $reason ?? $plan->reason,
            plan: $plan,
            promotionId: $promotion?->id ?? $plan->promotionId,
            promotionStatus: $promotion?->status?->value ?? $plan->promotionStatus,
            candidateVersionId: $plan->candidateVersionId,
            calculationVersion: $plan->calculationVersion,
            curveRole: $this->candidateRole($plan->candidateVersionId),
            previousOperationalVersionId: $plan->currentOperationalVersionId,
            externalValidationId: $plan->externalValidationId,
            requesterId: $requesterId ?? $promotion?->requested_by,
            writes: $writes,
        );
    }
}

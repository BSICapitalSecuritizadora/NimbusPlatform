<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuCurvePromotionResult;
use App\Domain\PuCalculator\Enums\PuCurvePromotionStatus;
use App\Domain\PuCalculator\Enums\PuCurveRole;
use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Models\Emission;
use App\Models\EmissionPuCurvePromotion;
use App\Models\EmissionPuCurveVersion;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Única classe autorizada a efetuar o switch operacional da curva de PU.
 *
 * A promoção é uma transição de papel na MESMA versão: a candidate validada
 * externamente passa a `curve_role = operational` e a operacional vigente
 * anterior passa a `obsolete`. Nada é recalculado, nenhuma linha diária é
 * reescrita ou duplicada, e a identidade financeira do artefato
 * (`calculation_version`, `curve_checksum`, `input_fingerprint`, `rows_count`,
 * valores das linhas) permanece bit a bit a mesma. É por isso que esta fase não
 * precisa de engine, de BCB, de IndexRate, de evento ou de parâmetro.
 *
 * Efeito colateral conhecido e deliberadamente não reaberto nesta fase: depois
 * da troca de papel, `EmissionPuDailyCurve::belongsToCandidateVersion()` passa a
 * devolver `false` para as linhas promovidas, então elas voltam a ter exatamente
 * o mesmo lifecycle das linhas operacionais históricas -- que a arquitetura
 * anterior já tratava como mutáveis. Resolver a imutabilidade operacional
 * inteira é outro escopo; aqui o efeito é apenas documentado.
 *
 * A classe é aberta para extensão exclusivamente para permitir o cenário de
 * teste que força uma falha ENTRE a obsolescência da operacional anterior e a
 * ativação da candidate, provando que a transação reverte o switch por inteiro.
 */
class PuCurveOperationalPromotionService
{
    public const ACTION_PROMOTED = 'promotion_executed';

    public const ACTION_ALREADY_EXECUTED = 'promotion_already_executed';

    public const ACTION_NOT_FOUND = 'promotion_not_found';

    public const ACTION_NOT_APPROVED = 'promotion_not_approved';

    public const ACTION_REJECTED = 'promotion_rejected';

    public const ACTION_READY = 'ready_to_execute';

    public const ACTION_INTEGRITY_FAILURE = 'promotion_integrity_failure';

    public const ACTION_STATE_CHANGED = 'promotion_state_changed';

    public const ACTION_EXECUTOR_REQUIRED = 'promotion_executor_required';

    public const ACTION_EXECUTOR_NOT_FOUND = 'promotion_executor_not_found';

    public const ACTION_EXECUTOR_INACTIVE = 'promotion_executor_inactive';

    public const ACTION_EXECUTOR_UNAPPROVED = 'promotion_executor_unapproved';

    public const ACTION_EXECUTOR_UNAUTHORIZED = 'promotion_executor_unauthorized';

    public const OBSOLETE_REASON = 'superseded_by_promotion';

    public function __construct(
        private readonly PuCurvePromotionEligibilityService $eligibility,
        private readonly PuCurvePromotionActorService $actors,
        private readonly PuAuditLogService $auditLog,
    ) {}

    /**
     * Preflight read-only. Tudo o que pode ser verificado fora da transação é
     * verificado aqui, para que a transação do switch seja curta.
     */
    public function inspect(
        ?EmissionPuCurvePromotion $promotion,
        ?string $executorIdentifier = null,
    ): PuCurvePromotionResult {
        if (! $promotion instanceof EmissionPuCurvePromotion) {
            return new PuCurvePromotionResult(
                action: self::ACTION_NOT_FOUND,
                reason: 'The promotion dossier does not exist.',
            );
        }

        if ($promotion->status === PuCurvePromotionStatus::Executed) {
            return $this->result(
                $promotion,
                self::ACTION_ALREADY_EXECUTED,
                'This promotion was already executed; no write will be repeated.',
                newOperationalVersionId: $promotion->candidate_curve_version_id,
            );
        }

        if ($promotion->status === PuCurvePromotionStatus::Rejected) {
            return $this->result(
                $promotion,
                self::ACTION_REJECTED,
                'A rejected promotion can never be executed.',
            );
        }

        if (! ($promotion->status?->isExecutable() ?? false)) {
            return $this->result(
                $promotion,
                self::ACTION_NOT_APPROVED,
                'Only an approved promotion can be executed; the request is still pending independent review.',
            );
        }

        $candidate = $promotion->relationLoaded('candidate')
            ? $promotion->candidate
            : $promotion->candidate()->first();
        $eligibility = $this->eligibility->inspect($candidate);

        if (! $eligibility['ready'] || ! $candidate instanceof EmissionPuCurveVersion) {
            return $this->result(
                $promotion,
                $eligibility['action'] === PuCurvePromotionEligibilityService::ACTION_INTEGRITY_FAILURE
                    ? self::ACTION_INTEGRITY_FAILURE
                    : self::ACTION_STATE_CHANGED,
                $eligibility['reason'],
            );
        }

        $mismatch = $this->capturedIdentityMismatch($promotion, $eligibility);

        if ($mismatch !== null) {
            return $this->result($promotion, self::ACTION_INTEGRITY_FAILURE, $mismatch);
        }

        $baselineFailure = $this->baselineFailure($promotion, $candidate);

        if ($baselineFailure !== null) {
            return $this->result($promotion, self::ACTION_STATE_CHANGED, $baselineFailure);
        }

        if (trim((string) $executorIdentifier) !== '') {
            $resolution = $this->actors->resolve($executorIdentifier);

            if (! $resolution->resolved() || $resolution->actor === null) {
                return $this->result(
                    $promotion,
                    $this->executorAction($resolution->failure),
                    $resolution->reason ?? 'An explicit authorized promotion executor is required.',
                );
            }

            return $this->result(
                $promotion,
                self::ACTION_READY,
                'The approved promotion, its dossier and the operational baseline are intact; the switch can be executed.',
                executorId: $resolution->actor->id,
            );
        }

        return $this->result(
            $promotion,
            self::ACTION_READY,
            'The approved promotion, its dossier and the operational baseline are intact; the switch can be executed.',
        );
    }

    /**
     * Executa o switch operacional atômico.
     *
     * Ordem de locks, única e deliberada: Emission -> Promotion -> Candidate ->
     * versão operacional vigente. A Emission vem primeiro porque a 2B.5.16 já a
     * usa como serializador do namespace de versões, então duas promoções
     * concorrentes da mesma emissão nunca conseguem trocar a curva ao mesmo
     * tempo.
     */
    public function write(
        ?EmissionPuCurvePromotion $promotion,
        ?string $executorIdentifier,
    ): PuCurvePromotionResult {
        if ($promotion instanceof EmissionPuCurvePromotion) {
            $promotion = $promotion->fresh() ?? $promotion;
        }

        $preflight = $this->inspect($promotion);

        if (! $promotion instanceof EmissionPuCurvePromotion
            || $preflight->action !== self::ACTION_READY) {
            return $preflight;
        }

        if (trim((string) $executorIdentifier) === '') {
            return $this->result(
                $promotion,
                self::ACTION_EXECUTOR_REQUIRED,
                'An explicit authorized promotion executor is required for writes.',
            );
        }

        return DB::transaction(function () use ($promotion, $executorIdentifier): PuCurvePromotionResult {
            Emission::query()->whereKey($promotion->emission_id)->lockForUpdate()->firstOrFail();
            $lockedPromotion = EmissionPuCurvePromotion::query()
                ->whereKey($promotion->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedPromotion instanceof EmissionPuCurvePromotion) {
                return new PuCurvePromotionResult(
                    action: self::ACTION_NOT_FOUND,
                    reason: 'The promotion dossier no longer exists.',
                );
            }

            $lockedCandidate = EmissionPuCurveVersion::query()
                ->whereKey($lockedPromotion->candidate_curve_version_id)
                ->lockForUpdate()
                ->first();
            $lockedPrevious = $lockedPromotion->previous_operational_curve_version_id === null
                ? null
                : EmissionPuCurveVersion::query()
                    ->whereKey($lockedPromotion->previous_operational_curve_version_id)
                    ->lockForUpdate()
                    ->first();
            $lockedPromotion->setRelation('candidate', $lockedCandidate);
            $lockedPreflight = $this->inspect($lockedPromotion);

            if ($lockedPreflight->action !== self::ACTION_READY
                || ! $lockedCandidate instanceof EmissionPuCurveVersion) {
                return $lockedPreflight;
            }

            $resolution = $this->actors->resolve($executorIdentifier, lockForUpdate: true);

            if (! $resolution->resolved() || $resolution->actor === null) {
                return $this->result(
                    $lockedPromotion,
                    $this->executorAction($resolution->failure),
                    $resolution->reason ?? 'An explicit authorized promotion executor is required.',
                );
            }

            if ($lockedPrevious instanceof EmissionPuCurveVersion
                && $lockedPrevious->id === $lockedCandidate->id) {
                throw new LogicException('A promoted candidate can never supersede itself.');
            }

            $this->supersedePreviousOperational($lockedPrevious);
            $this->activateCandidate($lockedCandidate);

            $lockedPromotion->forceFill([
                'status' => PuCurvePromotionStatus::Executed,
                'executed_by' => $resolution->actor->id,
                'promoted_at' => now(),
            ])->save();
            $this->auditLog->logCurvePromotionExecuted($lockedPromotion, $resolution->actor);

            $promoted = $lockedCandidate->fresh() ?? $lockedCandidate;

            return $this->result(
                $lockedPromotion,
                self::ACTION_PROMOTED,
                'The externally validated candidate is now the operational curve; the previous operational version was superseded.',
                curveRole: $promoted->curve_role?->value,
                newOperationalVersionId: $promoted->id,
                executorId: $resolution->actor->id,
                writes: $lockedPrevious instanceof EmissionPuCurveVersion ? 3 : 2,
            );
        });
    }

    /**
     * Obsoleta EXATAMENTE a versão operacional capturada no pedido.
     *
     * `PuCurveVersionService::markPreviousVersionsObsolete()` foi auditado e
     * deliberadamente NÃO é reutilizado aqui: ele opera por emissão inteira e
     * pula versões `homologated`, então promover sobre uma operacional
     * homologada deixaria duas versões vivas, e uma candidate irmã poderia ser
     * atingida por engano. A promoção toca uma única linha, a que o dossiê
     * nomeia.
     */
    protected function supersedePreviousOperational(?EmissionPuCurveVersion $previous): void
    {
        if (! $previous instanceof EmissionPuCurveVersion) {
            return;
        }

        $previous->forceFill([
            'status' => PuCurveStatus::Obsolete,
            'obsolete_reason' => self::OBSOLETE_REASON,
        ])->save();
    }

    /**
     * Troca de papel da candidate. O lifecycle (`status`) NÃO é alterado:
     * `curve_role` é a dimensão oficial de "operacional" e `homologated` continua
     * sendo lifecycle de conteúdo, nunca sinônimo de vigência.
     */
    protected function activateCandidate(EmissionPuCurveVersion $candidate): void
    {
        $candidate->forceFill(['curve_role' => PuCurveRole::Operational])->save();
    }

    /**
     * O baseline operacional capturado no pedido tem de continuar sendo o
     * vigente, e a candidate tem de ser estritamente mais nova que ele.
     *
     * A segunda regra não é decorativa: os consumidores operacionais elegem a
     * curva vigente por `MAX(id)` entre as versões `operational`
     * (`Emission::latestPuCurveVersion()`) e por `ORDER BY id DESC` entre as
     * linhas diárias operacionais (`latestCalculationVersionForEmission()`).
     * Promover uma candidate mais antiga que a operacional vigente deixaria a
     * seleção operacional apontando para a versão substituída. Uma candidate
     * mais antiga que a operacional vigente também é, por construção, uma
     * candidate calculada sobre inputs anteriores -- recusá-la é governança, não
     * contorno técnico.
     */
    private function baselineFailure(
        EmissionPuCurvePromotion $promotion,
        EmissionPuCurveVersion $candidate,
    ): ?string {
        $emission = $promotion->relationLoaded('emission')
            ? $promotion->emission
            : $promotion->emission()->first();

        if (! $emission instanceof Emission) {
            return 'The emission of this promotion no longer exists.';
        }

        $current = $emission->latestPuCurveVersion()->first();

        if (($current?->id) !== $promotion->previous_operational_curve_version_id) {
            return 'The operational baseline changed after the promotion request; the switch was not executed.';
        }

        if ($current instanceof EmissionPuCurveVersion && $candidate->id <= $current->id) {
            return 'The candidate is older than the operational version it would replace; operational selection would stay on the superseded version.';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $eligibility
     */
    private function capturedIdentityMismatch(EmissionPuCurvePromotion $promotion, array $eligibility): ?string
    {
        if (! hash_equals($promotion->candidate_checksum, (string) $eligibility['candidateChecksum'])) {
            return 'The candidate checksum captured in the promotion request no longer matches the candidate dossier.';
        }

        if (! hash_equals($promotion->input_fingerprint, (string) $eligibility['inputFingerprint'])) {
            return 'The input fingerprint captured in the promotion request no longer matches the candidate dossier.';
        }

        if ($promotion->rows_count !== $eligibility['rowsCount']) {
            return 'The row count captured in the promotion request no longer matches the candidate dossier.';
        }

        if ($promotion->external_validation_id !== $eligibility['externalValidation']?->id) {
            return 'The external validation captured in the promotion request is no longer the validated dossier of this candidate.';
        }

        if (! hash_equals($promotion->benchmark_dataset_sha256, (string) $eligibility['benchmarkChecksum'])) {
            return 'The benchmark dataset checksum captured in the promotion request no longer matches the immutable benchmark.';
        }

        if (! hash_equals($promotion->comparison_sha256, (string) $eligibility['comparisonChecksum'])) {
            return 'The comparison checksum captured in the promotion request no longer matches the persisted comparison.';
        }

        return null;
    }

    private function executorAction(?string $failure): string
    {
        return match ($failure) {
            PuCurvePromotionActorService::ACTOR_NOT_FOUND => self::ACTION_EXECUTOR_NOT_FOUND,
            PuCurvePromotionActorService::ACTOR_INACTIVE => self::ACTION_EXECUTOR_INACTIVE,
            PuCurvePromotionActorService::ACTOR_UNAPPROVED => self::ACTION_EXECUTOR_UNAPPROVED,
            PuCurvePromotionActorService::ACTOR_UNAUTHORIZED => self::ACTION_EXECUTOR_UNAUTHORIZED,
            default => self::ACTION_EXECUTOR_REQUIRED,
        };
    }

    private function result(
        EmissionPuCurvePromotion $promotion,
        string $action,
        string $reason,
        ?string $curveRole = null,
        ?int $newOperationalVersionId = null,
        ?int $executorId = null,
        int $writes = 0,
    ): PuCurvePromotionResult {
        return new PuCurvePromotionResult(
            action: $action,
            reason: $reason,
            promotionId: $promotion->id,
            promotionStatus: $promotion->status?->value,
            candidateVersionId: $promotion->candidate_curve_version_id,
            calculationVersion: $promotion->calculation_version,
            curveRole: $curveRole,
            previousOperationalVersionId: $promotion->previous_operational_curve_version_id,
            newOperationalVersionId: $newOperationalVersionId,
            externalValidationId: $promotion->external_validation_id,
            requesterId: $promotion->requested_by,
            reviewerId: $promotion->reviewed_by,
            executorId: $executorId ?? $promotion->executed_by,
            writes: $writes,
        );
    }
}

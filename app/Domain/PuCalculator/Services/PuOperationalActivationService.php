<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuOperationalReadinessReport;
use App\Domain\PuCalculator\Enums\PuOperationalActivationStatus;
use App\Domain\PuCalculator\Exceptions\PuOperationalActivationException;
use App\Enums\AccessPermission;
use App\Models\Emission;
use App\Models\EmissionPuOperationalStatusChange;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Ativação e suspensão da operação do PU de uma emissão (Fase 7).
 *
 * Ativar é um ato próprio, autorizado e auditado -- não é homologar, e homologar
 * não ativa. Exige a autoridade `pu.operations.activate` (que não vem de
 * configurar parâmetros, gerar, validar, homologar nem liquidar), um usuário
 * operacional, o interruptor global ligado, uma avaliação de prontidão sem
 * bloqueio ({@see PuOperationalReadinessService}), o motivo e a referência da
 * aprovação documentada (a revisão contratual e a autorização do negócio, que o
 * sistema não tem como inferir). A avaliação usada fica gravada na decisão.
 *
 * Suspender exige a mesma autoridade e o motivo, e vale com o interruptor global
 * em qualquer posição: parar é sempre possível. A suspensão não toca curva,
 * obrigação nem liquidação -- só impede o que viria depois (gerar, estender,
 * homologar).
 *
 * As duas decisões travam a emissão primeiro (a mesma ordem de toda escrita
 * governada do PU) e releem a situação sob trava: duas ativações simultâneas
 * gravam uma decisão só, e a segunda é recusada.
 */
final class PuOperationalActivationService
{
    public function __construct(
        private readonly PuOperationalActivationPolicy $policy,
        private readonly PuOperationalReadinessService $readiness,
        private readonly PuAuditLogService $auditLog,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws PuOperationalActivationException
     */
    public function activate(
        Emission $emission,
        User $actor,
        string $reason,
        string $approvalReference,
        string $via = 'console',
    ): EmissionPuOperationalStatusChange {
        $this->authorize($actor, (int) $emission->getKey());
        $reason = $this->required($reason, 'o motivo da ativação', (int) $emission->getKey());
        $approvalReference = $this->required($approvalReference, 'a referência da aprovação documentada (revisão contratual e autorização do negócio)', (int) $emission->getKey());

        if (($current = $this->policy->status($emission)) === PuOperationalActivationStatus::Activated) {
            throw $this->invalidTransition((int) $emission->getKey(), $current, PuOperationalActivationStatus::Activated);
        }

        if (! $this->policy->globallyEnabled()) {
            throw new PuOperationalActivationException(
                reasonCode: PuOperationalActivationException::REASON_GLOBALLY_DISABLED,
                emissionId: (int) $emission->getKey(),
                status: null,
                message: sprintf(
                    '%s: as operações do PU estão desligadas neste ambiente (PU_OPERATIONS_ENABLED); nenhuma emissão pode ser ativada nele.',
                    PuOperationalActivationException::REASON_GLOBALLY_DISABLED,
                ),
            );
        }

        $report = $this->readiness->assess($emission);

        if (! $report->status->allowsActivation()) {
            throw new PuOperationalActivationException(
                reasonCode: PuOperationalActivationException::REASON_NOT_READY,
                emissionId: (int) $emission->getKey(),
                status: $report->activationStatus,
                message: sprintf(
                    '%s: a emissão #%d está %s e não pode ser ativada. %s',
                    PuOperationalActivationException::REASON_NOT_READY,
                    (int) $emission->getKey(),
                    mb_strtolower($report->status->label()),
                    implode(' ', $report->blockers()),
                ),
            );
        }

        return $this->record($emission, $actor, PuOperationalActivationStatus::Activated, $reason, $approvalReference, $report, $via);
    }

    /**
     * @throws AuthorizationException
     * @throws PuOperationalActivationException
     */
    public function suspend(Emission $emission, User $actor, string $reason, string $via = 'console'): EmissionPuOperationalStatusChange
    {
        $this->authorize($actor, (int) $emission->getKey());
        $reason = $this->required($reason, 'o motivo da suspensão', (int) $emission->getKey());

        return $this->record($emission, $actor, PuOperationalActivationStatus::Suspended, $reason, null, null, $via);
    }

    private function record(
        Emission $emission,
        User $actor,
        PuOperationalActivationStatus $to,
        string $reason,
        ?string $approvalReference,
        ?PuOperationalReadinessReport $report,
        string $via,
    ): EmissionPuOperationalStatusChange {
        return DB::transaction(function () use ($emission, $actor, $to, $reason, $approvalReference, $report, $via): EmissionPuOperationalStatusChange {
            $locked = Emission::query()->whereKey($emission->getKey())->lockForUpdate()->firstOrFail();
            $from = $this->policy->status($locked, lockForShare: true);

            $allowed = $to === PuOperationalActivationStatus::Activated
                ? in_array($from, [PuOperationalActivationStatus::Inactive, PuOperationalActivationStatus::Suspended], true)
                : $from === PuOperationalActivationStatus::Activated;

            if (! $allowed) {
                throw $this->invalidTransition((int) $locked->getKey(), $from, $to);
            }

            $change = EmissionPuOperationalStatusChange::query()->create([
                'emission_id' => $locked->getKey(),
                'status' => $to,
                'previous_status' => $from,
                'reason' => $reason,
                'approval_reference' => $approvalReference,
                'readiness_status' => $report?->status->value,
                'readiness_snapshot' => $report?->toArray(),
                'decided_by' => $actor->getKey(),
                'decided_via' => $via,
                'decided_at' => now(),
            ]);

            $this->auditLog->logOperationalStatusChanged($locked, $change, $actor);

            return $change;
        });
    }

    private function invalidTransition(int $emissionId, PuOperationalActivationStatus $from, PuOperationalActivationStatus $to): PuOperationalActivationException
    {
        return new PuOperationalActivationException(
            reasonCode: PuOperationalActivationException::REASON_INVALID_TRANSITION,
            emissionId: $emissionId,
            status: $from,
            message: sprintf(
                '%s: a emissão #%d está "%s"; %s não se aplica. Nada foi gravado.',
                PuOperationalActivationException::REASON_INVALID_TRANSITION,
                $emissionId,
                mb_strtolower($from->label()),
                $to === PuOperationalActivationStatus::Activated ? 'ativar' : 'suspender',
            ),
        );
    }

    /**
     * @throws AuthorizationException
     */
    private function authorize(User $actor, int $emissionId): void
    {
        if (! $actor->isOperational()) {
            throw new AuthorizationException(sprintf('O usuário #%d não está ativo e não pode decidir a operação do PU da emissão #%d.', (int) $actor->getKey(), $emissionId));
        }

        if (! $actor->can(AccessPermission::PuOperationsActivate->value)) {
            throw new AuthorizationException(sprintf(
                'Ativar ou suspender a operação do PU exige a permissão %s (emissão #%d).',
                AccessPermission::PuOperationsActivate->value,
                $emissionId,
            ));
        }
    }

    private function required(string $value, string $what, int $emissionId): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new PuOperationalActivationException(
                reasonCode: PuOperationalActivationException::REASON_MISSING_JUSTIFICATION,
                emissionId: $emissionId,
                status: null,
                message: sprintf('%s: informe %s.', PuOperationalActivationException::REASON_MISSING_JUSTIFICATION, $what),
            );
        }

        return $value;
    }
}

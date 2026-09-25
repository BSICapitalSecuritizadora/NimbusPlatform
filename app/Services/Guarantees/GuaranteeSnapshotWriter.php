<?php

namespace App\Services\Guarantees;

use App\DTOs\Guarantees\EmissionGuaranteePositionData;
use App\DTOs\Guarantees\GuaranteePositionData;
use App\Enums\AccessPermission;
use App\Enums\GuaranteeValueSource;
use App\Enums\GuaranteeValueStatus;
use App\Models\Emission;
use App\Models\Guarantee;
use App\Models\GuaranteeMonthlyPosition;
use App\Models\GuaranteeSnapshot;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Grava a posição da competência (§23 do escopo).
 *
 * O motor apura; este serviço persiste. A separação importa porque a apuração
 * roda a cada abertura da aba, e a gravação só acontece quando alguém atualiza
 * ou fecha a competência — o histórico não pode mudar sozinho.
 *
 * Competência fechada é imutável: reabri-la exige permissão própria, motivo e
 * fica auditada, porque o número já saiu em relatório.
 *
 * A apuração que é gravada acontece dentro da transação, depois de travar o
 * snapshot existente: um Quadro de Vendas publicado no meio espera a gravação
 * e só então marca a competência como desatualizada, em vez de a gravação
 * apagar a marca com um número calculado antes do quadro.
 */
class GuaranteeSnapshotWriter
{
    public const LOG_NAME = 'guarantee_competences';

    public const EVENT_VALUE_UPDATED = 'guarantee_value_updated';

    public const EVENT_COMPETENCE_CLOSED = 'guarantee_competence_closed';

    public const EVENT_COMPETENCE_REOPENED = 'guarantee_competence_reopened';

    public function __construct(
        private readonly EmissionGuaranteeCoverageEngine $engine,
    ) {}

    /**
     * Recalcula e grava a competência sem fechá-la. Sem mês, vale o mês corrente
     * do calendário de negócio.
     */
    public function persist(Emission $emission, ?string $referenceMonth = null, ?User $actor = null): GuaranteeSnapshot
    {
        $referenceMonth = $this->resolveCompetence($referenceMonth ?? GuaranteeSnapshot::currentBusinessMonth());

        return DB::transaction(function () use ($emission, $referenceMonth, $actor): GuaranteeSnapshot {
            $this->assertOpen($emission, $referenceMonth);

            $position = $this->engine->buildPosition($this->freshEmission($emission), $referenceMonth);

            $this->persistPositions($emission, $position, $actor);

            return $this->persistSnapshot($emission, $position, $actor);
        });
    }

    /**
     * Registra o valor de uma garantia cuja fonte é manual (§22).
     *
     * Só garantias sem fonte automática aceitam digitação: deixar alguém
     * sobrescrever um saldo lido da conta vinculada criaria um número sem
     * origem rastreável.
     */
    public function recordManualValue(
        Guarantee $guarantee,
        string $referenceMonth,
        ?float $value,
        User $actor,
    ): GuaranteeMonthlyPosition {
        $referenceMonth = GuaranteeSnapshot::normalizeReferenceMonth($referenceMonth);

        if ($referenceMonth === null) {
            throw ValidationException::withMessages([
                'reference_month' => 'Informe a competência no formato MM/AAAA.',
            ]);
        }

        $emission = $guarantee->emission;

        return DB::transaction(function () use ($guarantee, $emission, $referenceMonth, $value, $actor): GuaranteeMonthlyPosition {
            $this->assertOpen($emission, $referenceMonth);

            $previous = $guarantee->monthlyPositions()
                ->whereDate('reference_month', $referenceMonth)
                ->first();

            /** @var GuaranteeMonthlyPosition $position */
            $position = $guarantee->monthlyPositions()->updateOrCreate(
                ['reference_month' => $referenceMonth],
                [
                    'emission_id' => $emission->getKey(),
                    'current_value' => $value,
                    'value_source' => GuaranteeValueSource::Manual,
                    'value_status' => $value === null ? GuaranteeValueStatus::Pending : GuaranteeValueStatus::Manual,
                    'computed_at' => now(),
                    'updated_by' => $actor->getKey(),
                ],
            );

            activity(self::LOG_NAME)
                ->causedBy($actor)
                ->performedOn($guarantee)
                ->event(self::EVENT_VALUE_UPDATED)
                ->withProperties([
                    'emission_id' => $emission->getKey(),
                    'guarantee_id' => $guarantee->getKey(),
                    'reference_month' => $referenceMonth,
                    'old_value' => $previous?->current_value === null ? null : (float) $previous->current_value,
                    'new_value' => $value,
                ])
                ->log('Valor da garantia atualizado na competência');

            return $position;
        });
    }

    /**
     * Fecha a competência: consolida a posição e a torna imutável.
     *
     * Quando algum empreendimento das garantias de estoque está sem o quadro do
     * próprio mês (posição transportada ou ausente), o fechamento só acontece
     * com `$acknowledgedSalesBoardGaps` igual às lacunas que o servidor encontra
     * agora ({@see EmissionGuaranteePositionData::salesBoardGapsFingerprint()}).
     * A confirmação — quem, quando, quais empreendimentos e meses — fica gravada
     * no snapshot.
     */
    public function close(
        Emission $emission,
        string $referenceMonth,
        User $actor,
        ?string $acknowledgedSalesBoardGaps = null,
    ): GuaranteeSnapshot {
        $referenceMonth = $this->resolveCompetence($referenceMonth);

        return DB::transaction(function () use ($emission, $referenceMonth, $actor, $acknowledgedSalesBoardGaps): GuaranteeSnapshot {
            $this->assertOpen($emission, $referenceMonth);

            $position = $this->engine->buildPosition($this->freshEmission($emission), $referenceMonth);

            $this->assertPartialCoverageAcknowledged($position, $acknowledgedSalesBoardGaps);

            $this->persistPositions($emission, $position, $actor);

            $snapshot = $this->persistSnapshot($emission, $position, $actor);

            $confirmation = $position->hasSalesBoardGaps()
                ? ['gaps' => $position->salesBoardCoverage->gaps()]
                : null;

            $snapshot->forceFill([
                'closed_at' => now(),
                'closed_by' => $actor->getKey(),
                'partial_coverage_confirmation' => $confirmation,
                'partial_coverage_confirmed_at' => $confirmation === null ? null : now(),
                'partial_coverage_confirmed_by' => $confirmation === null ? null : $actor->getKey(),
            ])->save();

            activity(self::LOG_NAME)
                ->causedBy($actor)
                ->performedOn($snapshot)
                ->event(self::EVENT_COMPETENCE_CLOSED)
                ->withProperties([
                    'emission_id' => $emission->getKey(),
                    'reference_month' => $position->referenceMonth,
                    'coverage_ratio' => $position->coverageRatio,
                    'coverage_status' => $position->coverageStatus->value,
                    'total_eligible_value' => $position->totalEligibleValue,
                    'sales_board_coverage' => $position->salesBoardCoverage?->toArray(),
                    'partial_coverage_confirmation' => $confirmation,
                ])
                ->log('Competência de garantias fechada');

            return $snapshot->refresh();
        });
    }

    /**
     * Reabre uma competência fechada. Exige a permissão própria e um motivo:
     * desfazer um fechamento reescreve indicador que já saiu em relatório.
     *
     * A confirmação de posição parcial pertence ao fechamento desfeito e sai com
     * ele — continua na auditoria do fechamento. A marca de desatualização
     * permanece até a competência ser apurada de novo.
     */
    public function reopen(Emission $emission, string $referenceMonth, User $actor, string $reason): GuaranteeSnapshot
    {
        if (! $actor->can(AccessPermission::GuaranteesReopenCompetence->value)) {
            throw new AuthorizationException('Você não possui permissão para reabrir competências de garantias.');
        }

        $referenceMonth = $this->resolveCompetence($referenceMonth);
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'Informe o motivo da reabertura.',
            ]);
        }

        return DB::transaction(function () use ($emission, $referenceMonth, $actor, $reason): GuaranteeSnapshot {
            /** @var GuaranteeSnapshot|null $snapshot */
            $snapshot = $emission->guaranteeSnapshots()
                ->whereDate('reference_month', $referenceMonth)
                ->lockForUpdate()
                ->first();

            if ($snapshot === null || ! $snapshot->isClosed()) {
                throw ValidationException::withMessages([
                    'reference_month' => 'Esta competência não está fechada.',
                ]);
            }

            $previouslyClosedAt = $snapshot->closed_at?->toIso8601String();
            $previouslyClosedBy = $snapshot->closed_by;

            $snapshot->forceFill([
                'closed_at' => null,
                'closed_by' => null,
                'partial_coverage_confirmation' => null,
                'partial_coverage_confirmed_at' => null,
                'partial_coverage_confirmed_by' => null,
            ])->save();

            activity(self::LOG_NAME)
                ->causedBy($actor)
                ->performedOn($snapshot)
                ->event(self::EVENT_COMPETENCE_REOPENED)
                ->withProperties([
                    'emission_id' => $emission->getKey(),
                    'reference_month' => $referenceMonth,
                    'reason' => $reason,
                    'previously_closed_at' => $previouslyClosedAt,
                    'previously_closed_by' => $previouslyClosedBy,
                    'sales_board_outdated_at' => $snapshot->sales_board_outdated_at?->toIso8601String(),
                ])
                ->log('Competência de garantias reaberta');

            return $snapshot->refresh();
        });
    }

    /**
     * Competência válida e já iniciada no calendário de negócio. Um texto que
     * não é mês nunca vira "o mês corrente" por omissão, e um mês que ainda não
     * começou não tem posição a gravar — fechá-lo o tornaria imutável antes de
     * existir.
     */
    private function resolveCompetence(string $referenceMonth): string
    {
        $normalized = GuaranteeSnapshot::normalizeReferenceMonth($referenceMonth);

        if ($normalized === null) {
            throw ValidationException::withMessages([
                'reference_month' => 'Informe a competência no formato MM/AAAA.',
            ]);
        }

        if ($normalized > GuaranteeSnapshot::currentBusinessMonth()) {
            throw ValidationException::withMessages([
                'reference_month' => sprintf(
                    'A competência %s ainda não começou.',
                    GuaranteeSnapshot::formatReferenceMonthForDisplay($normalized),
                ),
            ]);
        }

        return $normalized;
    }

    /**
     * A emissão relida do banco, sem relações carregadas por quem chamou: a
     * apuração gravada tem de ver os quadros e recebíveis de agora, não os que
     * a tela carregou ao abrir.
     */
    private function freshEmission(Emission $emission): Emission
    {
        return $emission->fresh() ?? $emission;
    }

    private function assertPartialCoverageAcknowledged(
        EmissionGuaranteePositionData $position,
        ?string $acknowledgedSalesBoardGaps,
    ): void {
        if (! $position->hasSalesBoardGaps()) {
            return;
        }

        $label = $position->referenceMonthLabel();
        $gaps = implode('; ', $position->salesBoardGapDescriptions());

        if (blank($acknowledgedSalesBoardGaps)) {
            throw ValidationException::withMessages([
                'confirm_partial_coverage' => sprintf(
                    'A posição do Quadro de Vendas de %s está incompleta (%s). Confirme o fechamento com a posição parcial.',
                    $label,
                    $gaps,
                ),
            ]);
        }

        if ($acknowledgedSalesBoardGaps !== $position->salesBoardGapsFingerprint()) {
            throw ValidationException::withMessages([
                'confirm_partial_coverage' => sprintf(
                    'A posição do Quadro de Vendas de %s mudou desde a confirmação (%s). Revise e confirme novamente.',
                    $label,
                    $gaps,
                ),
            ]);
        }
    }

    /**
     * Trava o snapshot existente até o fim da transação e recusa competência
     * fechada.
     *
     * @return GuaranteeSnapshot|null o snapshot existente, quando houver
     */
    private function assertOpen(Emission $emission, string $referenceMonth): ?GuaranteeSnapshot
    {
        /** @var GuaranteeSnapshot|null $snapshot */
        $snapshot = $emission->guaranteeSnapshots()
            ->whereDate('reference_month', $referenceMonth)
            ->lockForUpdate()
            ->first();

        if ($snapshot?->isClosed()) {
            throw ValidationException::withMessages([
                'reference_month' => 'Esta competência está fechada. Reabra-a antes de alterar os valores.',
            ]);
        }

        return $snapshot;
    }

    private function persistPositions(
        Emission $emission,
        EmissionGuaranteePositionData $position,
        ?User $actor,
    ): void {
        foreach ($position->positions as $guaranteePosition) {
            /** @var GuaranteePositionData $guaranteePosition */
            $guaranteePosition->guarantee->monthlyPositions()->updateOrCreate(
                ['reference_month' => $position->referenceMonth],
                array_merge($guaranteePosition->toSnapshotAttributes(), [
                    'emission_id' => $emission->getKey(),
                    'outstanding_balance' => $position->outstandingBalance,
                    'computed_at' => now(),
                    'updated_by' => $actor?->getKey(),
                ]),
            );
        }
    }

    /**
     * Grava a apuração. Apurar de novo é o que tira a marca de desatualizado:
     * o número passa a refletir os quadros de agora.
     */
    private function persistSnapshot(
        Emission $emission,
        EmissionGuaranteePositionData $position,
        ?User $actor,
    ): GuaranteeSnapshot {
        /** @var GuaranteeSnapshot $snapshot */
        $snapshot = $emission->guaranteeSnapshots()->updateOrCreate(
            ['reference_month' => $position->referenceMonth],
            array_merge($position->toSnapshotAttributes(), [
                'sales_board_outdated_at' => null,
                'computed_at' => now(),
                'updated_by' => $actor?->getKey(),
            ]),
        );

        return $snapshot;
    }
}

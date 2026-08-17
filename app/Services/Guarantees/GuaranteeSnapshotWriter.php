<?php

namespace App\Services\Guarantees;

use App\DTOs\Guarantees\EmissionGuaranteePositionData;
use App\DTOs\Guarantees\GuaranteePositionData;
use App\Enums\GuaranteeValueSource;
use App\Enums\GuaranteeValueStatus;
use App\Models\Emission;
use App\Models\Guarantee;
use App\Models\GuaranteeMonthlyPosition;
use App\Models\GuaranteeSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Grava a posição da competência (§23 do escopo).
 *
 * O motor apura; este serviço persiste. A separação importa porque a apuração
 * roda a cada abertura da aba, e a gravação só acontece quando alguém atualiza
 * ou fecha a competência — o histórico não pode mudar sozinho.
 *
 * Competência fechada é imutável: reabri-la exige permissão própria e fica
 * auditada, porque o número já saiu em relatório.
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
     * Recalcula e grava a competência sem fechá-la.
     */
    public function persist(Emission $emission, ?string $referenceMonth = null, ?User $actor = null): GuaranteeSnapshot
    {
        $position = $this->engine->buildPosition($emission, $referenceMonth);

        return DB::transaction(function () use ($emission, $position, $actor): GuaranteeSnapshot {
            $snapshot = $this->assertOpen($emission, $position->referenceMonth);

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
        $this->assertOpen($emission, $referenceMonth);

        return DB::transaction(function () use ($guarantee, $emission, $referenceMonth, $value, $actor): GuaranteeMonthlyPosition {
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
     */
    public function close(Emission $emission, string $referenceMonth, User $actor): GuaranteeSnapshot
    {
        $position = $this->engine->buildPosition($emission, $referenceMonth);

        return DB::transaction(function () use ($emission, $position, $actor): GuaranteeSnapshot {
            $this->assertOpen($emission, $position->referenceMonth);

            $this->persistPositions($emission, $position, $actor);

            $snapshot = $this->persistSnapshot($emission, $position, $actor);

            $snapshot->forceFill([
                'closed_at' => now(),
                'closed_by' => $actor->getKey(),
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
                ])
                ->log('Competência de garantias fechada');

            return $snapshot->refresh();
        });
    }

    public function reopen(Emission $emission, string $referenceMonth, User $actor, ?string $reason = null): GuaranteeSnapshot
    {
        $referenceMonth = GuaranteeSnapshot::normalizeReferenceMonth($referenceMonth) ?? $referenceMonth;

        /** @var GuaranteeSnapshot|null $snapshot */
        $snapshot = $emission->guaranteeSnapshots()
            ->whereDate('reference_month', $referenceMonth)
            ->first();

        if ($snapshot === null || ! $snapshot->isClosed()) {
            throw ValidationException::withMessages([
                'reference_month' => 'Esta competência não está fechada.',
            ]);
        }

        $snapshot->forceFill(['closed_at' => null, 'closed_by' => null])->save();

        activity(self::LOG_NAME)
            ->causedBy($actor)
            ->performedOn($snapshot)
            ->event(self::EVENT_COMPETENCE_REOPENED)
            ->withProperties([
                'emission_id' => $emission->getKey(),
                'reference_month' => $referenceMonth,
                'reason' => $reason,
            ])
            ->log('Competência de garantias reaberta');

        return $snapshot->refresh();
    }

    /**
     * @return GuaranteeSnapshot|null o snapshot existente, quando houver
     */
    private function assertOpen(Emission $emission, string $referenceMonth): ?GuaranteeSnapshot
    {
        /** @var GuaranteeSnapshot|null $snapshot */
        $snapshot = $emission->guaranteeSnapshots()
            ->whereDate('reference_month', $referenceMonth)
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

    private function persistSnapshot(
        Emission $emission,
        EmissionGuaranteePositionData $position,
        ?User $actor,
    ): GuaranteeSnapshot {
        /** @var GuaranteeSnapshot $snapshot */
        $snapshot = $emission->guaranteeSnapshots()->updateOrCreate(
            ['reference_month' => $position->referenceMonth],
            array_merge($position->toSnapshotAttributes(), [
                'computed_at' => now(),
                'updated_by' => $actor?->getKey(),
            ]),
        );

        return $snapshot;
    }
}

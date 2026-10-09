<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuObligationComponentData;
use App\Domain\PuCalculator\DTOs\PuObligationDraft;
use App\Domain\PuCalculator\DTOs\PuObligationRefreshResult;
use App\Domain\PuCalculator\Enums\PuObligationCalculationState;
use App\Domain\PuCalculator\Enums\PuObligationLifecycle;
use App\Domain\PuCalculator\Enums\PuSettlementState;
use App\Domain\PuCalculator\Enums\PuSettlementStatus;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuObligation;
use App\Models\EmissionPuObligationCalculation;
use App\Models\EmissionPuObligationComponent;
use App\Models\EmissionPuReconciliation;
use App\Models\EmissionPuSettlement;
use App\Models\EmissionPuSettlementConflict;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * Obrigações financeiras do PU a partir da curva oficial (Fase 5).
 *
 * O contrato determina a obrigação; a curva oficial calcula o valor esperado; a
 * liquidação registra o que de fato aconteceu; a conciliação compara. Nenhuma
 * camada sobrescreve a outra:
 *
 *  - homologar, invalidar ou estender a oficial atualiza as obrigações e anexa um
 *    cálculo esperado NOVO quando o valor muda -- o anterior fica, imutável, com a
 *    marca de substituição. Liquidação nunca é lida como cálculo nem escrita aqui;
 *  - uma obrigação só está liquidada quando há um fato de liquidação registrado
 *    ({@see PuSettlementService}). A passagem do tempo, a data de pagamento ou o
 *    cálculo de uma curva homologada não liquidam nada;
 *  - a obrigação tem identidade econômica estável (UNIQUE no banco): uma versão
 *    nova nunca cria a segunda obrigação do mesmo pagamento nem deixa a liquidação
 *    órfã. A que sai do cronograma oficial fica superada, nunca apagada;
 *  - a conciliação é refeita dos fatos a cada mudança e o resultado entra no
 *    histórico com o cálculo e a liquidação usados.
 *
 * Tudo numa transação com a emissão travada primeiro -- a mesma ordem da
 * homologação, da extensão e do registro de liquidação (emissão → obrigações →
 * cálculos → liquidações): ou entram as obrigações, os cálculos e a conciliação,
 * ou nada. O Cronograma de Pagamentos informado (`payments`) só é lido.
 */
final class PuFinancialObligationService
{
    public function __construct(
        private readonly EmissionPuReader $reader,
        private readonly PuObligationScheduleBuilder $builder,
        private readonly PuObligationReconciler $reconciler,
        private readonly PuAuditLogService $auditLog,
    ) {}

    public function refresh(Emission $emission, string $trigger, ?int $actorId = null): PuObligationRefreshResult
    {
        return DB::transaction(function () use ($emission, $trigger, $actorId): PuObligationRefreshResult {
            $locked = Emission::query()->whereKey($emission->id)->lockForUpdate()->firstOrFail();
            $official = $this->reader->officialVersion($locked, fresh: true);
            $existing = EmissionPuObligation::query()
                ->where('emission_id', $locked->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (EmissionPuObligation $obligation): string => $obligation->key());

            if (! $official instanceof EmissionPuCurveVersion && $existing->isEmpty()) {
                return new PuObligationRefreshResult(null);
            }

            $payments = Payment::query()->where('emission_id', $locked->id)->orderBy('payment_date')->orderBy('id')->get();
            $schedule = $this->builder->build($locked, $official, $payments);
            $counts = ['created' => 0, 'calculations_added' => 0, 'calculations_superseded' => 0, 'superseded' => 0, 'reactivated' => 0, 'reconciliations_changed' => 0];
            $settledWithChangedCalculation = [];
            $byKey = [];

            foreach ($schedule->drafts as $draft) {
                $obligation = $existing->get($draft->key()) ?? new EmissionPuObligation([
                    'emission_id' => $locked->id,
                    'obligation_type' => $draft->type,
                    'contractual_date' => $draft->contractualDate,
                    'sequence' => $draft->sequence,
                ]);

                if (! $obligation->exists) {
                    $counts['created']++;
                } elseif (! $obligation->isActive() && $draft->lifecycle === PuObligationLifecycle::Active) {
                    $counts['reactivated']++;
                } elseif ($obligation->isActive() && $draft->lifecycle === PuObligationLifecycle::Superseded) {
                    $counts['superseded']++;
                }

                $this->applyHeader($obligation, $draft);
                $change = $this->applyCalculation($obligation, $draft, $official);

                if ($change !== null) {
                    $counts['calculations_added'] += $change['added'] ? 1 : 0;
                    $counts['calculations_superseded'] += $change['superseded'] instanceof EmissionPuObligationCalculation ? 1 : 0;
                    $this->auditSettledChange($obligation, $change['superseded'], $change['added'], $settledWithChangedCalculation);
                }

                $byKey[$draft->key()] = $obligation;
            }

            foreach ($schedule->drafts as $draft) {
                if ($draft->supersededByKey !== null && isset($byKey[$draft->key()], $byKey[$draft->supersededByKey])) {
                    $byKey[$draft->key()]->forceFill(['superseded_by_obligation_id' => $byKey[$draft->supersededByKey]->id])->save();
                }
            }

            foreach ($existing as $key => $obligation) {
                if (isset($byKey[$key])) {
                    continue;
                }

                $wasActive = $obligation->isActive();
                $superseded = $this->withoutOfficialSchedule($obligation, $official);
                $counts['calculations_superseded'] += $superseded instanceof EmissionPuObligationCalculation ? 1 : 0;
                $counts['superseded'] += $official instanceof EmissionPuCurveVersion && $wasActive ? 1 : 0;
                $this->auditSettledChange($obligation, $superseded, null, $settledWithChangedCalculation);
            }

            $obligations = EmissionPuObligation::query()->where('emission_id', $locked->id)->orderBy('id')->get();

            foreach ($obligations as $obligation) {
                if ($this->reconcile($obligation, $trigger) instanceof EmissionPuReconciliation) {
                    $counts['reconciliations_changed']++;
                }
            }

            $result = new PuObligationRefreshResult(
                $schedule->calculationVersion,
                $counts,
                $schedule->unmatchedInformedDates,
                $settledWithChangedCalculation,
            );

            if ($result->changedAnything()) {
                $this->auditLog->logObligationsRefreshed($locked, $result, $trigger, $actorId);
            }

            return $result;
        });
    }

    /**
     * Reconciliação da obrigação a partir dos fatos atuais. Grava um resultado
     * novo (e o resumo na obrigação) só quando ele difere do último: refazer a
     * conciliação sem fato novo não escreve nada. Chamar com a emissão travada.
     */
    public function reconcile(EmissionPuObligation $obligation, string $trigger): ?EmissionPuReconciliation
    {
        $calculation = $obligation->current_calculation_id !== null
            ? EmissionPuObligationCalculation::query()->with('components')->find($obligation->current_calculation_id)
            : null;
        $settlement = EmissionPuSettlement::query()
            ->where('obligation_id', $obligation->id)
            ->where('status', PuSettlementStatus::Active->value)
            ->first();
        $openConflicts = EmissionPuSettlementConflict::query()->where('obligation_id', $obligation->id)->open()->count();
        $atSettlement = $settlement?->expected_calculation_id !== null
            ? EmissionPuObligationCalculation::query()->find($settlement->expected_calculation_id)
            : null;
        $result = $this->reconciler->evaluate($obligation, $calculation, $settlement, $openConflicts, $atSettlement);
        $latest = $obligation->latest_reconciliation_id !== null
            ? EmissionPuReconciliation::query()->find($obligation->latest_reconciliation_id)
            : null;
        $settlementState = $settlement instanceof EmissionPuSettlement ? PuSettlementState::Settled : PuSettlementState::Unsettled;

        if ($latest instanceof EmissionPuReconciliation && hash_equals((string) $latest->result_fingerprint, $result->fingerprint())) {
            if ($obligation->settlement_state !== $settlementState) {
                $obligation->forceFill(['settlement_state' => $settlementState])->save();
            }

            return null;
        }

        $record = EmissionPuReconciliation::query()->create([
            'obligation_id' => $obligation->id,
            'emission_id' => $obligation->emission_id,
            'status' => $result->status,
            'reason' => $result->reason,
            'calculation_id' => $result->calculationId,
            'settlement_id' => $result->settlementId,
            'expected_total' => $result->expectedTotal,
            'actual_total' => $result->actualTotal,
            'difference' => $result->difference,
            'divergence' => $result->divergence,
            'result_fingerprint' => $result->fingerprint(),
            'trigger' => $trigger,
            'evaluated_at' => now(),
        ]);

        $obligation->forceFill([
            'settlement_state' => $settlementState,
            'reconciliation_status' => $result->status,
            'latest_reconciliation_id' => $record->id,
        ])->save();

        return $record;
    }

    /**
     * Atualiza as obrigações depois do commit da transação corrente (ou já, fora
     * de transação), uma vez por emissão. Para quem mexe num insumo do esperado
     * segurando outras travas -- correção de índice, divergência da extensão,
     * insumo contratual, cronograma informado -- e não pode travar a emissão no
     * meio sem inverter a ordem de locks.
     *
     * Fase 6: o pedido fica gravado na transação de quem pede
     * ({@see PuObligationRefreshRecovery}). Uma falha da tentativa pós-commit não
     * desfaz o fato que a provocou e não se perde: o pedido fica com a falha
     * classificada e é retomado pela varredura (`pu:obligations:recover`) --
     * sem depender de um evento futuro qualquer.
     */
    public function refreshAfterCommit(int $emissionId, string $trigger): void
    {
        app(PuObligationRefreshScheduler::class)->schedule($emissionId, $trigger);
    }

    private function applyHeader(EmissionPuObligation $obligation, PuObligationDraft $draft): void
    {
        $superseded = $draft->lifecycle === PuObligationLifecycle::Superseded;

        $obligation->fill([
            'due_date' => $draft->dueDate,
            'lifecycle_status' => $draft->lifecycle,
            'supersession_reason' => $superseded ? $draft->supersessionReason : null,
            'source_event_ids' => $draft->eventIds,
            'payment_id' => $draft->paymentId,
            'calculation_state' => $draft->state,
            'calculation_state_reason' => $draft->stateReason,
        ]);

        if ($superseded && $obligation->superseded_at === null) {
            $obligation->superseded_at = now();
        }

        if (! $superseded) {
            $obligation->superseded_at = null;
            $obligation->superseded_by_obligation_id = null;
        }

        if (! $obligation->exists) {
            $obligation->settlement_state = PuSettlementState::Unsettled;
        }

        if ($obligation->isDirty() || ! $obligation->exists) {
            $obligation->save();
        }
    }

    /**
     * Anexa o cálculo esperado novo quando o valor mudou e marca o anterior como
     * substituído; nunca reescreve um cálculo. Sem valor confiável na oficial
     * vigente, o cálculo de outra versão deixa de ser o vigente.
     *
     * @return array{added: ?EmissionPuObligationCalculation, superseded: ?EmissionPuObligationCalculation}|null
     */
    private function applyCalculation(EmissionPuObligation $obligation, PuObligationDraft $draft, ?EmissionPuCurveVersion $official): ?array
    {
        $current = $obligation->current_calculation_id !== null
            ? EmissionPuObligationCalculation::query()->whereKey($obligation->current_calculation_id)->lockForUpdate()->first()
            : null;

        if ($draft->lifecycle === PuObligationLifecycle::Superseded) {
            return $current instanceof EmissionPuObligationCalculation
                ? ['added' => null, 'superseded' => $this->supersede($obligation, $current, null, 'obligation_superseded')]
                : null;
        }

        if (! $draft->hasCalculation() || ! $official instanceof EmissionPuCurveVersion) {
            if ($current instanceof EmissionPuObligationCalculation && $current->curve_version_id !== $official?->id) {
                return ['added' => null, 'superseded' => $this->supersede($obligation, $current, null, 'official_version_without_trustworthy_calculation')];
            }

            return null;
        }

        $fingerprint = $draft->fingerprint($official->id);

        if ($current instanceof EmissionPuObligationCalculation
            && $current->curve_version_id === $official->id
            && hash_equals((string) $current->components_fingerprint, $fingerprint)) {
            return null;
        }

        $reason = $current instanceof EmissionPuObligationCalculation && $current->curve_version_id !== $official->id
            ? 'new_official_version'
            : 'expected_components_changed';

        if ($current instanceof EmissionPuObligationCalculation) {
            $current->forceFill(['superseded_at' => now(), 'supersession_reason' => $reason])->save();
        }

        $total = $draft->total();
        $added = EmissionPuObligationCalculation::query()->create([
            'obligation_id' => $obligation->id,
            'emission_id' => $obligation->emission_id,
            'curve_version_id' => $official->id,
            'calculation_version' => $official->calculation_version,
            'status' => $total !== null ? EmissionPuObligationCalculation::STATUS_COMPLETE : EmissionPuObligationCalculation::STATUS_INCOMPLETE,
            'due_date' => $draft->dueDate,
            'total_amount' => $total,
            'components_fingerprint' => $fingerprint,
            'calculated_at' => now(),
        ]);

        foreach ($draft->components as $component) {
            $this->storeComponent($added, $component);
        }

        if ($current instanceof EmissionPuObligationCalculation) {
            $current->forceFill(['superseded_by_calculation_id' => $added->id])->save();
        }

        $obligation->forceFill(['current_calculation_id' => $added->id])->save();

        return ['added' => $added, 'superseded' => $current];
    }

    /**
     * Obrigação que o cronograma oficial vigente não descreve mais. Sem curva
     * oficial nenhuma, ela continua no contrato (ativa), só sem valor esperado;
     * com outra oficial que não a contém, fica superada.
     */
    private function withoutOfficialSchedule(EmissionPuObligation $obligation, ?EmissionPuCurveVersion $official): ?EmissionPuObligationCalculation
    {
        $current = $obligation->current_calculation_id !== null
            ? EmissionPuObligationCalculation::query()->whereKey($obligation->current_calculation_id)->lockForUpdate()->first()
            : null;

        if ($official instanceof EmissionPuCurveVersion && $obligation->isActive()) {
            $obligation->forceFill([
                'lifecycle_status' => PuObligationLifecycle::Superseded,
                'superseded_at' => now(),
                'supersession_reason' => 'not_in_official_schedule',
            ]);
        }

        $obligation->forceFill([
            'calculation_state' => PuObligationCalculationState::NoOfficialCalculation,
            'calculation_state_reason' => $official instanceof EmissionPuCurveVersion
                ? 'A curva oficial vigente não descreve mais esta obrigação (evento cancelado ou movido).'
                : 'Não há curva oficial vigente para a emissão.',
        ]);

        if ($obligation->isDirty()) {
            $obligation->save();
        }

        return $current instanceof EmissionPuObligationCalculation
            ? $this->supersede($obligation, $current, null, $official instanceof EmissionPuCurveVersion ? 'obligation_superseded' : 'official_version_invalidated')
            : null;
    }

    private function supersede(EmissionPuObligation $obligation, EmissionPuObligationCalculation $current, ?EmissionPuObligationCalculation $by, string $reason): EmissionPuObligationCalculation
    {
        $current->forceFill([
            'superseded_at' => now(),
            'superseded_by_calculation_id' => $by?->id,
            'supersession_reason' => $reason,
        ])->save();

        $obligation->forceFill(['current_calculation_id' => $by?->id])->save();

        return $current;
    }

    private function storeComponent(EmissionPuObligationCalculation $calculation, PuObligationComponentData $component): void
    {
        EmissionPuObligationComponent::query()->create([
            'calculation_id' => $calculation->id,
            'component' => $component->component,
            'owner' => $component->owner,
            'status' => $component->status,
            'amount' => $component->amount,
            'unit_amount' => $component->unitAmount,
            'quantity' => $component->quantity,
            'reason' => $component->reason,
            'source' => $component->source,
        ]);
    }

    /**
     * Cálculo esperado de uma obrigação JÁ liquidada mudou: evidência na trilha. A
     * liquidação não muda; a conciliação mostra a divergência.
     *
     * @param  list<int>  $settled
     */
    private function auditSettledChange(
        EmissionPuObligation $obligation,
        ?EmissionPuObligationCalculation $before,
        ?EmissionPuObligationCalculation $after,
        array &$settled,
    ): void {
        if (! $before instanceof EmissionPuObligationCalculation && ! $after instanceof EmissionPuObligationCalculation) {
            return;
        }

        $settlement = EmissionPuSettlement::query()
            ->where('obligation_id', $obligation->id)
            ->where('status', PuSettlementStatus::Active->value)
            ->first();

        if (! $settlement instanceof EmissionPuSettlement) {
            return;
        }

        $settled[] = (int) $obligation->id;
        $this->auditLog->logSettledObligationCalculationChanged($obligation, $settlement, $before, $after);
    }
}

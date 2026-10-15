<?php

declare(strict_types=1);

namespace App\Support\PuCalculator;

use App\Domain\PuCalculator\Enums\PuObligationCalculationState;
use App\Domain\PuCalculator\Enums\PuObligationComponent;
use App\Domain\PuCalculator\Enums\PuReconciliationStatus;
use App\Models\EmissionPuObligation;
use App\Models\EmissionPuObligationCalculation;
use App\Models\EmissionPuObligationComponent;
use App\Models\EmissionPuReconciliation;
use App\Models\EmissionPuSettlement;
use App\Models\EmissionPuSettlementConflict;
use App\Models\Payment;
use Carbon\CarbonImmutable;

/**
 * Leitura de uma obrigação do PU para a tela de detalhe (Fase 8).
 *
 * Separa os três fatos que nunca se misturam -- o cronograma contratual
 * informado, o esperado oficial versionado e a liquidação registrada -- e traz o
 * histórico de cada um como foi gravado. Não calcula nada: a diferença é a que a
 * conciliação gravou, e um componente que a origem não informou aparece como
 * "não informado", nunca rateado a partir do total.
 */
final class PuObligationDetail
{
    /** Conciliações mais recentes mostradas no detalhe (o histórico completo fica no banco). */
    private const RECENT_RECONCILIATIONS = 5;

    /**
     * @return array<string, mixed>
     */
    public static function build(EmissionPuObligation $obligation): array
    {
        // Recarrega tudo: a linha da tabela traz só as colunas da listagem.
        $obligation->load([
            'payment',
            'supersededBy',
            'currentCalculation.components',
            'currentCalculation.curveVersion:id,calculation_version,status,homologated_at',
            'activeSettlement.recordedBy:id,name',
            'activeSettlement.expectedCalculation:id,calculation_version,total_amount',
            'latestReconciliation',
        ]);

        $calculation = $obligation->currentCalculation;
        $trustworthy = $obligation->calculation_state === PuObligationCalculationState::Calculated
            && ($calculation?->isComplete() ?? false);
        $settlement = $obligation->activeSettlement;
        $reconciliation = $obligation->latestReconciliation;

        return [
            'identity' => self::identity($obligation),
            'informed' => self::informed($obligation->payment),
            'expected' => [
                'available' => $trustworthy,
                'total' => $trustworthy ? PuDisplay::money((string) $calculation->total_amount) : null,
                'unavailable_label' => PuDisplay::unavailableExpected($obligation->calculation_state),
                'state_label' => $obligation->calculation_state?->label(),
                'state_color' => PuDisplay::calculationStateColor($obligation->calculation_state),
                'state_icon' => PuDisplay::calculationStateIcon($obligation->calculation_state),
                'state_reason' => $obligation->calculation_state_reason,
                'version' => $calculation?->calculation_version,
                'calculated_at' => PuDisplay::dateTime($calculation?->calculated_at),
                'due_date' => PuDisplay::date($calculation?->due_date),
                'last_known_total' => (! $trustworthy && $calculation?->isComplete())
                    ? PuDisplay::money((string) $calculation->total_amount)
                    : null,
            ],
            'settlement' => self::settlement($settlement),
            'reconciliation' => [
                'status' => $obligation->reconciliation_status,
                'label' => $obligation->reconciliation_status?->label() ?? PuReconciliationStatus::Pending->label(),
                'color' => PuDisplay::reconciliationColor($obligation->reconciliation_status),
                'icon' => PuDisplay::reconciliationIcon($obligation->reconciliation_status),
                'explanation' => PuDisplay::reconciliationExplanation($obligation->reconciliation_status),
                'difference' => $reconciliation?->difference !== null ? PuDisplay::signedMoney((string) $reconciliation->difference) : null,
                'divergence' => self::divergence($reconciliation),
                'evaluated_at' => PuDisplay::dateTime($reconciliation?->evaluated_at),
            ],
            'components' => self::components($calculation, $settlement),
            'calculations' => self::calculations($obligation),
            'ledger' => self::ledger($obligation),
            'conflicts' => self::conflicts($obligation),
            'reconciliations' => self::reconciliations($obligation),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function identity(EmissionPuObligation $obligation): array
    {
        return [
            'type' => $obligation->obligation_type->label(),
            'sequence' => (int) $obligation->sequence,
            'contractual_date' => PuDisplay::date($obligation->contractual_date),
            'due_date' => PuDisplay::date($obligation->due_date),
            'lifecycle' => $obligation->lifecycle_status->label(),
            'active' => $obligation->isActive(),
            'superseded_at' => PuDisplay::dateTime($obligation->superseded_at),
            'supersession_reason' => $obligation->supersession_reason,
        ];
    }

    /**
     * Linha do Cronograma de Pagamentos informado ligada à obrigação, quando há.
     *
     * @return array<string, mixed>|null
     */
    private static function informed(?Payment $payment): ?array
    {
        if (! $payment instanceof Payment) {
            return null;
        }

        return [
            'date' => PuDisplay::date($payment->payment_date),
            'rows' => [
                ['label' => 'Prêmio', 'value' => PuDisplay::money((string) $payment->premium_value)],
                ['label' => 'Juros', 'value' => PuDisplay::money((string) $payment->interest_value)],
                ['label' => 'Amortização', 'value' => PuDisplay::money((string) $payment->amortization_value)],
                ['label' => 'Amortização extraordinária', 'value' => PuDisplay::money((string) $payment->extra_amortization_value)],
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function settlement(?EmissionPuSettlement $settlement): ?array
    {
        if (! $settlement instanceof EmissionPuSettlement) {
            return null;
        }

        return [
            'id' => (int) $settlement->id,
            'amount' => PuDisplay::money((string) $settlement->amount),
            'date' => PuDisplay::date($settlement->settlement_date),
            'source' => $settlement->source?->label(),
            'reference' => $settlement->external_reference,
            'entry' => $settlement->entry_type?->label(),
            'recorded_by' => $settlement->recordedBy?->name ?? ($settlement->recorded_via !== 'user' ? 'Integração: '.$settlement->recorded_via : null),
            'recorded_at' => PuDisplay::dateTime($settlement->recorded_at),
            'expected_version_at_recording' => $settlement->expectedCalculation?->calculation_version,
            'expected_total_at_recording' => $settlement->expectedCalculation?->total_amount !== null
                ? PuDisplay::money((string) $settlement->expectedCalculation->total_amount)
                : null,
            'components_provided' => $settlement->providedComponents() !== null,
        ];
    }

    /**
     * Componentes do esperado vigente, lado a lado com os informados pela origem
     * da liquidação (só quando ela os informou).
     *
     * @return array{rows: list<array<string, mixed>>, settlement_provided: bool}
     */
    private static function components(?EmissionPuObligationCalculation $calculation, ?EmissionPuSettlement $settlement): array
    {
        $provided = $settlement?->providedComponents();
        $rows = [];
        $byComponent = $calculation?->components?->groupBy(fn (EmissionPuObligationComponent $row): string => $row->component->value) ?? collect();

        foreach (PuObligationComponent::cases() as $component) {
            $expectedRows = $byComponent->get($component->value, collect());
            $actual = $provided[$component->value] ?? null;

            if ($expectedRows->isEmpty() && $actual === null) {
                continue;
            }

            foreach ($expectedRows->isEmpty() ? [null] : $expectedRows->values()->all() as $index => $row) {
                $rows[] = [
                    'label' => $component->label(),
                    'status' => $row?->status?->label() ?? 'Não esperado',
                    'status_color' => PuDisplay::componentStatusColor($row?->status),
                    'owner' => $row?->owner?->label(),
                    'expected' => $row?->amount !== null ? PuDisplay::money((string) $row->amount) : null,
                    'reason' => $row?->reason,
                    'actual' => $index === 0 && $actual !== null ? PuDisplay::money((string) $actual) : null,
                ];
            }
        }

        return ['rows' => $rows, 'settlement_provided' => $provided !== null];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function calculations(EmissionPuObligation $obligation): array
    {
        return $obligation->calculations()
            ->with('curveVersion:id,calculation_version,status')
            ->orderByDesc('id')
            ->get()
            ->map(fn (EmissionPuObligationCalculation $calculation): array => [
                'version' => $calculation->calculation_version,
                'current' => $calculation->isCurrent(),
                'complete' => $calculation->isComplete(),
                'total' => $calculation->isComplete() ? PuDisplay::money((string) $calculation->total_amount) : null,
                'due_date' => PuDisplay::date($calculation->due_date),
                'calculated_at' => PuDisplay::dateTime($calculation->calculated_at),
                'superseded_at' => PuDisplay::dateTime($calculation->superseded_at),
                'supersession_reason' => $calculation->supersession_reason,
            ])
            ->all();
    }

    /**
     * Livro de liquidações da obrigação, em ordem de registro.
     *
     * @return list<array<string, mixed>>
     */
    private static function ledger(EmissionPuObligation $obligation): array
    {
        return $obligation->settlements()
            ->with(['recordedBy:id,name', 'expectedCalculation:id,calculation_version'])
            ->orderBy('id')
            ->get()
            ->map(fn (EmissionPuSettlement $entry): array => [
                'id' => (int) $entry->id,
                'entry' => $entry->entry_type?->label(),
                'status' => $entry->status?->label(),
                'status_color' => PuDisplay::settlementStatusColor($entry->status),
                'date' => PuDisplay::date($entry->settlement_date),
                'amount' => $entry->amount !== null ? PuDisplay::money((string) $entry->amount) : null,
                'source' => $entry->source?->label(),
                'reference' => $entry->external_reference,
                'reason' => $entry->reason,
                'predecessor_id' => $entry->predecessor_id !== null ? (int) $entry->predecessor_id : null,
                'recorded_by' => $entry->recordedBy?->name ?? ($entry->recorded_via !== 'user' ? 'Integração: '.$entry->recorded_via : null),
                'recorded_at' => PuDisplay::dateTime($entry->recorded_at),
                'expected_version' => $entry->expectedCalculation?->calculation_version,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function conflicts(EmissionPuObligation $obligation): array
    {
        return $obligation->conflicts()
            ->orderByDesc('id')
            ->get()
            ->map(fn (EmissionPuSettlementConflict $conflict): array => [
                'id' => (int) $conflict->id,
                'kind' => $conflict->kind?->label(),
                'status' => $conflict->status?->label(),
                'status_color' => PuDisplay::conflictStatusColor($conflict->status),
                'detected_at' => PuDisplay::dateTime($conflict->detected_at),
                'incoming_date' => isset($conflict->incoming_payload['settlement_date'])
                    ? PuDisplay::date(CarbonImmutable::parse((string) $conflict->incoming_payload['settlement_date']))
                    : null,
                'incoming_amount' => PuDisplay::money($conflict->incoming_payload['amount'] ?? null),
                'source' => $conflict->source?->label(),
                'reference' => $conflict->external_reference,
                'resolved_at' => PuDisplay::dateTime($conflict->resolved_at),
                'resolution_reason' => $conflict->resolution_reason,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function reconciliations(EmissionPuObligation $obligation): array
    {
        return $obligation->reconciliations()
            ->orderByDesc('id')
            ->limit(self::RECENT_RECONCILIATIONS)
            ->get()
            ->map(fn (EmissionPuReconciliation $reconciliation): array => [
                'status' => $reconciliation->status?->label(),
                'status_color' => PuDisplay::reconciliationColor($reconciliation->status),
                'expected' => $reconciliation->expected_total !== null ? PuDisplay::money((string) $reconciliation->expected_total) : null,
                'actual' => $reconciliation->actual_total !== null ? PuDisplay::money((string) $reconciliation->actual_total) : null,
                'difference' => $reconciliation->difference !== null ? PuDisplay::signedMoney((string) $reconciliation->difference) : null,
                'trigger' => PuDisplay::reconciliationTrigger($reconciliation->trigger),
                'evaluated_at' => PuDisplay::dateTime($reconciliation->evaluated_at),
            ])
            ->all();
    }

    /**
     * O detalhe da divergência como a conciliação gravou, em frases.
     *
     * @return list<string>
     */
    private static function divergence(?EmissionPuReconciliation $reconciliation): array
    {
        $divergence = is_array($reconciliation?->divergence) ? $reconciliation->divergence : [];
        $lines = [];

        if (isset($divergence['amount'])) {
            $lines[] = sprintf(
                'Valor: esperado %s, liquidado %s, diferença %s.',
                PuDisplay::money($divergence['amount']['expected'] ?? null) ?? '—',
                PuDisplay::money($divergence['amount']['actual'] ?? null) ?? '—',
                PuDisplay::signedMoney($divergence['amount']['difference'] ?? null) ?? '—',
            );
        }

        if (isset($divergence['date'])) {
            $lines[] = sprintf(
                'Data: esperada %s, liquidada em %s.',
                self::isoDate($divergence['date']['expected'] ?? null),
                self::isoDate($divergence['date']['actual'] ?? null),
            );
        }

        foreach ((array) ($divergence['components'] ?? []) as $component => $difference) {
            $lines[] = sprintf(
                '%s: esperado %s, informado pela origem %s (%s).',
                PuObligationComponent::tryFrom((string) $component)?->label() ?? (string) $component,
                PuDisplay::money($difference['expected'] ?? null) ?? '—',
                PuDisplay::money($difference['actual'] ?? null) ?? '—',
                PuDisplay::signedMoney($difference['difference'] ?? null) ?? '—',
            );
        }

        if (($divergence['component_reconciliation'] ?? null) === 'total_only') {
            $lines[] = 'A origem informou só o total: os componentes não foram conciliados um a um.';
        }

        if (isset($divergence['calculation_changed_after_settlement'])) {
            $changed = $divergence['calculation_changed_after_settlement'];
            $lines[] = sprintf(
                'O esperado mudou depois do registro da liquidação: era %s no registro, hoje é %s.',
                PuDisplay::money($changed['expected_total_at_settlement'] ?? null) ?? 'indisponível',
                PuDisplay::money($changed['current_expected_total'] ?? null) ?? 'indisponível',
            );
        }

        if (isset($divergence['open_conflicts'])) {
            $lines[] = sprintf('%d conflito(s) de liquidação aguardando decisão.', (int) $divergence['open_conflicts']);
        }

        return $lines;
    }

    private static function isoDate(mixed $value): string
    {
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value)->format('d/m/Y') : '—';
    }
}

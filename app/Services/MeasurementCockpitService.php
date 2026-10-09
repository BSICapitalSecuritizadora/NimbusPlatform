<?php

namespace App\Services;

use App\Models\Measurement;
use App\Models\MeasurementPayment;
use App\Models\User;

class MeasurementCockpitService
{
    public function __construct(private MeasurementOperationalReadModel $readModel) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function summaryFor(User $user, array $filters = []): array
    {
        if (! $user->can('measurements.view')) {
            return $this->emptySummary();
        }

        $allRows = $this->readModel->applyFilters(
            $this->readModel->scopedQueryFor($user),
            $filters,
            $user,
        );

        // Contam as revisões correntes -- a vigente e a que está em andamento,
        // que é trabalho próprio e conta ao lado dela --, o mesmo recorte padrão
        // da listagem de medições, para o número do cartão e o da lista
        // coincidirem. O histórico (substituídas, recusadas, canceladas) fica
        // de fora. Os pagamentos ficam na revisão em que foram registrados e
        // somam todos.
        $query = (clone $allRows)->currentRevisions();

        $stageCounts = (clone $query)
            ->reorder()
            ->selectRaw('current_stage, COUNT(*) as aggregate')
            ->groupBy('current_stage')
            ->pluck('aggregate', 'current_stage')
            ->map(fn (mixed $count): int => (int) $count);

        $statusCounts = (clone $query)
            ->reorder()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn (mixed $count): int => (int) $count);

        $slaCounts = [
            MeasurementSlaService::STATUS_APPROACHING => 0,
            MeasurementSlaService::STATUS_OVERDUE => 0,
            MeasurementSlaService::STATUS_CALENDAR_UNAVAILABLE => 0,
        ];

        (clone $query)
            ->withPendingWork()
            ->with(['reviews', 'pauses', 'payments.currentReceiptEvidence', 'reviewingSuccessor:id,previous_revision_id,revision_number,revision_status'])
            ->reorder('measurements.id')
            ->lazyById(100, column: 'measurements.id', alias: 'id')
            ->each(function (Measurement $measurement) use (&$slaCounts): void {
                $status = $this->readModel->slaEvaluation($measurement)['status'];

                if (array_key_exists($status, $slaCounts)) {
                    $slaCounts[$status]++;
                }
            });

        $measurementIds = (clone $allRows)->reorder()->select('measurements.id');
        $paymentSummary = MeasurementPayment::query()
            ->whereIn('measurement_id', $measurementIds)
            ->selectRaw('COUNT(*) as payment_count')
            ->selectRaw('COALESCE(SUM(amount), 0) as recorded_amount')
            ->first();

        $delegatedQuery = clone $query;
        $this->readModel->applyAssignmentFilter($delegatedQuery, $user, 'delegated');

        return [
            'total' => (int) $statusCounts->sum(),
            'stages' => collect(range(1, 5))
                ->mapWithKeys(fn (int $stage): array => [$stage => (int) $stageCounts->get($stage, 0)])
                ->all(),
            'paused' => (int) $statusCounts->get('paused', 0),
            'finalized' => (int) $statusCounts->get('finalized', 0),
            'awaiting_receipt' => (int) $statusCounts->get('awaiting_receipt', 0),
            'ready_to_finalize' => (int) $statusCounts->get('approved', 0),
            'approaching' => $slaCounts[MeasurementSlaService::STATUS_APPROACHING],
            'overdue' => $slaCounts[MeasurementSlaService::STATUS_OVERDUE],
            'calendar_unavailable' => $slaCounts[MeasurementSlaService::STATUS_CALENDAR_UNAVAILABLE],
            'delegated' => $delegatedQuery->count(),
            'payment_count' => (int) ($paymentSummary?->payment_count ?? 0),
            'pending_receipt_count' => MeasurementPayment::query()->whereIn('measurement_id', $measurementIds)->withoutReceipt()->count(),
            'recorded_amount' => (float) ($paymentSummary?->recorded_amount ?? 0),
        ];
    }

    /** @return array<string, mixed> */
    private function emptySummary(): array
    {
        return [
            'total' => 0,
            'stages' => array_fill_keys(range(1, 5), 0),
            'paused' => 0,
            'finalized' => 0,
            'awaiting_receipt' => 0,
            'ready_to_finalize' => 0,
            'approaching' => 0,
            'overdue' => 0,
            'calendar_unavailable' => 0,
            'delegated' => 0,
            'payment_count' => 0,
            'pending_receipt_count' => 0,
            'recorded_amount' => 0.0,
        ];
    }
}

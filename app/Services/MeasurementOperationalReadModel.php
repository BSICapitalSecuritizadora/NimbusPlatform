<?php

namespace App\Services;

use App\Enums\MeasurementResponsibility;
use App\Models\Emission;
use App\Models\Measurement;
use App\Models\MeasurementPlanSet;
use App\Models\Operation;
use App\Models\ResponsibilityDelegation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class MeasurementOperationalReadModel
{
    /** @var array<int, array<string, mixed>> */
    private array $slaEvaluations = [];

    /** @var array<int, Collection<int, array{delegation: ResponsibilityDelegation, responsibility: MeasurementResponsibility}>> */
    private array $operationalDelegationsByOperation = [];

    private ?int $delegationViewerId = null;

    public function __construct(
        private MeasurementSlaService $sla,
        private MeasurementWorkflow $workflow,
    ) {}

    /** @return Builder<Measurement> */
    public function queryFor(User $user): Builder
    {
        return $this->scopedQueryFor($user)
            ->with([
                'operation.emission:id,name,bsi_code,if_code,isin_code',
                'operation.paymentManager:id,name',
                'operation.paymentReceiptUploader:id,name',
                'operation.paymentFinalizer:id,name',
                'assets.planSet.construction:id,development_name',
                'payments.planSet.construction:id,development_name',
                'payments.createdByUser:id,name',
                'payments.receiptUploadedByUser:id,name',
                'reviews.reviewer:id,name',
                'pauses:id,measurement_id,stage,paused_at,resumed_at',
            ])
            ->withCount([
                'payments',
                'payments as payments_with_receipt_count' => fn (Builder $payments): Builder => $payments
                    ->whereNotNull('receipt_path')
                    ->where('receipt_path', '!=', ''),
            ])
            ->withSum('payments', 'amount');
    }

    /** @return Builder<Measurement> */
    public function scopedQueryFor(User $user): Builder
    {
        $query = Measurement::query();

        if (! $user->can('measurements.view')) {
            return $query->whereRaw('1 = 0');
        }

        return $query->visibleTo($user);
    }

    /** @return Builder<Measurement> */
    public function paymentQueryFor(User $user): Builder
    {
        return $this->queryFor($user)
            ->where(function (Builder $payments): void {
                $payments
                    ->whereIn('status', ['awaiting_payment', 'awaiting_receipt', 'approved', 'finalized'])
                    ->orWhereHas('payments')
                    ->orWhere(function (Builder $pausedAtPayment): void {
                        $pausedAtPayment
                            ->where('status', 'paused')
                            ->where('current_stage', MeasurementWorkflow::STAGE_PAYMENT);
                    });
            });
    }

    /**
     * @param  Builder<Measurement>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Measurement>
     */
    public function applyFilters(Builder $query, array $filters, User $user): Builder
    {
        $query
            ->when(filled($filters['competence_from'] ?? null), fn (Builder $measurements): Builder => $measurements
                ->whereDate('reference_month', '>=', $filters['competence_from']))
            ->when(filled($filters['competence_to'] ?? null), fn (Builder $measurements): Builder => $measurements
                ->whereDate('reference_month', '<=', $filters['competence_to']))
            ->when(filled($filters['operation_id'] ?? null), fn (Builder $measurements): Builder => $measurements
                ->where('operation_id', (int) $filters['operation_id']))
            ->when(filled($filters['emission_id'] ?? null), fn (Builder $measurements): Builder => $measurements
                ->whereHas('operation', fn (Builder $operations): Builder => $operations
                    ->where('emission_id', (int) $filters['emission_id'])))
            ->when(filled($filters['responsible_user_id'] ?? null), fn (Builder $measurements): Builder => $this
                ->applyResponsibleFilter($measurements, (int) $filters['responsible_user_id']))
            ->when(filled($filters['stage'] ?? null), fn (Builder $measurements): Builder => $measurements
                ->where('current_stage', (int) $filters['stage']))
            ->when(filled($filters['status'] ?? null), fn (Builder $measurements): Builder => $measurements
                ->where('status', (string) $filters['status']))
            ->when(filled($filters['assignment'] ?? null), fn (Builder $measurements): Builder => $this
                ->applyAssignmentFilter($measurements, $user, (string) $filters['assignment']));

        return $this->applySlaFilter($query, $filters['sla_status'] ?? null);
    }

    /** @param Builder<Measurement> $query */
    public function applySearch(Builder $query, string $search): Builder
    {
        $term = trim($search);

        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $matches) use ($term): void {
            if (ctype_digit($term)) {
                $matches->orWhereKey((int) $term);
            }

            $matches
                ->orWhereHas('operation', function (Builder $operations) use ($term): void {
                    $operations
                        ->where('code', 'like', "%{$term}%")
                        ->orWhere('title', 'like', "%{$term}%")
                        ->orWhereHas('emission', function (Builder $emissions) use ($term): void {
                            $emissions
                                ->where('name', 'like', "%{$term}%")
                                ->orWhere('bsi_code', 'like', "%{$term}%")
                                ->orWhere('if_code', 'like', "%{$term}%")
                                ->orWhere('isin_code', 'like', "%{$term}%");
                        });

                    foreach ([
                        'assignedUser',
                        'responsibleUser',
                        'stage2Reviewer',
                        'stage3Reviewer',
                        'paymentManager',
                        'paymentReceiptUploader',
                        'paymentFinalizer',
                    ] as $relationship) {
                        $operations->orWhereHas($relationship, fn (Builder $users): Builder => $users
                            ->where('name', 'like', "%{$term}%"));
                    }
                })
                ->orWhereHas('payments', fn (Builder $payments): Builder => $payments
                    ->where('method', 'like', "%{$term}%"))
                ->orWhereHas('assets.planSet.construction', fn (Builder $constructions): Builder => $constructions
                    ->where('development_name', 'like', "%{$term}%"));
        });
    }

    /** @param Builder<Measurement> $query */
    public function applyResponsibleFilter(Builder $query, int $userId): Builder
    {
        return $query->whereHas('operation', fn (Builder $operations): Builder => $operations
            ->where(fn (Builder $responsibilities): Builder => $this
                ->applyResponsibilityColumns($responsibilities, $userId)));
    }

    /** @param Builder<Measurement> $query */
    public function applyAssignmentFilter(Builder $query, User $user, ?string $assignment): Builder
    {
        if (! in_array($assignment, ['direct', 'delegated'], true)) {
            return $query;
        }

        if ($user->hasAnyRole(['super-admin', 'admin'])) {
            return $assignment === 'delegated'
                ? $query->whereRaw('1 = 0')
                : $query->whereHas('operation', fn (Builder $operations): Builder => $this
                    ->applyDirectParticipation($operations, $user));
        }

        if ($assignment === 'direct') {
            return $query->whereHas('operation', fn (Builder $operations): Builder => $this
                ->applyDirectParticipation($operations, $user));
        }

        return $query->whereDoesntHave('operation', fn (Builder $operations): Builder => $this
            ->applyDirectParticipation($operations, $user));
    }

    /** @param Builder<Measurement> $query */
    public function applySlaFilter(Builder $query, mixed $status): Builder
    {
        if (! is_string($status) || ! in_array($status, $this->filterableSlaStatuses(), true)) {
            return $query;
        }

        $matchingIds = (clone $query)
            ->with(['reviews', 'pauses'])
            ->reorder('measurements.id')
            ->lazyById(100, column: 'measurements.id', alias: 'id')
            ->filter(fn (Measurement $measurement): bool => $this->slaEvaluation($measurement)['status'] === $status)
            ->map(fn (Measurement $measurement): int => (int) $measurement->getKey())
            ->all();

        return $matchingIds === []
            ? $query->whereRaw('1 = 0')
            : $query->whereKey($matchingIds);
    }

    /** @return array<string, mixed> */
    public function slaEvaluation(Measurement $measurement): array
    {
        $id = (int) $measurement->getKey();

        return $this->slaEvaluations[$id] ??= $this->sla->evaluate($measurement);
    }

    public function stageLabel(Measurement $measurement): string
    {
        $stage = $this->workflow->unifiedStage($measurement);

        return MeasurementWorkflow::STAGE_LABELS[$stage] ?? '—';
    }

    public function pendingLabel(Measurement $measurement): string
    {
        if ($measurement->status === 'paused') {
            return 'Etapa pausada';
        }

        if ($measurement->status === 'awaiting_payment') {
            return (int) $measurement->payments_count === 0
                ? 'Aguardando registro'
                : 'Aguardando aprovação';
        }

        return match ($measurement->status) {
            'awaiting_receipt' => 'Aguardando comprovante',
            'approved' => 'Pronta para finalizar',
            'finalized' => 'Concluída',
            default => 'Acompanhamento',
        };
    }

    public function receiptStatusLabel(Measurement $measurement): string
    {
        $payments = (int) $measurement->payments_count;
        $withReceipt = (int) $measurement->payments_with_receipt_count;

        return match (true) {
            $payments === 0 => 'Sem pagamentos',
            $withReceipt === $payments => 'Todos anexados',
            $withReceipt === 0 => 'Todos pendentes',
            default => sprintf('%d de %d anexados', $withReceipt, $payments),
        };
    }

    public function slaLabel(Measurement $measurement): string
    {
        return match ($this->slaEvaluation($measurement)['status']) {
            MeasurementSlaService::STATUS_ON_TIME => 'No prazo',
            MeasurementSlaService::STATUS_APPROACHING => 'Em atenção',
            MeasurementSlaService::STATUS_OVERDUE => 'Vencido',
            MeasurementSlaService::STATUS_PAUSED => 'Pausado',
            MeasurementSlaService::STATUS_COMPLETED => 'Concluído',
            MeasurementSlaService::STATUS_CALENDAR_UNAVAILABLE => 'Calendário indisponível',
            MeasurementSlaService::STATUS_INVALID_CONFIG => 'Configuração inválida',
            MeasurementSlaService::STATUS_NOT_CONFIGURED => 'Não configurado',
            default => 'Não aplicável',
        };
    }

    public function slaDescription(Measurement $measurement): ?string
    {
        $evaluation = $this->slaEvaluation($measurement);

        if ($evaluation['calendar_unavailable']) {
            return 'Prazo indisponível por calendário';
        }

        if ($evaluation['paused']) {
            return $evaluation['deadline_at']
                ? 'Prazo: '.$evaluation['deadline_at']->format('d/m/Y H:i').' · SLA pausado'
                : 'SLA pausado';
        }

        if ($evaluation['status'] === MeasurementSlaService::STATUS_OVERDUE) {
            return collect([
                $evaluation['deadline_at'] ? 'Prazo: '.$evaluation['deadline_at']->format('d/m/Y H:i') : null,
                $evaluation['elapsed_business_days'].' dias úteis decorridos',
            ])->filter()->implode(' · ');
        }

        if (is_int($evaluation['remaining_business_days'])) {
            return collect([
                $evaluation['deadline_at'] ? 'Prazo: '.$evaluation['deadline_at']->format('d/m/Y H:i') : null,
                $evaluation['remaining_business_days'].' dias úteis restantes',
            ])->filter()->implode(' · ');
        }

        return $evaluation['deadline_at']?->format('d/m/Y H:i');
    }

    public function slaColor(Measurement $measurement): string
    {
        return match ($this->slaEvaluation($measurement)['status']) {
            MeasurementSlaService::STATUS_ON_TIME, MeasurementSlaService::STATUS_COMPLETED => 'success',
            MeasurementSlaService::STATUS_APPROACHING, MeasurementSlaService::STATUS_PAUSED => 'warning',
            MeasurementSlaService::STATUS_OVERDUE, MeasurementSlaService::STATUS_INVALID_CONFIG => 'danger',
            MeasurementSlaService::STATUS_CALENDAR_UNAVAILABLE => 'info',
            default => 'gray',
        };
    }

    /** @return Collection<int, string> */
    public function planSetLabels(Measurement $measurement): Collection
    {
        return $measurement->payments
            ->pluck('planSet')
            ->merge($measurement->assets->pluck('planSet'))
            ->filter()
            ->unique('id')
            ->map(fn (MeasurementPlanSet $planSet): string => collect([
                $planSet->name,
                $planSet->construction?->development_name,
            ])->filter()->unique()->implode(' · '))
            ->filter()
            ->unique()
            ->values();
    }

    public function operationalDelegationLabel(Measurement $measurement, User $viewer): string
    {
        $this->loadOperationalDelegations($viewer);
        $entries = $this->operationalDelegationsByOperation[(int) $measurement->operation_id] ?? collect();

        if ($entries->isEmpty()) {
            return 'Sem delegação ativa';
        }

        return $entries
            ->map(fn (array $entry): string => sprintf(
                '%s: %s até %s',
                $entry['responsibility']->label(),
                $entry['delegation']->delegate?->name ?? 'Delegado',
                $entry['delegation']->ends_at?->format('d/m/Y H:i') ?? '—',
            ))
            ->implode(' · ');
    }

    /** @return array<int, string> */
    public function operationOptions(User $user): array
    {
        return Operation::query()
            ->visibleTo($user)
            ->orderBy('title')
            ->get(['id', 'code', 'title'])
            ->mapWithKeys(fn (Operation $operation): array => [
                $operation->getKey() => trim($operation->code.' · '.$operation->title, ' ·'),
            ])
            ->all();
    }

    /** @return array<int, string> */
    public function emissionOptions(User $user): array
    {
        return Emission::query()
            ->whereHas('operations', fn (Builder $operations): Builder => $operations->visibleTo($user))
            ->orderBy('name')
            ->get(['id', 'name', 'bsi_code'])
            ->mapWithKeys(fn (Emission $emission): array => [
                $emission->getKey() => trim($emission->bsi_code.' · '.$emission->name, ' ·'),
            ])
            ->all();
    }

    /** @return array<int, string> */
    public function responsibleOptions(User $user): array
    {
        $ids = Operation::query()
            ->visibleTo($user)
            ->get(Operation::RESPONSIBILITY_FIELDS)
            ->flatMap(fn (Operation $operation): array => $operation->only(Operation::RESPONSIBILITY_FIELDS))
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        return User::query()
            ->whereKey($ids)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /** @return array<int, string> */
    public function paymentManagerOptions(User $user): array
    {
        $ids = Operation::query()
            ->visibleTo($user)
            ->whereNotNull('payment_manager_user_id')
            ->distinct()
            ->pluck('payment_manager_user_id');

        return User::query()->whereKey($ids)->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @return array<int, string> */
    public function operationalDelegateOptions(User $user): array
    {
        $this->loadOperationalDelegations($user);

        return collect($this->operationalDelegationsByOperation)
            ->flatten(1)
            ->pluck('delegation')
            ->unique('delegate_user_id')
            ->sortBy(fn (ResponsibilityDelegation $delegation): string => $delegation->delegate?->name ?? '')
            ->mapWithKeys(fn (ResponsibilityDelegation $delegation): array => [
                (int) $delegation->delegate_user_id => $delegation->delegate?->name ?? 'Delegado',
            ])
            ->all();
    }

    /** @param Builder<Measurement> $query */
    public function applyPaymentManagerFilter(Builder $query, ?int $managerId): Builder
    {
        return $managerId
            ? $query->whereHas('operation', fn (Builder $operations): Builder => $operations
                ->where('payment_manager_user_id', $managerId))
            : $query;
    }

    /** @param Builder<Measurement> $query */
    public function applyOperationalDelegateFilter(Builder $query, User $viewer, ?int $delegateId): Builder
    {
        if (! $delegateId) {
            return $query;
        }

        $this->loadOperationalDelegations($viewer);

        $operationIds = collect($this->operationalDelegationsByOperation)
            ->filter(fn (Collection $entries): bool => $entries
                ->contains(fn (array $entry): bool => (int) $entry['delegation']->delegate_user_id === $delegateId))
            ->keys()
            ->all();

        return $operationIds === [] ? $query->whereRaw('1 = 0') : $query->whereIn('operation_id', $operationIds);
    }

    /** @return list<string> */
    public function filterableSlaStatuses(): array
    {
        return [
            MeasurementSlaService::STATUS_ON_TIME,
            MeasurementSlaService::STATUS_APPROACHING,
            MeasurementSlaService::STATUS_OVERDUE,
            MeasurementSlaService::STATUS_PAUSED,
            MeasurementSlaService::STATUS_CALENDAR_UNAVAILABLE,
        ];
    }

    /** @param Builder<Operation> $query */
    private function applyResponsibilityColumns(Builder $query, int $userId): Builder
    {
        foreach (Operation::RESPONSIBILITY_FIELDS as $index => $column) {
            $index === 0 ? $query->where($column, $userId) : $query->orWhere($column, $userId);
        }

        return $query;
    }

    /** @param Builder<Operation> $query */
    private function applyDirectParticipation(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $direct) use ($user): void {
            $this->applyResponsibilityColumns($direct, (int) $user->getKey());
            $direct->orWhereHas('rejectionNotifyUsers', fn (Builder $users): Builder => $users->whereKey($user->getKey()));
        });
    }

    private function loadOperationalDelegations(User $viewer): void
    {
        if ($this->delegationViewerId === (int) $viewer->getKey()) {
            return;
        }

        $this->delegationViewerId = (int) $viewer->getKey();
        $this->operationalDelegationsByOperation = [];

        $operations = Operation::query()
            ->visibleTo($viewer)
            ->where(function (Builder $responsibilities): void {
                $responsibilities
                    ->whereNotNull('payment_manager_user_id')
                    ->orWhereNotNull('payment_receipt_uploader_user_id')
                    ->orWhereNotNull('payment_finalizer_user_id');
            })
            ->get([
                'id',
                'payment_manager_user_id',
                'payment_receipt_uploader_user_id',
                'payment_finalizer_user_id',
            ]);

        if ($operations->isEmpty()) {
            return;
        }

        $delegations = ResponsibilityDelegation::query()
            ->active()
            ->whereIn('delegator_user_id', $operations
                ->flatMap(fn (Operation $operation): array => [
                    $operation->payment_manager_user_id,
                    $operation->payment_receipt_uploader_user_id,
                    $operation->payment_finalizer_user_id,
                ])
                ->filter()
                ->unique())
            ->with([
                'delegator.roles.permissions',
                'delegator.permissions',
                'delegate.roles.permissions',
                'delegate.permissions',
            ])
            ->get();

        foreach ($operations as $operation) {
            $entries = collect();

            foreach ([
                MeasurementResponsibility::PaymentManager,
                MeasurementResponsibility::ReceiptUploader,
                MeasurementResponsibility::Finalizer,
            ] as $responsibility) {
                $responsibleUserId = $operation->getAttribute($responsibility->operationColumn());
                $matching = $delegations
                    ->where('delegator_user_id', $responsibleUserId)
                    ->filter(fn (ResponsibilityDelegation $delegation): bool => $delegation->covers($operation, $responsibility)
                        && $this->isEffectiveDelegation($delegation, $responsibility))
                    ->values();

                $entries->push(...$matching->map(fn (ResponsibilityDelegation $delegation): array => [
                    'delegation' => $delegation,
                    'responsibility' => $responsibility,
                ])->all());
            }

            if ($entries->isNotEmpty()) {
                $this->operationalDelegationsByOperation[(int) $operation->getKey()] = $entries;
            }
        }
    }

    private function isEffectiveDelegation(
        ResponsibilityDelegation $delegation,
        MeasurementResponsibility $responsibility,
    ): bool {
        $delegator = $delegation->delegator;
        $delegate = $delegation->delegate;

        return $delegator instanceof User
            && $delegate instanceof User
            && $delegator->isActive()
            && $delegator->isApproved()
            && $delegate->isActive()
            && $delegate->isApproved()
            && $delegator->can($responsibility->permission())
            && $delegate->can($responsibility->permission());
    }
}

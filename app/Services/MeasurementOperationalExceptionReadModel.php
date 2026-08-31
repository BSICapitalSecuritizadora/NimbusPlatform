<?php

namespace App\Services;

use App\DTOs\Measurements\MeasurementOperationalException;
use App\DTOs\Measurements\MeasurementOperationalExceptionResult;
use App\Enums\AccessPermission;
use App\Enums\MeasurementOperationalExceptionType;
use App\Enums\MeasurementResponsibility;
use App\Models\Emission;
use App\Models\Measurement;
use App\Models\Operation;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class MeasurementOperationalExceptionReadModel
{
    private const CHUNK_SIZE = 100;

    public function __construct(
        private MeasurementWorkflow $workflow,
        private MeasurementSlaService $sla,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function scanFor(
        User $actor,
        array $filters = [],
        string $search = '',
        int $page = 1,
        int $perPage = 25,
    ): MeasurementOperationalExceptionResult {
        $page = max(1, $page);
        $perPage = min(100, max(10, $perPage));
        $counts = $this->emptyCounts();

        if (! $this->canAccess($actor)) {
            return new MeasurementOperationalExceptionResult([], $counts, 0, $page, $perPage);
        }

        $exceptionFilter = $this->exceptionFilter($filters['exception_type'] ?? null);

        if ($exceptionFilter === false) {
            return new MeasurementOperationalExceptionResult([], $counts, 0, $page, $perPage);
        }

        $query = $this->applySqlFilters($this->visibleQuery($actor), $filters, $search);
        $offset = ($page - 1) * $perPage;
        $items = [];
        $total = 0;

        foreach ($query
            ->reorder('measurements.id')
            ->lazyById(
                self::CHUNK_SIZE,
                column: 'measurements.id',
                alias: 'id',
            ) as $measurement) {
            foreach ($this->classify($measurement) as $exception) {
                if ($exceptionFilter instanceof MeasurementOperationalExceptionType
                    && $exception->type !== $exceptionFilter) {
                    continue;
                }

                $counts[$exception->type->value]++;

                if ($total >= $offset && count($items) < $perPage) {
                    $items[] = $exception;
                }

                $total++;
            }
        }

        return new MeasurementOperationalExceptionResult($items, $counts, $total, $page, $perPage);
    }

    /** @return array<int, string> */
    public function operationOptionsFor(User $actor): array
    {
        if (! $this->canAccess($actor)) {
            return [];
        }

        return Operation::query()
            ->select(['operations.id', 'operations.code', 'operations.title'])
            ->whereIn(
                'operations.id',
                Measurement::query()->visibleTo($actor)->select('measurements.operation_id'),
            )
            ->orderBy('operations.code')
            ->orderBy('operations.title')
            ->get()
            ->mapWithKeys(fn (Operation $operation): array => [
                (int) $operation->getKey() => collect([$operation->code, $operation->title])
                    ->filter(fn (mixed $value): bool => filled($value))
                    ->implode(' — '),
            ])
            ->all();
    }

    /** @return array<int, string> */
    public function emissionOptionsFor(User $actor): array
    {
        if (! $this->canAccess($actor)) {
            return [];
        }

        $visibleOperationIds = Measurement::query()
            ->visibleTo($actor)
            ->select('measurements.operation_id');

        return Emission::query()
            ->select(['emissions.id', 'emissions.name'])
            ->whereIn(
                'emissions.id',
                Operation::query()
                    ->whereIn('operations.id', $visibleOperationIds)
                    ->select('operations.emission_id'),
            )
            ->orderBy('emissions.name')
            ->pluck('emissions.name', 'emissions.id')
            ->mapWithKeys(fn (string $name, int|string $id): array => [(int) $id => $name])
            ->all();
    }

    public function canAccess(User $actor): bool
    {
        return $actor->can(AccessPermission::MeasurementsExceptionsView->value)
            && $actor->can(AccessPermission::MeasurementsView->value);
    }

    /**
     * @return Builder<Measurement>
     */
    private function visibleQuery(User $actor): Builder
    {
        return Measurement::query()
            ->visibleTo($actor)
            ->select([
                'measurements.id',
                'measurements.operation_id',
                'measurements.reference_month',
                'measurements.filename',
                'measurements.status',
                'measurements.current_stage',
                'measurements.created_at',
                'measurements.updated_at',
            ])
            ->with([
                'operation:id,emission_id,code,title,assigned_user_id,responsible_user_id,stage2_reviewer_user_id,stage3_reviewer_user_id,payment_manager_user_id,payment_receipt_uploader_user_id,payment_finalizer_user_id',
                'operation.emission:id,name',
                'operation.responsibleUser:id,name,is_active',
                'operation.stage2Reviewer:id,name,is_active',
                'operation.stage3Reviewer:id,name,is_active',
                'operation.paymentManager:id,name,is_active',
                'operation.paymentReceiptUploader:id,name,is_active',
                'operation.paymentFinalizer:id,name,is_active',
                'reviews:id,measurement_id,stage,status,created_at,reviewed_at',
                'pauses:id,measurement_id,stage,paused_at,resumed_at',
            ]);
    }

    /**
     * @param  Builder<Measurement>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Measurement>
     */
    private function applySqlFilters(Builder $query, array $filters, string $search): Builder
    {
        $operationId = $this->positiveInteger($filters['operation_id'] ?? null);
        $emissionId = $this->positiveInteger($filters['emission_id'] ?? null);
        $stage = $this->positiveInteger($filters['stage'] ?? null);
        $competenceFrom = $this->date($filters['competence_from'] ?? null);
        $competenceTo = $this->date($filters['competence_to'] ?? null);

        if ($this->filledInvalidInteger($filters['operation_id'] ?? null, $operationId)
            || $this->filledInvalidInteger($filters['emission_id'] ?? null, $emissionId)
            || $this->filledInvalidInteger($filters['stage'] ?? null, $stage)
            || ($stage !== null && ($stage < 1 || $stage > 5))
            || $this->filledInvalidDate($filters['competence_from'] ?? null, $competenceFrom)
            || $this->filledInvalidDate($filters['competence_to'] ?? null, $competenceTo)) {
            return $query->whereRaw('1 = 0');
        }

        $query
            ->when($operationId !== null, fn (Builder $measurements): Builder => $measurements
                ->where('measurements.operation_id', $operationId))
            ->when($emissionId !== null, fn (Builder $measurements): Builder => $measurements
                ->whereHas('operation', fn (Builder $operations): Builder => $operations
                    ->where('operations.emission_id', $emissionId)))
            ->when($stage !== null, fn (Builder $measurements): Builder => $measurements
                ->where('measurements.current_stage', $stage))
            ->when($competenceFrom !== null, fn (Builder $measurements): Builder => $measurements
                ->whereDate('measurements.reference_month', '>=', $competenceFrom))
            ->when($competenceTo !== null, fn (Builder $measurements): Builder => $measurements
                ->whereDate('measurements.reference_month', '<=', $competenceTo));

        $search = trim($search);

        if ($search === '') {
            return $query;
        }

        $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($search, 0, 100)).'%';

        return $query->where(function (Builder $measurements) use ($term): void {
            $measurements
                ->where('measurements.filename', 'like', $term)
                ->orWhereHas('operation', function (Builder $operations) use ($term): void {
                    $operations
                        ->where('operations.code', 'like', $term)
                        ->orWhere('operations.title', 'like', $term)
                        ->orWhereHas('emission', fn (Builder $emissions): Builder => $emissions
                            ->where('emissions.name', 'like', $term));
                });
        });
    }

    /** @return list<MeasurementOperationalException> */
    private function classify(Measurement $measurement): array
    {
        if (! $measurement->operation instanceof Operation) {
            return [];
        }

        $operation = $measurement->operation;
        $stage = $this->workflow->unifiedStage($measurement);
        $responsibility = $this->requiredResponsibility($measurement, $stage);
        $exceptions = [];

        if ($responsibility instanceof MeasurementResponsibility
            && ! $this->hasActivePermanentResponsible($operation, $responsibility)) {
            $type = MeasurementOperationalExceptionType::forResponsibility($responsibility);
            $exceptions[] = $this->makeException(
                $measurement,
                $operation,
                $type,
                $stage,
                $responsibility,
                null,
            );
        }

        $slaException = $this->slaException($measurement, $operation, $stage, $responsibility);

        if ($slaException instanceof MeasurementOperationalException) {
            $exceptions[] = $slaException;
        }

        return $exceptions;
    }

    private function requiredResponsibility(
        Measurement $measurement,
        int $stage,
    ): ?MeasurementResponsibility {
        return match ($measurement->status) {
            'pending', 'in_review', 'paused' => match ($stage) {
                1, 2, 3 => MeasurementResponsibility::primaryForStage($stage),
                4 => MeasurementResponsibility::PaymentManager,
                default => null,
            },
            'awaiting_payment' => MeasurementResponsibility::PaymentManager,
            'awaiting_receipt' => MeasurementResponsibility::ReceiptUploader,
            'approved' => MeasurementResponsibility::Finalizer,
            default => null,
        };
    }

    private function hasActivePermanentResponsible(
        Operation $operation,
        MeasurementResponsibility $responsibility,
    ): bool {
        if ($operation->responsibleUserIdFor($responsibility) === null) {
            return false;
        }

        return $this->responsibleUser($operation, $responsibility)?->isActive() ?? false;
    }

    private function responsibleUser(
        Operation $operation,
        MeasurementResponsibility $responsibility,
    ): ?User {
        $user = match ($responsibility) {
            MeasurementResponsibility::EngineeringReviewer => $operation->responsibleUser,
            MeasurementResponsibility::ManagementReviewer => $operation->stage2Reviewer,
            MeasurementResponsibility::ComplianceReviewer => $operation->stage3Reviewer,
            MeasurementResponsibility::PaymentManager => $operation->paymentManager,
            MeasurementResponsibility::ReceiptUploader => $operation->paymentReceiptUploader,
            MeasurementResponsibility::Finalizer => $operation->paymentFinalizer,
        };

        return $user instanceof User ? $user : null;
    }

    private function slaException(
        Measurement $measurement,
        Operation $operation,
        int $stage,
        ?MeasurementResponsibility $responsibility,
    ): ?MeasurementOperationalException {
        try {
            $status = $this->sla->evaluate($measurement)['status'] ?? null;
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        $type = match ($status) {
            MeasurementSlaService::STATUS_NOT_CONFIGURED => MeasurementOperationalExceptionType::SlaNotConfigured,
            MeasurementSlaService::STATUS_INVALID_CONFIG => MeasurementOperationalExceptionType::SlaInvalidConfig,
            default => null,
        };

        if (! $type instanceof MeasurementOperationalExceptionType) {
            return null;
        }

        return $this->makeException(
            $measurement,
            $operation,
            $type,
            $stage,
            $responsibility,
            is_string($status) ? $status : null,
        );
    }

    private function makeException(
        Measurement $measurement,
        Operation $operation,
        MeasurementOperationalExceptionType $type,
        int $stage,
        ?MeasurementResponsibility $responsibility,
        ?string $slaStatus,
    ): MeasurementOperationalException {
        return new MeasurementOperationalException(
            measurement: $measurement,
            operation: $operation,
            type: $type,
            stage: $stage,
            expectedResponsibility: $responsibility,
            configuredResponsibleName: $responsibility instanceof MeasurementResponsibility
                ? $this->configuredResponsibleLabel($operation, $responsibility)
                : null,
            slaStatus: $slaStatus,
        );
    }

    private function configuredResponsibleLabel(
        Operation $operation,
        MeasurementResponsibility $responsibility,
    ): ?string {
        $user = $this->responsibleUser($operation, $responsibility);

        if (! $user instanceof User) {
            return null;
        }

        return $user->isActive() ? $user->name : $user->name.' (inativo)';
    }

    /** @return array<string, int> */
    private function emptyCounts(): array
    {
        $counts = [];

        foreach (MeasurementOperationalExceptionType::cases() as $type) {
            $counts[$type->value] = 0;
        }

        return $counts;
    }

    private function exceptionFilter(mixed $value): MeasurementOperationalExceptionType|false|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            return false;
        }

        return MeasurementOperationalExceptionType::tryFrom($value) ?? false;
    }

    private function positiveInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $validated === false ? null : (int) $validated;
    }

    private function filledInvalidInteger(mixed $raw, ?int $normalized): bool
    {
        return $raw !== null && $raw !== '' && $normalized === null;
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value
            ? $value
            : null;
    }

    private function filledInvalidDate(mixed $raw, ?string $normalized): bool
    {
        return $raw !== null && $raw !== '' && $normalized === null;
    }
}

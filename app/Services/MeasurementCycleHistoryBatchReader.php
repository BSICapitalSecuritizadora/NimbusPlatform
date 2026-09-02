<?php

namespace App\Services;

use App\DTOs\Measurements\AuthorizedMeasurementCycleHistory;
use App\Enums\AccessPermission;
use App\Models\Measurement;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

class MeasurementCycleHistoryBatchReader
{
    public function __construct(private MeasurementCycleHistoryReadModel $historyReadModel) {}

    /**
     * Every requested id is reintersected with the actor's current visibility.
     * Missing or invisible ids are omitted rather than disclosed.
     *
     * @param  iterable<int, int>  $measurementIds
     * @return Collection<int, AuthorizedMeasurementCycleHistory>
     */
    public function read(User $actor, iterable $measurementIds): Collection
    {
        $this->authorize($actor);

        $ids = collect($measurementIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        $measurements = Measurement::query()
            ->visibleTo($actor)
            ->whereKey($ids)
            ->with([
                'operation:id,emission_id,code,title,responsible_user_id,stage2_reviewer_user_id,stage3_reviewer_user_id,payment_manager_user_id,payment_receipt_uploader_user_id,payment_finalizer_user_id',
                'operation.emission:id,name,bsi_code,if_code,isin_code',
                'reviews' => fn (HasMany $reviews): HasMany => $reviews
                    ->select(['id', 'measurement_id', 'stage', 'reviewer_user_id', 'status', 'reviewed_at'])
                    ->orderBy('stage'),
                'pauses' => fn (HasMany $pauses): HasMany => $pauses
                    ->select(['id', 'measurement_id', 'stage', 'paused_by', 'pause_reason', 'paused_at', 'resumed_at', 'resumed_by'])
                    ->orderBy('paused_at')
                    ->orderBy('id'),
                'payments' => fn (HasMany $payments): HasMany => $payments
                    ->select(['id', 'operation_id', 'measurement_id', 'pay_date', 'amount', 'method', 'created_by', 'created_at', 'receipt_uploaded_by', 'receipt_uploaded_at'])
                    ->orderBy('created_at')
                    ->orderBy('id'),
            ])
            ->orderBy('measurements.id')
            ->get([
                'id',
                'operation_id',
                'reference_month',
                'filename',
                'status',
                'current_stage',
                'analyzed_by',
                'analyzed_at',
            ]);

        if ($measurements->isEmpty()) {
            return collect();
        }

        $activitiesByMeasurement = Activity::query()
            ->where('subject_type', (new Measurement)->getMorphClass())
            ->whereIn('subject_id', $measurements->modelKeys())
            ->whereIn('log_name', [
                'measurement_workflow',
                'measurements',
                config('activitylog.default_log_name', 'default'),
            ])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get([
                'id',
                'log_name',
                'description',
                'subject_type',
                'subject_id',
                'causer_type',
                'causer_id',
                'event',
                'properties',
                'created_at',
            ])
            ->groupBy(fn (Activity $activity): int => (int) $activity->subject_id);

        return $measurements
            ->map(fn (Measurement $measurement): AuthorizedMeasurementCycleHistory => new AuthorizedMeasurementCycleHistory(
                measurement: $measurement,
                history: $this->historyReadModel->projectAuthorizedLoadedMeasurement(
                    $measurement,
                    $activitiesByMeasurement->get((int) $measurement->getKey(), collect())->values(),
                ),
            ))
            ->values();
    }

    private function authorize(User $actor): void
    {
        if (! $actor->can(AccessPermission::MeasurementsView->value)
            || ! $actor->can(AccessPermission::MeasurementsCycleReportsView->value)) {
            throw new AuthorizationException('Você não pode visualizar o relatório de ciclo de medições.');
        }
    }
}

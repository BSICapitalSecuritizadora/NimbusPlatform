<?php

namespace App\Services;

use App\Models\Measurement;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

class MeasurementStageActivityService
{
    /**
     * Builds a chronological list of formal stage decisions and current activity
     * for a measurement, ensuring returned stages, repeated visits and full audit
     * notes are preserved without overwriting history.
     *
     * @return Collection<int, array{
     *     stage: int,
     *     stage_label: string,
     *     reviewer_name: string,
     *     status_key: string,
     *     status_label: string,
     *     reviewed_at: ?Carbon,
     *     notes: ?string,
     *     note_label: string,
     *     note_type: string
     * }>
     */
    public function for(Measurement $measurement): Collection
    {
        $measurement->loadMissing([
            'operation.responsibleUser',
            'operation.stage2Reviewer',
            'operation.stage3Reviewer',
            'operation.paymentManager',
            'operation.paymentFinalizer',
            'reviews.reviewer',
        ]);

        $events = collect();

        // 1. Collect all workflow stage decision activities from activity log
        $activities = $measurement->activities()
            ->with('causer')
            ->whereIn('description', [
                'measurement_stage_approved',
                'measurement_stage_rejected',
                'measurement_finalization_returned',
                'measurement_finalized',
            ])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        foreach ($activities as $activity) {
            $mapped = $this->mapActivity($measurement, $activity);
            if ($mapped !== null) {
                $events->push($mapped);
            }
        }

        // 2. If no activities exist in the activity log (e.g. legacy records or tests with direct reviews), fallback to reviews
        if ($events->isEmpty()) {
            $this->pushReviewsFallback($measurement, $events);
        } else {
            // If the measurement is currently open, append the active stage currently awaiting decision as [Em análise]
            $this->pushActiveStageIfPending($measurement, $events);
        }

        return $events;
    }

    /**
     * Maps an ActivityLog record to a formal stage activity item.
     *
     * @return array{
     *     stage: int,
     *     stage_label: string,
     *     reviewer_name: string,
     *     status_key: string,
     *     status_label: string,
     *     reviewed_at: ?Carbon,
     *     notes: ?string,
     *     note_label: string,
     *     note_type: string
     * }|null
     */
    private function mapActivity(Measurement $measurement, Activity $activity): ?array
    {
        $props = $activity->properties?->all() ?? [];
        $description = $activity->description;
        $timestamp = $activity->created_at;

        if ($description === 'measurement_stage_approved') {
            $stage = (int) ($props['stage'] ?? 1);
            $stageLabel = MeasurementWorkflow::STAGE_LABELS[$stage] ?? "Etapa {$stage}";
            $reviewerName = $activity->causer?->name
                ?? $this->resolveActorName($props['actual_actor_user_id'] ?? null)
                ?? $this->resolveResponsibleName($measurement, $stage);

            return [
                'stage' => $stage,
                'stage_label' => $stageLabel,
                'reviewer_name' => $reviewerName,
                'status_key' => 'approved',
                'status_label' => 'Aprovada',
                'reviewed_at' => $timestamp,
                'notes' => filled($props['notes'] ?? null) ? (string) $props['notes'] : null,
                'note_label' => 'Observação',
                'note_type' => 'approved',
            ];
        }

        if ($description === 'measurement_stage_rejected') {
            $stage = (int) ($props['stage'] ?? 1);
            $stageLabel = MeasurementWorkflow::STAGE_LABELS[$stage] ?? "Etapa {$stage}";
            $targetStage = isset($props['target_stage']) && (int) $props['target_stage'] > 0
                ? (int) $props['target_stage']
                : null;
            $reviewerName = $activity->causer?->name
                ?? $this->resolveActorName($props['actual_actor_user_id'] ?? null)
                ?? $this->resolveResponsibleName($measurement, $stage);

            if ($targetStage !== null) {
                $targetLabel = MeasurementWorkflow::STAGE_LABELS[$targetStage] ?? "Etapa {$targetStage}";

                return [
                    'stage' => $stage,
                    'stage_label' => $stageLabel,
                    'reviewer_name' => $reviewerName,
                    'status_key' => 'returned',
                    'status_label' => "Devolvida para {$targetLabel}",
                    'reviewed_at' => $timestamp,
                    'notes' => filled($props['notes'] ?? null) ? (string) $props['notes'] : null,
                    'note_label' => 'Motivo da devolução',
                    'note_type' => 'returned',
                ];
            }

            return [
                'stage' => $stage,
                'stage_label' => $stageLabel,
                'reviewer_name' => $reviewerName,
                'status_key' => 'rejected',
                'status_label' => 'Rejeitada',
                'reviewed_at' => $timestamp,
                'notes' => filled($props['notes'] ?? null) ? (string) $props['notes'] : null,
                'note_label' => 'Motivo da rejeição',
                'note_type' => 'rejected',
            ];
        }

        if ($description === 'measurement_finalization_returned') {
            $stage = 5;
            $stageLabel = MeasurementWorkflow::STAGE_LABELS[5] ?? 'Finalização';
            $targetStage = (int) ($props['target_stage'] ?? 4);
            $targetLabel = MeasurementWorkflow::STAGE_LABELS[$targetStage] ?? "Etapa {$targetStage}";
            $reviewerName = $activity->causer?->name
                ?? $this->resolveActorName($props['actual_actor_user_id'] ?? null)
                ?? $this->resolveResponsibleName($measurement, 5);

            return [
                'stage' => 5,
                'stage_label' => $stageLabel,
                'reviewer_name' => $reviewerName,
                'status_key' => 'returned',
                'status_label' => "Devolvida para {$targetLabel}",
                'reviewed_at' => $timestamp,
                'notes' => filled($props['notes'] ?? null) ? (string) $props['notes'] : null,
                'note_label' => 'Motivo da devolução',
                'note_type' => 'returned',
            ];
        }

        if ($description === 'measurement_finalized') {
            $stage = 5;
            $stageLabel = MeasurementWorkflow::STAGE_LABELS[5] ?? 'Finalização';
            $reviewerName = $activity->causer?->name
                ?? $this->resolveActorName($props['actual_actor_user_id'] ?? null)
                ?? $this->resolveResponsibleName($measurement, 5);

            return [
                'stage' => 5,
                'stage_label' => $stageLabel,
                'reviewer_name' => $reviewerName,
                'status_key' => 'approved',
                'status_label' => 'Finalizada',
                'reviewed_at' => $timestamp,
                'notes' => filled($props['notes'] ?? null) ? (string) $props['notes'] : null,
                'note_label' => 'Observação',
                'note_type' => 'approved',
            ];
        }

        return null;
    }

    /**
     * Fallback for records with reviews but without ActivityLog entries.
     *
     * @param  Collection<int, array<string, mixed>>  $events
     */
    private function pushReviewsFallback(Measurement $measurement, Collection $events): void
    {
        $reviews = $measurement->reviews()
            ->with('reviewer')
            ->orderBy('reviewed_at')
            ->orderBy('stage')
            ->get();

        foreach ($reviews as $review) {
            $stage = (int) $review->stage;
            $stageLabel = MeasurementWorkflow::STAGE_LABELS[$stage] ?? "Etapa {$stage}";
            $reviewerName = $review->reviewer?->name ?? $this->resolveResponsibleName($measurement, $stage);

            if ($review->status === 'approved') {
                $events->push([
                    'stage' => $stage,
                    'stage_label' => $stageLabel,
                    'reviewer_name' => $reviewerName,
                    'status_key' => 'approved',
                    'status_label' => 'Aprovada',
                    'reviewed_at' => $review->reviewed_at,
                    'notes' => filled($review->notes) ? (string) $review->notes : null,
                    'note_label' => 'Observação',
                    'note_type' => 'approved',
                ]);
            } elseif ($review->status === 'rejected') {
                $isReturn = $stage > 1 && $measurement->status !== 'rejected';
                $targetStage = $stage > 1 ? $stage - 1 : 1;
                $targetLabel = MeasurementWorkflow::STAGE_LABELS[$targetStage] ?? "Etapa {$targetStage}";

                $events->push([
                    'stage' => $stage,
                    'stage_label' => $stageLabel,
                    'reviewer_name' => $reviewerName,
                    'status_key' => $isReturn ? 'returned' : 'rejected',
                    'status_label' => $isReturn ? "Devolvida para {$targetLabel}" : 'Rejeitada',
                    'reviewed_at' => $review->reviewed_at,
                    'notes' => filled($review->notes) ? (string) $review->notes : null,
                    'note_label' => $isReturn ? 'Motivo da devolução' : 'Motivo da rejeição',
                    'note_type' => $isReturn ? 'returned' : 'rejected',
                ]);
            } elseif ($review->status === 'pending') {
                $events->push([
                    'stage' => $stage,
                    'stage_label' => $stageLabel,
                    'reviewer_name' => $reviewerName,
                    'status_key' => 'pending',
                    'status_label' => 'Em análise',
                    'reviewed_at' => null,
                    'notes' => filled($review->notes) ? (string) $review->notes : null,
                    'note_label' => 'Observação',
                    'note_type' => 'pending',
                ]);
            }
        }
    }

    /**
     * Appends the active stage awaiting decision if measurement is open.
     *
     * @param  Collection<int, array<string, mixed>>  $events
     */
    private function pushActiveStageIfPending(Measurement $measurement, Collection $events): void
    {
        if (in_array($measurement->status, ['finalized', 'rejected'], true)) {
            return;
        }

        $currentStage = app(MeasurementWorkflow::class)->unifiedStage($measurement);
        $stageLabel = MeasurementWorkflow::STAGE_LABELS[$currentStage] ?? "Etapa {$currentStage}";
        $reviewerName = $this->resolveResponsibleName($measurement, $currentStage);

        $events->push([
            'stage' => $currentStage,
            'stage_label' => $stageLabel,
            'reviewer_name' => $reviewerName,
            'status_key' => $measurement->status === 'paused' ? 'paused' : 'pending',
            'status_label' => $measurement->status === 'paused' ? 'Pausada' : 'Em análise',
            'reviewed_at' => null,
            'notes' => null,
            'note_label' => 'Observação',
            'note_type' => 'pending',
        ]);
    }

    public function resolveResponsibleName(Measurement $measurement, int $stage): string
    {
        $operation = $measurement->operation;

        $user = match ($stage) {
            1 => $operation?->responsibleUser,
            2 => $operation?->stage2Reviewer,
            3 => $operation?->stage3Reviewer,
            4 => $operation?->paymentManager,
            5 => $operation?->paymentFinalizer,
            default => null,
        };

        if ($user && filled($user->name)) {
            return $user->name;
        }

        $review = $measurement->reviews->firstWhere('stage', $stage);
        if ($review && $review->reviewer && filled($review->reviewer->name)) {
            return $review->reviewer->name;
        }

        return 'Não atribuído';
    }

    private function resolveActorName(?int $userId): ?string
    {
        if (! $userId) {
            return null;
        }

        return User::query()->whereKey($userId)->value('name');
    }
}

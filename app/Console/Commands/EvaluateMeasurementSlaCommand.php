<?php

namespace App\Console\Commands;

use App\Enums\MeasurementResponsibility;
use App\Models\Measurement;
use App\Models\MeasurementReview;
use App\Models\ResponsibilityDelegation;
use App\Models\User;
use App\Notifications\MeasurementSlaNotification;
use App\Services\MeasurementSlaService;
use App\Services\MeasurementWorkflow;
use App\Services\ResponsibilityDelegationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class EvaluateMeasurementSlaCommand extends Command
{
    protected $signature = 'measurements:evaluate-sla {--dry-run : Apenas relata, não notifica}';

    protected $description = 'Avalia SLA das medições acionáveis e emite uma vez cada alerta por destinatário e ciclo';

    public function handle(
        MeasurementSlaService $sla,
        MeasurementWorkflow $workflow,
        ResponsibilityDelegationService $delegations,
    ): int {
        $dryRun = (bool) $this->option('dry-run');
        $warnings = 0;
        $overdues = 0;
        $skippedPaused = 0;

        MeasurementReview::query()
            ->where('status', 'pending')
            ->whereHas('measurement', fn ($query) => $query->whereIn('status', [
                'pending',
                'in_review',
                'awaiting_payment',
                'awaiting_receipt',
                'approved',
                'paused',
            ]))
            ->with([
                'measurement.operation',
                'measurement.reviews',
                'measurement.pauses',
            ])
            ->lazyById(100)
            ->each(function (MeasurementReview $review) use (
                $sla,
                $workflow,
                $delegations,
                $dryRun,
                &$warnings,
                &$overdues,
                &$skippedPaused,
            ): void {
                $measurement = $review->measurement;

                if (! $measurement instanceof Measurement) {
                    return;
                }

                if ($measurement->status === 'paused') {
                    $skippedPaused++;

                    return;
                }

                $stage = (int) $review->stage;

                if ($stage !== $workflow->unifiedStage($measurement)) {
                    return;
                }

                $evaluation = $sla->evaluate($measurement);
                $alertType = match ($evaluation['status']) {
                    MeasurementSlaService::STATUS_OVERDUE => 'overdue',
                    MeasurementSlaService::STATUS_APPROACHING => 'warning',
                    default => null,
                };

                if ($alertType === null) {
                    return;
                }

                $responsibility = $this->responsibilityFor($measurement, $stage);

                if (! $responsibility instanceof MeasurementResponsibility || ! $measurement->operation) {
                    return;
                }

                $recipients = $this->recipients($measurement, $responsibility, $delegations);

                foreach ($recipients as $recipient) {
                    $inserted = $dryRun || DB::table('measurement_sla_alerts')->insertOrIgnore([
                        'measurement_id' => $measurement->getKey(),
                        'stage' => $stage,
                        'alert_type' => $alertType,
                        'recipient_user_id' => $recipient['user']->getKey(),
                        'stage_started_at' => $evaluation['started_at'],
                        'business_day' => now()->toDateString(),
                        'notified_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]) === 1;

                    if (! $inserted) {
                        continue;
                    }

                    if (! $dryRun) {
                        try {
                            $recipient['user']->notify((new MeasurementSlaNotification(
                                $measurement,
                                $alertType,
                                $evaluation,
                                $recipient['delegation'],
                            ))->afterCommit());
                        } catch (\Throwable $exception) {
                            DB::table('measurement_sla_alerts')
                                ->where('measurement_id', $measurement->getKey())
                                ->where('stage', $stage)
                                ->where('alert_type', $alertType)
                                ->where('recipient_user_id', $recipient['user']->getKey())
                                ->where('stage_started_at', $evaluation['started_at'])
                                ->delete();
                            report($exception);
                        }
                    }

                    $alertType === 'warning' ? $warnings++ : $overdues++;
                }
            });

        $this->info("SLA evaluation complete: warnings={$warnings}, overdue={$overdues}, skipped_paused={$skippedPaused}, dry_run=".($dryRun ? 'yes' : 'no'));

        return self::SUCCESS;
    }

    private function responsibilityFor(Measurement $measurement, int $stage): ?MeasurementResponsibility
    {
        if ($stage !== MeasurementWorkflow::STAGE_FINALIZATION) {
            return MeasurementResponsibility::primaryForStage($stage);
        }

        return $measurement->status === 'awaiting_receipt'
            ? MeasurementResponsibility::ReceiptUploader
            : MeasurementResponsibility::Finalizer;
    }

    /**
     * @return list<array{user: User, delegation: ?ResponsibilityDelegation}>
     */
    private function recipients(
        Measurement $measurement,
        MeasurementResponsibility $responsibility,
        ResponsibilityDelegationService $delegations,
    ): array {
        $operation = $measurement->operation;
        $recipients = [];
        $directUserId = $operation->responsibleUserIdFor($responsibility);

        if ($directUserId !== null) {
            $direct = User::query()->whereKey($directUserId)->first();

            if ($direct?->isActive() && $direct->isApproved() && $direct->can($responsibility->permission())) {
                $recipients[$direct->getKey()] = ['user' => $direct, 'delegation' => null];
            }
        }

        foreach ($delegations->activeDelegatesForResponsibility($operation, $responsibility) as $delegation) {
            $recipients[$delegation->delegate_user_id] = [
                'user' => $delegation->delegate,
                'delegation' => $delegation,
            ];
        }

        return array_values($recipients);
    }
}

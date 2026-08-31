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
use App\Support\BusinessTime;
use App\Support\Users\UserIdentityMap;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

        // O agendador roda de hora em hora e o `$this->info()` do fim não ia a
        // lugar nenhum: saída de comando agendado não é capturada. Estes contadores
        // viram uma linha de log por execução -- agregada de propósito, porque
        // registrar cada medição saudável encheria o log e esconderia o resto.
        $counters = [
            'evaluated' => 0,
            'skipped_paused' => 0,
            'approaching' => 0,
            'overdue' => 0,
            'alerts_created' => 0,
            'alerts_deduplicated' => 0,
            'recipients_unavailable' => 0,
            'calendar_unavailable' => 0,
            'not_configured' => 0,
            'invalid_config' => 0,
            'notification_failures' => 0,
        ];

        // Uma execução avalia muitas medições das mesmas poucas operações, e
        // portanto dos mesmos poucos responsáveis. O mapa resolve cada um deles
        // uma vez -- com as relações que `can()` consulta --, nasce aqui e morre
        // no fim deste `handle()`. A próxima execução recomeça do banco.
        $users = new UserIdentityMap(['roles', 'permissions']);

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
                $users,
                $dryRun,
                &$counters,
            ): void {
                $measurement = $review->measurement;

                if (! $measurement instanceof Measurement) {
                    return;
                }

                if ($measurement->status === 'paused') {
                    $counters['skipped_paused']++;

                    return;
                }

                $stage = (int) $review->stage;

                if ($stage !== $workflow->unifiedStage($measurement)) {
                    return;
                }

                $evaluation = $sla->evaluate($measurement);
                $counters['evaluated']++;

                match ($evaluation['status']) {
                    MeasurementSlaService::STATUS_CALENDAR_UNAVAILABLE => $counters['calendar_unavailable']++,
                    MeasurementSlaService::STATUS_NOT_CONFIGURED => $counters['not_configured']++,
                    MeasurementSlaService::STATUS_INVALID_CONFIG => $counters['invalid_config']++,
                    default => null,
                };

                $alertType = match ($evaluation['status']) {
                    MeasurementSlaService::STATUS_OVERDUE => 'overdue',
                    MeasurementSlaService::STATUS_APPROACHING => 'warning',
                    default => null,
                };

                if ($alertType === null) {
                    return;
                }

                $alertType === 'warning' ? $counters['approaching']++ : $counters['overdue']++;

                $responsibility = $this->responsibilityFor($measurement, $stage);

                if (! $responsibility instanceof MeasurementResponsibility || ! $measurement->operation) {
                    return;
                }

                $recipients = $this->recipients($measurement, $responsibility, $delegations, $users);

                if ($recipients === []) {
                    // Prazo estourando e ninguém a avisar: nem responsável direto
                    // efetivo, nem delegado efetivo. É o caso que passava calado.
                    $counters['recipients_unavailable']++;

                    return;
                }

                foreach ($recipients as $recipient) {
                    $inserted = $dryRun || DB::table('measurement_sla_alerts')->insertOrIgnore([
                        'measurement_id' => $measurement->getKey(),
                        'stage' => $stage,
                        'alert_type' => $alertType,
                        'recipient_user_id' => $recipient['user']->getKey(),
                        'stage_started_at' => $evaluation['started_at'],
                        'business_day' => BusinessTime::dateString(),
                        'notified_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]) === 1;

                    if (! $inserted) {
                        $counters['alerts_deduplicated']++;

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
                            $counters['notification_failures']++;

                            continue;
                        }
                    }

                    $counters['alerts_created']++;
                }
            });

        $this->reportRun($counters, $dryRun);

        return self::SUCCESS;
    }

    /**
     * Uma linha por execução, com o que a execução encontrou.
     *
     * INFO para o resumo: uma avaliação que não achou nada de errado é
     * comportamento normal e não merece WARNING. O WARNING sai só quando a
     * execução terminou com algo que alguém precisa resolver -- e sai uma vez,
     * com os números, não uma vez por medição. Os problemas de calendário já
     * saem do próprio serviço, um por problema, e por isso não são repetidos
     * aqui como alarme.
     *
     * O contexto leva só identificadores de contagem: nenhum nome, e-mail ou
     * documento entra no log.
     *
     * @param  array<string, int>  $counters
     */
    private function reportRun(array $counters, bool $dryRun): void
    {
        $context = $counters + ['dry_run' => $dryRun];

        Log::info('SLA evaluation complete', $context);

        $actionable = $counters['calendar_unavailable']
            + $counters['invalid_config']
            + $counters['recipients_unavailable']
            + $counters['notification_failures'];

        if ($actionable > 0) {
            Log::warning('SLA evaluation finished with unresolved conditions', $context);
        }

        $this->info('SLA evaluation complete: '.collect($context)
            ->map(fn (int|bool $value, string $key): string => $key.'='.(is_bool($value) ? ($value ? 'yes' : 'no') : $value))
            ->implode(', '));
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
     * Quem deve ser alertado sobre esta responsabilidade: o responsável direto,
     * se ainda for efetivo, e os delegados efetivos -- deduplicados por usuário,
     * com a participação direta prevalecendo sobre a indicação delegada.
     *
     * A efetividade continua sendo decidida linha a linha; o mapa só evita
     * reconsultar o mesmo usuário. Cada medição ainda pergunta ao banco quais
     * delegações cobrem a sua operação.
     *
     * @return list<array{user: User, delegation: ?ResponsibilityDelegation}>
     */
    private function recipients(
        Measurement $measurement,
        MeasurementResponsibility $responsibility,
        ResponsibilityDelegationService $delegations,
        UserIdentityMap $users,
    ): array {
        $operation = $measurement->operation;
        $recipients = [];
        $direct = $users->get($operation->responsibleUserIdFor($responsibility));

        if ($direct?->isActive() && $direct->isApproved() && $direct->can($responsibility->permission())) {
            $recipients[$direct->getKey()] = ['user' => $direct, 'delegation' => null];
        }

        foreach ($delegations->activeDelegatesForResponsibility($operation, $responsibility, $users) as $delegation) {
            $recipients[$delegation->delegate_user_id] = [
                'user' => $delegation->delegate,
                'delegation' => $delegation,
            ];
        }

        return array_values($recipients);
    }
}

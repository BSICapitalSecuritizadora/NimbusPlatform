<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuOperationalSnapshot;
use App\Domain\PuCalculator\Enums\PuMonitorRunStatus;
use App\Models\PuMonitorRun;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Uma rodada do monitor operacional do PU (Fase 6): retrato → incidentes →
 * aviso, com a execução registrada.
 *
 * O monitor só observa e registra. Ele não gera, não estende, não homologa, não
 * recompõe obrigação e não toca liquidação: nada do que ele faz muda a verdade
 * financeira oficial. Pode rodar quantas vezes for preciso, inclusive em
 * paralelo -- a identidade do incidente é garantida pelo banco.
 *
 * A execução que não conclui fica "falhou" (ou "parcial", quando alguma
 * verificação falhou), com o erro -- nunca parece "tudo certo". Incidentes já
 * abertos continuam abertos: nada é resolvido sem a verificação que o produz
 * ter rodado inteira.
 */
final class PuOperationalMonitor
{
    private ?PuOperationalSnapshot $lastSnapshot = null;

    public function __construct(
        private readonly PuOperationalHealthService $health,
        private readonly PuOperationalIncidentService $incidents,
        private readonly PuOperationalAlertDispatcher $alerts,
        private readonly PuOperationalFailureClassifier $failures,
    ) {}

    public function run(string $trigger = 'scheduled', bool $notify = true): PuMonitorRun
    {
        $startedAt = CarbonImmutable::now();
        $run = PuMonitorRun::query()->create([
            'status' => PuMonitorRunStatus::Running,
            'trigger' => $trigger,
            'started_at' => $startedAt,
        ]);

        try {
            $snapshot = $this->lastSnapshot = $this->health->snapshot($startedAt);
            $sync = $this->incidents->synchronize($snapshot, $startedAt, (int) $run->id);
            $notified = $notify ? $this->alerts->dispatch($sync['notify']) : 0;

            $run->forceFill([
                'status' => $snapshot->allChecksSucceeded() ? PuMonitorRunStatus::Succeeded : PuMonitorRunStatus::Partial,
                'finished_at' => now(),
                'checks' => $snapshot->checks,
                'conditions_count' => count($snapshot->conditions()),
                'incidents_opened' => $sync['opened'],
                'incidents_updated' => $sync['updated'],
                'incidents_resolved' => $sync['resolved'],
                'notifications_sent' => $notified,
            ])->save();
        } catch (Throwable $exception) {
            report($exception);

            try {
                $run->forceFill([
                    'status' => PuMonitorRunStatus::Failed,
                    'finished_at' => now(),
                    'error' => $this->failures->sanitize($exception->getMessage()),
                ])->save();
            } catch (Throwable $recording) {
                // Sem conseguir nem registrar a falha, a execução fica "em execução"
                // para sempre: o resumo do monitor a trata como não concluída.
                report($recording);
            }
        }

        $this->prune();

        return $run->refresh();
    }

    public function lastSnapshot(): ?PuOperationalSnapshot
    {
        return $this->lastSnapshot;
    }

    /**
     * Histórico de execuções é diagnóstico: guardado só pelo prazo configurado.
     */
    private function prune(): void
    {
        try {
            $days = max(1, (int) config('pu_calculator.monitoring.run_retention_days', 30));
            PuMonitorRun::query()->where('started_at', '<', now()->subDays($days))->delete();
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}

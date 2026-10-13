<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuOperationalReadinessReport;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexerCapability;
use App\Domain\PuCalculator\Enums\PuMonitorRunStatus;
use App\Domain\PuCalculator\Enums\PuObligationRefreshStatus;
use App\Domain\PuCalculator\Enums\PuOperationalSeverity;
use App\Enums\AccessPermission;
use App\Jobs\ExtendPuDailyCurveJob;
use App\Jobs\GeneratePuDailyCurveJob;
use App\Models\Emission;
use App\Models\EmissionPuParameter;
use App\Models\PuMonitorRun;
use App\Models\PuObligationRefreshRequest;
use App\Models\PuOperationalIncident;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use ReflectionClass;
use Throwable;

/**
 * Preflight operacional do PU (Fase 7) -- só leitura.
 *
 * Junta, num retrato, o que precisa estar verificado antes de uma ativação: o
 * interruptor global, a certificação dos indexadores, a prontidão e a situação de
 * ativação de cada emissão configurada, a agenda e a fila, o monitor, os alertas,
 * o trabalho pendente, os incidentes críticos, quem detém as autoridades de
 * ativar, homologar e liquidar e a retenção da trilha. Não grava nada: a
 * prontidão de cada emissão roda numa transação desfeita
 * ({@see PuOperationalReadinessService}).
 *
 * Resultado de cada item: `ok`, `attention` (pede decisão ou configuração antes
 * de ativar), `blocked` (impede a ativação) ou `manual` (a aplicação não tem como
 * verificar -- backup, horário real do SGS, variáveis do App Service).
 */
final class PuOperationalPreflightService
{
    public const OK = 'ok';

    public const ATTENTION = 'attention';

    public const BLOCKED = 'blocked';

    public const MANUAL = 'manual';

    /** Comandos agendados do PU que a preflight confere. */
    private const PU_SCHEDULED_COMMANDS = [
        'pu:index-rates:sync',
        'pu:curves:generate-realized',
        'pu:operations:monitor',
        'pu:obligations:recover',
        'pu:queue-health',
    ];

    /** Eventos que a trilha protegida precisa reter. */
    private const REQUIRED_PROTECTED_EVENTS = [
        'homologated', 'invalidated', 'curve_extended', 'index_rate_corrected',
        'settlement_recorded', 'settlement_corrected', 'settlement_reversed',
        'operations_activated', 'operations_suspended',
    ];

    public function __construct(
        private readonly PuOperationalActivationPolicy $activation,
        private readonly PuOperationalReadinessService $readiness,
        private readonly PuIndexerCapabilityPolicy $indexers,
    ) {}

    /**
     * @param  list<int>|null  $emissionIds  só as emissões informadas (padrão: todas as configuradas)
     * @return array{evaluated_at: string, globally_enabled: bool, items: list<array{requirement: string, result: string, evidence: string, action: string}>, emissions: list<array<string, mixed>>, blocked: bool}
     */
    public function run(?array $emissionIds = null, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $items = [];
        $globallyEnabled = $this->activation->globallyEnabled();

        $items[] = $this->item(
            'Interruptor global (PU_OPERATIONS_ENABLED)',
            self::OK,
            $globallyEnabled ? 'Ligado: emissões ATIVADAS podem gerar, estender e homologar.' : 'Desligado: nenhuma geração, extensão, homologação ou liquidação roda neste ambiente.',
            $globallyEnabled ? 'Confirmar que ligar este ambiente foi autorizado.' : 'Só ligar quando a ativação da primeira emissão for autorizada.',
        );

        foreach (PuIndexer::cases() as $indexer) {
            $certification = $indexer->certification();
            $operational = $this->indexers->allows($indexer, PuIndexerCapability::Homologation);
            $items[] = $this->item(
                sprintf('Certificação do indexador %s', $indexer->value),
                $operational ? self::ATTENTION : self::OK,
                sprintf('%s; uso operacional %s.', $certification->label(), $operational ? 'permitido' : 'bloqueado (só simulação)'),
                implode(' ', $indexer->certificationConditions()),
            );
        }

        $emissions = $this->emissionReports($emissionIds, $now);
        $items[] = $this->emissionsItem($emissions);
        $items[] = $this->scheduleItem();
        $items[] = $this->queueItem();
        $items[] = $this->monitorItem($now);
        $items[] = $this->alertsItem();
        $items[] = $this->refreshItem();
        $items[] = $this->incidentsItem();
        $items[] = $this->permissionsItem();
        $items[] = $this->retentionItem();
        $items[] = $this->item(
            'Horário de divulgação do CDI (BCB/SGS)',
            self::MANUAL,
            sprintf('Premissa configurada: disponível depois de %s BRT, segunda sincronização às %s BRT.', (string) config('pu_indexes.bcb.series.cdi.available_after', '07:00'), (string) config('pu_indexes.bcb.series.cdi.post_publication_sync_at', '07:30')),
            'Confirmar o horário real de publicação no SGS antes da primeira emissão ativada.',
        );
        $items[] = $this->item(
            'Backup e restauração do banco (tabelas do PU)',
            self::MANUAL,
            'A aplicação não acessa nem verifica os backups do ambiente.',
            'Executar um ensaio de restauração das tabelas emission_pu_*, index_rate*, pu_* e activity_log num ambiente descartável.',
        );

        return [
            'evaluated_at' => $now->toIso8601String(),
            'globally_enabled' => $globallyEnabled,
            'items' => $items,
            'emissions' => array_map(fn (PuOperationalReadinessReport $report): array => $report->toArray(), $emissions),
            'blocked' => collect($items)->contains(fn (array $item): bool => $item['result'] === self::BLOCKED),
        ];
    }

    /**
     * @param  list<int>|null  $emissionIds
     * @return list<PuOperationalReadinessReport>
     */
    private function emissionReports(?array $emissionIds, CarbonImmutable $now): array
    {
        $ids = $emissionIds ?? array_map('intval', EmissionPuParameter::query()->orderBy('emission_id')->pluck('emission_id')->all());

        return Emission::query()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get()
            ->map(fn (Emission $emission): PuOperationalReadinessReport => $this->readiness->assess($emission, $now))
            ->values()
            ->all();
    }

    /**
     * @param  list<PuOperationalReadinessReport>  $reports
     * @return array{requirement: string, result: string, evidence: string, action: string}
     */
    private function emissionsItem(array $reports): array
    {
        if ($reports === []) {
            return $this->item('Emissões configuradas para PU', self::OK, 'Nenhuma emissão com parâmetros de PU.', 'Nada a ativar.');
        }

        $byStatus = collect($reports)->countBy(fn (PuOperationalReadinessReport $report): string => $report->status->value)->all();
        $byActivation = collect($reports)->countBy(fn (PuOperationalReadinessReport $report): string => $report->activationStatus->value)->all();

        return $this->item(
            'Emissões configuradas para PU',
            self::OK,
            sprintf('%d emissão(ões). Prontidão: %s. Ativação: %s.', count($reports), $this->counts($byStatus), $this->counts($byActivation)),
            'Ativar só emissão sem bloqueio, com a lista de verificação contratual coberta pela referência da aprovação.',
        );
    }

    /**
     * @return array{requirement: string, result: string, evidence: string, action: string}
     */
    private function scheduleItem(): array
    {
        try {
            $events = collect(app(Schedule::class)->events())
                ->filter(fn (Event $event): bool => collect(self::PU_SCHEDULED_COMMANDS)->contains(fn (string $command): bool => str_contains((string) $event->command, $command)));
        } catch (Throwable $exception) {
            return $this->item('Agenda do PU', self::ATTENTION, 'A agenda não pôde ser lida: '.$exception->getMessage(), 'Conferir `php artisan schedule:list`.');
        }

        $withoutOverlap = $events->filter(fn (Event $event): bool => ! $event->withoutOverlapping);
        $evidence = $events->map(fn (Event $event): string => sprintf(
            '%s [%s %s%s]',
            trim(str_replace(["'", '"'], '', (string) preg_replace('/^.*artisan\S*\s+/', '', (string) $event->command))),
            $event->expression,
            $event->timezone instanceof \DateTimeZone ? $event->timezone->getName() : (string) ($event->timezone ?: config('app.timezone')),
            $event->withoutOverlapping ? ', sem sobreposição' : '',
        ))->implode('; ');

        return $this->item(
            'Agenda do PU',
            $withoutOverlap->isEmpty() && $events->isNotEmpty() ? self::OK : self::ATTENTION,
            $evidence !== '' ? $evidence : 'Nenhuma tarefa do PU agendada.',
            'A rotina diária só age em emissão ativada com o interruptor ligado; o deploy roda `schedule:work` no startup e no WebJob (dois agendadores, sobreposição evitada pelo cache compartilhado).',
        );
    }

    /**
     * @return array{requirement: string, result: string, evidence: string, action: string}
     */
    private function queueItem(): array
    {
        $connection = (string) config('queue.default');
        $retryAfter = (int) config('queue.connections.'.$connection.'.retry_after', 0);
        $timeouts = [
            'GeneratePuDailyCurveJob' => (int) ((new ReflectionClass(GeneratePuDailyCurveJob::class))->getDefaultProperties()['timeout'] ?? 0),
            'ExtendPuDailyCurveJob' => (int) ((new ReflectionClass(ExtendPuDailyCurveJob::class))->getDefaultProperties()['timeout'] ?? 0),
        ];
        $longest = max($timeouts);
        $safe = $connection === 'sync' || $retryAfter > $longest;

        return $this->item(
            'Fila (timeouts x retry_after)',
            $safe ? self::OK : self::BLOCKED,
            sprintf('Conexão %s, retry_after %ds; timeout dos jobs: %s; trava da geração/extensão 1800s; concessão da atualização de obrigações %ds.', $connection, $retryAfter, $this->counts($timeouts, 's'), (int) config('pu_calculator.obligation_refresh.lease_seconds', 900)),
            $safe ? 'Worker do deploy: --tries=1 --timeout=600 (o timeout do job prevalece); conferir que o worker está rodando.' : 'retry_after precisa ser maior que o maior timeout de job do PU, senão o job é entregue duas vezes.',
        );
    }

    /**
     * @return array{requirement: string, result: string, evidence: string, action: string}
     */
    private function monitorItem(CarbonImmutable $now): array
    {
        $staleAfter = (int) config('pu_calculator.monitoring.monitor_stale_after_minutes', 60);
        $last = PuMonitorRun::query()->whereNotNull('finished_at')->orderByDesc('id')->first();

        if (! $last instanceof PuMonitorRun) {
            return $this->item('Monitor operacional', self::ATTENTION, 'Nenhuma execução completa do monitor registrada.', 'Conferir o agendador (`pu:operations:monitor` a cada 15 min).');
        }

        $fresh = $last->finished_at !== null && $last->finished_at->gte($now->subMinutes($staleAfter));

        return $this->item(
            'Monitor operacional',
            $fresh && $last->status !== PuMonitorRunStatus::Failed ? self::OK : self::ATTENTION,
            sprintf('Última execução #%d: %s, terminada em %s.', $last->id, $last->status?->value ?? '—', $last->finished_at?->toIso8601String() ?? '—'),
            $fresh ? 'Nada.' : sprintf('O monitor não completa há mais de %d minutos: conferir o agendador.', $staleAfter),
        );
    }

    /**
     * @return array{requirement: string, result: string, evidence: string, action: string}
     */
    private function alertsItem(): array
    {
        $recipients = count((array) config('pu_calculator.alerts.recipients', []));

        try {
            $monitors = User::permission(AccessPermission::PuOperationsMonitor->value)->operational()->count();
        } catch (Throwable) {
            $monitors = 0;
        }

        return $this->item(
            'Entrega de alertas',
            $recipients > 0 && $monitors > 0 ? self::OK : self::ATTENTION,
            sprintf('%d destinatário(s) de e-mail em PU_CALCULATOR_ALERT_RECIPIENTS; %d usuário(s) ativo(s) com pu.operations.monitor (sino).', $recipients, $monitors),
            $recipients > 0 ? 'Confirmar que os destinatários são autorizados a ver divergência de liquidação.' : 'Configurar PU_CALCULATOR_ALERT_RECIPIENTS no App Service antes da primeira ativação.',
        );
    }

    /**
     * @return array{requirement: string, result: string, evidence: string, action: string}
     */
    private function refreshItem(): array
    {
        $open = PuObligationRefreshRequest::query()->open()->count();
        $stuck = PuObligationRefreshRequest::query()
            ->whereIn('status', [PuObligationRefreshStatus::Exhausted->value, PuObligationRefreshStatus::Blocked->value])
            ->count();

        return $this->item(
            'Atualização durável das obrigações',
            $stuck === 0 ? self::OK : self::BLOCKED,
            sprintf('%d pedido(s) aberto(s); %d esgotado(s) ou bloqueado(s).', $open, $stuck),
            $stuck === 0 ? 'Nada.' : 'Retomar com `pu:obligations:recover --retry` (autorizado) antes de ativar.',
        );
    }

    /**
     * @return array{requirement: string, result: string, evidence: string, action: string}
     */
    private function incidentsItem(): array
    {
        $critical = PuOperationalIncident::query()->open()->where('severity', PuOperationalSeverity::Critical->value)->count();
        $open = PuOperationalIncident::query()->open()->count();

        return $this->item(
            'Incidentes operacionais',
            $critical === 0 ? self::OK : self::BLOCKED,
            sprintf('%d incidente(s) aberto(s), %d crítico(s).', $open, $critical),
            $critical === 0 ? 'Nada.' : 'Tratar os incidentes críticos antes de ativar.',
        );
    }

    /**
     * @return array{requirement: string, result: string, evidence: string, action: string}
     */
    private function permissionsItem(): array
    {
        $holders = [];

        foreach ([AccessPermission::PuOperationsActivate, AccessPermission::PuCurveHomologate, AccessPermission::PuCurveGenerate, AccessPermission::PuSettlementRecord] as $permission) {
            try {
                $holders[$permission->value] = User::permission($permission->value)->operational()->count();
            } catch (Throwable) {
                $holders[$permission->value] = 0;
            }
        }

        $segregated = $holders[AccessPermission::PuCurveHomologate->value] >= 1 && $holders[AccessPermission::PuCurveGenerate->value] >= 1;

        return $this->item(
            'Autoridades (ativar, homologar, gerar, liquidar)',
            $holders[AccessPermission::PuOperationsActivate->value] > 0 && $segregated ? self::OK : self::ATTENTION,
            'Usuários ativos por permissão: '.$this->counts($holders).'.',
            'Maker/checker: quem gera não homologa a própria curva (salvo o responsável da área, com validação e justificativa). Ativar não é homologar.',
        );
    }

    /**
     * @return array{requirement: string, result: string, evidence: string, action: string}
     */
    private function retentionItem(): array
    {
        $protected = (array) config('audit.protected_events.'.PuAuditLogService::LOG_NAME, []);
        $missing = array_values(array_diff(self::REQUIRED_PROTECTED_EVENTS, $protected));

        return $this->item(
            'Retenção da trilha de auditoria do PU',
            $missing === [] ? self::OK : self::BLOCKED,
            $missing === [] ? sprintf('Eventos críticos com retenção protegida (%d dias).', (int) config('audit.retention_workflow_days', 0)) : 'Eventos sem retenção protegida: '.implode(', ', $missing).'.',
            'O prazo protegido continua pendente de aprovação jurídica/compliance.',
        );
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function counts(array $counts, string $suffix = ''): string
    {
        return collect($counts)->map(fn (int $count, string $key): string => sprintf('%s=%d%s', $key, $count, $suffix))->implode(', ');
    }

    /**
     * @return array{requirement: string, result: string, evidence: string, action: string}
     */
    private function item(string $requirement, string $result, string $evidence, string $action): array
    {
        return ['requirement' => $requirement, 'result' => $result, 'evidence' => $evidence, 'action' => $action];
    }
}

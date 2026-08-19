<?php

declare(strict_types=1);

namespace App\Support\PuCalculator;

use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Domain\PuCalculator\Services\PuOperationalMonitorService;

/**
 * Compoe, para a camada de apresentacao, os indicadores ja calculados pelo
 * PuOperationalMonitorService em grupos operacionais (estado geral, excecoes e
 * fila) com tom semantico derivado do proprio valor de cada indicador.
 *
 * Esta classe nao calcula regra de negocio: apenas agrupa e rotula.
 *
 * @phpstan-type IndicatorArray array{
 *     key: string,
 *     label: string,
 *     value: int,
 *     description: string,
 *     icon: string,
 *     tone: string,
 *     focus: string|null,
 * }
 */
class PuOperationalDashboardData
{
    /**
     * Estagios da esteira operacional, na ordem real do fluxo de PU.
     */
    private const PIPELINE_STAGES = [
        PuCurveStatus::Processing,
        PuCurveStatus::Generated,
        PuCurveStatus::Validated,
        PuCurveStatus::Homologated,
    ];

    /**
     * Texto auxiliar de cada estagio, na linguagem operacional ja usada no sistema.
     */
    private const PIPELINE_DESCRIPTIONS = [
        'processing' => 'Geração ou validação em curso',
        'generated' => 'Curva gerada, aguardando validação',
        'validated' => 'Validada contra a planilha de referência',
        'homologated' => 'Homologada e protegida contra sobrescrita',
    ];

    /**
     * Peso semantico de cada estagio quando ha emissoes nele.
     */
    private const PIPELINE_SEVERITIES = [
        'processing' => 'progress',
        'generated' => 'neutral',
        'validated' => 'positive',
        'homologated' => 'positive',
    ];

    public function __construct(
        private readonly PuOperationalMonitorService $monitor,
    ) {}

    /**
     * @return array{
     *     total: int,
     *     homologated: int,
     *     validated: int,
     *     pipeline: list<array{key: string, label: string, value: int, description: string, tone: string, focus: string}>,
     *     exceptions: list<IndicatorArray>,
     *     queue: list<IndicatorArray>,
     *     exception_total: int,
     *     queue_total: int,
     *     health: array{tone: string, label: string, headline: string, description: string, icon: string, chips: list<array{label: string, value: int, tone: string}>},
     * }
     */
    public function overview(): array
    {
        $counts = $this->monitor->statusCounts();
        $queue = $this->monitor->queueMetrics();
        $missingCdi = $this->monitor->missingCdiCount();

        $exceptions = $this->exceptions($counts, $queue, $missingCdi);
        $queueIndicators = $this->queueIndicators($counts, $queue);

        return [
            'total' => $counts['total'],
            'homologated' => $counts[PuCurveStatus::Homologated->value],
            'validated' => $counts[PuCurveStatus::Validated->value],
            'pipeline' => $this->pipeline($counts),
            'exceptions' => $exceptions,
            'queue' => $queueIndicators,
            'exception_total' => $this->sumValues($exceptions),
            'queue_total' => $this->sumValues($queueIndicators),
            'health' => $this->health($counts, $queue, $missingCdi),
        ];
    }

    /**
     * Esteira "PU configurado -> Gerada -> Validada -> Homologada" com a
     * quantidade de emissoes cuja versao mais recente esta em cada estagio.
     *
     * @param  array<string, int>  $counts
     * @return list<array{key: string, label: string, value: int, description: string, tone: string, focus: string}>
     */
    private function pipeline(array $counts): array
    {
        $stages = [$this->stage(
            key: 'sem_curva',
            label: 'Sem curva',
            value: $counts['sem_curva'],
            description: 'PU configurado, mas curva ainda não gerada',
            severity: 'attention',
        )];

        foreach (self::PIPELINE_STAGES as $status) {
            $stages[] = $this->stage(
                key: $status->value,
                label: $status->label(),
                value: $counts[$status->value],
                description: self::PIPELINE_DESCRIPTIONS[$status->value],
                severity: self::PIPELINE_SEVERITIES[$status->value],
            );
        }

        return $stages;
    }

    /**
     * @return array{key: string, label: string, value: int, description: string, tone: string, focus: string}
     */
    private function stage(string $key, string $label, int $value, string $description, string $severity): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'description' => $description,
            'tone' => $this->toneFor($severity, $value),
            'focus' => $key,
        ];
    }

    /**
     * @param  array<string, int>  $counts
     * @param  array{pending_jobs: int, failed_pu_jobs: int, failed_jobs_total: int, stuck_versions: int}  $queue
     * @return list<IndicatorArray>
     */
    private function exceptions(array $counts, array $queue, int $missingCdi): array
    {
        return [
            $this->indicator(
                key: 'error',
                label: 'Com erro',
                value: $counts[PuCurveStatus::Error->value],
                description: 'Falha na geração ou na validação da curva',
                icon: 'heroicon-o-exclamation-circle',
                severity: 'critical',
                focus: PuCurveStatus::Error->value,
            ),
            $this->indicator(
                key: 'missing_cdi',
                label: 'CDI faltante',
                value: $missingCdi,
                description: 'Emissões bloqueadas por lacuna no índice',
                icon: 'heroicon-o-no-symbol',
                severity: 'critical',
                focus: 'missing_cdi',
            ),
            $this->indicator(
                key: 'divergent',
                label: 'Divergentes',
                value: $counts[PuCurveStatus::Divergent->value],
                description: 'Diferença apurada contra a planilha de referência',
                icon: 'heroicon-o-scale',
                severity: 'attention',
                focus: PuCurveStatus::Divergent->value,
            ),
            $this->indicator(
                key: 'sem_curva',
                label: 'Sem curva',
                value: $counts['sem_curva'],
                description: 'PU configurado, mas curva ainda não gerada',
                icon: 'heroicon-o-document-plus',
                severity: 'attention',
                focus: 'sem_curva',
            ),
            $this->indicator(
                key: 'obsolete',
                label: 'Obsoletas',
                value: $counts[PuCurveStatus::Obsolete->value],
                description: 'Curva invalidada ou substituída por versão mais recente',
                icon: 'heroicon-o-archive-box',
                severity: 'attention',
                focus: PuCurveStatus::Obsolete->value,
            ),
        ];
    }

    /**
     * @param  array<string, int>  $counts
     * @param  array{pending_jobs: int, failed_pu_jobs: int, failed_jobs_total: int, stuck_versions: int}  $queue
     * @return list<IndicatorArray>
     */
    private function queueIndicators(array $counts, array $queue): array
    {
        return [
            $this->indicator(
                key: 'processing',
                label: 'Processando',
                value: $counts[PuCurveStatus::Processing->value],
                description: 'Curvas em geração ou validação neste momento',
                icon: 'heroicon-o-arrow-path',
                severity: 'progress',
                focus: PuCurveStatus::Processing->value,
            ),
            $this->indicator(
                key: 'pending_jobs',
                label: 'Jobs pendentes',
                value: $queue['pending_jobs'],
                description: 'Tarefas aguardando execução na fila',
                icon: 'heroicon-o-queue-list',
                severity: 'progress',
            ),
            $this->indicator(
                key: 'stuck_versions',
                label: 'Travadas',
                value: $queue['stuck_versions'],
                description: 'Processamento sem avanço além do tempo esperado',
                icon: 'heroicon-o-clock',
                severity: 'attention',
            ),
            $this->indicator(
                key: 'failed_pu_jobs',
                label: 'Jobs de PU falhos',
                value: $queue['failed_pu_jobs'],
                description: 'Geração ou validação interrompida por erro',
                icon: 'heroicon-o-x-circle',
                severity: 'critical',
            ),
        ];
    }

    /**
     * Estado executivo do painel, derivado exclusivamente dos indicadores acima.
     *
     * @param  array<string, int>  $counts
     * @param  array{pending_jobs: int, failed_pu_jobs: int, failed_jobs_total: int, stuck_versions: int}  $queue
     * @return array{tone: string, label: string, headline: string, description: string, icon: string, chips: list<array{label: string, value: int, tone: string}>}
     */
    private function health(array $counts, array $queue, int $missingCdi): array
    {
        $failures = $counts[PuCurveStatus::Error->value] + $queue['failed_pu_jobs'] + $queue['stuck_versions'];
        $divergences = $counts[PuCurveStatus::Divergent->value];
        $attention = $divergences + $counts['sem_curva'] + $counts[PuCurveStatus::Obsolete->value];
        $critical = $failures + $missingCdi;

        $chips = [
            ['label' => $failures === 1 ? 'falha' : 'falhas', 'value' => $failures, 'tone' => $failures > 0 ? 'danger' : 'neutral'],
            ['label' => $divergences === 1 ? 'divergência' : 'divergências', 'value' => $divergences, 'tone' => $divergences > 0 ? 'warning' : 'neutral'],
            ['label' => $missingCdi === 1 ? 'CDI faltante' : 'CDIs faltantes', 'value' => $missingCdi, 'tone' => $missingCdi > 0 ? 'danger' : 'neutral'],
            ['label' => $queue['pending_jobs'] === 1 ? 'job pendente' : 'jobs pendentes', 'value' => $queue['pending_jobs'], 'tone' => 'neutral'],
        ];

        if ($critical > 0) {
            return [
                'tone' => 'danger',
                'label' => 'Ação imediata',
                'headline' => $critical === 1
                    ? '1 ocorrência crítica exige ação imediata'
                    : sprintf('%d ocorrências críticas exigem ação imediata', $critical),
                'description' => 'Falhas de processamento ou lacunas de índice estão bloqueando a evolução das curvas. Priorize os indicadores destacados abaixo.',
                'icon' => 'heroicon-o-exclamation-triangle',
                'chips' => $chips,
            ];
        }

        if ($attention > 0) {
            return [
                'tone' => 'warning',
                'label' => 'Atenção',
                'headline' => $attention === 1
                    ? '1 ocorrência exige atenção'
                    : sprintf('%d ocorrências exigem atenção', $attention),
                'description' => 'Nenhuma falha crítica, mas há curvas fora do estado final de homologação.',
                'icon' => 'heroicon-o-eye',
                'chips' => $chips,
            ];
        }

        return [
            'tone' => 'success',
            'label' => 'Operação normal',
            'headline' => 'Sem ocorrências críticas',
            'description' => $counts['total'] === 0
                ? 'Nenhuma emissão com parâmetros de PU configurados até o momento.'
                : 'Curvas, índices e fila de processamento dentro do esperado.',
            'icon' => 'heroicon-o-shield-check',
            'chips' => $chips,
        ];
    }

    /**
     * @return IndicatorArray
     */
    private function indicator(
        string $key,
        string $label,
        int $value,
        string $description,
        string $icon,
        string $severity,
        ?string $focus = null,
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'description' => $description,
            'icon' => $icon,
            'tone' => $this->toneFor($severity, $value),
            'focus' => $focus,
        ];
    }

    /**
     * Cor semantica discreta: neutro enquanto zerado, cor apenas quando ha ocorrencia.
     */
    private function toneFor(string $severity, int $value): string
    {
        if ($value <= 0) {
            return 'neutral';
        }

        return match ($severity) {
            'critical' => 'danger',
            'attention' => 'warning',
            'positive' => 'success',
            'progress' => 'info',
            default => 'neutral',
        };
    }

    /**
     * @param  list<IndicatorArray>  $indicators
     */
    private function sumValues(array $indicators): int
    {
        return array_sum(array_column($indicators, 'value'));
    }
}

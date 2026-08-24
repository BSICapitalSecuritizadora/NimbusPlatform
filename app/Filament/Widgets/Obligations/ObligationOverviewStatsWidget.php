<?php

namespace App\Filament\Widgets\Obligations;

use App\Enums\AccessPermission;
use App\Services\Obligations\ObligationDashboardData;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ObligationOverviewStatsWidget extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected ?string $heading = 'Visão Operacional';

    protected ?string $description = 'Situação atual das obrigações que exigem acompanhamento operacional.';

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.obligations.obligation-overview-stats-widget';

    /**
     * @return array<string, mixed>
     */
    public function getSummaryData(): array
    {
        $canViewEvidence = (bool) auth()->user()?->can(AccessPermission::ObligationsViewEvidence->value);
        $filters = app(ObligationDashboardData::class)->sanitizeFilters($this->pageFilters, $canViewEvidence);
        $summary = app(ObligationDashboardData::class)->summary($filters);

        $summary['total_formatted'] = $this->format($summary['total']);
        $summary['vencida_formatted'] = $this->format($summary['vencida']);
        $summary['vence_hoje_formatted'] = $this->format($summary['vence_hoje']);
        $summary['proximos_7_dias_formatted'] = $this->format($summary['proximos_7_dias']);

        return $summary;
    }

    /**
     * @return list<array{
     *     key: string,
     *     label: string,
     *     value: string,
     *     raw_value: int,
     *     badge: string,
     *     tone: string,
     *     icon: string,
     *     description: string
     * }>
     */
    public function getOverviewCards(): array
    {
        $summary = $this->getSummaryData();

        return [
            [
                'key' => 'vencida',
                'label' => 'Vencidas',
                'value' => $this->format($summary['vencida']),
                'raw_value' => $summary['vencida'],
                'badge' => 'Crítico',
                'tone' => $summary['vencida'] > 0 ? 'danger' : 'neutral',
                'icon' => 'heroicon-o-exclamation-triangle',
                'description' => 'Prazo expirado e ainda em aberto',
            ],
            [
                'key' => 'vence_hoje',
                'label' => 'Vencem Hoje',
                'value' => $this->format($summary['vence_hoje']),
                'raw_value' => $summary['vence_hoje'],
                'badge' => 'Urgente',
                'tone' => $summary['vence_hoje'] > 0 ? 'warning' : 'neutral',
                'icon' => 'heroicon-o-clock',
                'description' => 'Exigem atuação no dia',
            ],
            [
                'key' => 'proximos_7_dias',
                'label' => 'Próximos 7 Dias',
                'value' => $this->format($summary['proximos_7_dias']),
                'raw_value' => $summary['proximos_7_dias'],
                'badge' => 'Atenção',
                'tone' => $summary['proximos_7_dias'] > 0 ? 'warning' : 'neutral',
                'icon' => 'heroicon-o-calendar-days',
                'description' => 'Janela curta de vencimento',
            ],
            [
                'key' => 'total',
                'label' => 'Total de Obrigações',
                'value' => $this->format($summary['total']),
                'raw_value' => $summary['total'],
                'badge' => 'Carteira',
                'tone' => 'primary',
                'icon' => 'heroicon-o-rectangle-stack',
                'description' => 'Base total no recorte atual',
            ],
        ];
    }

    /**
     * @return list<array{
     *     key: string,
     *     label: string,
     *     value: string,
     *     raw_value: int,
     *     tone: string,
     *     border_class: string,
     *     bg_class: string,
     *     dot_class: string,
     *     text_class: string
     * }>
     */
    public function getAlertItems(): array
    {
        $summary = $this->getSummaryData();

        return [
            [
                'key' => 'vencidas_criticas',
                'label' => 'Críticas vencidas',
                'value' => $this->format($summary['vencidas_criticas']),
                'raw_value' => $summary['vencidas_criticas'],
                'tone' => 'danger',
                'border_class' => 'border-danger-300/80 dark:border-danger-500/30',
                'bg_class' => 'bg-danger-50 dark:bg-danger-500/15',
                'dot_class' => 'bg-danger-500',
                'text_class' => 'text-danger-800 dark:text-danger-200',
            ],
            [
                'key' => 'sem_responsavel',
                'label' => 'Sem Responsável',
                'value' => $this->format($summary['sem_responsavel']),
                'raw_value' => $summary['sem_responsavel'],
                'tone' => 'danger',
                'border_class' => 'border-danger-300/80 dark:border-danger-500/30',
                'bg_class' => 'bg-danger-50 dark:bg-danger-500/15',
                'dot_class' => 'bg-danger-500',
                'text_class' => 'text-danger-800 dark:text-danger-200',
            ],
            [
                'key' => 'alta_prioridade_proximos_7_dias',
                'label' => 'Alta Prioridade em 7 Dias',
                'value' => $this->format($summary['alta_prioridade_proximos_7_dias']),
                'raw_value' => $summary['alta_prioridade_proximos_7_dias'],
                'tone' => 'warning',
                'border_class' => 'border-amber-300/80 dark:border-amber-500/30',
                'bg_class' => 'bg-amber-50 dark:bg-amber-500/15',
                'dot_class' => 'bg-amber-500',
                'text_class' => 'text-amber-800 dark:text-amber-200',
            ],
            [
                'key' => 'sem_data',
                'label' => 'Sem Data de Vencimento',
                'value' => $this->format($summary['sem_data']),
                'raw_value' => $summary['sem_data'],
                'tone' => 'neutral',
                'border_class' => 'border-gray-200 dark:border-white/10',
                'bg_class' => 'bg-bsi-paper dark:bg-white/5',
                'dot_class' => 'bg-gray-400',
                'text_class' => 'text-gray-800 dark:text-gray-200',
            ],
        ];
    }

    /**
     * @return list<array{
     *     key: string,
     *     label: string,
     *     value: string,
     *     raw_value: int,
     *     tag: string,
     *     tone: string,
     *     description: string
     * }>
     */
    public function getSecondaryCards(): array
    {
        $summary = $this->getSummaryData();

        return [
            [
                'key' => 'vencidas_criticas',
                'label' => 'Críticas vencidas',
                'value' => $this->format($summary['vencidas_criticas']),
                'raw_value' => $summary['vencidas_criticas'],
                'tag' => 'Crítico',
                'tone' => $summary['vencidas_criticas'] > 0 ? 'danger' : 'neutral',
                'description' => 'Críticas já vencidas',
            ],
            [
                'key' => 'alta_prioridade_proximos_7_dias',
                'label' => 'Alta Prioridade em 7 Dias',
                'value' => $this->format($summary['alta_prioridade_proximos_7_dias']),
                'raw_value' => $summary['alta_prioridade_proximos_7_dias'],
                'tag' => '7 Dias',
                'tone' => $summary['alta_prioridade_proximos_7_dias'] > 0 ? 'warning' : 'neutral',
                'description' => 'Alta ou crítica em até 7 dias',
            ],
            [
                'key' => 'em_analise',
                'label' => 'Em Análise',
                'value' => $this->format($summary['em_analise']),
                'raw_value' => $summary['em_analise'],
                'tag' => 'Revisão',
                'tone' => $summary['em_analise'] > 0 ? 'warning' : 'neutral',
                'description' => 'Aguardando validação operacional',
            ],
            [
                'key' => 'sem_responsavel',
                'label' => 'Sem Responsável',
                'value' => $this->format($summary['sem_responsavel']),
                'raw_value' => $summary['sem_responsavel'],
                'tag' => 'Atribuição',
                'tone' => $summary['sem_responsavel'] > 0 ? 'danger' : 'neutral',
                'description' => 'Ainda sem dono operacional',
            ],
            [
                'key' => 'proximos_30_dias',
                'label' => 'Próximos 30 Dias',
                'value' => $this->format($summary['proximos_30_dias']),
                'raw_value' => $summary['proximos_30_dias'],
                'tag' => '30 Dias',
                'tone' => 'info',
                'description' => 'Planejamento do próximo ciclo',
            ],
            [
                'key' => 'concluida',
                'label' => 'Concluídas',
                'value' => $this->format($summary['concluida']),
                'raw_value' => $summary['concluida'],
                'tag' => 'Concluído',
                'tone' => 'success',
                'description' => 'Marcadas como cumpridas',
            ],
            [
                'key' => 'nao_aplicavel',
                'label' => 'Não Aplicáveis',
                'value' => $this->format($summary['nao_aplicavel']),
                'raw_value' => $summary['nao_aplicavel'],
                'tag' => 'Encerrado',
                'tone' => 'neutral',
                'description' => 'Encerradas fora do fluxo operacional',
            ],
            [
                'key' => 'sem_data',
                'label' => 'Sem Data de Vencimento',
                'value' => $this->format($summary['sem_data']),
                'raw_value' => $summary['sem_data'],
                'tag' => 'Sem Prazo',
                'tone' => 'neutral',
                'description' => 'Requerem definição de prazo',
            ],
        ];
    }

    protected function getStats(): array
    {
        $canViewEvidence = (bool) auth()->user()?->can(AccessPermission::ObligationsViewEvidence->value);
        $filters = app(ObligationDashboardData::class)->sanitizeFilters($this->pageFilters, $canViewEvidence);
        $summary = app(ObligationDashboardData::class)->summary($filters);

        return [
            Stat::make('Vencidas', $this->format($summary['vencida']))
                ->color('danger')
                ->description('Prazo expirado e ainda em aberto'),
            Stat::make('Vencem Hoje', $this->format($summary['vence_hoje']))
                ->color('warning')
                ->description('Exigem atuação no dia'),
            Stat::make('Próximos 7 Dias', $this->format($summary['proximos_7_dias']))
                ->color('warning')
                ->description('Janela curta de vencimento'),
            Stat::make('Sem Responsável', $this->format($summary['sem_responsavel']))
                ->color('danger')
                ->description('Ainda sem dono operacional'),
            Stat::make('Críticas vencidas', $this->format($summary['vencidas_criticas']))
                ->color('danger')
                ->description('Críticas já vencidas'),
            Stat::make('Em Análise', $this->format($summary['em_analise']))
                ->color('warning')
                ->description('Aguardando validação operacional'),
            Stat::make('Próximos 30 Dias', $this->format($summary['proximos_30_dias']))
                ->color('info')
                ->description('Planejamento do próximo ciclo'),
            Stat::make('Alta Prioridade em 7 Dias', $this->format($summary['alta_prioridade_proximos_7_dias']))
                ->color('warning')
                ->description('Alta ou crítica em até 7 dias'),
            Stat::make('Total de Obrigações', $this->format($summary['total']))
                ->color('primary')
                ->description('Base total no recorte atual'),
            Stat::make('Concluídas', $this->format($summary['concluida']))
                ->color('success')
                ->description('Marcadas como cumpridas'),
            Stat::make('Não Aplicáveis', $this->format($summary['nao_aplicavel']))
                ->color('gray')
                ->description('Encerradas fora do fluxo operacional'),
            Stat::make('Sem Data de Vencimento', $this->format($summary['sem_data']))
                ->color('gray')
                ->description('Requerem definição de prazo'),
        ];
    }

    protected function format(int $value): string
    {
        return number_format($value, 0, ',', '.');
    }
}

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

    protected ?string $description = 'Indicadores priorizados para o acompanhamento diário das obrigações.';

    protected int|string|array $columnSpan = 'full';

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

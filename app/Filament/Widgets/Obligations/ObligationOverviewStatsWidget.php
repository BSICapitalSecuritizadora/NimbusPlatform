<?php

namespace App\Filament\Widgets\Obligations;

use App\Services\Obligations\ObligationDashboardData;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ObligationOverviewStatsWidget extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected ?string $heading = 'Resumo Geral';

    protected ?string $description = 'Indicadores consolidados das obrigações de todas as emissões.';

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $summary = app(ObligationDashboardData::class)->summary();

        return [
            Stat::make('Total de Obrigações', $this->format($summary['total']))
                ->color('primary')
                ->description('Obrigações cadastradas em todas as emissões'),
            Stat::make('Vencidas', $this->format($summary['vencida']))
                ->color('danger')
                ->description('Prazo expirado — atenção imediata'),
            Stat::make('Em Análise', $this->format($summary['em_analise']))
                ->color('warning')
                ->description('Aguardando validação operacional'),
            Stat::make('Concluídas', $this->format($summary['concluida']))
                ->color('success')
                ->description('Obrigações cumpridas'),
            Stat::make('Não Aplicáveis', $this->format($summary['nao_aplicavel']))
                ->color('gray')
                ->description('Encerradas por não se aplicarem'),
            Stat::make('Vencem Hoje', $this->format($summary['vence_hoje']))
                ->color('warning')
                ->description('Vencimento no dia de hoje'),
            Stat::make('Próximos 7 Dias', $this->format($summary['proximos_7_dias']))
                ->color('warning')
                ->description('Vencendo na próxima semana'),
            Stat::make('Próximos 30 Dias', $this->format($summary['proximos_30_dias']))
                ->color('info')
                ->description('Vencendo no próximo mês'),
            Stat::make('Sem Responsável', $this->format($summary['sem_responsavel']))
                ->color('danger')
                ->description('Ainda sem dono operacional definido'),
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

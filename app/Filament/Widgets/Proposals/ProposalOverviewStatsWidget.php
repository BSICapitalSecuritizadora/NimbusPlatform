<?php

namespace App\Filament\Widgets\Proposals;

use App\Support\Proposals\ProposalDashboardData;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ProposalOverviewStatsWidget extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected ?string $heading = 'Resumo Geral';

    protected ?string $description = 'Principais indicadores do fluxo de propostas e prospecções comerciais.';

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $summary = app(ProposalDashboardData::class)->summary();

        return [
            Stat::make('Total de Propostas', number_format($summary['total'], 0, ',', '.'))
                ->color('primary')
                ->description(number_format($summary['received_last_30_days'], 0, ',', '.').' novos envios nos últimos 30 dias'),
            Stat::make('Aguardando Documentação', number_format($summary['awaiting_completion'], 0, ',', '.'))
                ->color('warning')
                ->description('Documentação complementar pendente'),
            Stat::make('Em Análise Técnica', number_format($summary['in_review'], 0, ',', '.'))
                ->color('info')
                ->description('Em avaliação ativa pela equipe'),
            Stat::make('Aguardando Informações', number_format($summary['awaiting_information'], 0, ',', '.'))
                ->color('warning')
                ->description('Pendentes de resposta ou ajuste do cliente'),
            Stat::make('Propostas Aprovadas', number_format($summary['approved'], 0, ',', '.'))
                ->color('success')
                ->description('Propostas deferidas no fluxo comercial'),
            Stat::make('Propostas Indeferidas', number_format($summary['rejected'], 0, ',', '.'))
                ->color('danger')
                ->description('Propostas não aprovadas'),
            Stat::make('Formalização Concluída', number_format($summary['completed'], 0, ',', '.'))
                ->color('gray')
                ->description(number_format($summary['attention'], 0, ',', '.').' solicitações requerem atenção imediata'),
        ];
    }
}

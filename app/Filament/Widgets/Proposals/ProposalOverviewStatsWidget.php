<?php

namespace App\Filament\Widgets\Proposals;

use App\Support\Proposals\ProposalDashboardData;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ProposalOverviewStatsWidget extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected ?string $heading = 'Resumo geral';

    protected ?string $description = 'Indicadores principais do funil de propostas no escopo do usuário logado.';

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $summary = app(ProposalDashboardData::class)->summary();

        return [
            Stat::make('Total de propostas', number_format($summary['total'], 0, ',', '.'))
                ->color('primary')
                ->description(number_format($summary['received_last_30_days'], 0, ',', '.').' novas nos últimos 30 dias'),
            Stat::make('Aguardando complementação', number_format($summary['awaiting_completion'], 0, ',', '.'))
                ->color('warning')
                ->description('Propostas ainda pendentes de envio complementar do cliente'),
            Stat::make('Em análise', number_format($summary['in_review'], 0, ',', '.'))
                ->color('info')
                ->description('Em avaliação ativa do time comercial'),
            Stat::make('Aguardando retorno', number_format($summary['awaiting_information'], 0, ',', '.'))
                ->color('warning')
                ->description('Pendentes de resposta ou ajuste do cliente'),
            Stat::make('Aprovadas', number_format($summary['approved'], 0, ',', '.'))
                ->color('success')
                ->description('Propostas já aprovadas no fluxo comercial'),
            Stat::make('Rejeitadas', number_format($summary['rejected'], 0, ',', '.'))
                ->color('danger')
                ->description('Propostas encerradas por reprovação'),
            Stat::make('Concluídas', number_format($summary['completed'], 0, ',', '.'))
                ->color('gray')
                ->description(number_format($summary['attention'], 0, ',', '.').' propostas pedem atenção no momento'),
        ];
    }
}

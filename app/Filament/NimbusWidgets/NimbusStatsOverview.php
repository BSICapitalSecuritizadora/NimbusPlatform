<?php

namespace App\Filament\NimbusWidgets;

use App\Models\Nimbus\GeneralDocument;
use App\Models\Nimbus\PortalUser;
use App\Models\Nimbus\Submission;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class NimbusStatsOverview extends BaseWidget
{
    protected ?string $pollingInterval = '30s';

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int|array
    {
        return [
            'default' => 1,
            'sm' => 2,
            'md' => 2,
            'lg' => 3,
            'xl' => 3,
        ];
    }

    protected function getStats(): array
    {
        return [
            Stat::make('Envios recebidos', Submission::count())
                ->icon('heroicon-m-inbox-arrow-down')
                ->description('Total de submissões')
                ->extraAttributes(['class' => 'nimbus-stat-card nimbus-stat-received']),

            Stat::make('Aguardando análise', Submission::whereIn('status', [
                Submission::STATUS_PENDING,
                Submission::STATUS_UNDER_REVIEW,
                Submission::STATUS_NEEDS_CORRECTION,
            ])->count())
                ->icon('heroicon-m-clock')
                ->description('Pendentes ou em revisão')
                ->extraAttributes(['class' => 'nimbus-stat-card nimbus-stat-pending']),

            Stat::make('Aprovados', Submission::where('status', Submission::STATUS_COMPLETED)->count())
                ->icon('heroicon-m-check-badge')
                ->description('Finalizados com sucesso')
                ->extraAttributes(['class' => 'nimbus-stat-card nimbus-stat-approved']),

            Stat::make('Rejeitados', Submission::where('status', Submission::STATUS_REJECTED)->count())
                ->icon('heroicon-m-x-circle')
                ->description('Recusados na análise')
                ->extraAttributes(['class' => 'nimbus-stat-card nimbus-stat-rejected']),

            Stat::make('Usuários cadastrados', PortalUser::where('status', 'ACTIVE')->count())
                ->icon('heroicon-m-users')
                ->description('Contas ativas no portal')
                ->extraAttributes(['class' => 'nimbus-stat-card nimbus-stat-users']),

            Stat::make('Documentos vigentes', GeneralDocument::where('is_active', true)->count())
                ->icon('heroicon-m-document-text')
                ->description('Biblioteca ativa')
                ->extraAttributes(['class' => 'nimbus-stat-card nimbus-stat-documents']),
        ];
    }
}

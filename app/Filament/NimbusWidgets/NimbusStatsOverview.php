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

    protected function getColumns(): int
    {
        return 3;
    }

    protected function getStats(): array
    {
        return [
            Stat::make('Envios Recebidos', Submission::count())
                ->icon('heroicon-m-inbox-arrow-down')
                ->extraAttributes(['class' => 'nimbus-stat-card bg-slate-500 border border-slate-600 shadow-sm']),

            Stat::make('Aguardando Análise', Submission::whereIn('status', [
                Submission::STATUS_PENDING,
                Submission::STATUS_UNDER_REVIEW,
                Submission::STATUS_NEEDS_CORRECTION,
            ])->count())
                ->icon('heroicon-m-clock')
                ->extraAttributes(['class' => 'nimbus-stat-card bg-amber-500 border border-amber-600 shadow-sm']),

            Stat::make('Aprovados', Submission::where('status', Submission::STATUS_COMPLETED)->count())
                ->icon('heroicon-m-check-badge')
                ->extraAttributes(['class' => 'nimbus-stat-card bg-emerald-500 border border-emerald-600 shadow-sm']),

            Stat::make('Rejeitados', Submission::where('status', Submission::STATUS_REJECTED)->count())
                ->icon('heroicon-m-x-circle')
                ->extraAttributes(['class' => 'nimbus-stat-card bg-rose-500 border border-rose-600 shadow-sm']),

            Stat::make('Usuários Cadastrados', PortalUser::where('status', 'ACTIVE')->count())
                ->icon('heroicon-m-users')
                ->extraAttributes(['class' => 'nimbus-stat-card bg-yellow-500 border border-yellow-600 shadow-sm']),

            Stat::make('Documentos Vigentes', GeneralDocument::where('is_active', true)->count())
                ->icon('heroicon-m-document-text')
                ->extraAttributes(['class' => 'nimbus-stat-card bg-sky-500 border border-sky-600 shadow-sm']),
        ];
    }
}

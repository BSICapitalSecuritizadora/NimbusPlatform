<?php

namespace App\Filament\NimbusWidgets;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use App\Models\Nimbus\Submission;
use App\Models\Nimbus\PortalUser;
use App\Models\Nimbus\GeneralDocument;

class NimbusStatsOverview extends BaseWidget
{
    protected static ?string $pollingInterval = '30s';
    
    // Spans 2 columns to match the left-to-right flow, or just "full" row
    protected int | string | array $columnSpan = 'full';

    protected function getColumns(): int
    {
        return 3;
    }

    protected function getStats(): array
    {
        return [
            Stat::make('Envios Recebidos', Submission::count())
                ->descriptionIcon('heroicon-m-inbox-arrow-down')
                ->color('secondary')
                ->extraAttributes(['class' => 'bg-gray-50 border border-gray-100 shadow-sm']),
            
            Stat::make('Aguardando Análise', Submission::where('status', 'PENDING')->count())
                ->descriptionIcon('heroicon-m-clock')
                ->color('warning')
                ->extraAttributes(['class' => 'bg-orange-50 border border-orange-100 shadow-sm']),

            Stat::make('Aprovados', Submission::where('status', 'COMPLETED')->count())
                ->descriptionIcon('heroicon-m-check-badge')
                ->color('success')
                ->extraAttributes(['class' => 'bg-emerald-50 border border-emerald-100 shadow-sm']),

            Stat::make('Rejeitados', Submission::where('status', 'REJECTED')->count())
                ->descriptionIcon('heroicon-m-x-circle')
                ->color('danger')
                ->extraAttributes(['class' => 'bg-rose-50 border border-rose-100 shadow-sm']),

            Stat::make('Usuários Cadastrados', PortalUser::where('status', 'ACTIVE')->count())
                ->descriptionIcon('heroicon-m-users')
                ->color('info')
                ->extraAttributes(['class' => 'bg-amber-50 border border-amber-100 shadow-sm']),

            Stat::make('Documentos Vigentes', GeneralDocument::where('is_active', true)->count())
                ->descriptionIcon('heroicon-m-document-text')
                ->color('primary')
                ->extraAttributes(['class' => 'bg-blue-50 border border-blue-100 shadow-sm']),
        ];
    }
}

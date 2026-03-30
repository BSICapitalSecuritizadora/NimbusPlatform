<?php

namespace App\Filament\Widgets\Nimbus\Widgets;

use App\Models\Nimbus\PortalUser;
use App\Models\Nimbus\Submission;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SubmissionStats extends BaseWidget
{
    protected ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        return [
            Stat::make('Novas Submissões (Pendentes)', Submission::where('status', 'PENDING')->count())
                ->description('Aguardando análise inicial')
                ->descriptionIcon('heroicon-m-clock')
                ->color('warning'),
            Stat::make('Em Análise', Submission::where('status', 'UNDER_REVIEW')->count())
                ->description('Sendo avaliadas internamente')
                ->descriptionIcon('heroicon-m-document-magnifying-glass')
                ->color('info'),
            Stat::make('Usuários no Portal', PortalUser::count())
                ->description('Emissores e parceiros liberados')
                ->descriptionIcon('heroicon-m-users')
                ->color('success'),
        ];
    }
}

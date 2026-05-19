<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\Proposals\ProposalAttentionTableWidget;
use App\Filament\Widgets\Proposals\ProposalOverviewStatsWidget;
use App\Filament\Widgets\Proposals\ProposalRecentTableWidget;
use App\Filament\Widgets\Proposals\ProposalRepresentativeLoadChartWidget;
use App\Filament\Widgets\Proposals\ProposalStatusDistributionChartWidget;
use App\Filament\Widgets\Proposals\ProposalVolumeChartWidget;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Filament\Support\Icons\Heroicon;

class ProposalDashboard extends Dashboard
{
    protected static string $routePath = '/proposal-dashboard';

    protected static ?string $title = 'Painel de Propostas';

    protected static ?string $navigationLabel = 'Painel de Propostas';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|\UnitEnum|null $navigationGroup = 'Comercial';

    protected static ?int $navigationSort = 5;

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user?->hasAnyRole(['super-admin', 'admin', 'commercial-representative'])
            || (bool) $user?->can('proposals.view');
    }

    public function getColumns(): int|array
    {
        return [
            'md' => 2,
            'xl' => 2,
        ];
    }

    public function getWidgets(): array
    {
        return [
            ProposalOverviewStatsWidget::class,
            ProposalStatusDistributionChartWidget::class,
            ProposalVolumeChartWidget::class,
            ProposalRepresentativeLoadChartWidget::class,
            ProposalAttentionTableWidget::class,
            ProposalRecentTableWidget::class,
        ];
    }

    public function getSubheading(): ?string
    {
        return auth()->user()?->hasRole('commercial-representative')
            ? 'Acompanhe sua carteira comercial, pendências de documentação e atividades recentes.'
            : 'Visão gerencial e operacional das propostas comerciais, desempenho de representantes e fluxo de prospecção.';
    }
}

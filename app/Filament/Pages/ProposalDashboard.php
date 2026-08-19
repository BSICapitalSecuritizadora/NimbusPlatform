<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\Proposals\ProposalAttentionTableWidget;
use App\Filament\Widgets\Proposals\ProposalOverviewStatsWidget;
use App\Filament\Widgets\Proposals\ProposalRecentTableWidget;
use App\Filament\Widgets\Proposals\ProposalRepresentativeLoadChartWidget;
use App\Filament\Widgets\Proposals\ProposalShortcutsWidget;
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

    protected static ?int $navigationSort = 0;

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-proposal-dashboard',
    ];

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user?->hasAnyRole(['super-admin', 'admin', 'commercial-representative'])
            || (bool) $user?->can('proposals.view');
    }

    public function getColumns(): int|array
    {
        return [
            'default' => 1,
            'xl' => 12,
        ];
    }

    public function getWidgets(): array
    {
        return [
            ProposalShortcutsWidget::class,
            ProposalOverviewStatsWidget::class,
            ProposalVolumeChartWidget::class,
            ProposalStatusDistributionChartWidget::class,
            ProposalRepresentativeLoadChartWidget::class,
            ProposalAttentionTableWidget::class,
            ProposalRecentTableWidget::class,
        ];
    }

    public function getSubheading(): ?string
    {
        $user = auth()->user();

        if ($user?->hasRole('commercial-representative')) {
            return 'Gestão individual da sua carteira comercial: acompanhe novas entradas, SLAs de atendimento e solicitações de clientes.';
        }

        return 'Visão gerencial e operacional das propostas comerciais, eficiência de conversão do funil e distribuição da fila de atendimento.';
    }
}

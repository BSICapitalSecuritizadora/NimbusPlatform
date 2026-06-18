<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\Obligations\ObligationOperationalTableWidget;
use App\Filament\Widgets\Obligations\ObligationOverviewStatsWidget;
use App\Filament\Widgets\Obligations\ObligationsByEmissionChartWidget;
use App\Filament\Widgets\Obligations\ObligationStatusDistributionChartWidget;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Filament\Support\Icons\Heroicon;

class ObligationDashboard extends Dashboard
{
    protected static string $routePath = '/obligation-dashboard';

    protected static ?string $title = 'Painel de Obrigações';

    protected static ?string $navigationLabel = 'Painel de Obrigações';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|\UnitEnum|null $navigationGroup = 'Gestão';

    protected static ?int $navigationSort = 25;

    public static function canAccess(): bool
    {
        return (bool) Filament::auth()->user()?->can('obligations.view');
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
            ObligationOverviewStatsWidget::class,
            ObligationStatusDistributionChartWidget::class,
            ObligationsByEmissionChartWidget::class,
            ObligationOperationalTableWidget::class,
        ];
    }

    public function getSubheading(): ?string
    {
        return 'Visão consolidada das obrigações de todas as emissões: vencimentos, pendências críticas e distribuição por emissão.';
    }
}

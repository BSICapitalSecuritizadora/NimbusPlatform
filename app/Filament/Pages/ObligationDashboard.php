<?php

namespace App\Filament\Pages;

use App\Enums\AccessPermission;
use App\Filament\Widgets\Obligations\ObligationEvidenceOverviewStatsWidget;
use App\Filament\Widgets\Obligations\ObligationOperationalTableWidget;
use App\Filament\Widgets\Obligations\ObligationOverdueAgingChartWidget;
use App\Filament\Widgets\Obligations\ObligationOverviewStatsWidget;
use App\Filament\Widgets\Obligations\ObligationPriorityDistributionChartWidget;
use App\Filament\Widgets\Obligations\ObligationsByAreaChartWidget;
use App\Filament\Widgets\Obligations\ObligationsByEmissionChartWidget;
use App\Filament\Widgets\Obligations\ObligationsByResponsibleChartWidget;
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
        $user = Filament::auth()->user();

        return (bool) $user?->can(AccessPermission::ObligationsViewDashboard->value)
            && (bool) $user?->can(AccessPermission::ObligationsView->value);
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
            ObligationEvidenceOverviewStatsWidget::class,
            ObligationStatusDistributionChartWidget::class,
            ObligationPriorityDistributionChartWidget::class,
            ObligationsByEmissionChartWidget::class,
            ObligationOverdueAgingChartWidget::class,
            ObligationsByResponsibleChartWidget::class,
            ObligationsByAreaChartWidget::class,
            ObligationOperationalTableWidget::class,
        ];
    }

    public function getSubheading(): ?string
    {
        return 'Visão operacional das obrigações de todas as emissões: vencimentos, responsáveis, prioridades, áreas e situação documental.';
    }
}

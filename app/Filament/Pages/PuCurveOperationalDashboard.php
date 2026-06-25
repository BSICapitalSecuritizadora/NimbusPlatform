<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\PuCalculator\PuCurveOperationalTableWidget;
use App\Filament\Widgets\PuCalculator\PuCurveOverviewStatsWidget;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Filament\Support\Icons\Heroicon;

class PuCurveOperationalDashboard extends Dashboard
{
    protected static string $routePath = '/pu-curve-dashboard';

    protected static ?string $title = 'Painel Operacional de PU';

    protected static ?string $navigationLabel = 'Painel Operacional de PU';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBarSquare;

    protected static string|\UnitEnum|null $navigationGroup = 'Operações';

    protected static ?int $navigationSort = 26;

    public static function canAccess(): bool
    {
        return (bool) Filament::auth()->user()?->can('pu.dashboard.view');
    }

    public function getColumns(): int|array
    {
        return 1;
    }

    public function getWidgets(): array
    {
        return [
            PuCurveOverviewStatsWidget::class,
            PuCurveOperationalTableWidget::class,
        ];
    }

    public function getSubheading(): ?string
    {
        return 'Estado consolidado das curvas de PU de todas as emissões: status, validações, homologações, CDI faltante e saúde da fila.';
    }
}

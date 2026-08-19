<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\PuCalculator\PuCurveOperationalSummaryWidget;
use App\Filament\Widgets\PuCalculator\PuCurveOperationalTableWidget;
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

    protected static ?int $navigationSort = 0;

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-pu-dashboard',
    ];

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
            PuCurveOperationalSummaryWidget::class,
            PuCurveOperationalTableWidget::class,
        ];
    }

    public function getSubheading(): ?string
    {
        return 'Monitoramento consolidado das curvas de PU: posição na esteira operacional, exceções que exigem ação, cobertura de índice e saúde da fila de processamento.';
    }
}

<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\Dashboard\DeadlinesWidget;
use App\Filament\Widgets\Dashboard\ExecutiveIndicatorsWidget;
use App\Filament\Widgets\Dashboard\MyPendingsWidget;
use App\Filament\Widgets\Dashboard\OperationalAlertsWidget;
use App\Filament\Widgets\Dashboard\RecentActivitiesWidget;
use App\Filament\Widgets\Dashboard\ShortcutsWidget;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'Cockpit Operacional';

    protected ?string $subheading = 'Prioridades, indicadores e prazos reunidos para uma leitura operacional mais rápida.';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page',
    ];

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
            ShortcutsWidget::class,
            ExecutiveIndicatorsWidget::class,
            OperationalAlertsWidget::class,
            MyPendingsWidget::class,
            RecentActivitiesWidget::class,
            DeadlinesWidget::class,
        ];
    }
}

<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\Dashboard\DeadlinesWidget;
use App\Filament\Widgets\Dashboard\ExecutiveIndicatorsWidget;
use App\Filament\Widgets\Dashboard\MyPendingsWidget;
use App\Filament\Widgets\Dashboard\OperationalAlertsWidget;
use App\Filament\Widgets\Dashboard\ShortcutsWidget;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'Cockpit Operacional';

    public function getColumns(): int|array
    {
        return [
            'default' => 1,
            'md' => 2,
            'xl' => 2,
        ];
    }

    public function getWidgets(): array
    {
        return [
            ShortcutsWidget::class,
            ExecutiveIndicatorsWidget::class,
            OperationalAlertsWidget::class,
            MyPendingsWidget::class,
            DeadlinesWidget::class,
            \App\Filament\Widgets\Dashboard\RecentActivitiesWidget::class,
        ];
    }
}

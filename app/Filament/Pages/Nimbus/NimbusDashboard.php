<?php

namespace App\Filament\Pages\Nimbus;

use App\Filament\NimbusWidgets\NimbusRecentActivities;
use App\Filament\NimbusWidgets\NimbusRecentSubmissions;
use App\Filament\NimbusWidgets\NimbusStatsOverview;
use App\Filament\NimbusWidgets\NimbusStatusDistribution;
use App\Filament\NimbusWidgets\NimbusVolumeChart;
use Filament\Pages\Dashboard as BaseDashboard;

class NimbusDashboard extends BaseDashboard
{
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static \UnitEnum|string|null $navigationGroup = 'NimbusDocs';

    protected static ?string $navigationParentItem = 'Visão Geral';

    protected static ?string $title = 'Visão Geral';

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?int $navigationSort = -10;

    protected static string $routePath = '/nimbus-dashboard';

    public function getColumns(): int|array
    {
        return 3;
    }

    public function getWidgets(): array
    {
        return [
            NimbusStatsOverview::class,
            NimbusRecentSubmissions::class,
            NimbusRecentActivities::class,
            NimbusStatusDistribution::class,
            NimbusVolumeChart::class,
        ];
    }
}

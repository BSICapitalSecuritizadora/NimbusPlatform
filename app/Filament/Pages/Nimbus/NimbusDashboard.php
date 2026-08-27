<?php

namespace App\Filament\Pages\Nimbus;

use App\Filament\NimbusWidgets\NimbusRecentActivities;
use App\Filament\NimbusWidgets\NimbusRecentSubmissions;
use App\Filament\NimbusWidgets\NimbusStatsOverview;
use App\Filament\NimbusWidgets\NimbusStatusDistribution;
use App\Filament\NimbusWidgets\NimbusVolumeChart;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard as BaseDashboard;

class NimbusDashboard extends BaseDashboard
{
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static \UnitEnum|string|null $navigationGroup = 'Gestão Documental Externa';

    protected static ?string $title = 'Visão Geral';

    protected static ?string $navigationLabel = 'Visão Geral';

    protected static ?int $navigationSort = -20;

    protected static string $routePath = '/gestao-documental-externa-dashboard';

    protected array $extraBodyAttributes = [
        'class' => 'bsi-cockpit-page bsi-nimbus-dashboard',
    ];

    public static function canAccess(): bool
    {
        return Filament::auth()->user()?->can('nimbus.submissions.view') ?? false;
    }

    public function getSubheading(): ?string
    {
        return 'Painel de controle de envios, solicitações e atividades de usuários no portal.';
    }

    public function getColumns(): int|array
    {
        return [
            'default' => 1,
            'sm' => 1,
            'md' => 2,
            'lg' => 12,
            'xl' => 12,
        ];
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

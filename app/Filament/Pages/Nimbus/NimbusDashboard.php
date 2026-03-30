<?php

namespace App\Filament\Pages\Nimbus;

use App\Filament\Widgets\Nimbus\Widgets\LatestSubmissions;
use App\Filament\Widgets\Nimbus\Widgets\SubmissionStats;
use BackedEnum;
use Filament\Pages\Dashboard as BaseDashboard;
use UnitEnum;

class NimbusDashboard extends BaseDashboard
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static string|UnitEnum|null $navigationGroup = 'NimbusDocs';

    protected static ?string $title = 'Dashboard NimbusDocs';

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?int $navigationSort = -1;

    protected static string $routePath = 'nimbusdocs';

    public function getWidgets(): array
    {
        return [
            SubmissionStats::class,
            LatestSubmissions::class,
        ];
    }
}

<?php

namespace App\Filament\Widgets\Dashboard;

use Filament\Widgets\Widget;
use Spatie\Activitylog\Models\Activity;

class RecentActivitiesWidget extends Widget
{
    protected string $view = 'filament.widgets.dashboard.recent-activities-widget';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 6;

    protected function getViewData(): array
    {
        if (! auth()->user()->can('activities.view')) {
            return ['activities' => collect()];
        }

        $activities = Activity::with('causer')
            ->latest()
            ->limit(10)
            ->get();

        return [
            'activities' => $activities,
        ];
    }
}

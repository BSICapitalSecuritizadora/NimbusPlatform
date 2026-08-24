<?php

namespace App\Filament\Widgets\BusinessCalendars;

use App\Domain\PuCalculator\Services\BusinessCalendarCatalogService;
use App\Domain\PuCalculator\Services\BusinessCalendarYearService;
use App\Models\BusinessCalendarImportRun;
use Filament\Widgets\Widget;

class BusinessCalendarOverview extends Widget
{
    protected static bool $isDiscovered = false;

    protected string $view = 'filament.widgets.business-calendars.business-calendar-overview';

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $latestAttemptIds = BusinessCalendarImportRun::query()
            ->selectRaw('MAX(id) AS id')
            ->groupBy('calendar_code')
            ->pluck('id');

        return [
            'definitions' => app(BusinessCalendarCatalogService::class)->definitions(),
            'summaries' => collect(app(BusinessCalendarYearService::class)->administrativeSummary())
                ->groupBy('calendar_code'),
            'latestAttempts' => BusinessCalendarImportRun::query()
                ->whereKey($latestAttemptIds)
                ->get()
                ->keyBy('calendar_code'),
        ];
    }
}

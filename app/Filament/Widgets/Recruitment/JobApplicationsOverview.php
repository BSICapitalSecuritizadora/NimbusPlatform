<?php

namespace App\Filament\Widgets\Recruitment;

use App\Models\JobApplication;
use Filament\Widgets\Widget;

class JobApplicationsOverview extends Widget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected string $view = 'filament.widgets.recruitment.job-applications-overview';

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $counts = JobApplication::query()
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as new_count,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as screening_count,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as interview_count,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as finalist_count,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as hired_count,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as rejected_count
            ', [
                JobApplication::STATUS_NEW,
                JobApplication::STATUS_SCREENING,
                JobApplication::STATUS_INTERVIEW,
                JobApplication::STATUS_FINALIST,
                JobApplication::STATUS_HIRED,
                JobApplication::STATUS_REJECTED,
            ])
            ->first();

        $totalCount = (int) ($counts?->total ?? 0);
        $newCount = (int) ($counts?->new_count ?? 0);
        $screeningCount = (int) ($counts?->screening_count ?? 0);
        $interviewCount = (int) ($counts?->interview_count ?? 0);
        $finalistCount = (int) ($counts?->finalist_count ?? 0);
        $hiredCount = (int) ($counts?->hired_count ?? 0);
        $rejectedCount = (int) ($counts?->rejected_count ?? 0);

        return [
            'metrics' => [
                'total' => $totalCount,
                'new' => $newCount,
                'screening' => $screeningCount,
                'interview' => $interviewCount,
                'finalist' => $finalistCount,
                'hired' => $hiredCount,
                'rejected' => $rejectedCount,
                'completed' => $hiredCount + $rejectedCount,
            ],
        ];
    }
}

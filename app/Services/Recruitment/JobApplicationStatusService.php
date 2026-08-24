<?php

namespace App\Services\Recruitment;

use App\Models\JobApplication;
use App\Models\JobApplicationStatusHistory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class JobApplicationStatusService
{
    /**
     * Change status and record history atomically.
     */
    public static function changeStatus(
        JobApplication $application,
        string $newStatus,
        ?string $note = null,
        ?int $changedById = null,
    ): bool {
        if ($application->status === $newStatus) {
            return false;
        }

        $fromStatus = $application->status;
        $changedById ??= Auth::id();

        return DB::transaction(function () use ($application, $fromStatus, $newStatus, $note, $changedById): bool {
            $application->update([
                'status' => $newStatus,
                'reviewed_at' => now(),
                'reviewed_by_user_id' => $changedById,
            ]);

            JobApplicationStatusHistory::create([
                'job_application_id' => $application->id,
                'from_status' => $fromStatus,
                'to_status' => $newStatus,
                'changed_by_user_id' => $changedById,
                'note' => $note,
            ]);

            return true;
        });
    }

    public static function recordInitialHistory(JobApplication $application): void
    {
        if ($application->statusHistories()->exists()) {
            return;
        }

        JobApplicationStatusHistory::create([
            'job_application_id' => $application->id,
            'from_status' => null,
            'to_status' => $application->status,
            'changed_by_user_id' => $application->reviewed_by_user_id,
            'note' => null,
            'created_at' => $application->created_at,
            'updated_at' => $application->created_at,
        ]);
    }

    /**
     * Backfill histories for existing applications without duplicating.
     */
    public static function backfillMissing(): int
    {
        $count = 0;
        JobApplication::query()
            ->whereDoesntHave('statusHistories')
            ->eachById(function (JobApplication $app) use (&$count): void {
                self::recordInitialHistory($app);
                $count++;
            });

        return $count;
    }
}

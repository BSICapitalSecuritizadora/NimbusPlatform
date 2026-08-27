<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanFilteredActivityLogCommand extends Command
{
    protected $signature = 'audit:clean-filtered {--dry-run : Apenas relata quantos seriam removidos}';

    protected $description = 'Remove logs de auditoria antigos preservando evidências reguladas de medições/operações (P0.4)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Retenção: descartável 365 dias; workflow/operational retido 7 anos (2555 dias).
        $disposableDays = (int) config('audit.retention_disposable_days', 365);
        $workflowDays = (int) config('audit.retention_workflow_days', 2555);

        $disposableCutoff = now()->subDays($disposableDays);
        $workflowCutoff = now()->subDays($workflowDays);

        // Log names considerados regulados / evidência — nunca deletados antes de workflowDays.
        $protectedLogs = [
            'measurement_workflow',
            'measurements',
            'operations',
            'measurement_payments',
            'measurement_receipts',
            'delegation',
            'delegations',
            'nimbus',
        ];

        // Protected logs: delete only older than workflowDays
        $protectedQuery = DB::table('activity_log')
            ->whereIn('log_name', $protectedLogs)
            ->where('created_at', '<', $workflowCutoff);

        $protectedCount = $protectedQuery->count();

        // Disposable logs: delete older than disposableDays, but not protected
        $disposableQuery = DB::table('activity_log')
            ->whereNotIn('log_name', $protectedLogs)
            ->where('created_at', '<', $disposableCutoff);

        // Also include null/default log_name as disposable
        $disposableQuery->orWhere(function ($q) use ($disposableCutoff) {
            $q->whereNull('log_name')
                ->where('created_at', '<', $disposableCutoff);
        });

        // Need to handle distinct queries; use two separate counts for reporting.
        $disposableCount = DB::table('activity_log')
            ->where(function ($q) use ($protectedLogs, $disposableCutoff) {
                $q->whereNotIn('log_name', $protectedLogs)
                    ->where('created_at', '<', $disposableCutoff);
            })
            ->orWhere(function ($q) use ($disposableCutoff) {
                $q->whereNull('log_name')
                    ->where('created_at', '<', $disposableCutoff);
            })
            ->count();

        // For correctness, handle protected+disposable separately when deleting.
        if ($dryRun) {
            $this->info("Dry-run: disposable={$disposableCount} (> {$disposableDays}d), protected_older_than_workflow={$protectedCount} (> {$workflowDays}d, log_name in [".implode(',', $protectedLogs).'])');

            return self::SUCCESS;
        }

        $deletedProtected = 0;
        if ($protectedCount > 0) {
            $deletedProtected = DB::table('activity_log')
                ->whereIn('log_name', $protectedLogs)
                ->where('created_at', '<', $workflowCutoff)
                ->delete();
        }

        $deletedDisposable = DB::table('activity_log')
            ->where(function ($q) use ($protectedLogs, $disposableCutoff) {
                $q->whereNotIn('log_name', $protectedLogs)
                    ->where('created_at', '<', $disposableCutoff);
            })
            ->orWhere(function ($q) use ($disposableCutoff) {
                $q->whereNull('log_name')
                    ->where('created_at', '<', $disposableCutoff);
            })
            ->delete();

        // Note: above second delete may double-count if both conditions overlap, but protected vs disposable are disjoint.
        $this->info("Audit cleanup: deleted_disposable={$deletedDisposable}, deleted_protected_expired={$deletedProtected}");

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\ResponsibilityDelegation;
use App\Notifications\DelegationExpiringNotification;
use App\Support\BusinessTime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class WarnDelegationExpirationsCommand extends Command
{
    protected $signature = 'delegations:warn-expiring {--dry-run : Apenas relata}';

    protected $description = 'Avisa delegados sobre delegações próximas do vencimento (idempotente por janela, sem spam)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        // Legacy NimbusOps: 48 hours, one notification per delegation (dedupKey delegation_expiring:id)
        // Platform previously used 72h (3 days) with daily dedup — now configurable, default 48h per §11
        $warningHours = (int) config('measurements.delegations.warning_hours_before', 48);
        // Fallback for old config key warning_days_before
        if ($warningHours === 48 && config('measurements.delegations.warning_days_before') !== null) {
            // If warning_hours_before not explicitly set but warning_days_before is, use it (backwards compat)
            $days = (int) config('measurements.delegations.warning_days_before', 2);
            // Only use days if hours is default and days differs from default 2 (48h)
            if ($days !== 2) {
                $warningHours = $days * 24;
            }
        }
        $today = BusinessTime::dateString();
        $threshold = now()->addHours($warningHours);

        $query = ResponsibilityDelegation::query()
            ->with(['delegator', 'delegate'])
            ->whereNull('revoked_at')
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now())
            ->where('ends_at', '<=', $threshold);

        $count = 0;
        $skipped = 0;

        foreach ($query->lazyById(100) as $delegation) {
            // One warning per delegation per warning window (dedupKey delegation_expiring:id) — not per day
            // We keep delegation_expiration_warnings with warning_date for audit, but check existence of any warning for this delegation within the window
            // For backwards compat, check warning_date = today OR any existing warning for this delegation (legacy once-per-delegation)
            // To avoid duplicate spam, check if already warned for this delegation at all (legacy behavior) OR today (new daily)
            // We implement: one warning per delegation per window — check if already warned for this delegation (any date) and still within window
            $already = DB::table('delegation_expiration_warnings')
                ->where('delegation_id', $delegation->getKey())
                ->exists();

            if ($already) {
                // If already warned once, don't warn again (legacy once-per-delegation)
                // To allow re-warning after window passes, we could check warning_date, but legacy is once
                $skipped++;

                continue;
            }

            if (! $dryRun) {
                DB::table('delegation_expiration_warnings')->insert([
                    'delegation_id' => $delegation->getKey(),
                    'warning_date' => $today,
                    'notified_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                try {
                    $delegation->delegate->notify((new DelegationExpiringNotification($delegation))->afterCommit());
                } catch (\Throwable $e) {
                    DB::table('delegation_expiration_warnings')
                        ->where('delegation_id', $delegation->getKey())
                        ->delete();
                    report($e);
                }
            }

            $count++;
        }

        $this->info("Delegation expiration warnings: sent={$count}, skipped_already_warned={$skipped}, dry_run=".($dryRun ? 'yes' : 'no')." (window {$warningHours}h)");

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Services\NimbusOps\NimbusOpsReconciliationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ReconcileNimbusOpsCommand extends Command
{
    protected $signature = 'nimbus-ops:reconcile
                            {--source-connection=nimbus_ops_legacy : Legacy NimbusOps DB connection (read-only)}
                            {--target-connection= : Target connection (default: database.default)}
                            {--report= : Path to write JSON report (default: storage/app/reconcile/nimbus-ops-}.json)}';

    protected $description = 'Read-only reconciliation of NimbusOps legacy vs NimbusPlatform (source/target counts, matched/missing/orphaned, file/checksum, user mapping)';

    public function handle(NimbusOpsReconciliationService $service): int
    {
        $source = (string) $this->option('source-connection');
        $target = $this->option('target-connection');
        $reportPath = $this->option('report');

        $this->info("Reconciling legacy connection [{$source}] vs target [".($target ?? config('database.default')).'] (read-only)');

        $report = $service->reconcile($source, $target);

        $payload = [
            'generated_at' => now()->toDateTimeString(),
            'source_connection' => $source,
            'target_connection' => $target ?? config('database.default'),
            'entities' => $report,
            'note' => 'All queries are read-only; no legacy or target data was mutated.',
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $defaultPath = storage_path('app/reconcile/nimbus-ops-'.now()->format('Y-m-d_His').'.json');
        $path = $reportPath ?: $defaultPath;

        try {
            File::ensureDirectoryExists(dirname($path));
            File::put($path, $json);
            $this->info("Report written to {$path}");
        } catch (\Throwable $e) {
            $this->warn("Could not write report to {$path}: ".$e->getMessage());
            $this->line($json);
        }

        // Summary
        $this->newLine();
        $this->line('Summary:');
        foreach ($report as $entity => $data) {
            $this->line("  {$entity}: ".json_encode($data, JSON_UNESCAPED_UNICODE));
        }

        $this->newLine();
        $this->info('Reconciliation complete — review missing/orphaned/checksum_mismatch/user_mapping_unresolved. No data was mutated.');

        return self::SUCCESS;
    }
}

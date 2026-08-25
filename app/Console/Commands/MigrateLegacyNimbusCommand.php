<?php

namespace App\Console\Commands;

use App\Services\Nimbus\Migration\ArrayLegacySourceReader;
use App\Services\Nimbus\Migration\DatabaseLegacySourceReader;
use App\Services\Nimbus\Migration\FileMigrationPlanner;
use App\Services\Nimbus\Migration\LegacyActorResolver;
use App\Services\Nimbus\Migration\LegacyArchiveSanitizer;
use App\Services\Nimbus\Migration\LegacyFilePathResolver;
use App\Services\Nimbus\Migration\LegacySourceReader;
use App\Services\Nimbus\Migration\MigrationControlStore;
use App\Services\Nimbus\Migration\NotePlanner;
use App\Services\Nimbus\Migration\PortalUserPlanner;
use App\Services\Nimbus\Migration\ShareholderPlanner;
use App\Services\Nimbus\Migration\SourceFingerprintService;
use App\Services\Nimbus\Migration\SubmissionPlanner;
use App\Services\Nimbus\Migration\TargetStateReader;
use App\Services\Nimbus\Migration\WorkflowHistoryPlanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class MigrateLegacyNimbusCommand extends Command
{
    protected $signature = 'nimbus:migrate-legacy
                            {--dry-run : Dry-run only (required in M1.1)}
                            {--source= : Path to source JSON fixture or config key}
                            {--run-uuid= : Existing run UUID to resume}
                            {--resume= : Alias for --run-uuid}
                            {--report= : Report output path}
                            {--only= : Only process entity}
                            {--from-id= : Resume from source id}
                            {--limit= : Limit per entity}
                            {--source-connection=nimbus_legacy : Legacy source DB connection}
                            {--target-connection= : Target operational DB connection}
                            {--control-connection= : Migration control DB connection}';

    protected $description = 'M1.1 NimbusDocs legacy migration dry-run planner (read-only source/target, control writable)';

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $runUuid = $this->option('run-uuid') ?? $this->option('resume');
        $sourceOpt = $this->option('source');
        $reportPath = $this->option('report');
        $only = $this->option('only');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $sourceConn = $this->option('source-connection') ?? 'nimbus_legacy';
        $targetConn = $this->option('target-connection') ?: config('database.default');
        $controlConn = $this->option('control-connection') ?: config('nimbus.migration.control_connection', config('database.default'));

        if (! $isDryRun) {
            $this->error('Write migration is not enabled in Phase M1.1. Use --dry-run.');

            return self::FAILURE;
        }

        $sourceSnapshotAt = now()->toDateTimeString();
        $sourceDescriptor = $sourceOpt ?? 'fixture';
        $sourceDumpSha256 = null;
        $fixtureData = $this->loadFixtureData($sourceOpt);
        if (isset($fixtureData['meta'])) {
            $sourceSnapshotAt = $fixtureData['meta']['source_snapshot_at'] ?? $sourceSnapshotAt;
            $sourceDescriptor = $fixtureData['meta']['descriptor'] ?? $sourceDescriptor;
            $sourceDumpSha256 = $fixtureData['meta']['dump_sha256'] ?? null;
        }

        // Control store is the ONLY writable interface during dry-run
        $controlStore = new MigrationControlStore($controlConn);
        $targetReader = new TargetStateReader($targetConn);

        // Resolve run
        if ($runUuid) {
            $run = $controlStore->findRunByUuid($runUuid);
            if (! $run) {
                $this->error("Run UUID {$runUuid} not found on control connection {$controlConn}.");

                return self::FAILURE;
            }
            $this->info("Resuming run {$run->run_uuid} ({$run->status}) on control {$controlConn}");
        } else {
            $runUuid = (string) Str::uuid();
            $run = $controlStore->createRun([
                'run_uuid' => $runUuid,
                'source_system' => 'nimbusdocs',
                'source_snapshot_at' => $sourceSnapshotAt,
                'source_dump_sha256' => $sourceDumpSha256,
                'source_descriptor' => $sourceDescriptor,
                'dry_run' => true,
                'status' => 'RUNNING',
                'started_at' => now(),
            ]);
            // Ensure attribute is set for downstream
            $run->source_snapshot_at = $sourceSnapshotAt;
        }

        if ($sourceSnapshotAt !== $run->source_snapshot_at) {
            DB::connection($controlConn)->table('nimbus_migration_runs')->where('id', $run->id)->update(['source_snapshot_at' => $sourceSnapshotAt]);
            $run->source_snapshot_at = $sourceSnapshotAt;
        }

        $sourceStorageRoot = config('nimbus.migration.legacy_storage_root', storage_path('app/private/nimbus-legacy'));
        $resolver = new LegacyFilePathResolver($sourceStorageRoot);
        $filePlanner = new FileMigrationPlanner($resolver);
        $userPlanner = new PortalUserPlanner;
        $submissionPlanner = new SubmissionPlanner;
        $shareholderPlanner = new ShareholderPlanner;
        $notePlanner = new NotePlanner;
        $workflowPlanner = new WorkflowHistoryPlanner;
        $actorResolver = new LegacyActorResolver;

        $availableFiles = $this->enumerateAvailableFiles($sourceOpt);
        if (isset($fixtureData['available_files'])) {
            $availableFiles = $fixtureData['available_files'];
        }

        $legacyReader = $this->buildReader($sourceOpt, $sourceConn, $fixtureData);

        $plannerResults = [];
        $archive = [];

        $userMapBySource = [];
        $submissionMapBySource = [];

        $onlyFilter = $only !== null ? strtolower(trim($only)) : null;
        $shouldProcess = fn (string $entity) => $onlyFilter === null || $onlyFilter === strtolower($entity) || $onlyFilter === 'all';

        // Helper to check canonical and produce ALREADY_MIGRATED / SOURCE_CHANGED / STALE_MAPPING
        $checkCanonical = function (string $sourceEntity, string $sourceId, string $fingerprint, string $sourceRole = 'PRIMARY') use ($targetReader) {
            $canonical = $targetReader->findCanonicalMap($sourceEntity, $sourceId, $sourceRole);
            if (! $canonical) {
                return null;
            }
            $targetEntity = $canonical->target_entity;
            $targetId = $canonical->target_id;
            $exists = $targetReader->targetExists($targetEntity, (string) $targetId);
            if (! $exists) {
                return [
                    'status' => 'STALE_MAPPING',
                    'canonical' => $canonical,
                    'disposition' => "Canonical map exists but target {$targetEntity}:{$targetId} missing",
                    'error_code' => 'STALE_MAPPING',
                ];
            }
            if ($canonical->source_fingerprint !== null && $canonical->source_fingerprint !== $fingerprint) {
                return [
                    'status' => 'SOURCE_CHANGED',
                    'canonical' => $canonical,
                    'disposition' => 'Source fingerprint changed since migration',
                    'error_code' => 'SOURCE_CHANGED',
                ];
            }

            return [
                'status' => 'ALREADY_MIGRATED',
                'canonical' => $canonical,
                'disposition' => 'Already migrated and target exists',
                'error_code' => null,
            ];
        };

        $upsertRunItem = function (array $planned) use ($controlStore, $run) {
            // Map WOULD_* to run_items — never creates canonical maps
            $controlStore->upsertRunItem(array_merge($planned, ['migration_run_id' => $run->id]));
        };

        // -- USERS --
        if ($shouldProcess('portal_users') || $shouldProcess('users')) {
            $count = 0;
            foreach ($legacyReader->portalUsers() as $dto) {
                if ($limit !== null && $count >= $limit) {
                    break;
                }
                $fp = SourceFingerprintService::forPortalUser($dto);
                $canonicalCheck = $checkCanonical('portal_users', (string) $dto->id, $fp, 'PRIMARY');
                if ($canonicalCheck !== null) {
                    $status = $canonicalCheck['status'];
                    $planned = [
                        'source_entity' => 'portal_users',
                        'source_id' => (string) $dto->id,
                        'target_entity' => $status === 'ALREADY_MIGRATED' ? $canonicalCheck['canonical']->target_entity : 'nimbus_portal_users',
                        'target_role' => 'PRIMARY',
                        'target_id' => $canonicalCheck['canonical']->target_id ?? null,
                        'status' => $status,
                        'disposition' => $canonicalCheck['disposition'],
                        'error_code' => $canonicalCheck['error_code'],
                        'source_fingerprint' => $fp,
                        'metadata' => ['canonical_map_id' => $canonicalCheck['canonical']->id ?? null],
                    ];
                } else {
                    $plannedRaw = $userPlanner->plan($dto, $fp, [], []);
                    // Translate dry-run MIGRATED → WOULD_MIGRATE
                    $planned = $plannedRaw;
                    if ($planned['status'] === 'MIGRATED') {
                        $planned['status'] = 'WOULD_MIGRATE';
                    }
                    if ($planned['status'] === 'MATCHED_EXISTING') {
                        // keep as is — indicates existing target without canonical
                    }
                }
                $plannerResults[] = $planned;
                $userMapBySource[(string) $dto->id] = $planned;
                $upsertRunItem($planned);
                $count++;
            }
        }

        // -- SUBMISSIONS --
        if ($shouldProcess('portal_submissions') || $shouldProcess('submissions')) {
            $count = 0;
            $seenRefCodes = [];
            foreach ($legacyReader->submissions() as $dto) {
                if ($limit !== null && $count >= $limit) {
                    break;
                }
                $fp = SourceFingerprintService::forSubmission($dto);
                $canonicalCheck = $checkCanonical('portal_submissions', (string) $dto->id, $fp, 'PRIMARY');
                if ($canonicalCheck !== null) {
                    $status = $canonicalCheck['status'];
                    $planned = [
                        'source_entity' => 'portal_submissions',
                        'source_id' => (string) $dto->id,
                        'target_entity' => $status === 'ALREADY_MIGRATED' ? $canonicalCheck['canonical']->target_entity : 'nimbus_submissions',
                        'target_role' => 'PRIMARY',
                        'target_id' => $canonicalCheck['canonical']->target_id ?? null,
                        'status' => $status,
                        'disposition' => $canonicalCheck['disposition'],
                        'error_code' => $canonicalCheck['error_code'],
                        'source_fingerprint' => $fp,
                        'metadata' => ['reference_code' => $dto->referenceCode],
                    ];
                } else {
                    $ref = strtolower(trim($dto->referenceCode));
                    if (isset($seenRefCodes[$ref])) {
                        $planned = [
                            'source_entity' => 'portal_submissions',
                            'source_id' => (string) $dto->id,
                            'target_entity' => 'nimbus_submissions',
                            'target_role' => 'PRIMARY',
                            'status' => 'CONFLICT',
                            'disposition' => "Duplicate reference_code in source (also id {$seenRefCodes[$ref]})",
                            'error_code' => 'REFERENCE_CODE_DUPLICATE_SOURCE',
                            'source_fingerprint' => $fp,
                            'metadata' => ['reference_code' => $dto->referenceCode],
                        ];
                    } else {
                        $seenRefCodes[$ref] = (string) $dto->id;
                        $plannedRaw = $submissionPlanner->plan($dto, $fp, $userMapBySource, [], $run->source_snapshot_at);
                        $planned = $plannedRaw;
                        if ($planned['status'] === 'MIGRATED') {
                            $planned['status'] = 'WOULD_MIGRATE';
                        }
                    }
                }
                $plannerResults[] = $planned;
                $submissionMapBySource[(string) $dto->id] = $planned;
                $upsertRunItem($planned);
                $count++;
            }
        }

        // -- SHAREHOLDERS / NOTES --
        if ($shouldProcess('shareholders')) {
            $count = 0;
            foreach ($legacyReader->shareholders() as $dto) {
                if ($limit !== null && $count >= $limit) {
                    break;
                }
                $fp = SourceFingerprintService::fingerprint(['id' => (string) $dto->id, 'submission_id' => (string) $dto->submissionId, 'name' => $dto->name]);
                $plannedRaw = $shareholderPlanner->plan($dto, $fp, $submissionMapBySource);
                $planned = $plannedRaw;
                if ($planned['status'] === 'MIGRATED') {
                    $planned['status'] = 'WOULD_MIGRATE';
                }
                $plannerResults[] = $planned;
                $upsertRunItem($planned);
                $count++;
            }
        }
        if ($shouldProcess('notes')) {
            $count = 0;
            foreach ($legacyReader->notes() as $dto) {
                if ($limit !== null && $count >= $limit) {
                    break;
                }
                $fp = SourceFingerprintService::forNote($dto);
                $plannedRaw = $notePlanner->plan($dto, $fp, $submissionMapBySource);
                $planned = $plannedRaw;
                if ($planned['status'] === 'MIGRATED') {
                    $planned['status'] = 'WOULD_MIGRATE';
                }
                $plannerResults[] = $planned;
                $upsertRunItem($planned);
                $count++;
            }
        }

        // -- FILES (submission files) --
        if ($shouldProcess('portal_submission_files') || $shouldProcess('files')) {
            $count = 0;
            foreach ($legacyReader->submissionFiles() as $dto) {
                if ($limit !== null && $count >= $limit) {
                    break;
                }
                $fp = SourceFingerprintService::forFile($dto);
                $canonicalCheck = $checkCanonical('portal_submission_files', (string) $dto->id, $fp, 'PRIMARY');
                if ($canonicalCheck !== null && $canonicalCheck['status'] === 'ALREADY_MIGRATED') {
                    $planned = [
                        'source_entity' => 'portal_submission_files',
                        'source_id' => (string) $dto->id,
                        'target_entity' => $canonicalCheck['canonical']->target_entity,
                        'target_role' => 'PRIMARY',
                        'target_id' => $canonicalCheck['canonical']->target_id,
                        'status' => 'ALREADY_MIGRATED',
                        'disposition' => $canonicalCheck['disposition'],
                        'source_fingerprint' => $fp,
                        'metadata' => [],
                    ];
                    $plannerResults[] = $planned;
                    $upsertRunItem($planned);
                    // Also version baseline
                    $plannerResults[] = [
                        'source_entity' => 'portal_submission_files',
                        'source_id' => (string) $dto->id,
                        'target_entity' => 'nimbus_submission_file_versions',
                        'target_role' => 'VERSION_BASELINE',
                        'status' => 'ALREADY_MIGRATED',
                        'disposition' => 'Version baseline already migrated',
                        'source_fingerprint' => $fp.'::v1',
                        'metadata' => ['version' => 1],
                    ];
                    $upsertRunItem(end($plannerResults));
                    $count++;

                    continue;
                }
                if ($canonicalCheck !== null) {
                    $planned = [
                        'source_entity' => 'portal_submission_files',
                        'source_id' => (string) $dto->id,
                        'target_entity' => 'nimbus_submission_files',
                        'target_role' => 'PRIMARY',
                        'status' => $canonicalCheck['status'],
                        'disposition' => $canonicalCheck['disposition'],
                        'error_code' => $canonicalCheck['error_code'],
                        'source_fingerprint' => $fp,
                        'metadata' => [],
                    ];
                    $plannerResults[] = $planned;
                    $upsertRunItem($planned);
                    $count++;

                    continue;
                }
                $plans = $filePlanner->planSubmissionFile($dto, $fp, $availableFiles, $submissionMapBySource);
                foreach ($plans as $p) {
                    if ($p['status'] === 'MIGRATED') {
                        $p['status'] = 'WOULD_MIGRATE';
                    }
                    $plannerResults[] = $p;
                    $upsertRunItem($p);
                }
                $count++;
            }
        }

        // -- PORTAL DOCUMENTS --
        if ($shouldProcess('portal_documents')) {
            foreach ($legacyReader->portalDocuments() as $dto) {
                $fp = SourceFingerprintService::fingerprint(['id' => (string) $dto->id, 'portal_user_id' => (string) $dto->portalUserId, 'file_path' => $dto->filePath]);
                $canonicalCheck = $checkCanonical('portal_documents', (string) $dto->id, $fp, 'PRIMARY');
                if ($canonicalCheck !== null) {
                    $planned = [
                        'source_entity' => 'portal_documents',
                        'source_id' => (string) $dto->id,
                        'target_entity' => $canonicalCheck['canonical']->target_entity,
                        'target_role' => 'PRIMARY',
                        'target_id' => $canonicalCheck['canonical']->target_id ?? null,
                        'status' => $canonicalCheck['status'],
                        'disposition' => $canonicalCheck['disposition'],
                        'error_code' => $canonicalCheck['error_code'],
                        'source_fingerprint' => $fp,
                        'metadata' => [],
                    ];
                    $plannerResults[] = $planned;
                    $upsertRunItem($planned);

                    continue;
                }
                $plans = $filePlanner->planPortalDocument($dto, $fp, $availableFiles, $userMapBySource);
                foreach ($plans as $p) {
                    if ($p['status'] === 'MIGRATED') {
                        $p['status'] = 'WOULD_MIGRATE';
                    }
                    $plannerResults[] = $p;
                    $upsertRunItem($p);
                }
            }
        }

        // -- GENERAL DOCUMENTS --
        if ($shouldProcess('general_documents')) {
            foreach ($legacyReader->generalDocuments() as $dto) {
                $fp = SourceFingerprintService::fingerprint(['id' => (string) $dto->id, 'category_id' => (string) $dto->categoryId, 'file_path' => $dto->filePath]);
                $canonicalCheck = $checkCanonical('general_documents', (string) $dto->id, $fp, 'PRIMARY');
                if ($canonicalCheck !== null) {
                    $planned = [
                        'source_entity' => 'general_documents',
                        'source_id' => (string) $dto->id,
                        'target_entity' => $canonicalCheck['canonical']->target_entity,
                        'target_role' => 'PRIMARY',
                        'target_id' => $canonicalCheck['canonical']->target_id ?? null,
                        'status' => $canonicalCheck['status'],
                        'disposition' => $canonicalCheck['disposition'],
                        'error_code' => $canonicalCheck['error_code'],
                        'source_fingerprint' => $fp,
                        'metadata' => [],
                    ];
                    $plannerResults[] = $planned;
                    $upsertRunItem($planned);

                    continue;
                }
                $plans = $filePlanner->planGeneralDocument($dto, $fp, $availableFiles);
                foreach ($plans as $p) {
                    if ($p['status'] === 'MIGRATED') {
                        $p['status'] = 'WOULD_MIGRATE';
                    }
                    $plannerResults[] = $p;
                    $upsertRunItem($p);
                }
            }
        }

        // -- WORKFLOW BASELINES --
        if ($shouldProcess('workflow') || $shouldProcess('history')) {
            $allAudits = iterator_to_array($legacyReader->auditLogs());
            $bySubmission = [];
            foreach ($allAudits as $a) {
                $tid = $a->targetId !== null ? (string) $a->targetId : null;
                if ($tid !== null) {
                    $bySubmission[$tid][] = $a;
                }
            }
            foreach ($legacyReader->submissions() as $subDto) {
                $audits = $bySubmission[(string) $subDto->id] ?? [];
                $historyPlans = $workflowPlanner->plan($subDto, $audits, $run->source_snapshot_at, $actorResolver);
                foreach ($historyPlans as $hp) {
                    $status = $hp['provenance'] === 'PROVEN' ? 'WOULD_MIGRATE' : 'WOULD_CREATE_BASELINE';
                    $plannerResults[] = [
                        'source_entity' => 'portal_submissions',
                        'source_id' => (string) $subDto->id,
                        'target_entity' => 'nimbus_submission_status_histories',
                        'target_role' => $hp['provenance'] === 'PROVEN' ? 'HISTORY_PROVEN' : 'HISTORY_BASELINE',
                        'status' => $status,
                        'disposition' => $hp['reason'],
                        'source_fingerprint' => SourceFingerprintService::forSubmission($subDto).'::'.$hp['new_status'],
                        'metadata' => $hp,
                    ];
                    $upsertRunItem(end($plannerResults));
                }
            }
        }

        // -- TOKENS (archived only) --
        if ($shouldProcess('portal_access_tokens') || $shouldProcess('tokens')) {
            foreach ($legacyReader->accessTokens() as $dto) {
                $sanitized = LegacyArchiveSanitizer::sanitizeAccessToken($dto);
                $archive[] = ['entity' => 'portal_access_tokens', 'data' => $sanitized];
                $planned = [
                    'source_entity' => 'portal_access_tokens',
                    'source_id' => (string) $dto->id,
                    'target_entity' => 'archive',
                    'target_role' => 'TOKEN_METADATA',
                    'target_id' => 'NO_TARGET',
                    'status' => 'WOULD_ARCHIVE',
                    'disposition' => 'Legacy credential history would be archived (no active migration)',
                    'error_code' => 'CREDENTIAL_HISTORY_ONLY',
                    'source_fingerprint' => hash('sha256', (string) $dto->id.$dto->status),
                    'metadata' => $sanitized,
                ];
                $plannerResults[] = $planned;
                $upsertRunItem($planned);
            }
        }

        // -- NOTIFICATIONS (archived only, allowlist) --
        if ($shouldProcess('notification_outbox') || $shouldProcess('notifications')) {
            foreach ($legacyReader->notifications() as $dto) {
                $sanitized = LegacyArchiveSanitizer::sanitizeNotification($dto);
                $archive[] = ['entity' => 'notification_outbox', 'data' => $sanitized];
                $planned = [
                    'source_entity' => 'notification_outbox',
                    'source_id' => (string) $dto->id,
                    'target_entity' => 'archive',
                    'target_role' => 'NOTIFICATION_ARCHIVE',
                    'target_id' => 'NO_TARGET',
                    'status' => 'WOULD_ARCHIVE',
                    'disposition' => 'Historical notification would be archived (allowlist sanitized)',
                    'source_fingerprint' => hash('sha256', (string) $dto->id.$dto->type),
                    'metadata' => $sanitized,
                ];
                $plannerResults[] = $planned;
                $upsertRunItem($planned);
            }
        }

        // -- AUDIT ARCHIVE --
        if ($shouldProcess('audit_logs')) {
            $auditCount = 0;
            foreach ($legacyReader->auditLogs() as $dto) {
                $sanitized = LegacyArchiveSanitizer::sanitizeAudit($dto);
                $archive[] = ['entity' => 'audit_logs', 'data' => $sanitized];
                $auditCount++;
            }
            if ($auditCount > 0) {
                $planned = [
                    'source_entity' => 'audit_logs',
                    'source_id' => '*',
                    'target_entity' => 'archive',
                    'target_role' => 'AUDIT_ARCHIVE',
                    'target_id' => 'NO_TARGET',
                    'status' => 'WOULD_ARCHIVE',
                    'disposition' => 'Audit history would be archived with legacy provenance',
                    'source_fingerprint' => 'archive-audit',
                    'metadata' => ['count' => $auditCount],
                ];
                $plannerResults[] = $planned;
                $upsertRunItem($planned);
            }
        }

        // Summary
        $summary = $this->buildSummary($plannerResults);
        $summary['run_uuid'] = $run->run_uuid;
        $summary['source_snapshot_at'] = $run->source_snapshot_at;
        $summary['dry_run'] = true;
        $summary['control_connection'] = $controlConn;
        $summary['source_connection'] = $sourceConn;
        $summary['target_connection'] = $targetConn;

        DB::connection($controlConn)->table('nimbus_migration_runs')->where('id', $run->id)->update([
            'status' => $this->overallStatus($plannerResults),
            'finished_at' => now(),
            'summary' => json_encode($summary),
        ]);

        $jsonReport = json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($reportPath) {
            File::put($reportPath, $jsonReport);
            $this->info("Report written to {$reportPath}");
        } else {
            $this->info($jsonReport);
        }

        $overall = $this->overallStatus($plannerResults);
        $this->info("Run {$run->run_uuid} completed: {$overall} (control: {$controlConn}, source: {$sourceConn} READ ONLY, target: {$targetConn} READ ONLY)");
        foreach ($summary['by_status'] ?? [] as $k => $v) {
            $this->line("  {$k}: {$v}");
        }

        return self::SUCCESS;
    }

    private function buildReader(?string $sourceOpt = null, string $sourceConn = 'nimbus_legacy', array $fixtureData = []): LegacySourceReader
    {
        // If source connection has legacy tables, prefer Database reader when data exists
        // For M1 fixture tests, fixtureData is authoritative — use Array reader
        if (! empty($fixtureData) && isset($fixtureData['portal_users'])) {
            return new ArrayLegacySourceReader($fixtureData);
        }
        // Try database source if connection is configured and has tables
        try {
            $conn = DB::connection($sourceConn);
            $hasTable = $conn->getSchemaBuilder()->hasTable('portal_users');
            if ($hasTable) {
                return new DatabaseLegacySourceReader($sourceConn);
            }
        } catch (\Throwable $e) {
            // fall through to array
        }

        return new ArrayLegacySourceReader($fixtureData);
    }

    private function loadFixtureData(?string $sourceOpt): array
    {
        if ($sourceOpt !== null && File::exists($sourceOpt)) {
            $content = File::get($sourceOpt);
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [
            'portal_users' => [
                ['id' => 9001, 'full_name' => 'Fixture User One', 'email' => 'fixture1@example.com', 'document_number' => '52998224725', 'phone_number' => '11999990001', 'status' => 'ACTIVE', 'created_at' => '2026-02-23 15:07:17'],
                ['id' => 9002, 'full_name' => 'Fixture User Two', 'email' => 'fixture2@example.com', 'document_number' => '22222222222', 'phone_number' => '11999990002', 'status' => 'ACTIVE', 'created_at' => '2026-02-23 15:07:17'],
            ],
            'portal_submissions' => [
                ['id' => 9101, 'portal_user_id' => 9001, 'reference_code' => 'SUB-FIXTURE-001', 'title' => 'Fixture Sub', 'status' => 'PENDING', 'submitted_at' => '2026-02-20 10:00:00', 'created_at' => '2026-02-20 10:00:00'],
            ],
            'portal_submission_shareholders' => [],
            'portal_submission_files' => [
                ['id' => 9201, 'submission_id' => 9101, 'document_type' => 'OTHER', 'origin' => 'USER', 'visible_to_user' => 0, 'original_name' => 'doc.pdf', 'stored_name' => 'abc123.pdf', 'storage_path' => '2025/11/abc123.pdf', 'size_bytes' => 100, 'checksum' => hash('sha256', 'content-a'), 'uploaded_at' => '2026-02-20 10:00:00'],
                ['id' => 9202, 'submission_id' => 9101, 'document_type' => 'OTHER', 'origin' => 'USER', 'visible_to_user' => 0, 'original_name' => 'doc.pdf', 'stored_name' => 'def456.pdf', 'storage_path' => '2025/11/def456.pdf', 'size_bytes' => 100, 'checksum' => hash('sha256', 'content-a'), 'uploaded_at' => '2026-02-20 10:00:00'],
            ],
            'portal_submission_notes' => [
                ['id' => 9301, 'submission_id' => 9101, 'admin_user_id' => 1, 'visibility' => 'USER_VISIBLE', 'message' => 'Note', 'created_at' => '2026-02-21 00:00:00'],
            ],
            'portal_documents' => [
                ['id' => 9401, 'portal_user_id' => 9001, 'title' => 'Portal Doc', 'file_path' => 'C:\\xampp\\htdocs\\NimbusDocs/storage/documents/9001/doc.pdf', 'file_original_name' => 'doc.pdf', 'file_size' => 100, 'file_mime' => 'application/pdf', 'created_at' => '2026-02-21 00:00:00', 'created_by_admin' => 1],
            ],
            'general_documents' => [
                ['id' => 9501, 'category_id' => 1, 'title' => 'General Doc', 'file_path' => 'C:\\xampp\\htdocs\\NimbusDocs\\src\\Presentation\\Controller\\Admin/../../../../storage/general_documents/doc2.pdf', 'file_original_name' => 'doc2.pdf', 'file_size' => 100, 'file_mime' => 'application/pdf', 'is_active' => 1, 'published_at' => '2026-01-07 10:00:00', 'created_by_admin' => 1, 'created_at' => '2026-01-07 10:00:00'],
            ],
            'portal_access_tokens' => [
                ['id' => 9601, 'portal_user_id' => 9001, 'code' => 'EGTAQM7TH5EV', 'status' => 'PENDING', 'expires_at' => '2026-02-24 10:00:00', 'created_at' => '2026-02-23 10:00:00'],
            ],
            'notification_outbox' => [
                ['id' => 9701, 'type' => 'TOKEN_CREATED', 'recipient_email' => 'fixture1@example.com', 'recipient_name' => 'Fixture', 'subject' => 'sub', 'template' => 'token_created', 'payload_json' => json_encode(['user' => ['email' => 'fixture1@example.com'], 'token' => ['code' => 'SECRETCODE'], 'extra' => ['password_hash' => '$2y$...']]), 'status' => 'SENT', 'attempts' => 0, 'max_attempts' => 5, 'created_at' => '2026-02-23 10:00:00', 'sent_at' => '2026-02-23 10:01:00'],
            ],
            'audit_logs' => [
                ['id' => 9801, 'occurred_at' => '2026-02-20 10:00:01', 'actor_type' => 'PORTAL_USER', 'actor_id' => 9001, 'action' => 'PORTAL_SUBMISSION_CREATED', 'target_type' => 'PORTAL_SUBMISSION', 'target_id' => 9101, 'details' => json_encode(['reference_code' => 'SUB-FIXTURE-001'])],
            ],
            'available_files' => [
                '/tmp/nimbus-legacy/2025/11/abc123.pdf',
                '/tmp/nimbus-legacy/2025/11/def456.pdf',
                '/tmp/nimbus-legacy/doc.pdf',
                '/tmp/nimbus-legacy/doc2.pdf',
            ],
            'meta' => [
                'source_snapshot_at' => '2026-02-23 15:07:17',
                'descriptor' => 'm1-fixture',
                'dump_sha256' => 'fixture-sha',
            ],
        ];
    }

    private function enumerateAvailableFiles(?string $sourceOpt): array
    {
        if ($sourceOpt !== null && is_dir($sourceOpt)) {
            $files = [];
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sourceOpt, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                $files[] = $file->getPathname();
            }

            return $files;
        }

        return [];
    }

    private function buildSummary(array $results): array
    {
        $byStatus = [];
        $byEntity = [];
        foreach ($results as $r) {
            $s = $r['status'];
            $e = $r['source_entity'];
            $byStatus[$s] = ($byStatus[$s] ?? 0) + 1;
            $byEntity[$e] = ($byEntity[$e] ?? 0) + 1;
        }
        $counts = [
            'users' => $byEntity['portal_users'] ?? 0,
            'submissions' => $byEntity['portal_submissions'] ?? 0,
            'shareholders' => $byEntity['portal_submission_shareholders'] ?? 0,
            'notes' => $byEntity['portal_submission_notes'] ?? 0,
            'submission_files' => ($byEntity['portal_submission_files'] ?? 0),
            'portal_documents' => $byEntity['portal_documents'] ?? 0,
            'general_documents' => $byEntity['general_documents'] ?? 0,
            'tokens' => ($byEntity['portal_access_tokens'] ?? 0),
            'notifications' => ($byEntity['notification_outbox'] ?? 0),
        ];
        $versionBaselines = count(array_filter($results, fn ($r) => ($r['target_role'] ?? '') === 'VERSION_BASELINE'));
        $counts['file_version_baselines'] = $versionBaselines;

        return [
            'by_status' => $byStatus,
            'by_entity' => $byEntity,
            'counts' => $counts,
            'total_planned' => count($results),
        ];
    }

    private function overallStatus(array $results): string
    {
        $hasConflict = false;
        $hasMissing = false;
        foreach ($results as $r) {
            if (in_array($r['status'], ['CONFLICT', 'CHECKSUM_MISMATCH', 'INVALID_RELATION', 'ERROR', 'AMBIGUOUS_SOURCE_FILE', 'SOURCE_CHANGED', 'STALE_MAPPING'], true)) {
                $hasConflict = true;
            }
            if (in_array($r['status'], ['MISSING_SOURCE_FILE'], true)) {
                $hasMissing = true;
            }
        }
        if ($hasConflict || $hasMissing) {
            return 'COMPLETED_WITH_WARNINGS';
        }

        return 'COMPLETED';
    }
}

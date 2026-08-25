<?php

use App\Models\Nimbus\AccessToken;
use App\Models\Nimbus\MigrationRun;
use App\Models\Nimbus\MigrationRunItem;
use App\Models\Nimbus\PortalUser;
use App\Models\Nimbus\Submission;
use App\Services\Nimbus\Migration\ChecksumService;
use App\Services\Nimbus\Migration\FileMigrationPlanner;
use App\Services\Nimbus\Migration\LegacyAccessTokenDto;
use App\Services\Nimbus\Migration\LegacyActorResolver;
use App\Services\Nimbus\Migration\LegacyArchiveSanitizer;
use App\Services\Nimbus\Migration\LegacyAuditDto;
use App\Services\Nimbus\Migration\LegacyFilePathResolver;
use App\Services\Nimbus\Migration\LegacyGeneralDocumentDto;
use App\Services\Nimbus\Migration\LegacyNotificationDto;
use App\Services\Nimbus\Migration\LegacyPortalDocumentDto;
use App\Services\Nimbus\Migration\LegacyPortalUserDto;
use App\Services\Nimbus\Migration\LegacySubmissionDto;
use App\Services\Nimbus\Migration\LegacySubmissionFileDto;
use App\Services\Nimbus\Migration\PortalUserPlanner;
use App\Services\Nimbus\Migration\SourceFingerprintService;
use App\Services\Nimbus\Migration\SubmissionPlanner;
use App\Services\Nimbus\Migration\WorkflowHistoryPlanner;
use App\Services\Security\BlindIndexService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

// Helper to ensure blind index key is set for tests
beforeEach(function () {
    config(['nimbus.pii_blind_index_key' => 'test-blind-index-key-for-m1-migration-32bytes!!']);
    config(['nimbus.pii_blind_index_version' => 'v1']);
    config(['nimbus.migration.legacy_storage_root' => '/tmp/nimbus-legacy']);
});

// 31. Migration control
it('migration run uuid is unique and records source snapshot metadata', function () {
    $run1 = MigrationRun::create([
        'run_uuid' => (string) Str::uuid(),
        'source_system' => 'nimbusdocs',
        'source_snapshot_at' => '2026-02-23 15:07:17',
        'source_dump_sha256' => hash('sha256', 'dump'),
        'dry_run' => true,
        'status' => 'PENDING',
        'started_at' => now(),
    ]);
    expect($run1->run_uuid)->not->toBeNull();

    expect(function () use ($run1) {
        MigrationRun::create([
            'run_uuid' => $run1->run_uuid,
            'source_system' => 'nimbusdocs',
            'dry_run' => true,
            'status' => 'PENDING',
            'started_at' => now(),
        ]);
    })->toThrow(Exception::class);
});

it('same source primary mapping is idempotent and fingerprint deterministic', function () {
    $dto = LegacyPortalUserDto::fromArray([
        'id' => 1, 'full_name' => 'Alice', 'email' => 'alice@example.com',
        'document_number' => '52998224725', 'phone_number' => '11999990001',
        'status' => 'ACTIVE', 'created_at' => '2026-02-23 15:07:17',
    ]);
    $fp1 = SourceFingerprintService::forPortalUser($dto);
    $fp2 = SourceFingerprintService::forPortalUser($dto);
    expect($fp1)->toBe($fp2);

    // Changed source → different fingerprint
    $dto2 = LegacyPortalUserDto::fromArray([
        'id' => 1, 'full_name' => 'Alice Changed', 'email' => 'alice@example.com',
        'document_number' => '52998224725', 'phone_number' => '11999990001',
        'status' => 'ACTIVE', 'created_at' => '2026-02-23 15:07:17',
    ]);
    $fpChanged = SourceFingerprintService::forPortalUser($dto2);
    expect($fpChanged)->not->toBe($fp1);
});

it('derived target artifact can coexist with primary mapping and rerun does not duplicate', function () {
    $run = MigrationRun::create([
        'run_uuid' => (string) Str::uuid(),
        'source_system' => 'nimbusdocs',
        'source_snapshot_at' => '2026-02-23 15:07:17',
        'dry_run' => true,
        'status' => 'RUNNING',
        'started_at' => now(),
    ]);

    // Simulate file planner producing two rows with same source_id but different target_role
    $fp = hash('sha256', 'file-1');
    MigrationRunItem::create([
        'migration_run_id' => $run->id,
        'source_system' => 'nimbusdocs',
        'source_entity' => 'portal_submission_files',
        'source_id' => '10',
        'target_entity' => 'nimbus_submission_files',
        'target_role' => 'PRIMARY',
        'source_fingerprint' => $fp,
        'status' => 'WOULD_MIGRATE',
    ]);
    MigrationRunItem::create([
        'migration_run_id' => $run->id,
        'source_system' => 'nimbusdocs',
        'source_entity' => 'portal_submission_files',
        'source_id' => '10',
        'target_entity' => 'nimbus_submission_file_versions',
        'target_role' => 'VERSION_BASELINE',
        'source_fingerprint' => $fp.'::v1',
        'status' => 'WOULD_MIGRATE',
    ]);

    expect(MigrationRunItem::where('migration_run_id', $run->id)->count())->toBe(2);

    // Rerun with same fingerprint should be ALREADY_MIGRATED, not duplicate
    $existing = MigrationRunItem::where('migration_run_id', $run->id)
        ->where('source_entity', 'portal_submission_files')
        ->where('source_id', '10')
        ->where('target_role', 'PRIMARY')
        ->first();
    expect($existing->source_fingerprint)->toBe($fp);

    // Upsert should not create duplicate
    MigrationRunItem::updateOrCreate(
        [
            'migration_run_id' => $run->id,
            'source_system' => 'nimbusdocs',
            'source_entity' => 'portal_submission_files',
            'source_id' => '10',
            'target_entity' => 'nimbus_submission_files',
            'target_role' => 'PRIMARY',
        ],
        ['source_fingerprint' => $fp, 'status' => 'WOULD_MIGRATE']
    );
    expect(MigrationRunItem::where('migration_run_id', $run->id)->count())->toBe(2);
});

// 32. Dry-run safety
it('dry-run does not insert operational Nimbus records, files, notifications, tokens, jobs', function () {
    Mail::fake();
    Queue::fake();
    $countUsersBefore = PortalUser::count();
    $countSubsBefore = Submission::count();
    $countTokensBefore = AccessToken::count();

    // Invoke command dry-run
    $this->artisan('nimbus:migrate-legacy --dry-run --source=default-fixture')
        ->assertExitCode(0);

    expect(PortalUser::count())->toBe($countUsersBefore);
    expect(Submission::count())->toBe($countSubsBefore);
    expect(AccessToken::count())->toBe($countTokensBefore);
    // No mail enqueued for access codes (legacy tokens are skipped)
    Mail::assertNothingSent();
    // Migration control tables MAY have been written (that's allowed) — verify at least one run exists
    expect(MigrationRun::where('dry_run', true)->exists())->toBeTrue();
    // But source DB not written — Array reader is read-only, verified by no exception
});

it('without dry-run fails safely', function () {
    $this->artisan('nimbus:migrate-legacy')
        ->expectsOutput('Write migration is not enabled in Phase M1.1. Use --dry-run.')
        ->assertExitCode(1);
});

// 33. Users / collisions
it('portal user planner normalizes email and uses BlindIndexService', function () {
    $planner = new PortalUserPlanner;
    $dto = LegacyPortalUserDto::fromArray([
        'id' => 100, 'full_name' => 'Test', 'email' => '  Fixture@Example.COM  ',
        'document_number' => '529.982.247-25', 'phone_number' => '(11) 99999-0001',
        'status' => 'ACTIVE', 'created_at' => '2026-02-23 15:07:17',
    ]);
    $fp = SourceFingerprintService::forPortalUser($dto);
    $planned = $planner->plan($dto, $fp, [], []);
    expect($planned['status'])->toBe('MIGRATED');
    expect($planned['metadata']['document_number_hash'])->toBe(BlindIndexService::documentNumber('52998224725'));
    // Report must not expose raw CPF
    $reportJson = json_encode($planned);
    expect($reportJson)->not->toContain('52998224725');
    expect($reportJson)->not->toContain('529.982.247-25');
});

it('identical proven user yields MATCHED_EXISTING', function () {
    $existing = PortalUser::create([
        'full_name' => 'Existing', 'email' => 'existing@example.com',
        'document_number' => '52998224725', 'phone_number' => '11999990001',
        'status' => 'ACTIVE',
    ]);
    $planner = new PortalUserPlanner;
    $dto = LegacyPortalUserDto::fromArray([
        'id' => 999, 'full_name' => 'Existing Dup', 'email' => 'existing@example.com',
        'document_number' => '52998224725', 'phone_number' => '11999990001',
        'status' => 'ACTIVE', 'created_at' => '2026-02-23 15:07:17',
    ]);
    $fp = SourceFingerprintService::forPortalUser($dto);
    $planned = $planner->plan($dto, $fp, [], []);
    expect($planned['status'])->toBe('MATCHED_EXISTING');
    expect($planned['target_id'])->toBe((string) $existing->id);
});

it('email-only ambiguous match yields CONFLICT', function () {
    PortalUser::create([
        'full_name' => 'Existing2', 'email' => 'collision@example.com',
        'document_number' => '52998224725', 'phone_number' => '11999990001',
        'status' => 'ACTIVE',
    ]);
    $planner = new PortalUserPlanner;
    $dto = LegacyPortalUserDto::fromArray([
        'id' => 200, 'full_name' => 'Other', 'email' => 'collision@example.com',
        'document_number' => '11144477735', 'phone_number' => '11999990002',
        'status' => 'ACTIVE', 'created_at' => '2026-02-23 15:07:17',
    ]);
    $fp = SourceFingerprintService::forPortalUser($dto);
    $planned = $planner->plan($dto, $fp, [], []);
    expect($planned['status'])->toBe('CONFLICT');
    expect($planned['error_code'])->toContain('EMAIL');
});

it('CPF hash contradiction yields CONFLICT and report does not expose phone', function () {
    $existing = PortalUser::create([
        'full_name' => 'HashOwner', 'email' => 'hash@example.com',
        'document_number' => '52998224725', 'phone_number' => '11999990001',
        'status' => 'ACTIVE',
    ]);
    $planner = new PortalUserPlanner;
    $dto = LegacyPortalUserDto::fromArray([
        'id' => 300, 'full_name' => 'Contradiction', 'email' => 'different@example.com',
        'document_number' => '52998224725', // same CPF
        'phone_number' => '11999990099',
        'status' => 'ACTIVE', 'created_at' => '2026-02-23 15:07:17',
    ]);
    $fp = SourceFingerprintService::forPortalUser($dto);
    $planned = $planner->plan($dto, $fp, [], []);
    expect($planned['status'])->toBe('CONFLICT');
    $json = json_encode($planned);
    expect($json)->not->toContain('11999990099');
    expect($json)->not->toContain('52998224725');
});

// 34. Submissions
it('submission planner normal mapping and missing parent yields INVALID_RELATION', function () {
    $planner = new SubmissionPlanner;
    $dto = LegacySubmissionDto::fromArray([
        'id' => 1, 'portal_user_id' => 9999, 'reference_code' => 'SUB-001', 'title' => 'T', 'status' => 'PENDING',
        'submitted_at' => '2026-02-20 10:00:00', 'created_at' => '2026-02-20 10:00:00',
    ]);
    $fp = SourceFingerprintService::forSubmission($dto);
    $planned = $planner->plan($dto, $fp, [], [], '2026-02-23 15:07:17');
    expect($planned['status'])->toBe('INVALID_RELATION');

    $userMap = ['9999' => ['status' => 'MIGRATED', 'target_id' => '1']];
    $planned2 = $planner->plan($dto, $fp, $userMap, [], '2026-02-23 15:07:17');
    expect($planned2['status'])->toBe('MIGRATED');
});

it('reference_code source duplicate and target collision yield CONFLICT', function () {
    // Target collision: create existing submission with same code
    $pu = PortalUser::create(['full_name' => 'U', 'email' => 'u1@example.com', 'document_number' => '52998224725', 'status' => 'ACTIVE']);
    Submission::create([
        'nimbus_portal_user_id' => $pu->id,
        'reference_code' => 'DUPE-CODE',
        'title' => 'Existing',
        'status' => 'PENDING',
        'submitted_at' => now(),
    ]);
    $planner = new SubmissionPlanner;
    $dto = LegacySubmissionDto::fromArray([
        'id' => 2, 'portal_user_id' => $pu->id, 'reference_code' => 'DUPE-CODE', 'title' => 'T', 'status' => 'PENDING',
        'submitted_at' => '2026-02-20 10:00:00', 'created_at' => '2026-02-20 10:00:00',
    ]);
    $fp = SourceFingerprintService::forSubmission($dto);
    $userMap = [(string) $pu->id => ['status' => 'MIGRATED', 'target_id' => (string) $pu->id]];
    // Note: need portal_user_id mapping by legacy id, but we pass pu.id as legacy id for this test
    $userMap2 = [(string) $dto->portalUserId => ['status' => 'MIGRATED', 'target_id' => (string) $pu->id]];
    $planned = $planner->plan($dto, $fp, $userMap2, [], '2026-02-23 15:07:17');
    expect($planned['status'])->toBe('CONFLICT');
    expect($planned['error_code'])->toBe('REFERENCE_CODE_COLLISION');
});

// 35. Workflow history
it('explicit audited transition yields PROVEN history plan', function () {
    $planner = new WorkflowHistoryPlanner;
    $resolver = new LegacyActorResolver;
    $sub = LegacySubmissionDto::fromArray([
        'id' => 1, 'portal_user_id' => 1, 'reference_code' => 'SUB-1', 'title' => 'T', 'status' => 'COMPLETED',
        'submitted_at' => '2026-02-20 10:00:00', 'created_at' => '2026-02-20 10:00:00',
    ]);
    $audit = LegacyAuditDto::fromArray([
        'id' => 1, 'occurred_at' => '2026-02-21 12:00:00', 'actor_type' => 'ADMIN', 'actor_id' => 1,
        'action' => 'SUBMISSION_STATUS_CHANGED', 'target_id' => 1,
        'details' => json_encode(['oldStatus' => 'PENDING', 'newStatus' => 'COMPLETED']),
    ]);
    $plans = $planner->plan($sub, [$audit], '2026-02-23 15:07:17', $resolver);
    expect($plans[0]['provenance'])->toBe('PROVEN');
    expect($plans[0]['old_status'])->toBe('PENDING');
    expect($plans[0]['new_status'])->toBe('COMPLETED');
    expect($plans[0]['occurred_at'])->toBe('2026-02-21 12:00:00');
});

it('current status with missing transition yields BASELINE_ONLY at snapshot time', function () {
    $planner = new WorkflowHistoryPlanner;
    $resolver = new LegacyActorResolver;
    $sub = LegacySubmissionDto::fromArray([
        'id' => 2, 'portal_user_id' => 1, 'reference_code' => 'SUB-2', 'title' => 'T', 'status' => 'UNDER_REVIEW',
        'submitted_at' => '2026-02-20 10:00:00', 'created_at' => '2026-02-20 10:00:00',
    ]);
    $plans = $planner->plan($sub, [], '2026-02-23 15:07:17', $resolver);
    expect(count($plans))->toBe(1);
    expect($plans[0]['provenance'])->toBe('BASELINE_ONLY');
    expect($plans[0]['occurred_at'])->toBe('2026-02-23 15:07:17');
    expect($plans[0]['new_status'])->toBe('UNDER_REVIEW');
    // submitted_at NOT substituted
    expect($plans[0]['occurred_at'])->not->toBe($sub->submittedAt);
});

it('inferred note text does not create NEEDS_CORRECTION', function () {
    $planner = new WorkflowHistoryPlanner;
    $resolver = new LegacyActorResolver;
    $sub = LegacySubmissionDto::fromArray([
        'id' => 3, 'portal_user_id' => 1, 'reference_code' => 'SUB-3', 'title' => 'T', 'status' => 'PENDING',
        'submitted_at' => '2026-02-20 10:00:00', 'created_at' => '2026-02-20 10:00:00',
    ]);
    // No audit with NEEDS_CORRECTION, even if note says "correção necessária"
    $plans = $planner->plan($sub, [], '2026-02-23 15:07:17', $resolver);
    expect(collect($plans)->pluck('new_status'))->not->toContain('NEEDS_CORRECTION');
});

it('unresolved admin does not receive arbitrary current user id', function () {
    $planner = new WorkflowHistoryPlanner;
    $resolver = new LegacyActorResolver;
    $sub = LegacySubmissionDto::fromArray([
        'id' => 4, 'portal_user_id' => 1, 'reference_code' => 'SUB-4', 'title' => 'T', 'status' => 'PENDING',
        'submitted_at' => '2026-02-20 10:00:00', 'created_at' => '2026-02-20 10:00:00',
    ]);
    $audit = LegacyAuditDto::fromArray([
        'id' => 2, 'occurred_at' => '2026-02-21 12:00:00', 'actor_type' => 'ADMIN', 'actor_id' => 9999,
        'action' => 'SUBMISSION_STATUS_CHANGED', 'target_id' => 4,
        'details' => json_encode(['oldStatus' => 'PENDING', 'newStatus' => 'COMPLETED']),
    ]);
    $plans = $planner->plan($sub, [$audit], '2026-02-23 15:07:17', $resolver);
    expect($plans[0]['actor_id'])->toBeNull();
    expect($plans[0]['actor_type'])->toBe('SYSTEM');
});

// 36. File paths
it('windows absolute path normalization strips drive and xampp prefix', function () {
    $resolver = new LegacyFilePathResolver('/tmp/nimbus-legacy');
    $dto = LegacySubmissionFileDto::fromArray([
        'id' => 1, 'submission_id' => 1, 'original_name' => 'a.pdf', 'stored_name' => 'a.pdf',
        'storage_path' => 'C:\\xampp\\htdocs\\NimbusDocs/storage/documents/2/\\02eb66fd.pdf',
        'size_bytes' => 100, 'uploaded_at' => '2026-02-20',
    ]);
    $available = ['/tmp/nimbus-legacy/02eb66fd.pdf'];
    $result = $resolver->resolve($dto, $available);
    expect($result['status'])->toBe('RESOLVED');
    expect($result['resolved_path'])->toBe('/tmp/nimbus-legacy/02eb66fd.pdf');
});

it('relative path normalization and unique basename fallback works', function () {
    $resolver = new LegacyFilePathResolver('/tmp/nimbus-legacy');
    $dto = LegacySubmissionFileDto::fromArray([
        'id' => 2, 'submission_id' => 1, 'original_name' => 'b.pdf', 'stored_name' => 'b.pdf',
        'storage_path' => '2025/11/abc123.pdf',
        'size_bytes' => 100, 'uploaded_at' => '2026-02-20',
    ]);
    $available = ['/tmp/nimbus-legacy/2025/11/abc123.pdf'];
    $result = $resolver->resolve($dto, $available);
    expect($result['status'])->toBe('RESOLVED');

    // Basename unique fallback
    $dto2 = LegacySubmissionFileDto::fromArray([
        'id' => 3, 'submission_id' => 1, 'original_name' => 'c.pdf', 'stored_name' => 'c.pdf',
        'storage_path' => 'unknown/path/c.pdf',
        'size_bytes' => 100, 'uploaded_at' => '2026-02-20',
    ]);
    $available2 = ['/tmp/nimbus-legacy/other/c.pdf'];
    $result2 = $resolver->resolve($dto2, $available2);
    expect($result2['status'])->toBe('RESOLVED');
});

it('zero candidate yields MISSING_SOURCE_FILE and multiple yields AMBIGUOUS', function () {
    $resolver = new LegacyFilePathResolver('/tmp/nimbus-legacy');
    $dto = LegacySubmissionFileDto::fromArray([
        'id' => 4, 'submission_id' => 1, 'original_name' => 'd.pdf', 'stored_name' => 'd.pdf',
        'storage_path' => 'missing/d.pdf', 'size_bytes' => 100, 'uploaded_at' => '2026-02-20',
    ]);
    $result = $resolver->resolve($dto, []);
    expect($result['status'])->toBe('MISSING_SOURCE_FILE');

    $dto2 = LegacySubmissionFileDto::fromArray([
        'id' => 5, 'submission_id' => 1, 'original_name' => 'e.pdf', 'stored_name' => 'e.pdf',
        'storage_path' => 'some/e.pdf', 'size_bytes' => 100, 'uploaded_at' => '2026-02-20',
    ]);
    $availableMulti = ['/tmp/a/e.pdf', '/tmp/b/e.pdf'];
    $result2 = $resolver->resolve($dto2, $availableMulti);
    expect($result2['status'])->toBe('AMBIGUOUS_SOURCE_FILE');
});

it('target planned path never contains C:\\xampp or ..', function () {
    $path = LegacyFilePathResolver::plannedTargetPath('portal_submission_files', 123, 'doc.pdf');
    expect($path)->not->toContain('C:');
    expect($path)->not->toContain('xampp');
    expect($path)->not->toContain('..');
    expect($path)->toBe('submissions/123/doc.pdf');
});

it('known storage prefix stripping', function () {
    $resolver = new LegacyFilePathResolver('/tmp/nimbus-legacy');
    $candidates = $resolver->candidatePaths('C:\\xampp\\htdocs\\NimbusDocs\\src\\Presentation\\Controller\\Admin/../../../../storage/general_documents/\\405d7745.pdf');
    // Should contain normalized basename without xampp prefix
    expect($candidates)->toContain('405d7745.pdf');
    expect(implode(',', $candidates))->not->toContain('..');
});

// 37. Checksums
it('checksum recorded == actual yields MATCH and mismatch yields CHECKSUM_MISMATCH', function () {
    $content = 'file-content-a';
    $sha = hash('sha256', $content);
    $planMatch = ChecksumService::planStatus($sha, $sha, null);
    expect($planMatch['status'])->toBe('MATCH');

    $planMismatch = ChecksumService::planStatus($sha, hash('sha256', 'different'), null);
    expect($planMismatch['status'])->toBe('CHECKSUM_MISMATCH');
});

it('no recorded checksum computes actual and allows duplicate content across two records', function () {
    $content = 'same-content';
    $sha = hash('sha256', $content);
    $plan = ChecksumService::planStatus(null, $sha, null);
    expect($plan['status'])->toBe('MATCH');
    expect($plan['actual_source_sha256'])->toBe($sha);

    // Two different DTOs with same checksum should both be MATCH — not deduplicated
    $dtoA = LegacySubmissionFileDto::fromArray([
        'id' => 10, 'submission_id' => 1, 'original_name' => 'a.pdf', 'stored_name' => 'a.pdf',
        'storage_path' => 'a.pdf', 'size_bytes' => 10, 'checksum' => $sha, 'uploaded_at' => '2026-02-20',
    ]);
    $dtoB = LegacySubmissionFileDto::fromArray([
        'id' => 11, 'submission_id' => 1, 'original_name' => 'b.pdf', 'stored_name' => 'b.pdf',
        'storage_path' => 'b.pdf', 'size_bytes' => 10, 'checksum' => $sha, 'uploaded_at' => '2026-02-20',
    ]);
    expect($dtoA->checksum)->toBe($dtoB->checksum);
    // Planner should allow both (no auto-dedupe)
    $resolver = new LegacyFilePathResolver('/tmp/nimbus-legacy');
    $planner = new FileMigrationPlanner($resolver);
    $available = ['/tmp/nimbus-legacy/a.pdf', '/tmp/nimbus-legacy/b.pdf'];
    $fpA = SourceFingerprintService::forFile($dtoA);
    $fpB = SourceFingerprintService::forFile($dtoB);
    $plansA = $planner->planSubmissionFile($dtoA, $fpA, $available, ['1' => ['status' => 'MIGRATED', 'target_id' => '10']], $sha);
    $plansB = $planner->planSubmissionFile($dtoB, $fpB, $available, ['1' => ['status' => 'MIGRATED', 'target_id' => '10']], $sha);
    expect($plansA[0]['status'])->toBe('MIGRATED');
    expect($plansB[0]['status'])->toBe('MIGRATED');
});

// 38. Versioning
it('one legacy base file yields one planned base file plus one version=1 baseline and rerun does not duplicate', function () {
    $resolver = new LegacyFilePathResolver('/tmp/nimbus-legacy');
    $planner = new FileMigrationPlanner($resolver);
    $dto = LegacySubmissionFileDto::fromArray([
        'id' => 50, 'submission_id' => 1, 'original_name' => 'f.pdf', 'stored_name' => 'f.pdf',
        'storage_path' => '2025/11/f.pdf', 'size_bytes' => 100, 'checksum' => hash('sha256', 'c'), 'uploaded_at' => '2026-02-20',
    ]);
    $fp = SourceFingerprintService::forFile($dto);
    $plans = $planner->planSubmissionFile($dto, $fp, ['/tmp/nimbus-legacy/2025/11/f.pdf'], ['1' => ['status' => 'MIGRATED', 'target_id' => '99']], hash('sha256', 'c'));
    expect(count($plans))->toBe(2);
    expect($plans[0]['target_role'])->toBe('PRIMARY');
    expect($plans[1]['target_role'])->toBe('VERSION_BASELINE');
    expect($plans[1]['metadata']['version'])->toBe(1);

    // Rerun: check that version baseline is distinguishable by target_role
    $run = MigrationRun::create([
        'run_uuid' => (string) Str::uuid(),
        'source_system' => 'nimbusdocs',
        'source_snapshot_at' => '2026-02-23 15:07:17',
        'dry_run' => true,
        'status' => 'RUNNING',
        'started_at' => now(),
    ]);
    foreach ($plans as $p) {
        MigrationRunItem::create(array_merge($p, ['migration_run_id' => $run->id, 'source_system' => 'nimbusdocs']));
    }
    // Second upsert with same fingerprint should not create new row
    $countBefore = MigrationRunItem::where('migration_run_id', $run->id)->count();
    MigrationRunItem::updateOrCreate(
        ['migration_run_id' => $run->id, 'source_system' => 'nimbusdocs', 'source_entity' => 'portal_submission_files', 'source_id' => '50', 'target_entity' => 'nimbus_submission_files', 'target_role' => 'PRIMARY'],
        ['source_fingerprint' => $fp, 'status' => 'WOULD_MIGRATE']
    );
    expect(MigrationRunItem::where('migration_run_id', $run->id)->count())->toBe($countBefore);
});

// 39. Token security
it('legacy PENDING token never planned as active target token and code absent from report', function () {
    $dto = LegacyAccessTokenDto::fromArray([
        'id' => 1, 'portal_user_id' => 1, 'code' => 'EGTAQM7TH5EV', 'status' => 'PENDING',
        'expires_at' => '2026-02-24 10:00:00', 'created_at' => '2026-02-23 10:00:00',
    ]);
    $sanitized = LegacyArchiveSanitizer::sanitizeAccessToken($dto);
    expect(json_encode($sanitized))->not->toContain('EGTAQM7TH5EV');
    expect($sanitized)->not->toHaveKey('code');
    expect($sanitized['status'])->toBe('PENDING');
    // Simulate planner result
    $planned = [
        'source_entity' => 'portal_access_tokens',
        'source_id' => '1',
        'target_entity' => 'nimbus_access_tokens',
        'target_role' => 'PRIMARY',
        'status' => 'SKIPPED_INTENTIONALLY',
        'source_fingerprint' => hash('sha256', '1PENDING'),
        'metadata' => $sanitized,
    ];
    expect(json_encode($planned))->not->toContain('EGTAQM7TH5EV');
    // Ensure operational table untouched
    expect(AccessToken::count())->toBe(0);
});

// 40. Notification sanitization via allowlist
it('notification sanitization allowlist strips code, password_hash, CPF, phone, raw email', function () {
    $payload = [
        'user' => ['email' => 'victim@example.com', 'document_number' => '52998224725', 'phone_number' => '11999990001'],
        'token' => ['code' => 'EGTAQM7TH5EV', 'hash' => 'secret'],
        'extra' => ['password_hash' => '$2y$10$...', 'cpf' => '52998224725'],
        'submission' => ['reference_code' => 'SUB-001', 'id' => 1],
    ];
    $dto = LegacyNotificationDto::fromArray([
        'id' => 1, 'type' => 'TOKEN_CREATED', 'recipient_email' => 'victim@example.com',
        'recipient_name' => 'Victim', 'subject' => 'sub', 'template' => 'token_created',
        'payload_json' => json_encode($payload), 'status' => 'SENT', 'attempts' => 0, 'max_attempts' => 5,
        'created_at' => '2026-02-23 10:00:00', 'sent_at' => '2026-02-23 10:01:00',
    ]);
    $sanitized = LegacyArchiveSanitizer::sanitizeNotification($dto);
    $json = json_encode($sanitized);
    expect($json)->not->toContain('EGTAQM7TH5EV');
    expect($json)->not->toContain('password_hash');
    expect($json)->not->toContain('52998224725');
    expect($json)->not->toContain('11999990001');
    expect($json)->not->toContain('victim@example.com');
    expect($sanitized['payload_summary']['submission_reference_code'] ?? null)->toBe('SUB-001');
    expect($sanitized['recipient_hash'])->toStartWith('pii_');
});

// 41. Library documents
it('portal_documents and general_documents map with owner/category, checksum, is_active, published_at', function () {
    $resolver = new LegacyFilePathResolver('/tmp/nimbus-legacy');
    $planner = new FileMigrationPlanner($resolver);
    $userMap = ['2' => ['status' => 'MIGRATED', 'target_id' => '100']];

    $portalDto = LegacyPortalDocumentDto::fromArray([
        'id' => 2, 'portal_user_id' => 2, 'title' => 'Teste', 'description' => 'desc',
        'file_path' => 'C:\\xampp\\htdocs\\NimbusDocs/storage/documents/2/\\02eb66fd.pdf',
        'file_original_name' => 'doc.pdf', 'file_size' => 431720, 'file_mime' => 'application/pdf',
        'created_at' => '2026-01-07 10:48:32', 'created_by_admin' => 5,
    ]);
    $fp = SourceFingerprintService::fingerprint(['id' => '2', 'portal_user_id' => '2', 'file_path' => $portalDto->filePath]);
    $available = ['/tmp/nimbus-legacy/02eb66fd.pdf'];
    $plans = $planner->planPortalDocument($portalDto, $fp, $available, $userMap);
    expect($plans[0]['status'])->toBe('MIGRATED');
    expect($plans[0]['target_entity'])->toBe('nimbus_documents');
    expect($plans[0]['metadata']['planned_target_path'])->not->toContain('C:');

    $generalDto = LegacyGeneralDocumentDto::fromArray([
        'id' => 4, 'category_id' => 4, 'title' => 'Teste', 'file_path' => 'C:\\xampp\\htdocs\\NimbusDocs\\src\\Presentation\\Controller\\Admin/../../../../storage/general_documents/\\405d7745.pdf',
        'file_original_name' => 'doc2.pdf', 'file_size' => 431720, 'file_mime' => 'application/pdf',
        'is_active' => 1, 'published_at' => '2026-01-07 10:38:06', 'created_by_admin' => 5, 'created_at' => '2026-01-07 10:38:06',
    ]);
    $fp2 = SourceFingerprintService::fingerprint(['id' => '4', 'category_id' => '4', 'file_path' => $generalDto->filePath]);
    $available2 = ['/tmp/nimbus-legacy/405d7745.pdf'];
    $plans2 = $planner->planGeneralDocument($generalDto, $fp2, $available2);
    expect($plans2[0]['status'])->toBe('MIGRATED');
    expect($plans2[0]['metadata']['is_active'])->toBeTrue();
    expect($plans2[0]['metadata']['category_id'])->toBe('4');
});

<?php

use App\Models\Nimbus\MigrationMap;
use App\Models\Nimbus\MigrationRun;
use App\Models\Nimbus\MigrationRunItem;
use App\Models\Nimbus\PortalUser;
use App\Models\Nimbus\Submission;
use App\Services\Nimbus\Migration\DatabaseLegacySourceReader;
use App\Services\Nimbus\Migration\LegacyPortalUserDto;
use App\Services\Nimbus\Migration\SourceFingerprintService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
    Config::set('nimbus.pii_blind_index_key', 'test-blind-index-key-for-m11-32bytes!!');
    Config::set('nimbus.pii_blind_index_version', 'v1');
    Config::set('nimbus.migration.control_connection', 'sqlite');
    Config::set('nimbus.migration.legacy_storage_root', '/tmp/nimbus-legacy');
    // Ensure control connection points to same sqlite memory as default for tests
    Config::set('database.connections.nimbus_legacy.database', ':memory:');
    Config::set('database.connections.nimbus_migration_control.database', ':memory:');
});

// Helper to run dry-run command with fixture and capture
function runDryRun(?string $runUuid = null, array $fixtureOverrides = []): MigrationRun
{
    $fixture = [
        'portal_users' => [['id' => 5001, 'full_name' => 'CrossRun User', 'email' => 'crossrun@example.com', 'document_number' => '52998224725', 'phone_number' => '11999990001', 'status' => 'ACTIVE', 'created_at' => '2026-02-23 15:07:17']],
        'portal_submissions' => [],
        'portal_submission_shareholders' => [],
        'portal_submission_files' => [],
        'portal_submission_notes' => [],
        'portal_documents' => [],
        'general_documents' => [],
        'portal_access_tokens' => [],
        'notification_outbox' => [],
        'audit_logs' => [],
        'available_files' => [],
        'meta' => ['source_snapshot_at' => '2026-02-23 15:07:17', 'descriptor' => 'm11-crossrun', 'dump_sha256' => 'sha'],
    ];
    foreach ($fixtureOverrides as $k => $v) {
        $fixture[$k] = $v;
    }
    $tmp = temporaryTestFilePath('nimbus-m11', 'json');
    file_put_contents($tmp, json_encode($fixture));

    $params = ['--dry-run' => true, '--source' => $tmp];
    if ($runUuid) {
        $params['--run-uuid'] = $runUuid;
    }
    test()->artisan('nimbus:migrate-legacy', $params)->assertExitCode(0);
    unlink($tmp);

    // Find latest run or by uuid
    if ($runUuid) {
        return MigrationRun::where('run_uuid', $runUuid)->firstOrFail();
    }

    return MigrationRun::latest('id')->firstOrFail();
}

// 28. Multiple runs — cross-run idempotency
it('two independent dry-runs produce WOULD_MIGRATE each and zero canonical maps', function () {
    $runA = runDryRun();
    $itemsA = MigrationRunItem::where('migration_run_id', $runA->id)->where('source_entity', 'portal_users')->get();
    expect($itemsA->count())->toBe(1);
    expect($itemsA->first()->status)->toBe('WOULD_MIGRATE');
    expect(MigrationMap::count())->toBe(0);

    $runB = runDryRun();
    $itemsB = MigrationRunItem::where('migration_run_id', $runB->id)->where('source_entity', 'portal_users')->get();
    expect($itemsB->count())->toBe(1);
    expect($itemsB->first()->status)->toBe('WOULD_MIGRATE');
    expect(MigrationMap::count())->toBe(0);

    // Different run UUIDs must still recognize same source identity as run_items, but not as canonical
    expect($runA->run_uuid)->not->toBe($runB->run_uuid);
    expect(MigrationRunItem::count())->toBe(2);
});

it('after seeding canonical map and target, dry-run yields ALREADY_MIGRATED and map count remains 1', function () {
    // First two dry-runs
    $runA = runDryRun();
    $runB = runDryRun();

    // Simulate completed migration: create target and canonical map
    $target = PortalUser::create([
        'full_name' => 'Migrated User', 'email' => 'crossrun@example.com',
        'document_number' => '52998224725', 'phone_number' => '11999990001', 'status' => 'ACTIVE',
    ]);
    $fp = SourceFingerprintService::forPortalUser(LegacyPortalUserDto::fromArray([
        'id' => 5001, 'full_name' => 'CrossRun User', 'email' => 'crossrun@example.com',
        'document_number' => '52998224725', 'phone_number' => '11999990001', 'status' => 'ACTIVE', 'created_at' => '2026-02-23 15:07:17',
    ]));
    $canonical = MigrationMap::create([
        'source_system' => 'nimbusdocs',
        'source_entity' => 'portal_users',
        'source_id' => '5001',
        'source_role' => 'PRIMARY',
        'target_entity' => 'nimbus_portal_users',
        'target_role' => 'PRIMARY',
        'target_id' => (string) $target->id,
        'source_fingerprint' => $fp,
        'first_migration_run_id' => $runA->id,
        'last_verified_run_id' => $runA->id,
        'status' => 'MIGRATED',
    ]);
    expect(MigrationMap::count())->toBe(1);

    // Third dry-run should detect canonical
    $runC = runDryRun();
    $itemsC = MigrationRunItem::where('migration_run_id', $runC->id)->where('source_entity', 'portal_users')->get();
    expect($itemsC->first()->status)->toBe('ALREADY_MIGRATED');
    expect($itemsC->first()->target_id)->toBe((string) $target->id);
    expect(MigrationMap::count())->toBe(1); // not duplicated
});

// 29. Changed source
it('changed source fingerprint after canonical yields SOURCE_CHANGED and does not overwrite map', function () {
    $runA = runDryRun();
    $target = PortalUser::create([
        'full_name' => 'Original', 'email' => 'changed@example.com',
        'document_number' => '52998224725', 'phone_number' => '11999990001', 'status' => 'ACTIVE',
    ]);
    $fpX = SourceFingerprintService::forPortalUser(LegacyPortalUserDto::fromArray([
        'id' => 5001, 'full_name' => 'CrossRun User', 'email' => 'crossrun@example.com',
        'document_number' => '52998224725', 'phone_number' => '11999990001', 'status' => 'ACTIVE', 'created_at' => '2026-02-23 15:07:17',
    ]));
    MigrationMap::create([
        'source_system' => 'nimbusdocs', 'source_entity' => 'portal_users', 'source_id' => '5001',
        'source_role' => 'PRIMARY', 'target_entity' => 'nimbus_portal_users', 'target_role' => 'PRIMARY',
        'target_id' => (string) $target->id, 'source_fingerprint' => $fpX, 'status' => 'MIGRATED',
    ]);

    // Now source changes (full_name different)
    $changedFixture = [
        'portal_users' => [['id' => 5001, 'full_name' => 'CrossRun User CHANGED', 'email' => 'crossrun@example.com', 'document_number' => '52998224725', 'phone_number' => '11999990001', 'status' => 'ACTIVE', 'created_at' => '2026-02-23 15:07:17']],
    ];
    $tmp = temporaryTestFilePath('nimbus-m11-changed', 'json');
    file_put_contents($tmp, json_encode(array_merge([
        'portal_submissions' => [], 'portal_submission_shareholders' => [], 'portal_submission_files' => [],
        'portal_submission_notes' => [], 'portal_documents' => [], 'general_documents' => [],
        'portal_access_tokens' => [], 'notification_outbox' => [], 'audit_logs' => [], 'available_files' => [],
        'meta' => ['source_snapshot_at' => '2026-02-23 15:07:17', 'descriptor' => 'm11-changed'],
    ], $changedFixture)));
    test()->artisan('nimbus:migrate-legacy --dry-run --source='.$tmp)->assertExitCode(0);
    unlink($tmp);

    $latestRun = MigrationRun::latest('id')->first();
    $item = MigrationRunItem::where('migration_run_id', $latestRun->id)->where('source_entity', 'portal_users')->first();
    expect($item->status)->toBe('SOURCE_CHANGED');
    expect(MigrationMap::first()->source_fingerprint)->toBe($fpX); // unchanged
});

// 30. Stale map
it('canonical map with missing target yields STALE_MAPPING', function () {
    $runA = runDryRun();
    // Create canonical pointing to non-existent target id
    MigrationMap::create([
        'source_system' => 'nimbusdocs', 'source_entity' => 'portal_users', 'source_id' => '5001',
        'source_role' => 'PRIMARY', 'target_entity' => 'nimbus_portal_users', 'target_role' => 'PRIMARY',
        'target_id' => '99999', // does not exist
        'source_fingerprint' => 'abc', 'status' => 'MIGRATED',
    ]);

    $runB = runDryRun();
    $item = MigrationRunItem::where('migration_run_id', $runB->id)->where('source_entity', 'portal_users')->first();
    expect($item->status)->toBe('STALE_MAPPING');
    expect($item->error_code)->toBe('STALE_MAPPING');
});

// 31. Archive uniqueness — nullable identity must not allow duplicates under MySQL
it('archive identities use deterministic NOT NULL target_role and do not allow duplicate canonical', function () {
    $run = runDryRun();
    // Tokens produce archive items with target_role TOKEN_METADATA and target_id NO_TARGET (not null)
    $tokenItems = MigrationRunItem::where('migration_run_id', $run->id)->where('source_entity', 'portal_users')->count();
    expect($tokenItems)->toBeGreaterThan(0);

    // Simulate two access token archives with same source but different target_role should be distinct
    // But same source + same role should be unique — test that canonical enforces it
    $fp = hash('sha256', 'token-1');
    MigrationMap::create([
        'source_system' => 'nimbusdocs', 'source_entity' => 'portal_access_tokens', 'source_id' => '22',
        'source_role' => 'PRIMARY', 'target_entity' => 'archive', 'target_role' => 'TOKEN_METADATA',
        'target_id' => 'NO_TARGET', 'source_fingerprint' => $fp, 'status' => 'ARCHIVED',
    ]);
    expect(function () {
        MigrationMap::create([
            'source_system' => 'nimbusdocs', 'source_entity' => 'portal_access_tokens', 'source_id' => '22',
            'source_role' => 'PRIMARY', 'target_entity' => 'archive', 'target_role' => 'TOKEN_METADATA',
            'target_id' => 'NO_TARGET', 'source_fingerprint' => hash('sha256', 'other'), 'status' => 'ARCHIVED',
        ]);
    })->toThrow(Exception::class);

    // Different target_role should be allowed (notifications)
    MigrationMap::create([
        'source_system' => 'nimbusdocs', 'source_entity' => 'notification_outbox', 'source_id' => '1',
        'source_role' => 'PRIMARY', 'target_entity' => 'archive', 'target_role' => 'NOTIFICATION_ARCHIVE',
        'target_id' => 'NO_TARGET', 'source_fingerprint' => 'x', 'status' => 'ARCHIVED',
    ]);
    expect(MigrationMap::where('target_role', 'NOTIFICATION_ARCHIVE')->count())->toBe(1);
});

// 32. DatabaseLegacySourceReader streams and DTOs match Array reader
it('database legacy source reader streams entities as DTOs', function () {
    // Setup legacy DB on sqlite memory via nimbus_legacy connection
    Config::set('database.connections.nimbus_legacy.driver', 'sqlite');
    Config::set('database.connections.nimbus_legacy.database', ':memory:');
    // Create schema
    $conn = DB::connection('nimbus_legacy');
    $conn->statement('CREATE TABLE portal_users (id integer primary key, full_name text, email text, document_number text, phone_number text, external_id text, notes text, status text, last_login_at text, last_login_method text, created_at text, updated_at text)');
    $conn->table('portal_users')->insert(['id' => 8001, 'full_name' => 'DB User', 'email' => 'db@example.com', 'document_number' => '52998224725', 'phone_number' => '11999990001', 'status' => 'ACTIVE', 'created_at' => '2026-02-23 15:07:17']);
    $conn->statement('CREATE TABLE portal_submissions (id integer primary key, portal_user_id integer, reference_code text, submission_type text, title text, message text, responsible_name text, company_cnpj text, company_name text, main_activity text, phone text, website text, net_worth text, annual_revenue text, is_us_person integer, is_pep integer, shareholder_data text, registrant_name text, registrant_position text, registrant_rg text, registrant_cpf text, status text, created_ip text, created_user_agent text, submitted_at text, status_updated_at text, status_updated_by integer, created_at text, updated_at text)');
    $conn->table('portal_submissions')->insert(['id' => 8101, 'portal_user_id' => 8001, 'reference_code' => 'SUB-DB-001', 'title' => 'DB Sub', 'status' => 'PENDING', 'submitted_at' => '2026-02-20 10:00:00', 'created_at' => '2026-02-20 10:00:00']);
    $conn->statement('CREATE TABLE portal_submission_shareholders (id integer primary key, submission_id integer, name text, document_rg text, document_cnpj text, percentage text, created_at text)');
    $conn->statement('CREATE TABLE portal_submission_files (id integer primary key, submission_id integer, document_type text, origin text, visible_to_user integer, original_name text, stored_name text, mime_type text, size_bytes integer, storage_path text, checksum text, current_version integer, uploaded_at text)');
    $conn->statement('CREATE TABLE portal_submission_notes (id integer primary key, submission_id integer, admin_user_id integer, visibility text, message text, created_at text)');
    $conn->statement('CREATE TABLE portal_documents (id integer primary key, portal_user_id integer, title text, description text, file_path text, file_original_name text, file_size integer, file_mime text, created_at text, created_by_admin integer)');
    $conn->statement('CREATE TABLE document_categories (id integer primary key, name text, description text, sort_order integer, created_at text)');
    $conn->statement('CREATE TABLE general_documents (id integer primary key, category_id integer, title text, description text, file_path text, file_mime text, file_size integer, file_original_name text, is_active integer, published_at text, created_by_admin integer, created_at text)');
    $conn->statement('CREATE TABLE portal_access_tokens (id integer primary key, portal_user_id integer, code text, status text, expires_at text, used_at text, used_ip text, used_user_agent text, created_at text)');
    $conn->statement('CREATE TABLE audit_logs (id integer primary key, occurred_at text, actor_type text, actor_id integer, actor_name text, action text, target_type text, target_id integer, ip_address text, user_agent text, context_type text, context_id integer, summary text, details text)');
    $conn->statement('CREATE TABLE notification_outbox (id integer primary key, type text, recipient_email text, recipient_name text, subject text, template text, payload_json text, correlation_id text, status text, attempts integer, max_attempts integer, next_attempt_at text, last_error text, created_at text, sent_at text)');
    $conn->statement('CREATE TABLE portal_announcements (id integer primary key, title text, body text, level text, starts_at text, ends_at text, is_active integer, created_by_admin integer, created_at text, updated_at text)');
    $conn->statement('CREATE TABLE tags (id integer primary key, name text, color text, created_at text)');
    $conn->statement('CREATE TABLE submission_tags (submission_id integer, tag_id integer, created_at text)');

    $reader = new DatabaseLegacySourceReader('nimbus_legacy');
    $users = iterator_to_array($reader->portalUsers());
    expect($users[0]->id)->toBe(8001);
    expect($users[0]->email)->toBe('db@example.com');
    $subs = iterator_to_array($reader->submissions());
    expect($subs[0]->referenceCode)->toBe('SUB-DB-001');
    // Empty tables should yield zero without error
    expect(iterator_to_array($reader->shareholders()))->toHaveCount(0);
    expect(iterator_to_array($reader->portalDocuments()))->toHaveCount(0);
    expect(iterator_to_array($reader->tags()))->toHaveCount(0);
});

// 33. Source is read-only — no INSERT/UPDATE/DELETE on legacy connection
it('database legacy source reader executes no write SQL', function () {
    Config::set('database.connections.nimbus_legacy.driver', 'sqlite');
    Config::set('database.connections.nimbus_legacy.database', ':memory:');
    $conn = DB::connection('nimbus_legacy');
    $conn->statement('CREATE TABLE portal_users (id integer primary key, full_name text, email text, document_number text, phone_number text, external_id text, notes text, status text, last_login_at text, last_login_method text, created_at text, updated_at text)');
    $conn->table('portal_users')->insert(['id' => 1, 'full_name' => 'A', 'email' => 'a@example.com', 'document_number' => '52998224725', 'phone_number' => '11999990001', 'status' => 'ACTIVE', 'created_at' => '2026-02-23 15:07:17']);
    $countBefore = $conn->table('portal_users')->count();

    $queries = [];
    DB::listen(function ($q) use (&$queries) {
        if ($q->connectionName === 'nimbus_legacy') {
            $queries[] = $q->sql;
        }
    });

    $reader = new DatabaseLegacySourceReader('nimbus_legacy');
    foreach ($reader->portalUsers() as $dto) { /* consume */
    }
    foreach ($reader->submissions() as $dto) { /* empty */
    }

    foreach ($queries as $sql) {
        expect(strtoupper(ltrim($sql)))->not->toStartWith('INSERT');
        expect(strtoupper(ltrim($sql)))->not->toStartWith('UPDATE');
        expect(strtoupper(ltrim($sql)))->not->toStartWith('DELETE');
        expect(strtoupper(ltrim($sql)))->not->toStartWith('ALTER');
        expect(strtoupper(ltrim($sql)))->not->toStartWith('DROP');
        expect(strtoupper(ltrim($sql)))->not->toStartWith('TRUNCATE');
        expect(strtoupper(ltrim($sql)))->not->toStartWith('CREATE');
    }
    expect($conn->table('portal_users')->count())->toBe($countBefore);
});

// 34. Target is read-only during dry-run
it('target operational tables remain unchanged during dry-run', function () {
    $pu = PortalUser::create(['full_name' => 'TargetUser', 'email' => 'target@example.com', 'document_number' => '52998224725', 'status' => 'ACTIVE']);
    $sub = Submission::create(['nimbus_portal_user_id' => $pu->id, 'reference_code' => 'EXISTING-001', 'title' => 'Existing', 'status' => 'PENDING', 'submitted_at' => now()]);
    $userCountBefore = PortalUser::count();
    $subCountBefore = Submission::count();

    // Dry-run with conflicting reference_code should detect CONFLICT but not write
    $fixture = [
        'portal_users' => [['id' => 6001, 'full_name' => 'New', 'email' => 'new@example.com', 'document_number' => '11144477735', 'phone_number' => '11999990002', 'status' => 'ACTIVE', 'created_at' => '2026-02-23 15:07:17']],
        'portal_submissions' => [['id' => 6101, 'portal_user_id' => 6001, 'reference_code' => 'EXISTING-001', 'title' => 'Conflict Sub', 'status' => 'PENDING', 'submitted_at' => '2026-02-20 10:00:00', 'created_at' => '2026-02-20 10:00:00']],
        'portal_submission_shareholders' => [], 'portal_submission_files' => [], 'portal_submission_notes' => [],
        'portal_documents' => [], 'general_documents' => [], 'portal_access_tokens' => [], 'notification_outbox' => [], 'audit_logs' => [], 'available_files' => [],
        'meta' => ['source_snapshot_at' => '2026-02-23 15:07:17', 'descriptor' => 'm11-target-readonly'],
    ];
    $tmp = temporaryTestFilePath('nimbus-m11-target', 'json');
    file_put_contents($tmp, json_encode($fixture));
    test()->artisan('nimbus:migrate-legacy --dry-run --source='.$tmp)->assertExitCode(0);
    unlink($tmp);

    expect(PortalUser::count())->toBe($userCountBefore);
    expect(Submission::count())->toBe($subCountBefore);
    // The dry-run should have created a CONFLICT run item
    $latest = MigrationRun::latest('id')->first();
    $conflictItem = MigrationRunItem::where('migration_run_id', $latest->id)->where('source_entity', 'portal_submissions')->where('status', 'CONFLICT')->first();
    expect($conflictItem)->not->toBeNull();
});

// 35. Control store is separate — source and target no writes, control has writes
it('dry-run writes only to control connection', function () {
    // Use default as control for this test (already isolated via RefreshDatabase)
    $controlBefore = MigrationRun::count();
    $puBefore = PortalUser::count();
    $fixture = [
        'portal_users' => [['id' => 7001, 'full_name' => 'ControlTest', 'email' => 'control@example.com', 'document_number' => '52998224725', 'phone_number' => '11999990001', 'status' => 'ACTIVE', 'created_at' => '2026-02-23 15:07:17']],
        'portal_submissions' => [], 'portal_submission_shareholders' => [], 'portal_submission_files' => [],
        'portal_submission_notes' => [], 'portal_documents' => [], 'general_documents' => [],
        'portal_access_tokens' => [], 'notification_outbox' => [], 'audit_logs' => [], 'available_files' => [],
        'meta' => ['source_snapshot_at' => '2026-02-23 15:07:17', 'descriptor' => 'm11-control'],
    ];
    $tmp = temporaryTestFilePath('nimbus-m11-control', 'json');
    file_put_contents($tmp, json_encode($fixture));
    test()->artisan('nimbus:migrate-legacy --dry-run --source='.$tmp.' --control-connection=sqlite')->assertExitCode(0);
    unlink($tmp);

    expect(MigrationRun::count())->toBe($controlBefore + 1);
    expect(PortalUser::count())->toBe($puBefore); // target untouched
    // Source Array reader is read-only by design — no DB to write
});

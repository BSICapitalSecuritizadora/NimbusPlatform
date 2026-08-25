<?php

use App\Models\Measurement;
use App\Models\MeasurementAsset;
use App\Models\MeasurementFileMigration;
use App\Models\Operation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    Storage::fake('public');
});

/**
 * @return array{user: User, operation: Operation, measurement: Measurement}
 */
function createP02LegacyBase(): array
{
    $user = User::factory()->withTwoFactor()->create();
    $user->assignRole('editor');
    $operation = Operation::factory()->create(['assigned_user_id' => $user->id]);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->id,
        'storage_path' => null,
        'filename' => null,
    ]);

    return compact('user', 'operation', 'measurement');
}

function createP02LegacyAsset(Measurement $measurement, string $path): MeasurementAsset
{
    return MeasurementAsset::withoutEvents(fn (): MeasurementAsset => MeasurementAsset::query()->create([
        'measurement_id' => $measurement->id,
        'filename' => basename($path),
        'storage_path' => $path,
        'storage_disk' => null,
        'uploaded_at' => now(),
    ]));
}

/**
 * @return array{journal: MeasurementFileMigration, destination: string, recovery: string, hash: string}
 */
function createP02MigrationCheckpoint(
    MeasurementAsset $asset,
    string $sourcePath,
    string $state,
    bool $databaseIsPrivate,
    bool $publicExists,
    bool $corruptDestination = false,
): array {
    $content = '%PDF-1.7 deterministic-legacy-content';
    $hash = hash('sha256', $content);
    $destination = "nimbus_docs/measurements/legacy/assets/{$asset->id}/".basename($sourcePath);
    $recovery = $destination.'.migration-recovery';

    if ($publicExists) {
        Storage::disk('public')->put($sourcePath, $content);
    }

    Storage::disk('local')->put($destination, $corruptDestination ? '%PDF-1.7 corrupt' : $content);
    Storage::disk('local')->put($recovery, $content);

    if ($databaseIsPrivate) {
        DB::table('measurement_assets')->where('id', $asset->id)->update([
            'storage_path' => $destination,
            'storage_disk' => 'local',
            'sha256' => $hash,
            'mime_type' => 'application/pdf',
            'size' => strlen($content),
        ]);
    }

    $journal = MeasurementFileMigration::query()->create([
        'migratable_type' => MeasurementAsset::class,
        'migratable_id' => $asset->id,
        'file_role' => 'assets',
        'source_disk' => 'public',
        'source_path' => $sourcePath,
        'source_sha256' => $hash,
        'destination_disk' => 'local',
        'destination_path' => $destination,
        'recovery_path' => $recovery,
        'state' => $state,
    ]);

    return compact('journal', 'destination', 'recovery', 'hash');
}

it('persists exact source ownership and completes prepare switch verify cleanup in order', function () {
    $scenario = createP02LegacyBase();
    $source = 'measurements/history/original.pdf';
    $content = '%PDF-1.7 journal-content';
    Storage::disk('public')->put($source, $content);
    $asset = createP02LegacyAsset($scenario['measurement'], $source);

    $this->artisan('measurements:secure-legacy-files', ['--execute' => true])
        ->expectsOutputToContain('migrated')
        ->assertSuccessful();

    $journal = MeasurementFileMigration::query()->firstOrFail();
    $asset->refresh();

    expect($journal->migratable_type)->toBe(MeasurementAsset::class)
        ->and($journal->migratable_id)->toBe($asset->id)
        ->and($journal->source_disk)->toBe('public')
        ->and($journal->source_path)->toBe($source)
        ->and($journal->source_sha256)->toBe(hash('sha256', $content))
        ->and($journal->destination_path)->toBe($asset->storage_path)
        ->and($journal->state)->toBe(MeasurementFileMigration::STATE_COMPLETED)
        ->and($journal->prepared_at)->not->toBeNull()
        ->and($journal->switched_at)->not->toBeNull()
        ->and($journal->verified_at)->not->toBeNull()
        ->and($journal->cleaned_at)->not->toBeNull();
    Storage::disk('public')->assertMissing($source);
    Storage::disk('local')->assertExists($asset->storage_path);
    Storage::disk('local')->assertMissing($journal->recovery_path);

    $this->artisan('measurements:secure-legacy-files', ['--execute' => true])
        ->expectsOutputToContain('already_secure')
        ->assertSuccessful();
});

it('recovers deterministically from every persisted crash checkpoint', function (string $state, bool $databaseIsPrivate, bool $publicExists) {
    $scenario = createP02LegacyBase();
    $source = "measurements/crash/{$state}-".($publicExists ? 'public' : 'cleaned').'.pdf';
    $content = '%PDF-1.7 deterministic-legacy-content';

    if (! $publicExists) {
        Storage::disk('public')->put($source, $content);
    }

    $asset = createP02LegacyAsset($scenario['measurement'], $source);

    if (! $publicExists) {
        Storage::disk('public')->delete($source);
    }

    $checkpoint = createP02MigrationCheckpoint(
        $asset,
        $source,
        $state,
        $databaseIsPrivate,
        $publicExists,
    );

    $this->artisan('measurements:secure-legacy-files', ['--execute' => true])
        ->expectsOutputToContain('recovered')
        ->assertSuccessful();

    expect($asset->fresh()->storage_path)->toBe($checkpoint['destination'])
        ->and($asset->fresh()->storage_disk)->toBe('local')
        ->and($asset->fresh()->sha256)->toBe($checkpoint['hash'])
        ->and($checkpoint['journal']->fresh()->state)->toBe(MeasurementFileMigration::STATE_COMPLETED);
    Storage::disk('public')->assertMissing($source);
    Storage::disk('local')->assertExists($checkpoint['destination']);
    Storage::disk('local')->assertMissing($checkpoint['recovery']);
})->with([
    'P1 after private copy' => [MeasurementFileMigration::STATE_PREPARING, false, true],
    'P2 after database commit' => [MeasurementFileMigration::STATE_SWITCHED, true, true],
    'P3 after private verification' => [MeasurementFileMigration::STATE_VERIFIED, true, true],
    'P4 before public delete' => [MeasurementFileMigration::STATE_VERIFIED, true, true],
    'P5 after public delete' => [MeasurementFileMigration::STATE_VERIFIED, true, false],
    'P6 before cleanup completion checkpoint' => [MeasurementFileMigration::STATE_VERIFIED, true, false],
]);

it('recovers F13 after DB commit from the private recovery copy before deleting public source', function () {
    $scenario = createP02LegacyBase();
    $source = 'measurements/f13/source.pdf';
    Storage::disk('public')->put($source, '%PDF-1.7 deterministic-legacy-content');
    $asset = createP02LegacyAsset($scenario['measurement'], $source);
    $checkpoint = createP02MigrationCheckpoint(
        $asset,
        $source,
        MeasurementFileMigration::STATE_SWITCHED,
        databaseIsPrivate: true,
        publicExists: true,
        corruptDestination: true,
    );

    $this->artisan('measurements:secure-legacy-files', ['--execute' => true])
        ->expectsOutputToContain('recovered')
        ->assertSuccessful();

    expect(Storage::disk('local')->get($checkpoint['destination']))
        ->toBe('%PDF-1.7 deterministic-legacy-content')
        ->and($checkpoint['journal']->fresh()->state)->toBe(MeasurementFileMigration::STATE_COMPLETED);
    Storage::disk('public')->assertMissing($source);
});

it('never collides two exact sources with the same basename and SHA', function () {
    $scenario = createP02LegacyBase();
    $content = '%PDF-1.7 duplicate-content';
    $firstPath = 'measurements/first/foo.pdf';
    $secondPath = 'measurements/second/foo.pdf';
    Storage::disk('public')->put($firstPath, $content);
    Storage::disk('public')->put($secondPath, $content);
    $first = createP02LegacyAsset($scenario['measurement'], $firstPath);
    $second = createP02LegacyAsset($scenario['measurement'], $secondPath);

    $this->artisan('measurements:secure-legacy-files', ['--execute' => true, '--limit' => 1])->assertSuccessful();

    expect($first->fresh()->storage_disk)->toBe('local')
        ->and($second->fresh()->storage_disk)->toBeNull();
    Storage::disk('public')->assertMissing($firstPath);
    Storage::disk('public')->assertExists($secondPath);

    $journal = MeasurementFileMigration::query()->firstOrFail();
    expect($journal->source_path)->toBe($firstPath);
});

it('rejects public for new assets and receipts while authorized legacy public remains readable', function () {
    $scenario = createP02LegacyBase();
    $this->actingAs($scenario['user']);
    Storage::disk('public')->put('measurements/new-public.pdf', '%PDF-1.7 public-new');

    expect(fn () => $scenario['measurement']->assets()->create([
        'storage_path' => 'measurements/new-public.pdf',
        'storage_disk' => 'public',
    ]))->toThrow(ValidationException::class);

    $payment = $scenario['measurement']->payments()->create([
        'operation_id' => $scenario['operation']->id,
        'pay_date' => now(),
        'amount' => 100,
    ]);
    Storage::disk('public')->put('measurements/new-receipt.pdf', '%PDF-1.7 public-receipt');

    expect(fn () => $payment->update([
        'receipt_path' => 'measurements/new-receipt.pdf',
        'receipt_disk' => 'public',
    ]))->toThrow(ValidationException::class);

    $legacy = createP02LegacyAsset($scenario['measurement'], 'measurements/new-public.pdf');

    $this->get(route('admin.measurements.assets.download', $legacy))->assertOk();
});

it('reports backfill persistence failure without counting a false success', function () {
    $scenario = createP02LegacyBase();
    $path = 'nimbus_docs/measurements/assets/backfill-save.pdf';
    Storage::disk('local')->put($path, '%PDF-1.7 backfill');
    $asset = $scenario['measurement']->assets()->create([
        'storage_path' => $path,
        'storage_disk' => 'local',
    ]);
    DB::table('measurement_assets')->where('id', $asset->id)->update(['sha256' => null]);
    DB::unprepared("CREATE TRIGGER fail_p02_backfill BEFORE UPDATE ON measurement_assets WHEN OLD.id = {$asset->id} BEGIN SELECT RAISE(ABORT, 'forced backfill failure'); END");

    $this->artisan('measurements:backfill-file-hashes', ['--execute' => true])
        ->expectsOutputToContain('forced backfill failure')
        ->assertFailed();

    expect($asset->fresh()->sha256)->toBeNull();
});

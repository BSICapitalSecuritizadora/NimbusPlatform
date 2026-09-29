<?php

use App\Models\Measurement;
use App\Models\MeasurementAsset;
use App\Models\Operation;
use App\Models\User;
use App\Services\DocumentStorageService;
use App\Services\MeasurementFileValidationService;
use App\Services\MeasurementWorkflow;
use App\Services\Security\ClamAvFileScanner;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\MeasurementReceiptEvidenceScenario;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    Storage::fake('public');
    Notification::fake();
});

function createFileSecurityEditor(): User
{
    $user = User::factory()->withTwoFactor()->create();
    $user->assignRole('editor');

    return $user;
}

/**
 * @return array{participant: User, outsider: User, operation: Operation, measurement: Measurement, asset: MeasurementAsset}
 */
function createFileSecurityScenario(): array
{
    $participant = createFileSecurityEditor();
    $outsider = createFileSecurityEditor();
    $operation = Operation::factory()->create(['assigned_user_id' => $participant->id]);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->id,
        'storage_path' => null,
        'filename' => null,
    ]);
    $path = 'nimbus_docs/measurements/assets/authorized.pdf';
    Storage::disk('local')->put($path, '%PDF-1.7 authorized-content');
    $asset = $measurement->assets()->create([
        'storage_path' => $path,
        'storage_disk' => 'local',
        'filename' => 'medicao.pdf',
    ]);

    return compact('participant', 'outsider', 'operation', 'measurement', 'asset');
}

function createLegacyPublicAsset(Measurement $measurement, string $path): MeasurementAsset
{
    return MeasurementAsset::withoutEvents(fn (): MeasurementAsset => MeasurementAsset::query()->create([
        'measurement_id' => $measurement->id,
        'filename' => basename($path),
        'storage_path' => $path,
        'storage_disk' => null,
        'uploaded_at' => now(),
    ]));
}

it('stores new measurement assets privately and derives SHA-256 from real content', function () {
    $scenario = createFileSecurityScenario();
    $asset = $scenario['asset']->fresh();

    Storage::disk('local')->assertExists($asset->storage_path);
    Storage::disk('public')->assertMissing($asset->storage_path);

    expect($asset->storage_disk)->toBe('local')
        ->and($asset->sha256)->toBe(hash('sha256', '%PDF-1.7 authorized-content'))
        ->and($asset->sha256)->toHaveLength(64)
        ->and($asset->size)->toBe(strlen('%PDF-1.7 authorized-content'));

    $this->get('/storage/'.$asset->storage_path)->assertNotFound();
});

it('serves private files only through an authorized controller with safe headers', function () {
    $scenario = createFileSecurityScenario();
    $route = route('admin.measurements.assets.download', $scenario['asset']);

    $this->actingAs($scenario['participant'])
        ->get($route)
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy');

    $this->actingAs($scenario['outsider'])
        ->get($route)
        ->assertForbidden();
});

it('rejects corrupted traversal paths instead of resolving them outside storage', function () {
    $scenario = createFileSecurityScenario();
    DB::table('measurement_assets')->where('id', $scenario['asset']->id)->update([
        'storage_path' => '../outside.pdf',
    ]);

    $this->actingAs($scenario['participant'])
        ->get(route('admin.measurements.assets.download', $scenario['asset']->id))
        ->assertNotFound();
});

it('rejects symlink escapes, Windows absolute paths and arbitrary disks', function (string $tamper) {
    $scenario = createFileSecurityScenario();

    if ($tamper === 'symlink') {
        Storage::disk('public')->put('outside.pdf', '%PDF-1.7 outside');
        $linkPath = Storage::disk('local')->path('nimbus_docs/measurements/assets/escape.pdf');
        @unlink($linkPath);
        symlink(Storage::disk('public')->path('outside.pdf'), $linkPath);
        $attributes = ['storage_path' => 'nimbus_docs/measurements/assets/escape.pdf'];
    } elseif ($tamper === 'windows') {
        $attributes = ['storage_path' => 'C:\\Windows\\system32\\secret.pdf'];
    } else {
        $attributes = ['storage_disk' => 's3'];
    }

    DB::table('measurement_assets')->where('id', $scenario['asset']->id)->update($attributes);

    $this->actingAs($scenario['participant'])
        ->get(route('admin.measurements.assets.download', $scenario['asset']->id))
        ->assertNotFound();

    $existenceCheck = fn (): bool => app(DocumentStorageService::class)->exists(
        (string) ($attributes['storage_path'] ?? $scenario['asset']->storage_path),
        (string) ($attributes['storage_disk'] ?? $scenario['asset']->storage_disk),
    );

    if ($tamper === 'arbitrary disk') {
        expect($existenceCheck)->toThrow(InvalidArgumentException::class, 'Unsupported storage disk [s3].');
    } else {
        expect($existenceCheck())->toBeFalse();
    }
})->with(['symlink', 'windows', 'arbitrary disk']);

it('persists receipt hash, size, mime and uploader from the stored file', function () {
    $uploader = createFileSecurityEditor();
    $operation = Operation::factory()->create(['payment_receipt_uploader_user_id' => $uploader->id]);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->id,
        'storage_path' => null,
        'status' => 'awaiting_receipt',
        'current_stage' => MeasurementWorkflow::STAGE_FINALIZATION,
    ]);
    $payment = $measurement->payments()->create([
        'operation_id' => $operation->id,
        'pay_date' => now(),
        'amount' => 200,
    ]);

    app(MeasurementWorkflow::class)->attachReceipt(
        $payment,
        $uploader,
        MeasurementReceiptEvidenceScenario::file('hash.pdf', '%PDF-1.7 receipt-bytes'),
    );

    $evidence = $payment->fresh()->currentReceiptEvidence;
    expect($evidence->sha256)->toBe(hash('sha256', '%PDF-1.7 receipt-bytes'))
        ->and($evidence->size)->toBe(strlen('%PDF-1.7 receipt-bytes'))
        ->and($evidence->uploaded_by)->toBe($uploader->id)
        ->and($evidence->storage_disk)->toBe('local');
});

it('backfills only missing hashes and is dry-run by default', function () {
    $scenario = createFileSecurityScenario();
    DB::table('measurement_assets')->where('id', $scenario['asset']->id)->update(['sha256' => null]);

    $this->artisan('measurements:backfill-file-hashes')->assertSuccessful();
    expect($scenario['asset']->fresh()->sha256)->toBeNull();

    $this->artisan('measurements:backfill-file-hashes', ['--execute' => true])->assertSuccessful();
    expect($scenario['asset']->fresh()->sha256)->toBe(hash('sha256', '%PDF-1.7 authorized-content'));

    DB::table('measurement_assets')->where('id', $scenario['asset']->id)->update(['sha256' => str_repeat('a', 64)]);
    Storage::disk('local')->put($scenario['asset']->storage_path, 'changed-content');

    $this->artisan('measurements:backfill-file-hashes', ['--execute' => true])->assertSuccessful();
    expect($scenario['asset']->fresh()->sha256)->toBe(str_repeat('a', 64));
});

it('returns a non-zero backfill result when an expected file is missing', function () {
    $scenario = createFileSecurityScenario();
    DB::table('measurement_assets')->where('id', $scenario['asset']->id)->update(['sha256' => null]);
    Storage::disk('local')->delete($scenario['asset']->storage_path);

    $this->artisan('measurements:backfill-file-hashes', ['--execute' => true])
        ->expectsOutputToContain('arquivo ausente')
        ->assertFailed();

    expect($scenario['asset']->fresh()->sha256)->toBeNull();
});

it('migrates public legacy files only after a verified private copy and supports dry-run', function () {
    $participant = createFileSecurityEditor();
    $operation = Operation::factory()->create(['assigned_user_id' => $participant->id]);
    $measurement = Measurement::factory()->create(['operation_id' => $operation->id, 'storage_path' => null]);
    Storage::disk('public')->put('measurements/legacy.pdf', '%PDF-1.7 legacy-content');
    $asset = createLegacyPublicAsset($measurement, 'measurements/legacy.pdf');

    $this->artisan('measurements:secure-legacy-files')->assertSuccessful();
    Storage::disk('public')->assertExists('measurements/legacy.pdf');
    expect($asset->fresh()->storage_disk)->toBeNull();

    $this->artisan('measurements:secure-legacy-files', ['--execute' => true])->assertSuccessful();
    $asset->refresh();

    expect($asset->storage_disk)->toBe('local')
        ->and($asset->sha256)->toBe(hash('sha256', '%PDF-1.7 legacy-content'))
        ->and($asset->storage_path)->toStartWith('nimbus_docs/measurements/legacy/assets/');
    Storage::disk('local')->assertExists($asset->storage_path);
    Storage::disk('public')->assertMissing('measurements/legacy.pdf');
});

it('reports a missing legacy public source as an incomplete migration', function () {
    $participant = createFileSecurityEditor();
    $operation = Operation::factory()->create(['assigned_user_id' => $participant->id]);
    $measurement = Measurement::factory()->create(['operation_id' => $operation->id, 'storage_path' => null]);
    Storage::disk('public')->put('measurements/missing-source.pdf', '%PDF-1.7 temporary-source');
    $asset = createLegacyPublicAsset($measurement, 'measurements/missing-source.pdf');
    Storage::disk('public')->delete('measurements/missing-source.pdf');

    $this->artisan('measurements:secure-legacy-files', ['--execute' => true])
        ->expectsOutputToContain('origem pública ausente')
        ->assertFailed();

    expect($asset->fresh()->storage_disk)->toBeNull()
        ->and($asset->fresh()->storage_path)->toBe('measurements/missing-source.pdf');
});

it('detects but never removes an unowned public residue from an older committed migration', function () {
    $participant = createFileSecurityEditor();
    $operation = Operation::factory()->create(['assigned_user_id' => $participant->id]);
    $measurement = Measurement::factory()->create(['operation_id' => $operation->id, 'storage_path' => null]);
    $publicPath = 'measurements/residual.pdf';
    $content = '%PDF-1.7 residual-content';
    Storage::disk('public')->put($publicPath, $content);
    $asset = createLegacyPublicAsset($measurement, $publicPath);
    $privatePath = "nimbus_docs/measurements/legacy/assets/{$asset->id}/residual.pdf";
    Storage::disk('local')->put($privatePath, $content);
    DB::table('measurement_assets')->where('id', $asset->id)->update([
        'storage_path' => $privatePath,
        'storage_disk' => 'local',
        'sha256' => hash('sha256', $content),
    ]);

    $this->artisan('measurements:secure-legacy-files')
        ->expectsOutputToContain('candidato público sem ownership registrado')
        ->assertFailed();
    Storage::disk('public')->assertExists($publicPath);

    $this->artisan('measurements:secure-legacy-files', ['--execute' => true])
        ->expectsOutputToContain('nenhuma exclusão foi realizada')
        ->assertFailed();

    expect($asset->fresh()->storage_disk)->toBe('local')
        ->and($asset->fresh()->storage_path)->toBe($privatePath);
    Storage::disk('public')->assertExists($publicPath);
});

it('is idempotent with an equal destination and rejects a divergent destination', function (bool $sameHash) {
    $participant = createFileSecurityEditor();
    $operation = Operation::factory()->create(['assigned_user_id' => $participant->id]);
    $measurement = Measurement::factory()->create(['operation_id' => $operation->id, 'storage_path' => null]);
    Storage::disk('public')->put('measurements/idempotent.pdf', '%PDF-1.7 legacy-content');
    $asset = createLegacyPublicAsset($measurement, 'measurements/idempotent.pdf');
    $target = "nimbus_docs/measurements/legacy/assets/{$asset->id}/idempotent.pdf";
    Storage::disk('local')->put($target, $sameHash ? '%PDF-1.7 legacy-content' : '%PDF-1.7 different-content');

    $exitCode = Artisan::call('measurements:secure-legacy-files', ['--execute' => true]);

    if ($sameHash) {
        expect($exitCode)->toBe(0);
        expect($asset->fresh()->storage_disk)->toBe('local');
        Storage::disk('public')->assertMissing('measurements/idempotent.pdf');
    } else {
        expect($exitCode)->toBe(1);
        expect($asset->fresh()->storage_disk)->toBeNull();
        Storage::disk('public')->assertExists('measurements/idempotent.pdf');
    }
})->with([true, false]);

it('does not report success when public deletion fails and recovers on rerun', function () {
    $participant = createFileSecurityEditor();
    $operation = Operation::factory()->create(['assigned_user_id' => $participant->id]);
    $measurement = Measurement::factory()->create(['operation_id' => $operation->id, 'storage_path' => null]);
    $public = Storage::disk('public');
    $local = Storage::disk('local');
    $public->put('measurements/delete-failure.pdf', '%PDF-1.7 legacy-content');
    $asset = createLegacyPublicAsset($measurement, 'measurements/delete-failure.pdf');
    $deleteAttempts = 0;
    $publicMock = Mockery::mock($public)->makePartial();
    $publicMock->shouldReceive('delete')->twice()->andReturnUsing(function (string $path) use (&$deleteAttempts, $public): bool {
        $deleteAttempts++;

        return $deleteAttempts === 1 ? false : $public->delete($path);
    });
    Storage::shouldReceive('disk')->with('public')->andReturn($publicMock);
    Storage::shouldReceive('disk')->with('local')->andReturn($local);

    $this->artisan('measurements:secure-legacy-files', ['--execute' => true])->assertFailed();
    expect($asset->fresh()->storage_disk)->toBe('local');
    expect($public->exists('measurements/delete-failure.pdf'))->toBeTrue();

    $this->artisan('measurements:secure-legacy-files', ['--execute' => true])->assertSuccessful();
    expect($asset->fresh()->storage_disk)->toBe('local');
    expect($public->exists('measurements/delete-failure.pdf'))->toBeFalse();
});

it('keeps database and public source retryable when the database save fails', function () {
    $participant = createFileSecurityEditor();
    $operation = Operation::factory()->create(['assigned_user_id' => $participant->id]);
    $measurement = Measurement::factory()->create(['operation_id' => $operation->id, 'storage_path' => null]);
    Storage::disk('public')->put('measurements/save-failure.pdf', '%PDF-1.7 legacy-content');
    $asset = createLegacyPublicAsset($measurement, 'measurements/save-failure.pdf');
    DB::unprepared("CREATE TRIGGER fail_asset_migration BEFORE UPDATE ON measurement_assets WHEN OLD.id = {$asset->id} BEGIN SELECT RAISE(ABORT, 'forced save failure'); END");

    $this->artisan('measurements:secure-legacy-files', ['--execute' => true])->assertFailed();
    expect($asset->fresh()->storage_disk)->toBeNull();
    Storage::disk('public')->assertExists('measurements/save-failure.pdf');

    DB::unprepared('DROP TRIGGER fail_asset_migration');
    $this->artisan('measurements:secure-legacy-files', ['--execute' => true])->assertSuccessful();
    expect($asset->fresh()->storage_disk)->toBe('local');
});

test('example', function () {
    $response = $this->get('/');

    $response->assertStatus(200);
});

it('blocks unsafe engineering uploads without orphan files or changing the previous asset', function (string $result, bool $replacement) {
    $scenario = createFileSecurityScenario();
    $previous = $scenario['asset']->fresh()->getRawOriginal();
    $path = 'nimbus_docs/measurements/assets/rejected.pdf';
    Storage::disk('local')->put($path, '%PDF-1.7 unsafe upload');
    $this->mock(ClamAvFileScanner::class, function ($mock) use ($result): void {
        $mock->shouldReceive('isEnabled')->andReturnTrue();
        $mock->shouldReceive('scanStream')->once()->andReturn($result);
        $mock->shouldNotReceive('scan');
    });

    expect(fn () => $replacement
        ? $scenario['asset']->update(['storage_path' => $path])
        : $scenario['measurement']->assets()->create(['storage_path' => $path, 'storage_disk' => 'local']))
        ->toThrow(ValidationException::class);

    expect($scenario['asset']->fresh()->getRawOriginal())->toBe($previous)
        ->and($scenario['measurement']->assets()->count())->toBe(1);
    Storage::disk('local')->assertMissing($path);
    Storage::disk('local')->assertExists($previous['storage_path']);
})->with([ClamAvFileScanner::RESULT_INFECTED, ClamAvFileScanner::RESULT_UNAVAILABLE])->with([false, true]);

it('scans engineering uploads as streams on every supported private disk', function (string $disk) {
    $scenario = createFileSecurityScenario();
    if ($disk === 'private') {
        Storage::fake($disk);
        config()->set('filesystems.disks.private.driver', 'azure');
    }
    $path = 'nimbus_docs/measurements/assets/clean.pdf';
    $bytes = '%PDF-1.7 clean engineering upload';
    Storage::disk($disk)->put($path, $bytes);
    $stream = null;
    $this->mock(ClamAvFileScanner::class, function ($mock) use ($bytes, &$stream): void {
        $mock->shouldReceive('isEnabled')->andReturnTrue();
        $mock->shouldNotReceive('scan');
        $mock->shouldReceive('scanStream')->once()->andReturnUsing(function ($input) use ($bytes, &$stream): string {
            $stream = $input;
            expect(stream_get_contents($input))->toBe($bytes);

            return ClamAvFileScanner::RESULT_CLEAN;
        });
    });

    $asset = $scenario['measurement']->assets()->create(['storage_path' => $path, 'storage_disk' => $disk]);

    expect($asset->sha256)->toBe(hash('sha256', $bytes))
        ->and(is_resource($stream))->toBeFalse();
    Storage::disk($disk)->assertExists($path);
})->with(['local', 'private']);

it('compensates every staged engineering upload when a later asset is rejected', function () {
    $measurementId = null;
    $this->mock(ClamAvFileScanner::class, function ($mock): void {
        $mock->shouldReceive('isEnabled')->andReturnTrue();
        $mock->shouldReceive('scanStream')->twice()->andReturn(ClamAvFileScanner::RESULT_CLEAN, ClamAvFileScanner::RESULT_INFECTED);
    });

    expect(function () use (&$measurementId): void {
        DB::transaction(function () use (&$measurementId): void {
            $validation = app(MeasurementFileValidationService::class);
            $paths = [
                $validation->storeAsset(MeasurementReceiptEvidenceScenario::file('first.pdf')),
                $validation->storeAsset(MeasurementReceiptEvidenceScenario::file('second.pdf')),
                $validation->storeAsset(MeasurementReceiptEvidenceScenario::file('not-yet-saved.pdf')),
            ];
            $measurement = Measurement::factory()->create(['storage_path' => null]);
            $measurementId = $measurement->id;
            foreach ($paths as $path) {
                $measurement->assets()->create(['storage_path' => $path, 'storage_disk' => 'local']);
            }
        });
    })->toThrow(ValidationException::class);

    expect($measurementId)->not->toBeNull();
    expect(Measurement::query()->find($measurementId))->toBeNull()
        ->and(MeasurementAsset::query()->where('measurement_id', $measurementId)->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles('nimbus_docs/measurements/assets'))->toBeEmpty();
});

it('does not delete an existing asset when a rejected upload references its path', function () {
    $scenario = createFileSecurityScenario();
    $this->mock(ClamAvFileScanner::class, function ($mock): void {
        $mock->shouldReceive('isEnabled')->andReturnTrue();
        $mock->shouldReceive('scanStream')->once()->andReturn(ClamAvFileScanner::RESULT_UNAVAILABLE);
    });

    expect(fn () => $scenario['measurement']->assets()->create([
        'storage_disk' => 'local', 'storage_path' => $scenario['asset']->storage_path,
    ]))->toThrow(ValidationException::class);

    Storage::disk('local')->assertExists($scenario['asset']->storage_path);
    expect($scenario['measurement']->assets()->count())->toBe(1);
});

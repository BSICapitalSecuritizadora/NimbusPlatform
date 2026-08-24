<?php

use App\Models\Measurement;
use App\Models\MeasurementAsset;
use App\Models\Operation;
use App\Models\User;
use App\Services\MeasurementWorkflow;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

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
    Storage::disk('local')->put('nimbus_docs/measurements/receipts/hash.pdf', 'receipt-bytes');

    app(MeasurementWorkflow::class)->attachReceipt(
        $payment,
        $uploader,
        'nimbus_docs/measurements/receipts/hash.pdf',
        'local',
    );

    expect($payment->fresh()->receipt_sha256)->toBe(hash('sha256', 'receipt-bytes'))
        ->and($payment->fresh()->receipt_size)->toBe(strlen('receipt-bytes'))
        ->and($payment->fresh()->receipt_uploaded_by)->toBe($uploader->id)
        ->and($payment->fresh()->receipt_disk)->toBe('local');
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

it('migrates public legacy files only after a verified private copy and supports dry-run', function () {
    $participant = createFileSecurityEditor();
    $operation = Operation::factory()->create(['assigned_user_id' => $participant->id]);
    $measurement = Measurement::factory()->create(['operation_id' => $operation->id, 'storage_path' => null]);
    Storage::disk('public')->put('measurements/legacy.pdf', 'legacy-content');
    $asset = $measurement->assets()->create([
        'storage_path' => 'measurements/legacy.pdf',
        'storage_disk' => null,
    ]);

    $this->artisan('measurements:secure-legacy-files')->assertSuccessful();
    Storage::disk('public')->assertExists('measurements/legacy.pdf');
    expect($asset->fresh()->storage_disk)->toBeNull();

    $this->artisan('measurements:secure-legacy-files', ['--execute' => true])->assertSuccessful();
    $asset->refresh();

    expect($asset->storage_disk)->toBe('local')
        ->and($asset->sha256)->toBe(hash('sha256', 'legacy-content'))
        ->and($asset->storage_path)->toStartWith('nimbus_docs/measurements/legacy/assets/');
    Storage::disk('local')->assertExists($asset->storage_path);
    Storage::disk('public')->assertMissing('measurements/legacy.pdf');
});

test('example', function () {
    $response = $this->get('/');

    $response->assertStatus(200);
});

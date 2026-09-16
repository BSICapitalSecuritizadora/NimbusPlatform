<?php

use App\Enums\MeasurementReceiptReviewStatus;
use App\Exceptions\MeasurementWorkflowException;
use App\Models\MeasurementPayment;
use App\Models\MeasurementPaymentReceiptEvidence;
use App\Models\User;
use App\Services\MeasurementReceiptEvidenceService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\MeasurementReceiptEvidenceScenario as Scenario;

beforeEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requer MySQL em banco exclusivo de testes: SQLite não prova os locks concorrentes.');
    }

    Artisan::call('migrate:fresh', ['--no-interaction' => true]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
});

/** @param array{action: string, actor_id: int, payment_id: int, evidence_id: ?int, storage_root: string, lock_marker?: string, wait_marker?: string} $instruction */
function receiptEvidenceMysqlTask(array $instruction): Closure
{
    return static function () use ($instruction): array {
        config()->set('filesystems.private_disk', 'local');
        config()->set('filesystems.disks.local.root', $instruction['storage_root']);
        Storage::forgetDisk('local');
        Notification::fake();

        if (isset($instruction['lock_marker'])) {
            DB::listen(static function (QueryExecuted $query) use ($instruction): void {
                static $marked = false;
                $sql = strtolower($query->sql);
                if (! $marked && str_contains($sql, '`measurements`') && str_contains($sql, 'for update')) {
                    $marked = true;
                    file_put_contents($instruction['lock_marker'], 'locked');
                    usleep(750_000);
                }
            });
        }

        try {
            if (isset($instruction['wait_marker'])) {
                $deadline = microtime(true) + 10;
                while (! is_file($instruction['wait_marker']) && microtime(true) < $deadline) {
                    usleep(10_000);
                }
                if (! is_file($instruction['wait_marker'])) {
                    throw new RuntimeException('O processo não confirmou o lock da medição.');
                }
            }

            $service = app(MeasurementReceiptEvidenceService::class);
            $actor = User::query()->findOrFail($instruction['actor_id']);
            $payment = MeasurementPayment::query()->findOrFail($instruction['payment_id']);
            $evidence = match ($instruction['action']) {
                'upload' => $service->upload($payment, $actor, Scenario::file(), $instruction['evidence_id'], 'Correção concorrente'),
                'correction' => $service->correctFinalizedReceipt($payment, $actor, Scenario::file(), 'Correção concorrente', $instruction['evidence_id']),
                default => $service->review(
                    MeasurementPaymentReceiptEvidence::query()->findOrFail($instruction['evidence_id']),
                    $actor,
                    MeasurementReceiptReviewStatus::from($instruction['action']),
                    true,
                    rejectionReason: 'Documento incorreto',
                ),
            };

            return ['success' => true, 'evidence_id' => $evidence->id, 'exception' => null];
        } catch (Throwable $exception) {
            return ['success' => false, 'evidence_id' => null, 'exception' => $exception::class];
        }
    };
}

it('serializes uploads so only one request based on the same version succeeds', function (bool $finalized) {
    $scenario = $finalized ? Scenario::legacy() : Scenario::open();
    $current = $scenario['payment']->currentReceiptEvidence;
    $marker = temporaryTestFilePath('receipt-upload-lock', 'lock');
    $instruction = [
        'action' => $finalized ? 'correction' : 'upload',
        'actor_id' => $scenario['actor']->id,
        'payment_id' => $scenario['payment']->id,
        'evidence_id' => $current?->id,
        'storage_root' => Storage::disk('local')->path(''),
    ];
    $before = $scenario['measurement']->getRawOriginal();
    $results = Concurrency::driver('process')->run([
        receiptEvidenceMysqlTask($instruction + ['lock_marker' => $marker]),
        receiptEvidenceMysqlTask($instruction + ['wait_marker' => $marker]),
    ]);

    expect($results[0]['success'])->toBeTrue()
        ->and($results[1]['exception'])->toBe(MeasurementWorkflowException::class)
        ->and($scenario['payment']->receiptEvidences()->pluck('version')->all())->toBe($finalized ? [2, 1] : [1]);
    if ($finalized) {
        expect($scenario['measurement']->fresh()->getRawOriginal())->toBe($before);
        Storage::disk('local')->assertExists($current->storage_path);
    }
    expect(Storage::disk('local')->allFiles('nimbus_docs/measurements/receipts'))->toHaveCount($finalized ? 2 : 1);
})->with([false, true])->group('mysql');

it('serializes approve against reject with exactly one immutable decision and audit event', function () {
    $scenario = Scenario::open();
    $evidence = app(MeasurementReceiptEvidenceService::class)->upload($scenario['payment'], $scenario['actor'], Scenario::file());
    $marker = temporaryTestFilePath('receipt-review-lock', 'lock');
    $instruction = [
        'actor_id' => $scenario['actor']->id,
        'payment_id' => $scenario['payment']->id,
        'evidence_id' => $evidence->id,
        'storage_root' => Storage::disk('local')->path(''),
    ];
    $results = Concurrency::driver('process')->run([
        receiptEvidenceMysqlTask(['action' => 'approved', 'lock_marker' => $marker] + $instruction),
        receiptEvidenceMysqlTask(['action' => 'rejected', 'wait_marker' => $marker] + $instruction),
    ]);

    expect($results[0]['success'])->toBeTrue()
        ->and($results[1]['exception'])->toBe(MeasurementWorkflowException::class)
        ->and($evidence->fresh()->review_status)->toBe(MeasurementReceiptReviewStatus::Approved)
        ->and(Activity::query()->where('log_name', 'measurement_evidence')->whereIn('description', ['receipt_evidence_approved', 'receipt_evidence_rejected'])->count())->toBe(1);
})->group('mysql');

it('refuses a review waiting behind a replacement upload', function () {
    $scenario = Scenario::open();
    $first = app(MeasurementReceiptEvidenceService::class)->upload($scenario['payment'], $scenario['actor'], Scenario::file());
    $marker = temporaryTestFilePath('receipt-supersession-lock', 'lock');
    $instruction = [
        'actor_id' => $scenario['actor']->id,
        'payment_id' => $scenario['payment']->id,
        'evidence_id' => $first->id,
        'storage_root' => Storage::disk('local')->path(''),
    ];
    $results = Concurrency::driver('process')->run([
        receiptEvidenceMysqlTask(['action' => 'upload', 'lock_marker' => $marker] + $instruction),
        receiptEvidenceMysqlTask(['action' => 'approved', 'wait_marker' => $marker] + $instruction),
    ]);

    expect($results[0]['success'])->toBeTrue()
        ->and($results[1]['exception'])->toBe(MeasurementWorkflowException::class)
        ->and($first->fresh()->review_status)->toBe(MeasurementReceiptReviewStatus::Pending)
        ->and($scenario['payment']->fresh()->currentReceiptEvidence->version)->toBe(2);
    Storage::disk('local')->assertExists($first->storage_path);
})->group('mysql');

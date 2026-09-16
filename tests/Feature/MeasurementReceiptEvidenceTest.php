<?php

use App\DTOs\Measurements\MeasurementCycleReportFilters;
use App\Enums\MeasurementReceiptReviewStatus;
use App\Exceptions\MeasurementWorkflowException;
use App\Models\MeasurementPayment;
use App\Models\ResponsibilityDelegation;
use App\Models\User;
use App\Services\DocumentStorageService;
use App\Services\MeasurementCycleEventNormalizer;
use App\Services\MeasurementCycleHistoryReadModel;
use App\Services\MeasurementCycleReportingService;
use App\Services\MeasurementFileValidationService;
use App\Services\MeasurementOperationalReadModel;
use App\Services\MeasurementReceiptEvidenceService;
use App\Services\MeasurementWorkflow;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\MeasurementReceiptEvidenceScenario as Scenario;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    config()->set('uploads.measurement_receipt.max_kb', 10240);
    config()->set('uploads.measurement_receipt.max_bytes', 10240 * 1024);
    Notification::fake();
    $this->travelTo(now()->setDate(2026, 9, 7)->setTime(10, 0));
});

it('materializes legacy receipts once without changing their metadata or bytes', function (bool $finalized, MeasurementReceiptReviewStatus $status) {
    $scenario = Scenario::legacy($finalized);
    $payment = $scenario['payment'];
    $evidence = $payment->currentReceiptEvidence;
    $before = $evidence->getRawOriginal();
    $bytes = Storage::disk('local')->get($payment->receipt_path);

    expect($evidence->version)->toBe(1)
        ->and($evidence->review_status)->toBe($status)
        ->and($evidence->original_filename)->toBeNull()
        ->and($evidence->supersedes_id)->toBeNull()
        ->and($evidence->storage_path)->toBe($payment->receipt_path)
        ->and($evidence->storage_disk)->toBe($payment->receipt_disk)
        ->and($evidence->sha256)->toBe($payment->receipt_sha256)
        ->and($evidence->mime_type)->toBe($payment->receipt_mime_type)
        ->and($evidence->size)->toBe($payment->receipt_size)
        ->and($evidence->uploaded_by)->toBe($payment->receipt_uploaded_by)
        ->and($evidence->uploaded_at->toDateTimeString())->toBe($payment->receipt_uploaded_at->toDateTimeString());

    Scenario::backfill();

    expect($payment->receiptEvidences()->count())->toBe(1)
        ->and($evidence->fresh()->getRawOriginal())->toBe($before)
        ->and(Storage::disk('local')->get($payment->receipt_path))->toBe($bytes)
        ->and($scenario['measurement']->fresh()->status)->toBe($finalized ? 'finalized' : 'awaiting_receipt');
})->with([
    'finalized legacy' => [true, MeasurementReceiptReviewStatus::LegacyUnreviewed],
    'open legacy' => [false, MeasurementReceiptReviewStatus::Pending],
]);

it('stores a first private version pending with the real filename hash and metadata', function () {
    $scenario = Scenario::open();
    $legacy = $scenario['payment']->getRawOriginal();
    $file = Scenario::file();
    $evidence = app(MeasurementReceiptEvidenceService::class)->upload($scenario['payment'], $scenario['actor'], $file);

    expect($evidence->version)->toBe(1)
        ->and($evidence->review_status)->toBe(MeasurementReceiptReviewStatus::Pending)
        ->and($evidence->original_filename)->toBe('comprovante TED.pdf')
        ->and($evidence->sha256)->toBe(hash_file('sha256', $file->getRealPath()))
        ->and($evidence->size)->toBe($file->getSize())
        ->and($evidence->mime_type)->toBe('application/pdf')
        ->and($evidence->storage_disk)->toBe('local')
        ->and($evidence->uploaded_by)->toBe($scenario['actor']->id)
        ->and($evidence->uploaded_at)->not->toBeNull()
        ->and($scenario['payment']->fresh()->getRawOriginal())->toBe($legacy)
        ->and($scenario['measurement']->fresh()->status)->toBe('awaiting_receipt');

    Storage::disk('local')->assertExists($evidence->storage_path);
    Storage::disk('public')->assertMissing($evidence->storage_path);
    $this->get('/storage/'.$evidence->storage_path)->assertNotFound();
});

it('keeps a rejected version and creates a distinct successor with no inherited approval', function () {
    $scenario = Scenario::open();
    $service = app(MeasurementReceiptEvidenceService::class);
    $first = $service->upload($scenario['payment'], $scenario['actor'], Scenario::file());
    $service->review($first, $scenario['actor'], MeasurementReceiptReviewStatus::Rejected, true, rejectionReason: 'Documento incorreto');
    $rejected = $first->fresh()->getRawOriginal();
    $second = $service->upload($scenario['payment'], $scenario['actor'], Scenario::file('correto.pdf'), $first->id, 'Substituição do documento incorreto');

    expect($second->version)->toBe(2)
        ->and($second->supersedes_id)->toBe($first->id)
        ->and($second->review_status)->toBe(MeasurementReceiptReviewStatus::Pending)
        ->and($second->reviewer_user_id)->toBeNull()
        ->and($second->storage_path)->not->toBe($first->storage_path)
        ->and($first->fresh()->getRawOriginal())->toBe($rejected)
        ->and($first->fresh()->supersededBy->id)->toBe($second->id)
        ->and($scenario['payment']->fresh()->currentReceiptEvidence->id)->toBe($second->id);
    Storage::disk('local')->assertExists([$first->storage_path, $second->storage_path]);
});

it('requires explicit confirmation and a nonblank rejection reason', function (bool $confirmed, ?string $reason) {
    $scenario = Scenario::open();
    $service = app(MeasurementReceiptEvidenceService::class);
    $evidence = $service->upload($scenario['payment'], $scenario['actor'], Scenario::file());

    expect(fn () => $service->review($evidence, $scenario['actor'], MeasurementReceiptReviewStatus::Rejected, $confirmed, rejectionReason: $reason))
        ->toThrow(ValidationException::class);
    expect($evidence->fresh()->review_status)->toBe(MeasurementReceiptReviewStatus::Pending);
    Storage::disk('local')->assertExists($evidence->storage_path);
})->with([[false, 'Documento incorreto'], [true, null], [true, '   ']]);

it('records an exact one-time decision and reviewer separately from the upload', function (MeasurementReceiptReviewStatus $decision) {
    $scenario = Scenario::open();
    $service = app(MeasurementReceiptEvidenceService::class);
    $evidence = $service->upload($scenario['payment'], $scenario['actor'], Scenario::file());
    $service->review($evidence, $scenario['actor'], $decision, true, 'Conferência humana', 'Documento incorreto');
    $reviewed = $evidence->fresh();

    expect($reviewed->review_status)->toBe($decision)
        ->and($reviewed->reviewer_user_id)->toBe($scenario['actor']->id)
        ->and($reviewed->reviewed_at)->not->toBeNull()
        ->and($reviewed->review_notes)->toBe('Conferência humana');
    $logs = Activity::query()->where('log_name', 'measurement_evidence')->where('subject_id', $evidence->id)->orderBy('id')->get();
    expect($logs)->toHaveCount(2)
        ->and($logs->first()->description)->toBe('receipt_evidence_uploaded')
        ->and($logs->last()->properties['sha256'])->toBe($evidence->sha256)
        ->and($logs->last()->properties['version'])->toBe(1)
        ->and($logs->last()->properties['authorization_source'])->toBe('direct')
        ->and(config('audit.protected_logs'))->toContain('measurement_evidence');

    expect(fn () => $service->review($evidence, $scenario['actor'], $decision, true, rejectionReason: 'Nova decisão'))
        ->toThrow(MeasurementWorkflowException::class);
})->with([MeasurementReceiptReviewStatus::Approved, MeasurementReceiptReviewStatus::Rejected]);

it('blocks future finalization for missing pending rejected or legacy-unreviewed evidence', function (?MeasurementReceiptReviewStatus $status) {
    $scenario = Scenario::open();
    $service = app(MeasurementReceiptEvidenceService::class);
    if ($status !== null) {
        $evidence = $service->upload($scenario['payment'], $scenario['actor'], Scenario::file());
        if ($status === MeasurementReceiptReviewStatus::Rejected) {
            $service->review($evidence, $scenario['actor'], $status, true, rejectionReason: 'Documento incorreto');
        } elseif ($status === MeasurementReceiptReviewStatus::LegacyUnreviewed) {
            DB::table('measurement_payment_receipt_evidences')->where('id', $evidence->id)->update(['review_status' => $status->value]);
        }
    }

    expect(fn () => app(MeasurementWorkflow::class)->finalize($scenario['measurement']->fresh(), $scenario['actor']))
        ->toThrow(MeasurementWorkflowException::class);
    expect($scenario['measurement']->fresh()->analyzed_at)->toBeNull();
})->with([null, MeasurementReceiptReviewStatus::Pending, MeasurementReceiptReviewStatus::Rejected, MeasurementReceiptReviewStatus::LegacyUnreviewed]);

it('requires every current version to be approved before finalization', function () {
    $scenario = Scenario::open();
    $other = MeasurementPayment::factory()->create([
        'operation_id' => $scenario['operation']->id,
        'measurement_id' => $scenario['measurement']->id,
        'plan_set_id' => $scenario['payment']->plan_set_id,
        'amount' => 10,
        'pay_date' => '2026-05-20',
    ]);
    $service = app(MeasurementReceiptEvidenceService::class);
    $first = $service->upload($scenario['payment'], $scenario['actor'], Scenario::file());
    $second = $service->upload($other, $scenario['actor'], Scenario::file());
    $service->review($first, $scenario['actor'], MeasurementReceiptReviewStatus::Approved, true);

    expect(fn () => app(MeasurementWorkflow::class)->finalize($scenario['measurement']->fresh(), $scenario['actor']))
        ->toThrow(MeasurementWorkflowException::class);
    $service->review($second, $scenario['actor'], MeasurementReceiptReviewStatus::Approved, true);
    app(MeasurementWorkflow::class)->finalize($scenario['measurement']->fresh(), $scenario['actor']);

    expect($scenario['measurement']->fresh()->status)->toBe('finalized')
        ->and($scenario['measurement']->fresh()->analyzed_at)->not->toBeNull();
});

it('allows an open legacy receipt to finalize only after an individual documentary approval', function () {
    $scenario = Scenario::legacy(finalized: false);
    $evidence = $scenario['payment']->currentReceiptEvidence;
    expect($evidence->review_status)->toBe(MeasurementReceiptReviewStatus::Pending);
    expect(fn () => app(MeasurementWorkflow::class)->finalize($scenario['measurement'], $scenario['actor']))
        ->toThrow(MeasurementWorkflowException::class);

    Scenario::approveCurrentReceipt($scenario['payment'], $scenario['actor']);
    app(MeasurementWorkflow::class)->finalize($scenario['measurement']->fresh(), $scenario['actor']);

    expect($scenario['measurement']->fresh()->status)->toBe('finalized')
        ->and($scenario['payment']->receiptEvidences()->count())->toBe(1)
        ->and($evidence->fresh()->review_status)->toBe(MeasurementReceiptReviewStatus::Approved);
});

it('refuses approval or finalization when the audited bytes are changed', function (bool $alreadyApproved) {
    $scenario = Scenario::open();
    $service = app(MeasurementReceiptEvidenceService::class);
    $evidence = $service->upload($scenario['payment'], $scenario['actor'], Scenario::file());
    if ($alreadyApproved) {
        $service->review($evidence, $scenario['actor'], MeasurementReceiptReviewStatus::Approved, true);
    }
    Storage::disk('local')->put($evidence->storage_path, '%PDF-1.7 altered bytes');

    if ($alreadyApproved) {
        expect(fn () => app(MeasurementWorkflow::class)->finalize($scenario['measurement']->fresh(), $scenario['actor']))
            ->toThrow(MeasurementWorkflowException::class);
    } else {
        expect(fn () => $service->review($evidence, $scenario['actor'], MeasurementReceiptReviewStatus::Approved, true))
            ->toThrow(ValidationException::class);
    }
})->with([false, true]);

it('corrects controlled finalized history without reopening or rewriting any canonical cycle data', function (MeasurementReceiptReviewStatus $decision, string $label) {
    $scenario = Scenario::legacy();
    $measurement = $scenario['measurement'];
    $service = app(MeasurementReceiptEvidenceService::class);
    $first = $scenario['payment']->currentReceiptEvidence;
    $before = $measurement->getRawOriginal();
    $reviews = $measurement->reviews()->orderBy('id')->get()->toArray();
    $history = app(MeasurementCycleHistoryReadModel::class)->for($scenario['actor'], $measurement)->toArray();
    $filters = new MeasurementCycleReportFilters(measurementId: $measurement->id);
    $report = app(MeasurementCycleReportingService::class)->report($scenario['actor'], $filters);
    $workflowLogIds = Activity::query()->whereIn('log_name', ['measurement_workflow', 'measurements'])->pluck('id')->all();

    $this->travelTo(now()->setDate(2026, 9, 16));
    $second = $service->correctFinalizedReceipt($scenario['payment'], $scenario['actor'], Scenario::file('correto.pdf'), 'Comprovante correspondente ao pagamento', $first->id);
    expect($second->version)->toBe(2)
        ->and($second->supersedes_id)->toBe($first->id)
        ->and($second->is_post_finalization)->toBeTrue()
        ->and($second->review_status)->toBe(MeasurementReceiptReviewStatus::Pending)
        ->and($service->documentaryStatus($measurement->fresh()))->toBe('Correção documental pendente');

    $service->review($second, $scenario['actor'], $decision, true, rejectionReason: 'Comprovante ainda incorreto');
    expect($service->documentaryStatus($measurement->fresh()))->toBe($label)
        ->and($measurement->fresh()->getRawOriginal())->toBe($before)
        ->and($measurement->reviews()->orderBy('id')->get()->toArray())->toBe($reviews)
        ->and(app(MeasurementCycleHistoryReadModel::class)->for($scenario['actor'], $measurement)->toArray())->toBe($history)
        ->and(Activity::query()->whereIn('log_name', ['measurement_workflow', 'measurements'])->pluck('id')->all())->toBe($workflowLogIds)
        ->and($first->fresh()->review_status)->toBe(MeasurementReceiptReviewStatus::LegacyUnreviewed);

    $logs = Activity::query()->where('log_name', 'measurement_evidence')->get();
    expect($logs->pluck('description')->all())->toContain('receipt_evidence_corrected_after_finalization')
        ->and(app(MeasurementCycleEventNormalizer::class)->normalizeMany($logs, $measurement))->toBeEmpty();
    Storage::disk('local')->assertExists([$first->storage_path, $second->storage_path]);
    $afterReport = app(MeasurementCycleReportingService::class)->report($scenario['actor'], $filters);
    expect($afterReport->summary)->toEqual($report->summary)
        ->and($afterReport->stageMetrics)->toEqual($report->stageMetrics)
        ->and($afterReport->totalRows)->toBe($report->totalRows);
})->with([
    [MeasurementReceiptReviewStatus::Approved, 'Correção documental regularizada'],
    [MeasurementReceiptReviewStatus::Rejected, 'Correção documental rejeitada'],
]);

it('requires a reason and the dedicated action for a finalized correction', function () {
    $scenario = Scenario::legacy();
    $service = app(MeasurementReceiptEvidenceService::class);
    $first = $scenario['payment']->currentReceiptEvidence;
    expect(fn () => $service->correctFinalizedReceipt($scenario['payment'], $scenario['actor'], Scenario::file(), '  ', $first->id))
        ->toThrow(ValidationException::class);
    expect(fn () => $service->upload($scenario['payment'], $scenario['actor'], Scenario::file(), $first->id, 'Correção'))
        ->toThrow(MeasurementWorkflowException::class);
    expect($scenario['payment']->receiptEvidences()->count())->toBe(1);
});

it('enforces both permission and responsibility for uploads corrections and reviews', function (string $action, bool $permissionOnly) {
    $scenario = $action === 'correction' ? Scenario::legacy() : Scenario::open();
    $service = app(MeasurementReceiptEvidenceService::class);
    $evidence = $action === 'correction'
        ? $scenario['payment']->currentReceiptEvidence
        : $service->upload($scenario['payment'], $scenario['actor'], Scenario::file());
    $outsider = User::factory()->withTwoFactor()->create();
    $permission = $action === 'review' ? 'measurements.finalize' : 'measurements.receipts';
    if ($permissionOnly) {
        $outsider->givePermissionTo($permission);
    } else {
        $column = $action === 'review' ? 'payment_finalizer_user_id' : 'payment_receipt_uploader_user_id';
        $scenario['operation']->forceFill([$column => $outsider->id])->save();
    }

    expect(fn () => match ($action) {
        'review' => $service->review($evidence, $outsider, MeasurementReceiptReviewStatus::Approved, true),
        'correction' => $service->correctFinalizedReceipt($scenario['payment'], $outsider, Scenario::file(), 'Correção', $evidence->id),
        default => $service->upload($scenario['payment'], $outsider, Scenario::file(), $evidence->id, 'Substituição'),
    })->toThrow(AuthorizationException::class);
})->with(['upload', 'review', 'correction'])->with([false, true]);

it('rejects stale uploads and stale decisions without deleting the historical file', function () {
    $scenario = Scenario::open();
    $service = app(MeasurementReceiptEvidenceService::class);
    $first = $service->upload($scenario['payment'], $scenario['actor'], Scenario::file());
    $second = $service->upload($scenario['payment'], $scenario['actor'], Scenario::file(), $first->id, 'Correção');

    expect(fn () => $service->review($first, $scenario['actor'], MeasurementReceiptReviewStatus::Approved, true))
        ->toThrow(MeasurementWorkflowException::class);
    expect(fn () => $service->upload($scenario['payment'], $scenario['actor'], Scenario::file(), $first->id, 'Requisição antiga'))
        ->toThrow(MeasurementWorkflowException::class);
    expect($scenario['payment']->receiptEvidences()->count())->toBe(2)
        ->and($first->fresh()->review_status)->toBe(MeasurementReceiptReviewStatus::Pending)
        ->and($second->fresh()->review_status)->toBe(MeasurementReceiptReviewStatus::Pending);
    Storage::disk('local')->assertExists([$first->storage_path, $second->storage_path]);
});

it('uses canonical delegation and admin override with their distinct audit sources', function (string $source) {
    $scenario = Scenario::open();
    $actor = User::factory()->withTwoFactor()->create();
    $actor->givePermissionTo(['measurements.receipts', 'measurements.finalize']);
    if ($source === 'admin_override') {
        $actor->assignRole('admin');
    } else {
        ResponsibilityDelegation::factory()->active()->forOperation($scenario['operation'])->create([
            'delegator_user_id' => $scenario['actor']->id,
            'delegate_user_id' => $actor->id,
            'created_by' => $scenario['actor']->id,
        ]);
    }

    $service = app(MeasurementReceiptEvidenceService::class);
    $evidence = $service->upload($scenario['payment'], $actor, Scenario::file());
    $service->review($evidence, $actor, MeasurementReceiptReviewStatus::Approved, true);
    $logs = Activity::query()->where('log_name', 'measurement_evidence')->get();
    expect($logs)->toHaveCount(2);
    foreach ($logs as $log) {
        expect($log->properties['authorization_source'])->toBe($source)
            ->and((int) $log->causer_id)->toBe($actor->id);
        if ($source === 'delegated') {
            expect($log->properties['delegation_id'])->not->toBeNull();
        }
    }
})->with(['delegated', 'admin_override']);

it('does not emit workflow activities when an open documentary state changes', function () {
    $scenario = Scenario::open();
    $logs = Activity::query()->whereIn('log_name', ['measurement_workflow', 'measurements'])->pluck('id')->all();
    $service = app(MeasurementReceiptEvidenceService::class);
    $evidence = $service->upload($scenario['payment'], $scenario['actor'], Scenario::file());
    $service->review($evidence, $scenario['actor'], MeasurementReceiptReviewStatus::Approved, true);

    expect($scenario['measurement']->fresh()->status)->toBe('approved')
        ->and(Activity::query()->whereIn('log_name', ['measurement_workflow', 'measurements'])->pluck('id')->all())->toBe($logs);
});

it('rejects a stale finalized correction without changing workflow revision', function () {
    $scenario = Scenario::legacy();
    $service = app(MeasurementReceiptEvidenceService::class);
    $first = $scenario['payment']->currentReceiptEvidence;
    $revision = $scenario['measurement']->workflow_revision;
    $service->correctFinalizedReceipt($scenario['payment'], $scenario['actor'], Scenario::file(), 'Correção', $first->id);

    expect(fn () => $service->correctFinalizedReceipt($scenario['payment'], $scenario['actor'], Scenario::file(), 'Correção obsoleta', $first->id))
        ->toThrow(MeasurementWorkflowException::class);
    expect($scenario['measurement']->fresh()->workflow_revision)->toBe($revision);
});

it('has database uniqueness for versions and prevents model mutations and deletion', function () {
    $scenario = Scenario::open();
    $evidence = app(MeasurementReceiptEvidenceService::class)->upload($scenario['payment'], $scenario['actor'], Scenario::file());

    expect(fn () => DB::transaction(fn () => DB::table('measurement_payment_receipt_evidences')->insert([
        'measurement_payment_id' => $scenario['payment']->id, 'version' => 1,
        'storage_path' => 'nimbus_docs/duplicate.pdf',
    ])))->toThrow(QueryException::class);
    expect(fn () => $evidence->forceFill(['storage_path' => 'nimbus_docs/changed.pdf'])->save())->toThrow(LogicException::class);
    expect(fn () => $evidence->fresh()->delete())->toThrow(LogicException::class);
    expect(fn () => $scenario['payment']->forceFill(['receipt_uploaded_at' => now()])->save())->toThrow(LogicException::class);
    expect(fn () => app(MeasurementWorkflow::class)->deleteReceipt($scenario['payment']->fresh(), $scenario['actor']))
        ->toThrow(MeasurementWorkflowException::class);
    Storage::disk('local')->assertExists($evidence->getRawOriginal('storage_path'));
});

it('preserves legacy fields on ordinary saves and skips hash and storage maintenance', function () {
    $scenario = Scenario::legacy();
    $payment = $scenario['payment'];
    $before = $payment->getRawOriginal();
    $evidence = $payment->currentReceiptEvidence;
    $payment->forceFill(['notes' => 'Nota operacional'])->save();
    $this->artisan('measurements:backfill-file-hashes', ['--execute' => true])->assertSuccessful();

    expect($payment->fresh()->receipt_path)->toBe($before['receipt_path'])
        ->and($payment->fresh()->receipt_sha256)->toBe($before['receipt_sha256'])
        ->and($evidence->fresh()->storage_path)->toBe($before['receipt_path']);
    Storage::disk('local')->assertExists($evidence->storage_path);
});

it('compensates failed storage validation or audit and keeps all previous versions', function (string $failure) {
    $scenario = Scenario::open();
    $service = app(MeasurementReceiptEvidenceService::class);
    $first = $service->upload($scenario['payment'], $scenario['actor'], Scenario::file());
    $before = Storage::disk('local')->allFiles('nimbus_docs/measurements/receipts');

    if ($failure === 'validation') {
        $this->partialMock(MeasurementFileValidationService::class, function ($mock): void {
            $mock->shouldReceive('validateReceipt')->once()->andThrow(ValidationException::withMessages(['receipt' => 'Falha controlada']));
        });
    } else {
        Event::listen('eloquent.creating: '.Activity::class, function (Activity $activity): void {
            if ($activity->log_name === 'measurement_evidence') {
                throw new RuntimeException('Falha controlada de auditoria');
            }
        });
    }

    expect(fn () => app(MeasurementReceiptEvidenceService::class)->upload($scenario['payment'], $scenario['actor'], Scenario::file(), $first->id, 'Correção'))
        ->toThrow($failure === 'validation' ? ValidationException::class : RuntimeException::class);
    expect($scenario['payment']->receiptEvidences()->count())->toBe(1)
        ->and(Storage::disk('local')->allFiles('nimbus_docs/measurements/receipts'))->toBe($before);
});

it('compensates a caller transaction rollback after a successful upload', function (bool $nested) {
    $scenario = Scenario::open();
    expect(fn () => DB::transaction(function () use ($scenario, $nested): void {
        $upload = fn () => app(MeasurementReceiptEvidenceService::class)->upload($scenario['payment'], $scenario['actor'], Scenario::file());
        if ($nested) {
            DB::transaction($upload);
        } else {
            $upload();
        }
        throw new RuntimeException('Rollback externo controlado');
    }))->toThrow(RuntimeException::class);

    expect($scenario['payment']->receiptEvidences()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles('nimbus_docs/measurements/receipts'))->toBeEmpty();
})->with([false, true]);

it('compensates an interrupted storage write even when the storage helper never returns', function () {
    $scenario = Scenario::open();
    $this->partialMock(DocumentStorageService::class, function ($mock): void {
        $mock->shouldReceive('storePrivateFile')->once()->andReturnUsing(function ($file, string $directory): never {
            $file->store(DocumentStorageService::PRIVATE_PREFIX.'/'.$directory, 'local');
            throw new RuntimeException('Falha após gravar bytes');
        });
    });

    expect(fn () => app(MeasurementReceiptEvidenceService::class)->upload($scenario['payment'], $scenario['actor'], Scenario::file()))
        ->toThrow(RuntimeException::class);
    expect($scenario['payment']->receiptEvidences()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles('nimbus_docs/measurements/receipts'))->toBeEmpty();
});

it('keeps materialized legacy public bytes and missing hashes untouched by maintenance', function () {
    $scenario = Scenario::open();
    $path = 'measurements/legacy/receipt.pdf';
    $bytes = '%PDF-1.7 legado público controlado';
    Storage::disk('public')->put($path, $bytes);
    DB::table('measurement_payments')->where('id', $scenario['payment']->id)->update([
        'receipt_path' => $path, 'receipt_disk' => null, 'receipt_sha256' => null,
        'receipt_mime_type' => 'application/pdf', 'receipt_size' => strlen($bytes),
    ]);
    Scenario::backfill();
    $evidence = $scenario['payment']->fresh()->currentReceiptEvidence;
    $before = $evidence->getRawOriginal();

    $this->artisan('measurements:backfill-file-hashes', ['--execute' => true])->assertSuccessful();
    $this->artisan('measurements:secure-legacy-files', ['--execute' => true])->assertSuccessful();

    expect($scenario['payment']->fresh()->receipt_path)->toBe($path)
        ->and($scenario['payment']->fresh()->receipt_sha256)->toBeNull()
        ->and($evidence->fresh()->getRawOriginal())->toBe($before)
        ->and(Storage::disk('public')->get($path))->toBe($bytes);
});

it('retains a public file shared by an asset migration and a materialized receipt', function () {
    $scenario = Scenario::open();
    $path = 'measurements/legacy/shared.pdf';
    $bytes = '%PDF-1.7 origem compartilhada';
    Storage::disk('public')->put($path, $bytes);
    DB::table('measurement_assets')->where('measurement_id', $scenario['measurement']->id)->update([
        'storage_path' => $path, 'storage_disk' => 'public', 'sha256' => hash('sha256', $bytes),
    ]);
    DB::table('measurement_payments')->where('id', $scenario['payment']->id)->update([
        'receipt_path' => $path, 'receipt_disk' => 'public', 'receipt_sha256' => hash('sha256', $bytes),
        'receipt_mime_type' => 'application/pdf', 'receipt_size' => strlen($bytes),
    ]);
    Scenario::backfill();
    $this->artisan('measurements:secure-legacy-files', ['--execute' => true])->assertSuccessful();

    expect($scenario['payment']->fresh()->currentReceiptEvidence->storage_path)->toBe($path)
        ->and(Storage::disk('public')->get($path))->toBe($bytes);
});

it('rejects invalid extensions empty files oversized files and invalid signatures', function (string $name, string $bytes) {
    $scenario = Scenario::open();
    expect(fn () => app(MeasurementReceiptEvidenceService::class)->upload($scenario['payment'], $scenario['actor'], Scenario::file($name, $bytes)))
        ->toThrow(ValidationException::class);
    expect($scenario['payment']->receiptEvidences()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles('nimbus_docs/measurements/receipts'))->toBeEmpty();
})->with([
    ['comprovante.exe', '%PDF-1.7 documento'],
    ['comprovante.pdf', ''],
    ['comprovante.pdf', 'arquivo sem assinatura PDF'],
    ['comprovante.pdf', '%PDF-1.7'.str_repeat('x', 11 * 1024 * 1024)],
]);

it('counts evidence-backed uploads in the operational read model without populating legacy fields', function () {
    $scenario = Scenario::open();
    app(MeasurementReceiptEvidenceService::class)->upload($scenario['payment'], $scenario['actor'], Scenario::file());
    $measurement = app(MeasurementOperationalReadModel::class)->queryFor($scenario['actor'])->findOrFail($scenario['measurement']->id);

    expect($measurement->payments_with_receipt_count)->toBe(1)
        ->and($scenario['payment']->fresh()->hasReceipt())->toBeTrue()
        ->and($scenario['payment']->fresh()->receipt_path)->toBeNull()
        ->and(MeasurementPayment::query()->whereKey($scenario['payment']->id)->withoutReceipt()->exists())->toBeFalse();
});

it('serves each historical version privately and makes the legacy route resolve current evidence', function () {
    $scenario = Scenario::legacy();
    $first = $scenario['payment']->currentReceiptEvidence;
    $second = app(MeasurementReceiptEvidenceService::class)->correctFinalizedReceipt($scenario['payment'], $scenario['actor'], Scenario::file(), 'Correção', $first->id);
    $third = app(MeasurementReceiptEvidenceService::class)->correctFinalizedReceipt($scenario['payment'], $scenario['actor'], Scenario::file(), 'Complementação documental', $second->id);
    $this->actingAs($scenario['actor']);

    foreach ([$first, $second, $third] as $evidence) {
        $this->get(route('admin.measurements.receipt-evidences.download', ['payment' => $scenario['payment'], 'evidence' => $evidence]))
            ->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Security-Policy');
        expect(Activity::query()->where('log_name', 'measurement_file_access')->latest('id')->first()->properties['evidence_id'])->toBe($evidence->id);
    }
    $this->get(route('admin.measurements.receipts.download', $scenario['payment']))->assertOk();
    expect(Activity::query()->where('log_name', 'measurement_file_access')->latest('id')->first()->properties['evidence_id'])->toBe($third->id);
});

it('rejects foreign evidence and unauthorized downloads', function (bool $sameMeasurement) {
    $scenario = Scenario::legacy();
    $other = Scenario::legacy();
    $route = route('admin.measurements.receipt-evidences.download', ['payment' => $scenario['payment'], 'evidence' => $scenario['payment']->currentReceiptEvidence]);
    $this->get($route)->assertRedirect();
    $this->actingAs($other['actor'])->get($route)->assertForbidden();
    if ($sameMeasurement) {
        $foreignPayment = MeasurementPayment::factory()->create([
            'measurement_id' => $scenario['measurement']->id,
            'operation_id' => $scenario['operation']->id,
            'plan_set_id' => $scenario['payment']->plan_set_id,
        ]);
        $foreignEvidence = app(MeasurementReceiptEvidenceService::class)->correctFinalizedReceipt(
            $foreignPayment, $scenario['actor'], Scenario::file(), 'Comprovante de outro pagamento', null,
        );
    } else {
        $foreignEvidence = $other['payment']->currentReceiptEvidence;
    }
    $this->actingAs($scenario['actor'])->get(route('admin.measurements.receipt-evidences.download', [
        'payment' => $scenario['payment'], 'evidence' => $foreignEvidence,
    ]))->assertNotFound();
})->with([false, true]);

it('blocks unsafe paths invalid disks and tampered hashes on versioned downloads', function (array $attributes) {
    $scenario = Scenario::legacy();
    $evidence = $scenario['payment']->currentReceiptEvidence;
    DB::table('measurement_payment_receipt_evidences')->where('id', $evidence->id)->update($attributes);
    $this->actingAs($scenario['actor'])->get(route('admin.measurements.receipt-evidences.download', [
        'payment' => $scenario['payment'], 'evidence' => $evidence,
    ]))->assertNotFound();
    $this->get(route('admin.measurements.receipts.download', $scenario['payment']))->assertNotFound();
})->with([
    [['storage_path' => '../outside.pdf']],
    [['storage_path' => 'C:\\Windows\\secret.pdf']],
    [['storage_disk' => 's3']],
    [['sha256' => str_repeat('0', 64)]],
]);

it('preserves legacy download without a hash while refusing to approve an unhashable historical version', function () {
    $scenario = Scenario::open();
    $path = 'nimbus_docs/measurements/receipts/unhashed.pdf';
    Storage::disk('local')->put($path, '%PDF-1.7 histórico');
    DB::table('measurement_payments')->where('id', $scenario['payment']->id)->update([
        'receipt_path' => $path, 'receipt_disk' => 'local', 'receipt_sha256' => null, 'receipt_mime_type' => 'application/pdf',
    ]);
    $this->actingAs($scenario['actor'])->get(route('admin.measurements.receipts.download', $scenario['payment']))->assertOk();
    expect(fn () => app(MeasurementReceiptEvidenceService::class)->upload($scenario['payment'], $scenario['actor'], Scenario::file()))
        ->toThrow(MeasurementWorkflowException::class);

    Scenario::backfill();
    $evidence = $scenario['payment']->fresh()->currentReceiptEvidence;
    $this->get(route('admin.measurements.receipt-evidences.download', ['payment' => $scenario['payment'], 'evidence' => $evidence]))->assertOk();
    expect(fn () => app(MeasurementReceiptEvidenceService::class)->review($evidence, $scenario['actor'], MeasurementReceiptReviewStatus::Approved, true))
        ->toThrow(ValidationException::class);
});

<?php

use App\Enums\AccessPermission;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\ObligationEvidencesRelationManager;
use App\Filament\Resources\Emissions\Pages\EditEmission;
use App\Models\Emission;
use App\Models\Obligation;
use App\Models\ObligationEvidence;
use App\Models\ObligationHistoryEntry;
use App\Models\User;
use App\Services\Obligations\ObligationEvidenceReviewService;
use App\Services\Obligations\ObligationEvidenceService;
use App\Services\Security\ClamAvFileScanner;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
});

function evidenceService(): ObligationEvidenceService
{
    return app(ObligationEvidenceService::class);
}

function makeEvidenceUserWithPermissions(array $permissions): User
{
    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

it('stores an evidence file linked to the obligation and emission', function () {
    $obligation = Obligation::factory()->create();
    $user = User::factory()->create();
    $file = UploadedFile::fake()->create('comprovante.pdf', 120, 'application/pdf');

    $evidence = evidenceService()->store($obligation, $file, 'Comprovante enviado à CVM', $user->id);

    expect($evidence->obligation_id)->toBe($obligation->id)
        ->and($evidence->emission_id)->toBe($obligation->emission_id)
        ->and($evidence->uploaded_by)->toBe($user->id)
        ->and($evidence->original_name)->toBe('comprovante.pdf')
        ->and($evidence->disk)->toBe('local')
        ->and($evidence->status)->toBe(ObligationEvidence::STATUS_PENDING)
        ->and($evidence->description)->toBe('Comprovante enviado à CVM');

    Storage::disk('local')->assertExists($evidence->path);
});

it('records an evidence_uploaded history event on upload', function () {
    $obligation = Obligation::factory()->create();
    $file = UploadedFile::fake()->create('relatorio.pdf', 50, 'application/pdf');

    $evidence = evidenceService()->store($obligation, $file, null, null);

    $entry = $obligation->historyEntries()
        ->where('event_type', ObligationHistoryEntry::EVENT_EVIDENCE_UPLOADED)
        ->first();

    expect($entry)->not->toBeNull()
        ->and($entry->description)->toContain('relatorio.pdf')
        ->and($entry->metadata['status'])->toBe(ObligationEvidence::STATUS_PENDING)
        ->and($entry->metadata['original_name'])->toBe('relatorio.pdf');
});

it('rejects files with a disallowed extension', function () {
    $obligation = Obligation::factory()->create();
    $file = UploadedFile::fake()->create('malware.php', 10);

    expect(fn () => evidenceService()->store($obligation, $file, null, null))
        ->toThrow(ValidationException::class);

    expect(ObligationEvidence::count())->toBe(0);
});

it('rejects files with a disallowed mime type', function () {
    $obligation = Obligation::factory()->create();
    $file = UploadedFile::fake()->create('comprovante.pdf', 10, 'application/x-msdownload');

    expect(fn () => evidenceService()->store($obligation, $file, null, null))
        ->toThrow(ValidationException::class);

    expect(ObligationEvidence::count())->toBe(0);
});

it('blocks an upload when the antivirus flags the file as infected', function () {
    $this->mock(ClamAvFileScanner::class, function ($mock) {
        $mock->shouldReceive('isEnabled')->andReturnTrue();
        $mock->shouldReceive('scan')->andReturn(ClamAvFileScanner::RESULT_INFECTED);
    });

    $obligation = Obligation::factory()->create();
    $file = UploadedFile::fake()->create('comprovante.pdf', 50, 'application/pdf');

    expect(fn () => app(ObligationEvidenceService::class)->store($obligation, $file, null, null))
        ->toThrow(ValidationException::class);

    expect(ObligationEvidence::count())->toBe(0);
});

it('blocks an upload when the antivirus is enabled but unavailable', function () {
    $this->mock(ClamAvFileScanner::class, function ($mock) {
        $mock->shouldReceive('isEnabled')->andReturnTrue();
        $mock->shouldReceive('scan')->andReturn(ClamAvFileScanner::RESULT_UNAVAILABLE);
    });

    $obligation = Obligation::factory()->create();
    $file = UploadedFile::fake()->create('comprovante.pdf', 50, 'application/pdf');

    expect(fn () => app(ObligationEvidenceService::class)->store($obligation, $file, null, null))
        ->toThrow(ValidationException::class);

    expect(ObligationEvidence::count())->toBe(0);
});

it('does not scan or block uploads when the antivirus is disabled', function () {
    config(['uploads.clamav.enabled' => false]);
    $obligation = Obligation::factory()->create();
    $file = UploadedFile::fake()->create('comprovante.pdf', 50, 'application/pdf');

    $evidence = evidenceService()->store($obligation, $file, null, null);

    expect($evidence)->not->toBeNull();
    Storage::disk('local')->assertExists($evidence->path);
});

it('rejects files larger than the configured limit', function () {
    config(['uploads.obligation_evidence.max_kb' => 100]);
    $obligation = Obligation::factory()->create();
    $file = UploadedFile::fake()->create('grande.pdf', 250, 'application/pdf');

    expect(fn () => evidenceService()->store($obligation, $file, null, null))
        ->toThrow(ValidationException::class);

    expect(ObligationEvidence::count())->toBe(0);
});

it('soft deletes an evidence and records the removal in the history', function () {
    $obligation = Obligation::factory()->create();
    $file = UploadedFile::fake()->create('comprovante.pdf', 30, 'application/pdf');
    $evidence = evidenceService()->store($obligation, $file, null, null);

    evidenceService()->delete($evidence);

    expect(ObligationEvidence::find($evidence->id))->toBeNull()
        ->and(ObligationEvidence::withTrashed()->find($evidence->id)->trashed())->toBeTrue()
        ->and(Storage::disk('local')->exists($evidence->path))->toBeTrue(); // file kept for auditability

    expect($obligation->historyEntries()->where('event_type', ObligationHistoryEntry::EVENT_EVIDENCE_REMOVED)->exists())->toBeTrue();
});

it('approves a pending evidence for users with the specific permission', function () {
    $reviewer = makeEvidenceUserWithPermissions([
        AccessPermission::ObligationsApproveEvidence->value,
    ]);
    $obligation = Obligation::factory()->create();
    $evidence = ObligationEvidence::factory()->create([
        'obligation_id' => $obligation->id,
        'emission_id' => $obligation->emission_id,
        'status' => ObligationEvidence::STATUS_PENDING,
    ]);

    app(ObligationEvidenceReviewService::class)->approve($evidence, $reviewer, 'Arquivo validado.');

    $evidence->refresh();
    $entry = $obligation->historyEntries()
        ->where('event_type', ObligationHistoryEntry::EVENT_EVIDENCE_APPROVED)
        ->latest('id')
        ->first();

    expect($evidence->status)->toBe(ObligationEvidence::STATUS_APPROVED)
        ->and($evidence->reviewed_by)->toBe($reviewer->id)
        ->and($evidence->reviewed_at)->not->toBeNull()
        ->and($evidence->review_notes)->toBe('Arquivo validado.')
        ->and($evidence->rejection_reason)->toBeNull()
        ->and($entry)->not->toBeNull()
        ->and($entry->source)->toBe(ObligationHistoryEntry::SOURCE_EVIDENCE_REVIEW)
        ->and($entry->metadata['evidence_id'])->toBe($evidence->id);
});

it('does not conclude the obligation automatically when an evidence is approved', function () {
    $reviewer = makeEvidenceUserWithPermissions([
        AccessPermission::ObligationsApproveEvidence->value,
    ]);
    $obligation = Obligation::factory()->create([
        'status' => 'em_analise',
    ]);
    $evidence = ObligationEvidence::factory()->create([
        'obligation_id' => $obligation->id,
        'emission_id' => $obligation->emission_id,
        'status' => ObligationEvidence::STATUS_PENDING,
    ]);

    app(ObligationEvidenceReviewService::class)->approve($evidence, $reviewer, null);

    expect($obligation->fresh()->status)->toBe('em_analise');
});

it('blocks evidence approval for users without the specific permission', function () {
    $reviewer = User::factory()->create();
    $evidence = ObligationEvidence::factory()->create([
        'status' => ObligationEvidence::STATUS_PENDING,
    ]);

    expect(fn () => app(ObligationEvidenceReviewService::class)->approve($evidence, $reviewer, null))
        ->toThrow(AuthorizationException::class);
});

it('rejects a pending evidence for users with the specific permission', function () {
    $reviewer = makeEvidenceUserWithPermissions([
        AccessPermission::ObligationsRejectEvidence->value,
    ]);
    $obligation = Obligation::factory()->create();
    $evidence = ObligationEvidence::factory()->create([
        'obligation_id' => $obligation->id,
        'emission_id' => $obligation->emission_id,
        'status' => ObligationEvidence::STATUS_PENDING,
    ]);

    app(ObligationEvidenceReviewService::class)->reject($evidence, $reviewer, 'Documento ilegível.');

    $evidence->refresh();
    $entry = $obligation->historyEntries()
        ->where('event_type', ObligationHistoryEntry::EVENT_EVIDENCE_REJECTED)
        ->latest('id')
        ->first();

    expect($evidence->status)->toBe(ObligationEvidence::STATUS_REJECTED)
        ->and($evidence->reviewed_by)->toBe($reviewer->id)
        ->and($evidence->reviewed_at)->not->toBeNull()
        ->and($evidence->rejection_reason)->toBe('Documento ilegível.')
        ->and($evidence->review_notes)->toBeNull()
        ->and($entry)->not->toBeNull()
        ->and($entry->source)->toBe(ObligationHistoryEntry::SOURCE_EVIDENCE_REVIEW)
        ->and($entry->metadata['evidence_id'])->toBe($evidence->id);
});

it('blocks evidence rejection for users without the specific permission', function () {
    $reviewer = User::factory()->create();
    $evidence = ObligationEvidence::factory()->create([
        'status' => ObligationEvidence::STATUS_PENDING,
    ]);

    expect(fn () => app(ObligationEvidenceReviewService::class)->reject($evidence, $reviewer, 'Sem permissão.'))
        ->toThrow(AuthorizationException::class);
});

it('requires a rejection reason when rejecting an evidence', function () {
    $reviewer = makeEvidenceUserWithPermissions([
        AccessPermission::ObligationsRejectEvidence->value,
    ]);
    $evidence = ObligationEvidence::factory()->create([
        'status' => ObligationEvidence::STATUS_PENDING,
    ]);

    expect(fn () => app(ObligationEvidenceReviewService::class)->reject($evidence, $reviewer, null))
        ->toThrow(ValidationException::class);
});

it('allows an authorized user to download an evidence', function () {
    $emission = Emission::factory()->create();
    $obligation = Obligation::factory()->for($emission)->create();
    $evidence = ObligationEvidence::factory()->create([
        'obligation_id' => $obligation->id,
        'emission_id' => $emission->id,
        'path' => 'nimbus_docs/obligation-evidences/comprovante.pdf',
        'disk' => 'local',
        'original_name' => 'comprovante.pdf',
    ]);
    Storage::disk('local')->put($evidence->path, 'conteudo-do-arquivo');

    $this->actingAs(makeEvidenceUserWithPermissions([
        AccessPermission::ObligationsDownloadEvidence->value,
    ]))
        ->get(route('admin.obligations.evidences.download', $evidence))
        ->assertOk();
});

it('returns 404 when the physical evidence file is missing', function () {
    $evidence = ObligationEvidence::factory()->create([
        'path' => 'nimbus_docs/obligation-evidences/missing.pdf',
        'disk' => 'local',
    ]);

    $this->actingAs(makeAdminUser())
        ->get(route('admin.obligations.evidences.download', $evidence))
        ->assertNotFound();
});

it('forbids download for users without the download evidence permission', function () {
    $evidence = ObligationEvidence::factory()->create([
        'path' => 'nimbus_docs/obligation-evidences/secret.pdf',
        'disk' => 'local',
    ]);
    Storage::disk('local')->put($evidence->path, 'conteudo');

    $this->actingAs(makeEvidenceUserWithPermissions([
        AccessPermission::ObligationsViewEvidence->value,
    ]))
        ->get(route('admin.obligations.evidences.download', $evidence))
        ->assertForbidden();
});

it('hides the evidences relation manager from users without permission', function () {
    $emission = Emission::factory()->create();

    $this->actingAs(User::factory()->create());

    expect(ObligationEvidencesRelationManager::canViewForRecord($emission, EditEmission::class))->toBeFalse();
});

it('lists the emission evidences for users with the view evidence permission', function () {
    $emission = Emission::factory()->create();
    $obligation = Obligation::factory()->for($emission)->create();
    $evidence = ObligationEvidence::factory()->create([
        'obligation_id' => $obligation->id,
        'emission_id' => $emission->id,
        'original_name' => 'evidencia-visivel.pdf',
    ]);

    $this->actingAs(makeEvidenceUserWithPermissions([
        AccessPermission::ObligationsViewEvidence->value,
    ]));

    Livewire::test(ObligationEvidencesRelationManager::class, [
        'ownerRecord' => $emission,
        'pageClass' => EditEmission::class,
    ])
        ->assertSuccessful()
        ->assertTableColumnExists('status')
        ->assertTableColumnExists('reviewer.name')
        ->assertTableColumnExists('reviewed_at')
        ->assertCanSeeTableRecords([$evidence])
        ->assertTableActionHidden('create');
});

it('shows the attach evidence action to users with the upload evidence permission', function () {
    $emission = Emission::factory()->create();
    $user = makeEvidenceUserWithPermissions([
        AccessPermission::ObligationsViewEvidence->value,
        AccessPermission::ObligationsUploadEvidence->value,
    ]);

    $this->actingAs($user);

    Livewire::test(ObligationEvidencesRelationManager::class, [
        'ownerRecord' => $emission,
        'pageClass' => EditEmission::class,
    ])
        ->assertSuccessful()
        ->assertTableActionVisible('create');
});

it('shows approve and reject actions only to users with the matching permissions', function () {
    $emission = Emission::factory()->create();
    $obligation = Obligation::factory()->for($emission)->create();
    $evidence = ObligationEvidence::factory()->create([
        'obligation_id' => $obligation->id,
        'emission_id' => $emission->id,
        'status' => ObligationEvidence::STATUS_PENDING,
    ]);

    $this->actingAs(makeEvidenceUserWithPermissions([
        AccessPermission::ObligationsViewEvidence->value,
        AccessPermission::ObligationsApproveEvidence->value,
    ]));

    Livewire::test(ObligationEvidencesRelationManager::class, [
        'ownerRecord' => $emission,
        'pageClass' => EditEmission::class,
    ])
        ->assertSuccessful()
        ->assertTableActionVisible('approve_evidence', $evidence)
        ->assertTableActionHidden('reject_evidence', $evidence);
});

it('keeps evidence review available to super admins', function () {
    $reviewer = User::factory()->create();
    $reviewer->assignRole('super-admin');

    $evidence = ObligationEvidence::factory()->create([
        'status' => ObligationEvidence::STATUS_PENDING,
    ]);

    app(ObligationEvidenceReviewService::class)->approve($evidence, $reviewer, null);

    expect($evidence->fresh()->status)->toBe(ObligationEvidence::STATUS_APPROVED);
});

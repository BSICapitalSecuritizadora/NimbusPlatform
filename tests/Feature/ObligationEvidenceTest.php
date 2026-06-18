<?php

use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\ObligationEvidencesRelationManager;
use App\Filament\Resources\Emissions\Pages\EditEmission;
use App\Models\Emission;
use App\Models\Obligation;
use App\Models\ObligationEvidence;
use App\Models\ObligationHistoryEntry;
use App\Models\User;
use App\Services\Obligations\ObligationEvidenceService;
use App\Services\Security\ClamAvFileScanner;
use Database\Seeders\RolesAndPermissionsSeeder;
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
        ->and($entry->metadata['original_name'])->toBe('relatorio.pdf');
});

it('rejects files with a disallowed extension', function () {
    $obligation = Obligation::factory()->create();
    $file = UploadedFile::fake()->create('malware.php', 10);

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

    $this->actingAs(makeAdminUser())
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

it('forbids download for users without the obligations permission', function () {
    $evidence = ObligationEvidence::factory()->create([
        'path' => 'nimbus_docs/obligation-evidences/secret.pdf',
        'disk' => 'local',
    ]);
    Storage::disk('local')->put($evidence->path, 'conteudo');

    $this->actingAs(User::factory()->create())
        ->get(route('admin.obligations.evidences.download', $evidence))
        ->assertForbidden();
});

it('hides the evidences relation manager from users without permission', function () {
    $emission = Emission::factory()->create();

    $this->actingAs(User::factory()->create());

    expect(ObligationEvidencesRelationManager::canViewForRecord($emission, EditEmission::class))->toBeFalse();
});

it('lists the emission evidences for authorized users', function () {
    $emission = Emission::factory()->create();
    $obligation = Obligation::factory()->for($emission)->create();
    $evidence = ObligationEvidence::factory()->create([
        'obligation_id' => $obligation->id,
        'emission_id' => $emission->id,
        'original_name' => 'evidencia-visivel.pdf',
    ]);

    $this->actingAs(makeAdminUser());

    Livewire::test(ObligationEvidencesRelationManager::class, [
        'ownerRecord' => $emission,
        'pageClass' => EditEmission::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$evidence])
        ->assertTableActionVisible('create');
});

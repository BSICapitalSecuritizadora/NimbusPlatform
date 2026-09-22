<?php

use App\Domain\PuCalculator\Enums\PuBaselineEvidenceDocumentType;
use App\Domain\PuCalculator\Enums\PuBaselineEvidenceStatus;
use App\Domain\PuCalculator\Enums\PuBaselineEvidenceType;
use App\Enums\AccessPermission;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\PuBaselineEvidencesRelationManager;
use App\Filament\Resources\Emissions\Pages\ViewEmission;
use App\Models\Document;
use App\Models\Emission;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('mounts the associar evidencia ao baseline modal in relation manager', function () {
    $user = makeAdminUser();
    $user->givePermissionTo([
        AccessPermission::PuParametersConfigure->value,
        AccessPermission::PuCurveView->value,
    ]);

    $emission = Emission::factory()->active()->create([
        'name' => 'CRI Alpha',
        'type' => 'CRI',
        'if_code' => 'IF-ALPHA-01',
    ]);

    $document = Document::factory()->create(['title' => 'Termo']);
    $emission->documents()->attach($document);

    // Ensure supports() is true
    $emission->puBaselineEvidences()->create([
        'evidence_type' => PuBaselineEvidenceType::FirstIntegralizationDate->value,
        'document_id' => $document->id,
        'document_type' => PuBaselineEvidenceDocumentType::B3SettlementStatement->value,
        'evidenced_value' => '2026-01-01',
        'status' => PuBaselineEvidenceStatus::PendingReview->value,
        'confidence' => 'high',
        'created_by' => $user->id,
    ]);

    $this->actingAs($user);

    $testable = Livewire::test(PuBaselineEvidencesRelationManager::class, [
        'ownerRecord' => $emission,
        'pageClass' => ViewEmission::class,
    ])
        ->mountTableAction('create')
        ->assertSuccessful()
        ->assertTableActionMounted('create')
        ->assertFormFieldExists('evidence_type')
        ->assertFormFieldExists('document_id')
        ->assertFormFieldExists('document_type')
        ->assertFormFieldExists('confidence');

    expect($testable->instance()->getMountedTableAction())->not->toBeNull();
});

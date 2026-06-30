<?php

use App\Enums\AccessPermission;
use App\Filament\Exports\ObligationExporter;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\ObligationsRelationManager;
use App\Filament\Resources\Emissions\Pages\EditEmission;
use App\Filament\Widgets\Obligations\ObligationOperationalTableWidget;
use App\Models\Emission;
use App\Models\Obligation;
use App\Models\ObligationEvidence;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Exports\Enums\ExportFormat;
use Filament\Actions\Exports\Models\Export as FilamentExport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-06-18 09:00:00'));
});

afterEach(function () {
    $this->travelBack();
});

function makeObligationExportUser(array $permissions): User
{
    $user = User::factory()->create([
        'approved_at' => now(),
        'is_active' => true,
    ]);
    $user->givePermissionTo($permissions);

    return $user;
}

function obligationExportRelationManager(Emission $emission): \Livewire\Features\SupportTesting\Testable
{
    return Livewire::test(ObligationsRelationManager::class, [
        'ownerRecord' => $emission,
        'pageClass' => EditEmission::class,
    ]);
}

it('queues the filtered global export for the current operational view', function () {
    Bus::fake();

    $alpha = Emission::factory()->create(['name' => 'Emissão Alpha']);
    $beta = Emission::factory()->create(['name' => 'Emissão Beta']);

    Obligation::factory()->for($alpha)->create([
        'title' => 'Obrigação vencida Alpha',
        'status' => 'vencida',
        'due_date' => '2026-06-10',
    ]);
    Obligation::factory()->for($beta)->create([
        'title' => 'Obrigação Beta',
        'status' => 'a_vencer',
        'due_date' => '2026-06-25',
    ]);

    $user = makeObligationExportUser([
        AccessPermission::ObligationsView->value,
        AccessPermission::ObligationsViewDashboard->value,
        AccessPermission::ObligationsViewEvidence->value,
        AccessPermission::ObligationsExport->value,
    ]);

    $this->actingAs($user);

    Livewire::test(ObligationOperationalTableWidget::class)
        ->assertTableActionVisible('export')
        ->assertTableActionHasLabel('export', 'Exportar visão filtrada atual')
        ->filterTable('emission_id', $alpha->id)
        ->filterTable('status', 'vencida')
        ->callTableAction('export');

    $export = FilamentExport::query()->latest('id')->first();

    expect($export)->not->toBeNull()
        ->and($export?->exporter)->toBe(ObligationExporter::class)
        ->and($export?->total_rows)->toBe(1)
        ->and($export?->user_id)->toBe($user->id);
});

it('hides the global export action from users without export permission', function () {
    $user = makeObligationExportUser([
        AccessPermission::ObligationsView->value,
        AccessPermission::ObligationsViewDashboard->value,
    ]);

    $this->actingAs($user);

    Livewire::test(ObligationOperationalTableWidget::class)
        ->assertTableActionHidden('export');

    expect(FilamentExport::query()->count())->toBe(0);
});

it('queues an emission-scoped export from the relation manager', function () {
    Bus::fake();

    $emission = Emission::factory()->create(['name' => 'Emissão Atual']);
    $otherEmission = Emission::factory()->create(['name' => 'Outra Emissão']);

    Obligation::factory()->for($emission)->create([
        'title' => 'Obrigação da emissão',
        'status' => 'a_vencer',
        'due_date' => '2026-06-25',
    ]);
    Obligation::factory()->for($otherEmission)->create([
        'title' => 'Obrigação externa',
        'status' => 'a_vencer',
        'due_date' => '2026-06-25',
    ]);

    $user = makeObligationExportUser([
        AccessPermission::ObligationsView->value,
        AccessPermission::ObligationsViewEvidence->value,
        AccessPermission::ObligationsExport->value,
    ]);

    $this->actingAs($user);

    obligationExportRelationManager($emission)
        ->assertTableActionVisible('export')
        ->assertTableActionHasLabel('export', 'Exportar obrigações desta emissão')
        ->filterTable('status', 'a_vencer')
        ->callTableAction('export');

    $export = FilamentExport::query()->latest('id')->first();

    expect($export)->not->toBeNull()
        ->and($export?->exporter)->toBe(ObligationExporter::class)
        ->and($export?->total_rows)->toBe(1)
        ->and($export?->user_id)->toBe($user->id);
});

it('exports operational columns, aging and evidence counters', function () {
    $responsible = User::factory()->create(['name' => 'Fernanda']);
    $completer = User::factory()->create(['name' => 'Marina']);
    $reviewer = User::factory()->create(['name' => 'Revisor']);
    $emission = Emission::factory()->create([
        'name' => 'Emissão Exportável',
        'bsi_code' => 'BSI-2026-0099',
    ]);

    $obligation = Obligation::factory()->for($emission)->create([
        'title' => 'Entrega de relatório',
        'description' => "Resumo\n operacionaL  ",
        'status' => 'concluida',
        'due_date' => '2026-06-10',
        'priority' => 'critical',
        'responsible_user_id' => $responsible->id,
        'responsible_area' => 'Compliance',
        'completed_at' => '2026-06-17 15:30:00',
        'completed_by' => $completer->id,
        'created_at' => '2026-06-01 08:15:00',
        'updated_at' => '2026-06-18 08:45:00',
    ]);

    ObligationEvidence::factory()->for($obligation)->for($emission)->approved($reviewer)->create([
        'uploaded_at' => '2026-06-12 10:00:00',
        'reviewed_at' => '2026-06-12 16:00:00',
    ]);
    ObligationEvidence::factory()->for($obligation)->for($emission)->create([
        'status' => ObligationEvidence::STATUS_PENDING,
        'uploaded_at' => '2026-06-14 09:00:00',
    ]);
    ObligationEvidence::factory()->for($obligation)->for($emission)->rejected($reviewer)->create([
        'uploaded_at' => '2026-06-16 11:30:00',
        'reviewed_at' => '2026-06-16 18:20:00',
    ]);

    $user = makeObligationExportUser([
        AccessPermission::ObligationsView->value,
        AccessPermission::ObligationsViewDashboard->value,
        AccessPermission::ObligationsViewEvidence->value,
        AccessPermission::ObligationsExport->value,
    ]);

    $this->actingAs($user);

    $columnMap = collect(ObligationExporter::getColumns())
        ->mapWithKeys(fn ($column): array => [$column->getName() => $column->getLabel()])
        ->all();

    $export = FilamentExport::query()->create([
        'file_disk' => 'local',
        'file_name' => 'obrigacoes-operacionais-1',
        'exporter' => ObligationExporter::class,
        'total_rows' => 1,
        'user_id' => $user->id,
    ]);

    $record = Obligation::query()
        ->with(['emission', 'responsibleUser', 'completedByUser'])
        ->withCount([
            'evidences',
            'evidences as approved_evidences_count' => fn (Builder $query): Builder => $query->approved(),
            'evidences as pending_evidences_count' => fn (Builder $query): Builder => $query->pending(),
            'evidences as rejected_evidences_count' => fn (Builder $query): Builder => $query->rejected(),
        ])
        ->withMax('evidences', 'uploaded_at')
        ->withMax([
            'evidences' => fn (Builder $query): Builder => $query->whereNotNull('reviewed_at'),
        ], 'reviewed_at')
        ->findOrFail($obligation->id);

    $exporter = new ObligationExporter($export, $columnMap, []);
    $row = array_combine(array_keys($columnMap), $exporter($record));

    expect($row)->toMatchArray([
        'emission.name' => 'Emissão Exportável',
        'emission.bsi_code' => 'BSI-2026-0099',
        'title' => 'Entrega de relatório',
        'description' => 'Resumo operacionaL',
        'status' => 'Concluída',
        'due_date' => '10/06/2026',
        'aging' => '8 a 15 dias',
        'responsibleUser.name' => 'Fernanda',
        'responsible_area' => 'Compliance',
        'priority' => 'Crítica',
        'source' => 'Manual',
        'created_at' => '01/06/2026 08:15',
        'updated_at' => '18/06/2026 08:45',
        'completed_at' => '17/06/2026 15:30',
        'completedByUser.name' => 'Marina',
        'document_status' => 'Com aprovação, pendência e rejeição',
        'evidences_count' => 3,
        'approved_evidences_count' => 1,
        'pending_evidences_count' => 1,
        'rejected_evidences_count' => 1,
        'evidences_max_uploaded_at' => '16/06/2026 11:30',
        'evidences_max_reviewed_at' => '16/06/2026 18:20',
    ]);

    expect($exporter->getFormats())->toBe([
        ExportFormat::Xlsx,
        ExportFormat::Csv,
    ]);

    expect($exporter->getFileName($export))->toBe('obrigacoes-operacionais-recorte-'.$export->getKey());
});

it('excludes sensitive fields and documentary columns when evidence permission is absent', function () {
    $user = makeObligationExportUser([
        AccessPermission::ObligationsView->value,
        AccessPermission::ObligationsViewDashboard->value,
        AccessPermission::ObligationsExport->value,
    ]);

    $this->actingAs($user);

    $columnNames = collect(ObligationExporter::getColumns())
        ->map(fn ($column): string => $column->getName())
        ->all();

    expect($columnNames)->not->toContain(
        'path',
        'disk',
        'review_notes',
        'rejection_reason',
        'source_excerpt',
        'completion_notes',
        'not_applicable_reason',
        'document_status',
        'evidences_count',
        'approved_evidences_count',
        'pending_evidences_count',
        'rejected_evidences_count',
    );
});

it('allows super admins to export obligations', function () {
    Bus::fake();

    Obligation::factory()->for(Emission::factory()->create())->create([
        'status' => 'vencida',
        'due_date' => '2026-06-10',
    ]);

    $user = User::factory()->create();
    $user->assignRole('super-admin');

    $this->actingAs($user);

    Livewire::test(ObligationOperationalTableWidget::class)
        ->assertTableActionVisible('export')
        ->callTableAction('export');

    expect(FilamentExport::query()->count())->toBe(1);
});

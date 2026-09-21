<?php

use App\Filament\Resources\Measurements\Pages\ViewMeasurement;
use App\Models\Measurement;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\Operation;
use App\Models\User;
use App\Services\MeasurementWorkflow;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    Notification::fake();
});

function reviewsTableTestMeasurement(array $overrides = []): array
{
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $stage1 = User::factory()->create(['name' => 'Engenheiro Carlos']);
    $stage1->assignRole('admin');

    $stage2 = User::factory()->create(['name' => 'Gestora Mariana']);
    $stage2->assignRole('admin');

    $stage3 = User::factory()->create(['name' => 'Compliance Roberto']);
    $stage3->assignRole('admin');

    $operation = Operation::factory()->create(array_merge([
        'responsible_user_id' => $stage1->id,
        'stage2_reviewer_user_id' => $stage2->id,
        'stage3_reviewer_user_id' => $stage3->id,
    ], $overrides));

    $planSet = MeasurementPlanSet::factory()->create(['operation_id' => $operation->id]);
    $line = MeasurementPlanLine::factory()->create([
        'plan_set_id' => $planSet->id,
        'operation_id' => $operation->id,
        'measurement_date' => '2026-08-01',
        'realized_monthly_percent' => 15,
        'realized_cumulative_percent' => 30,
    ]);

    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->id,
        'reference_month' => '2026-08-01',
        'status' => 'pending',
        'current_stage' => 1,
    ]);

    $path = "nimbus_docs/measurements/workflow/{$measurement->id}.pdf";
    Storage::disk('local')->put($path, '%PDF-1.7 workflow-test');
    $measurement->assets()->create([
        'plan_set_id' => $planSet->id,
        'plan_line_id' => $line->id,
        'storage_path' => $path,
        'storage_disk' => 'local',
    ]);

    return compact('admin', 'stage1', 'stage2', 'stage3', 'operation', 'measurement', 'planSet');
}

it('displays stage approvals with notes in full width second layer', function () {
    $ctx = reviewsTableTestMeasurement();
    $workflow = app(MeasurementWorkflow::class);

    $workflow->startReview($ctx['measurement'], $ctx['stage1']);
    $workflow->approve(
        $ctx['measurement']->fresh(),
        $ctx['stage1'],
        notes: 'Documentação da engenharia totalmente conforme.',
        engineeringProgress: [$ctx['planSet']->id => 15.0],
    );

    $this->actingAs($ctx['admin']);

    Livewire::test(ViewMeasurement::class, ['record' => $ctx['measurement']->id])
        ->assertSee('Atividade por Etapa')
        ->assertSee('Engenharia')
        ->assertSee('Engenheiro Carlos')
        ->assertSee('Aprovada')
        ->assertSee('Observação:')
        ->assertSee('Documentação da engenharia totalmente conforme.')
        ->assertSee('colspan="4"', false);
});

it('displays stage returns with specific target badge and reason label', function () {
    $ctx = reviewsTableTestMeasurement();
    $workflow = app(MeasurementWorkflow::class);

    $workflow->startReview($ctx['measurement'], $ctx['stage1']);
    $workflow->approve(
        $ctx['measurement']->fresh(),
        $ctx['stage1'],
        engineeringProgress: [$ctx['planSet']->id => 15.0],
    );

    // Stage 2 returns to Stage 1 (Engineering)
    $workflow->reject($ctx['measurement']->fresh(), $ctx['stage2'], 'Faltou o laudo de sondagem do solo.');

    $this->actingAs($ctx['admin']);

    Livewire::test(ViewMeasurement::class, ['record' => $ctx['measurement']->id])
        ->assertSee('Devolvida para Engenharia')
        ->assertSee('Gestora Mariana')
        ->assertSee('Motivo da devolução:')
        ->assertSee('Faltou o laudo de sondagem do solo.')
        ->assertSee('colspan="4"', false);
});

it('preserves chronological history with multiple events of the same stage', function () {
    $ctx = reviewsTableTestMeasurement();
    $workflow = app(MeasurementWorkflow::class);

    // 1. Stage 1 approves first time
    $workflow->startReview($ctx['measurement'], $ctx['stage1']);
    $workflow->approve(
        $ctx['measurement']->fresh(),
        $ctx['stage1'],
        notes: 'Primeira aprovação da engenharia.',
        engineeringProgress: [$ctx['planSet']->id => 15.0],
    );

    // 2. Stage 2 returns to Stage 1
    $workflow->reject($ctx['measurement']->fresh(), $ctx['stage2'], 'Ajustar memorial descritivo.');

    // 3. Stage 1 approves second time
    $workflow->approve(
        $ctx['measurement']->fresh(),
        $ctx['stage1'],
        notes: 'Memorial ajustado conforme solicitado.',
        engineeringProgress: [$ctx['planSet']->id => 15.0],
    );

    $this->actingAs($ctx['admin']);

    Livewire::test(ViewMeasurement::class, ['record' => $ctx['measurement']->id])
        ->assertSee('Primeira aprovação da engenharia.')
        ->assertSee('Devolvida para Engenharia')
        ->assertSee('Ajustar memorial descritivo.')
        ->assertSee('Memorial ajustado conforme solicitado.');
});

it('displays rejection with correct Motivo da rejeição label when stage 1 rejects', function () {
    $ctx = reviewsTableTestMeasurement();
    $workflow = app(MeasurementWorkflow::class);

    $workflow->startReview($ctx['measurement'], $ctx['stage1']);
    $workflow->reject($ctx['measurement']->fresh(), $ctx['stage1'], 'Obra cancelada definitivamente.');

    $this->actingAs($ctx['admin']);

    Livewire::test(ViewMeasurement::class, ['record' => $ctx['measurement']->id])
        ->assertSee('Rejeitada')
        ->assertSee('Motivo da rejeição:')
        ->assertSee('Obra cancelada definitivamente.');
});

it('displays fallback reviews when no activity log entries exist', function () {
    $ctx = reviewsTableTestMeasurement();

    // Directly seed review without activity log entries
    $ctx['measurement']->reviews()->create([
        'stage' => 1,
        'reviewer_user_id' => $ctx['stage1']->id,
        'status' => 'approved',
        'notes' => 'Aprovação legada sem activity log.',
        'reviewed_at' => now(),
    ]);

    $this->actingAs($ctx['admin']);

    Livewire::test(ViewMeasurement::class, ['record' => $ctx['measurement']->id])
        ->assertSee('Engenheiro Carlos')
        ->assertSee('Aprovada')
        ->assertSee('Aprovação legada sem activity log.');
});

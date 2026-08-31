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

/**
 * A proteção contra submissão obsoleta da P0 sempre teve a frase certa -- "A
 * etapa desta medição foi alterada por outra ação. Atualize a página." --, mas
 * ela morria como exceção não tratada: quem tinha a página aberta enquanto outra
 * pessoa decidia a etapa via um erro genérico, sem saber que não era culpa sua.
 *
 * Estes testes fixam as duas metades: a recusa vira mensagem, e a medição não se
 * move por causa dela.
 */
beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
});

/**
 * @return array{measurement: Measurement, planSet: MeasurementPlanSet, reviewer: User}
 */
function staleUxScenario(): array
{
    $reviewer = User::factory()->withTwoFactor()->create(['email' => fake()->unique()->safeEmail()]);
    $reviewer->assignRole('admin');
    test()->actingAs($reviewer);

    $operation = Operation::factory()->create(['responsible_user_id' => $reviewer->getKey()]);
    $planSet = MeasurementPlanSet::factory()->create(['operation_id' => $operation->getKey()]);
    $line = MeasurementPlanLine::factory()->create([
        'operation_id' => $operation->getKey(),
        'plan_set_id' => $planSet->getKey(),
        'measurement_date' => '2026-07-01',
        'realized_monthly_percent' => 0,
        'realized_cumulative_percent' => 0,
    ]);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->getKey(),
        'reference_month' => '2026-07-01',
        'status' => 'pending',
        'current_stage' => 1,
    ]);
    Storage::fake('local');
    Storage::disk('local')->put('measurements/stale.pdf', '%PDF-1.7 stale');
    $measurement->assets()->create([
        'plan_set_id' => $planSet->getKey(),
        'plan_line_id' => $line->getKey(),
        'storage_path' => 'measurements/stale.pdf',
    ]);
    app(MeasurementWorkflow::class)->startReview($measurement, $reviewer);

    return compact('measurement', 'planSet', 'reviewer');
}

it('explains a stale approval instead of failing with a technical error', function () {
    $scenario = staleUxScenario();
    $measurement = $scenario['measurement']->fresh();
    $revisionOnScreen = (int) $measurement->workflow_revision;

    // Outra pessoa decide a etapa enquanto esta página está aberta.
    $measurement->forceFill(['workflow_revision' => $revisionOnScreen + 1])->save();

    Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->callAction('approve', data: [
            'notes' => 'Aprovação tardia',
            'realized' => [$scenario['planSet']->getKey() => 10],
            'expected_stage' => 1,
            'expected_revision' => $revisionOnScreen,
        ])
        ->assertNotified('Ação não concluída.');

    $unchanged = $measurement->fresh();

    expect($unchanged->current_stage)->toBe(1)
        ->and((int) $unchanged->workflow_revision)->toBe($revisionOnScreen + 1)
        ->and($unchanged->reviewForStage(1)?->status)->toBe('pending');
});

it('keeps approving normally when the page is up to date', function () {
    $scenario = staleUxScenario();
    $measurement = $scenario['measurement']->fresh();

    Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->callAction('approve', data: [
            'notes' => 'Ok',
            'realized' => [$scenario['planSet']->getKey() => 10],
            'expected_stage' => 1,
            'expected_revision' => (int) $measurement->workflow_revision,
        ])
        ->assertNotified('Etapa aprovada.');

    expect($measurement->fresh()->current_stage)->toBe(2);
});

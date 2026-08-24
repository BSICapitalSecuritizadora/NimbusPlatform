<?php

use App\Models\Measurement;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\Operation;
use App\Models\User;
use App\Services\MeasurementTimeline;
use App\Services\MeasurementWorkflow;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    Notification::fake();
});

function timelineActor(): User
{
    $user = User::factory()->create();
    $user->assignRole('editor');

    return $user;
}

it('builds a chronological timeline of the measurement events', function () {
    $stage1 = timelineActor();
    $stage2 = timelineActor();
    $this->actingAs($stage1);

    $operation = Operation::factory()->create([
        'responsible_user_id' => $stage1->id,
        'stage2_reviewer_user_id' => $stage2->id,
    ]);
    $planSet = MeasurementPlanSet::factory()->create(['operation_id' => $operation->id]);
    $line = MeasurementPlanLine::factory()->create([
        'plan_set_id' => $planSet->id,
        'operation_id' => $operation->id,
        'measurement_date' => '2026-08-01',
        'realized_monthly_percent' => 0,
        'realized_cumulative_percent' => 0,
    ]);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->id,
        'reference_month' => '2026-08-01',
        'storage_path' => null,
        'status' => 'pending',
        'current_stage' => 1,
        'uploaded_at' => now()->subDay(),
        'uploaded_by' => $stage1->id,
    ]);
    Storage::disk('local')->put('measurements/timeline.pdf', 'timeline');
    $measurement->assets()->create([
        'plan_set_id' => $planSet->id,
        'plan_line_id' => $line->id,
        'storage_path' => 'measurements/timeline.pdf',
        'storage_disk' => 'local',
    ]);

    $workflow = app(MeasurementWorkflow::class);
    $workflow->startReview($measurement, $stage1);
    $workflow->approve($measurement->fresh(), $stage1, engineeringProgress: [$planSet->id => 10]); // advance to stage 2
    $workflow->reject($measurement->fresh(), $stage2, 'Revisar'); // return to stage 1

    $titles = app(MeasurementTimeline::class)->for($measurement->fresh())->pluck('title');

    expect($titles->first())->toBe('Medição enviada')
        ->and($titles)->toContain('Etapa 1 aprovada — avançou para Etapa 2')
        ->and($titles)->toContain('Devolvida para Etapa 1');
});

it('includes pauses and payments in the timeline', function () {
    $actor = timelineActor();
    $this->actingAs($actor);

    $operation = Operation::factory()->create([
        'responsible_user_id' => $actor->id,
        'stage2_reviewer_user_id' => $actor->id,
    ]);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->id,
        'storage_path' => null,
        'status' => 'in_review',
        'current_stage' => 2,
        'uploaded_at' => now()->subDay(),
        'uploaded_by' => $actor->id,
    ]);
    $measurement->reviews()->create([
        'stage' => 2,
        'reviewer_user_id' => $actor->id,
        'status' => 'pending',
    ]);

    $workflow = app(MeasurementWorkflow::class);
    $workflow->pause($measurement->fresh(), $actor, 'Aguardando documento');
    $workflow->resume($measurement->fresh(), $actor);

    $measurement->payments()->create([
        'operation_id' => $operation->id,
        'amount' => 1000,
        'pay_date' => now(),
        'created_by' => $actor->id,
    ]);

    $titles = app(MeasurementTimeline::class)->for($measurement->fresh())->pluck('title');

    expect($titles)->toContain('Análise pausada (Etapa 2)')
        ->and($titles)->toContain('Análise retomada')
        ->and($titles->contains(fn (string $t): bool => str_starts_with($t, 'Pagamento registrado')))->toBeTrue();
});

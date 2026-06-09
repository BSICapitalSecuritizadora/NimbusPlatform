<?php

use App\Models\Measurement;
use App\Models\Operation;
use App\Models\User;
use App\Services\MeasurementTimeline;
use App\Services\MeasurementWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('builds a chronological timeline of the measurement events', function () {
    $stage1 = User::factory()->create();
    $stage2 = User::factory()->create();
    $this->actingAs($stage1);

    $operation = Operation::factory()->create([
        'responsible_user_id' => $stage1->id,
        'stage2_reviewer_user_id' => $stage2->id,
    ]);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->id,
        'status' => 'pending',
        'current_stage' => 1,
        'uploaded_at' => now()->subDay(),
        'uploaded_by' => $stage1->id,
    ]);

    $workflow = app(MeasurementWorkflow::class);
    $workflow->startReview($measurement);
    $workflow->approve($measurement->fresh(), $stage1);          // advance to stage 2
    $workflow->reject($measurement->fresh(), $stage2, 'Revisar'); // return to stage 1

    $titles = app(MeasurementTimeline::class)->for($measurement->fresh())->pluck('title');

    expect($titles->first())->toBe('Medição enviada')
        ->and($titles)->toContain('Etapa 1 aprovada — avançou para Etapa 2')
        ->and($titles)->toContain('Devolvida para Etapa 1');
});

it('includes pauses and payments in the timeline', function () {
    $actor = User::factory()->create();
    $this->actingAs($actor);

    $operation = Operation::factory()->create([
        'responsible_user_id' => $actor->id,
        'stage2_reviewer_user_id' => $actor->id,
    ]);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->id,
        'status' => 'in_review',
        'current_stage' => 2,
        'uploaded_at' => now()->subDay(),
        'uploaded_by' => $actor->id,
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

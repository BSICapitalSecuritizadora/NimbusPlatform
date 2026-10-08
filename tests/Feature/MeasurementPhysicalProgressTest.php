<?php

use App\DTOs\Measurements\MeasurementPhysicalProgressContribution;
use App\Exceptions\MeasurementWorkflowException;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\Measurement;
use App\Models\MeasurementAsset;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\Operation;
use App\Services\MeasurementEngineeringService;
use App\Services\MeasurementFinancialReconciliationService;
use App\Services\MeasurementPhysicalProgressService;
use App\Services\MeasurementWorkflow;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\MeasurementPhysicalProgressScenario as Scenario;
use Tests\Support\MeasurementPlanVersionFixture;
use Tests\Support\MeasurementReceiptEvidenceScenario;

uses(RefreshDatabase::class);
pest()->group('parity');

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    Notification::fake();
});

/**
 * Mensagem da recusa do teto exatamente como o domínio a devolve.
 */
function physicalProgressLimitError(callable $approval, int $planSetId): string
{
    try {
        $approval();
    } catch (ValidationException $exception) {
        return $exception->errors()["realized.{$planSetId}"][0] ?? '';
    }

    test()->fail('A aprovação deveria ter sido recusada.');
}

/**
 * Recusa da aprovação como o domínio a devolve, ou `null` quando a Engenharia
 * aceitou -- para o dataset em que o mesmo arranjo ora passa, ora é recusado.
 */
function physicalProgressApprovalRefusal(callable $approval, int $planSetId): ?string
{
    try {
        $approval();
    } catch (ValidationException $exception) {
        return $exception->errors()["realized.{$planSetId}"][0] ?? '';
    }

    return null;
}

// ── Avanço físico inicial do plano ───────────────────────────────────────────

it('starts a plan without initial physical progress at 0.00 and without reference date', function () {
    $planSet = MeasurementPlanSet::factory()->create();

    expect($planSet->fresh()->initial_physical_progress_percent)->toBe('0.00')
        ->and($planSet->fresh()->initial_physical_progress_reference_date)->toBeNull();
});

it('records a positive initial physical progress with its reference date', function () {
    $planSet = MeasurementPlanSet::factory()->withInitialPhysicalProgress('35.5', '2026-04-30')->create();

    expect($planSet->fresh()->initial_physical_progress_percent)->toBe('35.50')
        ->and($planSet->fresh()->initial_physical_progress_reference_date->toDateString())->toBe('2026-04-30');
});

it('accepts a plan that starts at exactly 100%', function () {
    $planSet = MeasurementPlanSet::factory()->withInitialPhysicalProgress('100', '2026-04-30')->create();

    expect($planSet->fresh()->initial_physical_progress_percent)->toBe('100.00');
});

it('rejects an initial physical progress outside 0% to 100% or with more than two decimals', function (string $percent) {
    $operation = Operation::factory()->create();

    expect(fn () => MeasurementPlanSet::factory()->withInitialPhysicalProgress($percent, '2026-04-30')->create(['operation_id' => $operation->id]))
        ->toThrow(ValidationException::class, 'Informe o avanço físico inicial entre 0,00% e 100,00%, com no máximo duas casas decimais.')
        ->and(MeasurementPlanSet::query()->where('operation_id', $operation->id)->exists())->toBeFalse();
})->with([
    'just below zero' => ['-0.01'],
    'negative' => ['-5'],
    'just above 100%' => ['100.01'],
    'far above 100%' => ['150'],
    'three decimals' => ['35.555'],
]);

it('requires the reference date when the initial physical progress is positive', function () {
    $operation = Operation::factory()->create();

    expect(fn () => MeasurementPlanSet::factory()->create([
        'operation_id' => $operation->id,
        'initial_physical_progress_percent' => '35.00',
        'initial_physical_progress_reference_date' => null,
    ]))->toThrow(ValidationException::class, 'Informe a data de referência do avanço físico inicial.')
        ->and(MeasurementPlanSet::query()->where('operation_id', $operation->id)->exists())->toBeFalse();
});

it('refuses to change the initial physical progress after creation', function (array $change) {
    $planSet = MeasurementPlanSet::factory()->withInitialPhysicalProgress('35.00', '2026-04-30')->create();

    expect(fn () => $planSet->fresh()->update($change))
        ->toThrow(MeasurementWorkflowException::class, 'O avanço físico inicial do plano não pode ser alterado depois da criação.');

    expect($planSet->fresh()->initial_physical_progress_percent)->toBe('35.00')
        ->and($planSet->fresh()->initial_physical_progress_reference_date->toDateString())->toBe('2026-04-30');
})->with([
    'percent' => [['initial_physical_progress_percent' => '40.00']],
    'reference date' => [['initial_physical_progress_reference_date' => '2026-05-31']],
    'back to zero' => [['initial_physical_progress_percent' => 0, 'initial_physical_progress_reference_date' => null]],
]);

it('keeps the plan editable apart from the initial physical progress', function () {
    $planSet = MeasurementPlanSet::factory()->withInitialPhysicalProgress('35.00', '2026-04-30')->create(['name' => 'Plano antigo']);

    $planSet->fresh()->update(['name' => 'Plano novo', 'initial_physical_progress_percent' => '35']);

    expect($planSet->fresh()->name)->toBe('Plano novo')
        ->and($planSet->fresh()->initial_physical_progress_percent)->toBe('35.00');
});

it('sets the initial physical progress only on the plans the operation sync creates', function () {
    $actor = makeAdminUser();
    $emission = Emission::factory()->create();
    $existing = Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Torre A']);
    $new = Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Torre B']);
    $operation = Operation::factory()->forEmission($emission)->create();
    $existingPlan = MeasurementPlanSet::factory()->withInitialPhysicalProgress('20.00', '2026-03-31')->create([
        'operation_id' => $operation->id,
        'construction_id' => $existing->id,
    ]);
    $existingDraft = $existingPlan->draftVersion()->sole();

    // O formulário da operação devolve, do plano existente, o fundo que mostrou e
    // o contador do rascunho da V1 que leu: só assim o fundo do rascunho muda.
    $operation->syncDevelopmentPlans([
        [
            'construction_id' => $existing->id,
            'construction_fund_amount' => 100,
            'construction_fund_original' => $existingDraft->construction_fund_amount,
            'construction_fund_revision' => $existingDraft->revision,
            'initial_physical_progress_percent' => 70,
            'initial_physical_progress_reference_date' => '2026-09-30',
        ],
        ['construction_id' => $new->id, 'construction_fund_amount' => 200, 'initial_physical_progress_percent' => 45, 'initial_physical_progress_reference_date' => '2026-09-30'],
    ], $actor);

    $newPlan = $operation->planSets()->where('construction_id', $new->id)->sole();

    expect($existingPlan->fresh()->initial_physical_progress_percent)->toBe('20.00')
        ->and($existingPlan->fresh()->initial_physical_progress_reference_date->toDateString())->toBe('2026-03-31')
        ->and($existingPlan->fresh()->currentConstructionFundAmount())->toBe('100.00')
        ->and($newPlan->initial_physical_progress_percent)->toBe('45.00')
        ->and($newPlan->initial_physical_progress_reference_date->toDateString())->toBe('2026-09-30')
        ->and($newPlan->currentConstructionFundAmount())->toBe('200.00');
});

it('records who created the plan, when, and its initial physical progress in the protected trail', function () {
    $actor = makeAdminUser();
    $this->actingAs($actor);

    $planSet = MeasurementPlanSet::factory()->withInitialPhysicalProgress('35.00', '2026-04-30')->create();

    $creation = Activity::query()
        ->where('subject_type', MeasurementPlanSet::class)
        ->where('subject_id', $planSet->id)
        ->where('event', 'created')
        ->sole();

    expect($creation->log_name)->toBe('measurements')
        ->and($creation->causer_id)->toBe($actor->id)
        ->and($creation->created_at)->not->toBeNull()
        ->and($creation->attribute_changes['attributes']['initial_physical_progress_percent'] ?? null)->toBe('35.00')
        ->and(substr((string) ($creation->attribute_changes['attributes']['initial_physical_progress_reference_date'] ?? ''), 0, 10))->toBe('2026-04-30');
});

// ── Acumulado ────────────────────────────────────────────────────────────────

it('adds the first measurement to a zero initial physical progress', function () {
    $scenario = Scenario::plan();

    $may = Scenario::measured($scenario, '2026-05', 10);

    expect(Scenario::progress($scenario)->currentPercent())->toBe('10.00')
        ->and($scenario['lines']['2026-05']->fresh()->realized_cumulative_percent)->toBe('10.00')
        ->and($may->engineering_snapshot['plan_sets'][0]['realized_cumulative_percent'])->toBe('10.00');
});

it('adds the first measurement to the initial physical progress of a construction already underway', function () {
    $scenario = Scenario::plan(initialPercent: '30.00');

    Scenario::measured($scenario, '2026-05', 5);

    $progress = Scenario::progress($scenario);

    expect($progress->initialPercent())->toBe('30.00')
        ->and($progress->measuredPercent())->toBe('5.00')
        ->and($progress->currentPercent())->toBe('35.00')
        ->and($scenario['lines']['2026-05']->fresh()->realized_cumulative_percent)->toBe('35.00');
});

it('accumulates successive measurements over the initial physical progress', function () {
    $scenario = Scenario::plan(initialPercent: '30.00');

    Scenario::measured($scenario, '2026-05', 5);
    Scenario::measured($scenario, '2026-06', 4);

    expect(Scenario::progress($scenario)->currentPercent())->toBe('39.00')
        ->and($scenario['lines']['2026-06']->fresh()->realized_cumulative_percent)->toBe('39.00');
});

it('keeps the cumulative progress through a month without measurement', function (string $initial, string $may, string $june, string $july) {
    $scenario = Scenario::plan(initialPercent: $initial);

    Scenario::measured($scenario, '2026-05', 10);
    Scenario::measured($scenario, '2026-07', 5);

    $progress = Scenario::progress($scenario);
    $lines = $scenario['lines'];

    expect($progress->cumulativeThroughPosition($lines['2026-05']->measurement_date, $lines['2026-05']->sequence_number))->toBe((int) bcmul($may, '100'))
        ->and($progress->cumulativeThroughPosition($lines['2026-06']->measurement_date, $lines['2026-06']->sequence_number))->toBe((int) bcmul($june, '100'))
        ->and($progress->contributionsForLine($lines['2026-06']->id))->toBe([])
        ->and($progress->currentPercent())->toBe($july)
        ->and($scenario['lines']['2026-07']->fresh()->realized_cumulative_percent)->toBe($july);
})->with([
    'without initial progress' => ['0.00', '10.00', '10.00', '15.00'],
    'with 25% initial progress' => ['25.00', '35.00', '35.00', '40.00'],
]);

it('accumulates by schedule position when an earlier month is approved later', function () {
    $scenario = Scenario::plan();

    Scenario::measured($scenario, '2026-07', 5);
    Scenario::measured($scenario, '2026-05', 10);

    $progress = Scenario::progress($scenario);

    expect($progress->currentPercent())->toBe('15.00')
        ->and($scenario['lines']['2026-05']->fresh()->realized_cumulative_percent)->toBe('10.00')
        ->and($progress->cumulativeThroughPosition($scenario['lines']['2026-07']->measurement_date, 3))->toBe(1500);
});

it('accumulates by competence even when a schedule line was registered out of order', function () {
    $scenario = Scenario::plan(months: ['2026-05', '2026-07', '2026-06']);

    Scenario::measured($scenario, '2026-05', 10);
    Scenario::measured($scenario, '2026-07', 5);
    Scenario::measured($scenario, '2026-06', 4);

    $lines = $scenario['lines'];

    expect($lines['2026-06']->fresh()->realized_cumulative_percent)->toBe('14.00')
        ->and($lines['2026-07']->fresh()->realized_cumulative_percent)->toBe('15.00')
        ->and(Scenario::progress($scenario)->cumulativeThroughPosition($lines['2026-07']->measurement_date, $lines['2026-07']->sequence_number))->toBe(1900)
        ->and(Scenario::progress($scenario)->currentPercent())->toBe('19.00');
});

it('records in the Engineering snapshot the initial progress and the prior cumulative behind the limit', function () {
    $scenario = Scenario::plan(initialPercent: '30.00');
    Scenario::measured($scenario, '2026-05', 10);

    $june = Scenario::measured($scenario, '2026-06', 5);

    expect($june->engineering_snapshot['plan_sets'][0])->toMatchArray([
        'plan_initial_physical_progress_percent' => '30.00',
        'prior_realized_cumulative_percent' => '40.00',
        'realized_monthly_percent' => '5.00',
        'realized_cumulative_percent' => '45.00',
    ]);
});

it('accepts a month without progress and keeps the cumulative', function () {
    $scenario = Scenario::plan(initialPercent: '30.00');
    Scenario::measured($scenario, '2026-05', 10);

    $june = Scenario::measured($scenario, '2026-06', 0);

    $progress = Scenario::progress($scenario);

    expect($june->current_stage)->toBe(2)
        ->and($scenario['lines']['2026-06']->fresh()->realized_monthly_percent)->toBe('0.00')
        ->and($scenario['lines']['2026-06']->fresh()->realized_cumulative_percent)->toBe('40.00')
        ->and($progress->lineClaimant($scenario['lines']['2026-06']->id))->toBe($june->id)
        ->and($progress->currentPercent())->toBe('40.00');
});

it('treats as covered by the initial physical progress only a competence that ends by its reference date', function (string $referenceDate, ?string $refusal, string $mayCumulative, string $current) {
    $scenario = Scenario::plan(initialPercent: '35.00', referenceDate: $referenceDate);
    $may = Scenario::measurement($scenario, '2026-05');

    $error = physicalProgressApprovalRefusal(fn () => Scenario::approveEngineering($scenario, $may, 5), $scenario['planSet']->id);
    Scenario::measured($scenario, '2026-06', 5);

    expect($error)->toBe($refusal)
        ->and($scenario['lines']['2026-05']->fresh()->realized_cumulative_percent)->toBe($mayCumulative)
        ->and(Scenario::progress($scenario)->currentPercent())->toBe($current);
})->with([
    'reference at the end of the previous month' => ['2026-04-30', null, '40.00', '45.00'],
    // Maio ainda tem trabalho depois de 15/05, fora do avanço inicial: a competência não termina até a referência.
    'reference in the middle of the competence' => ['2026-05-15', null, '40.00', '45.00'],
    'reference on the last day of the competence' => ['2026-05-31', 'A competência 05/2026 de Plano padrão já está coberta pelo avanço físico inicial de 35,00% (referência 31/05/2026).', '0.00', '40.00'],
]);

it('counts an initial physical progress copied without reference date from the start without covering any competence', function () {
    $scenario = Scenario::plan();
    $april = CarbonImmutable::parse('2026-04-30');
    $knownBeforeTheCopy = Scenario::progress($scenario)->isKnownThroughDate($april);

    // Estado que a migration deixa nos planos copiados: percentual sem data. Sem
    // data não há competência coberta, e o inicial vale desde antes da primeira medição.
    Scenario::copiedInitialProgress($scenario, '35.00');
    $may = Scenario::measured($scenario, '2026-05', 5);

    $progress = Scenario::progress($scenario);

    expect($may->current_stage)->toBe(2)
        ->and($progress->initialReferenceDate)->toBeNull()
        ->and($progress->currentPercent())->toBe('40.00')
        ->and($scenario['lines']['2026-05']->fresh()->realized_cumulative_percent)->toBe('40.00')
        ->and($may->engineering_snapshot['plan_sets'][0])->toMatchArray([
            'plan_initial_physical_progress_percent' => '35.00',
            'prior_realized_cumulative_percent' => '35.00',
            'realized_monthly_percent' => '5.00',
            'realized_cumulative_percent' => '40.00',
        ])
        ->and($knownBeforeTheCopy)->toBeFalse()
        ->and($progress->isKnownThroughDate($april))->toBeTrue()
        ->and($progress->cumulativeThroughDate($april))->toBe(3500);
});

// ── Teto de 100% ─────────────────────────────────────────────────────────────

it('accepts progress that reaches exactly 100%', function () {
    $scenario = Scenario::plan(initialPercent: '99.00');

    Scenario::measured($scenario, '2026-05', 1);

    $progress = Scenario::progress($scenario);

    expect($progress->currentPercent())->toBe('100.00')
        ->and($progress->remainingPercent())->toBe('0.00')
        ->and($scenario['lines']['2026-05']->fresh()->realized_cumulative_percent)->toBe('100.00');
});

it('rejects progress above 100% explaining the current, entered and remaining percentages', function () {
    $scenario = Scenario::plan(initialPercent: '99.00');
    $measurement = Scenario::measurement($scenario, '2026-05');
    $revision = $measurement->workflow_revision;

    $error = physicalProgressLimitError(
        fn () => Scenario::approveEngineering($scenario, $measurement, '1.01'),
        $scenario['planSet']->id,
    );

    $measurement->refresh();

    expect($error)->toBe('O percentual físico informado para Plano padrão ultrapassaria o limite de 100% do empreendimento. Progresso atual: 99,00%. Percentual informado: 1,01%. Máximo restante: 1,00%.')
        ->and($measurement->engineering_snapshot)->toBeNull()
        ->and($measurement->current_stage)->toBe(MeasurementWorkflow::STAGE_ENGINEERING)
        ->and($measurement->workflow_revision)->toBe($revision)
        ->and($measurement->reviewForStage(1)?->status)->toBe('pending')
        ->and($scenario['lines']['2026-05']->fresh()->measurement_id)->toBeNull()
        ->and(Scenario::progress($scenario)->currentPercent())->toBe('99.00');
});

it('rejects the measurement that would take a construction from 95% to 101%', function () {
    $scenario = Scenario::plan(initialPercent: '35.00');
    Scenario::measured($scenario, '2026-05', 60);
    $june = Scenario::measurement($scenario, '2026-06');

    $error = physicalProgressLimitError(fn () => Scenario::approveEngineering($scenario, $june, 6), $scenario['planSet']->id);

    expect($error)->toContain('Progresso atual: 95,00%. Percentual informado: 6,00%. Máximo restante: 5,00%.')
        ->and(Scenario::progress($scenario)->currentPercent())->toBe('95.00');
});

it('rejects any positive progress for a construction registered at 100%', function () {
    $scenario = Scenario::plan(initialPercent: '100.00');
    $measurement = Scenario::measurement($scenario, '2026-05');

    $error = physicalProgressLimitError(fn () => Scenario::approveEngineering($scenario, $measurement, '0.01'), $scenario['planSet']->id);

    expect($error)->toContain('Progresso atual: 100,00%. Percentual informado: 0,01%. Máximo restante: 0,00%.');
});

it('accepts 0% in a competence covered by the initial physical progress without blocking another development', function () {
    $actor = makeAdminUser();
    $actor->givePermissionTo(['measurements.create', 'measurements.review']);
    config()->set('filesystems.private_disk', 'local');
    $operation = Operation::factory()->create(['status' => 'active', 'assigned_user_id' => $actor->id, 'responsible_user_id' => $actor->id]);
    $covered = MeasurementPlanSet::factory()->default()->withInitialPhysicalProgress('35.00', '2026-05-31')->create(['operation_id' => $operation->id, 'name' => 'Torre A']);
    $other = MeasurementPlanSet::factory()->create(['operation_id' => $operation->id, 'name' => 'Torre B']);
    $measurement = Measurement::factory()->create(['operation_id' => $operation->id, 'reference_month' => '2026-05-01', 'status' => 'pending', 'current_stage' => 1, 'storage_path' => null, 'filename' => null]);

    foreach ([$covered, $other] as $planSet) {
        $line = MeasurementPlanLine::factory()->create([
            'operation_id' => $operation->id, 'plan_set_id' => $planSet->id, 'sequence_number' => 1,
            'measurement_date' => '2026-05-01', 'initial_realized_cumulative_percent' => 0,
            'realized_monthly_percent' => 0, 'realized_cumulative_percent' => 0,
        ]);
        // O plano só recebe medição depois de ativado: a linha entra no rascunho da V1.
        MeasurementPlanVersionFixture::activate($planSet);
        Storage::disk('local')->put("nimbus_docs/measurements/assets/covered-{$planSet->id}.pdf", "%PDF-1.7 torre {$planSet->id}");
        $measurement->assets()->create(['plan_set_id' => $planSet->id, 'plan_line_id' => $line->id, 'storage_path' => "nimbus_docs/measurements/assets/covered-{$planSet->id}.pdf", 'storage_disk' => 'local']);
    }

    app(MeasurementWorkflow::class)->startReview($measurement, $actor);
    app(MeasurementWorkflow::class)->approve($measurement->fresh(), $actor, engineeringProgress: [$covered->id => 0, $other->id => 10]);
    $progress = app(MeasurementPhysicalProgressService::class)->forOperation($operation);

    expect($measurement->fresh()->current_stage)->toBe(2)
        ->and($progress[$covered->id]->currentPercent())->toBe('35.00')
        ->and($progress[$other->id]->currentPercent())->toBe('10.00');
});

it('covers no competence with a reference date when the initial physical progress is zero', function () {
    $scenario = Scenario::plan(initialPercent: '0.00', referenceDate: '2026-05-31');

    Scenario::measured($scenario, '2026-05', 5);

    expect(Scenario::progress($scenario)->currentPercent())->toBe('5.00');
});

it('refuses a reference date of the initial physical progress in the future', function () {
    $operation = Operation::factory()->create();

    expect(fn () => MeasurementPlanSet::factory()->withInitialPhysicalProgress('35.00', '2099-12-31')->create(['operation_id' => $operation->id]))
        ->toThrow(ValidationException::class, 'A data de referência do avanço físico inicial não pode ser futura.')
        ->and(MeasurementPlanSet::query()->where('operation_id', $operation->id)->exists())->toBeFalse();
});

it('answers an absurdly large percentage with a validation message instead of an error', function () {
    $scenario = Scenario::plan();
    $measurement = Scenario::measurement($scenario, '2026-05');
    $operation = Operation::factory()->create();

    expect(physicalProgressLimitError(fn () => Scenario::approveEngineering($scenario, $measurement, '99999999999999999'), $scenario['planSet']->id))
        ->toBe('Informe para Plano padrão o percentual realizado com no máximo duas casas decimais.')
        ->and(fn () => MeasurementPlanSet::factory()->withInitialPhysicalProgress('99999999999999999')->create(['operation_id' => $operation->id]))
        ->toThrow(ValidationException::class, 'Informe o avanço físico inicial entre 0,00% e 100,00%, com no máximo duas casas decimais.');
});

it('refuses missing, negative, above-100% and three-decimal monthly progress', function (mixed $percent, string $message) {
    $scenario = Scenario::plan();
    $measurement = Scenario::measurement($scenario, '2026-05');

    expect(physicalProgressLimitError(fn () => app(MeasurementWorkflow::class)->approve(
        $measurement->fresh(),
        $scenario['actor'],
        engineeringProgress: $percent === 'missing' ? [] : [$scenario['planSet']->id => $percent],
    ), $scenario['planSet']->id))->toBe($message);
})->with([
    'missing' => ['missing', 'Informe o percentual realizado de Plano padrão.'],
    'negative correction' => ['-5', 'O percentual realizado de Plano padrão não pode ser negativo. Para corrigir uma medição já aprovada, devolva-a à Engenharia.'],
    'above 100%' => ['100.01', 'Informe para Plano padrão um percentual realizado de no máximo 100%.'],
    'three decimals' => ['1.005', 'Informe para Plano padrão o percentual realizado com no máximo duas casas decimais.'],
]);

it('lets a construction at 100% approve a month without progress so the other developments keep going', function () {
    $actor = makeAdminUser();
    $actor->givePermissionTo(['measurements.create', 'measurements.review']);
    config()->set('filesystems.private_disk', 'local');
    $operation = Operation::factory()->create(['status' => 'active', 'assigned_user_id' => $actor->id, 'responsible_user_id' => $actor->id]);
    $finished = MeasurementPlanSet::factory()->default()->withInitialPhysicalProgress('100.00')->create(['operation_id' => $operation->id, 'name' => 'Torre concluída']);
    $ongoing = MeasurementPlanSet::factory()->create(['operation_id' => $operation->id, 'name' => 'Torre em obra']);
    $measurement = Measurement::factory()->create(['operation_id' => $operation->id, 'reference_month' => '2026-05-01', 'status' => 'pending', 'current_stage' => 1, 'storage_path' => null, 'filename' => null]);

    foreach ([$finished, $ongoing] as $planSet) {
        $line = MeasurementPlanLine::factory()->create([
            'operation_id' => $operation->id, 'plan_set_id' => $planSet->id, 'sequence_number' => 1,
            'measurement_date' => '2026-05-01', 'initial_realized_cumulative_percent' => 0,
            'realized_monthly_percent' => 0, 'realized_cumulative_percent' => 0,
        ]);
        // O plano só recebe medição depois de ativado: a linha entra no rascunho da V1.
        MeasurementPlanVersionFixture::activate($planSet);
        Storage::disk('local')->put("nimbus_docs/measurements/assets/finished-{$planSet->id}.pdf", "%PDF-1.7 torre {$planSet->id}");
        $measurement->assets()->create(['plan_set_id' => $planSet->id, 'plan_line_id' => $line->id, 'storage_path' => "nimbus_docs/measurements/assets/finished-{$planSet->id}.pdf", 'storage_disk' => 'local']);
    }

    app(MeasurementWorkflow::class)->startReview($measurement, $actor);
    app(MeasurementWorkflow::class)->approve($measurement->fresh(), $actor, engineeringProgress: [$finished->id => 0, $ongoing->id => 10]);
    $progress = app(MeasurementPhysicalProgressService::class)->forOperation($operation);

    expect($measurement->fresh()->current_stage)->toBe(2)
        ->and($progress[$finished->id]->currentPercent())->toBe('100.00')
        ->and($progress[$ongoing->id]->currentPercent())->toBe('10.00');
});

it('accepts a month without progress and refuses any other once the current approvals already exceed 100%', function () {
    $scenario = Scenario::plan();

    // Aprovações vigentes que somam 110%, como o motor antigo as aceitava (P1-03).
    // O snapshot gravado direto traz só o que o serviço lê dele; arquivo não é
    // preciso, porque ele só é consultado nas aprovações anteriores ao snapshot.
    foreach (['2026-05' => '60.00', '2026-06' => '50.00'] as $month => $percent) {
        $line = $scenario['lines'][$month];
        $approved = Measurement::factory()->create([
            'operation_id' => $scenario['operation']->id,
            'reference_month' => $line->measurement_date->toDateString(),
            'status' => 'in_review',
            'current_stage' => 2,
            'engineering_snapshot' => [
                'schema_version' => MeasurementEngineeringService::SNAPSHOT_SCHEMA_VERSION,
                'plan_sets' => [[
                    'plan_set_id' => $scenario['planSet']->id,
                    'plan_line_id' => $line->id,
                    'sequence_number' => $line->sequence_number,
                    'measurement_date' => $line->measurement_date->toDateString(),
                    'realized_monthly_percent' => $percent,
                ]],
            ],
        ]);
        $approved->reviews()->create(['stage' => 1, 'status' => 'approved', 'reviewer_user_id' => $scenario['actor']->id]);
    }

    $july = Scenario::measurement($scenario, '2026-07');

    $error = physicalProgressLimitError(fn () => Scenario::approveEngineering($scenario, $july, '0.01'), $scenario['planSet']->id);
    Scenario::approveEngineering($scenario, $july, 0);

    $progress = Scenario::progress($scenario);

    expect($error)->toContain('Progresso atual: 110,00%. Percentual informado: 0,01%. Máximo restante: 0,00%.')
        ->and($july->fresh()->current_stage)->toBe(2)
        ->and($scenario['lines']['2026-07']->fresh()->realized_cumulative_percent)->toBe('110.00')
        ->and($progress->currentPercent())->toBe('110.00')
        ->and($progress->remainingPercent())->toBe('0.00');
});

// ── Medições que não valem ───────────────────────────────────────────────────

it('ignores measurements whose Engineering approval is still pending', function () {
    $scenario = Scenario::plan(initialPercent: '20.00');
    Scenario::measurement($scenario, '2026-05');

    expect(Scenario::progress($scenario)->currentPercent())->toBe('20.00');
});

it('ignores a measurement rejected by Engineering even after an earlier approval', function () {
    $scenario = Scenario::plan();
    $may = Scenario::measured($scenario, '2026-05', 10);

    Scenario::returnToEngineering($scenario, $may);
    app(MeasurementWorkflow::class)->reject($may->fresh(), $scenario['actor'], 'Medição recusada pela Engenharia.');

    expect($may->fresh()->status)->toBe('rejected')
        ->and($scenario['lines']['2026-05']->fresh()->measurement_id)->toBe($may->id)
        ->and(Scenario::progress($scenario)->currentPercent())->toBe('0.00');
});

it('stops counting a measurement returned to Engineering while keeping its history', function () {
    $scenario = Scenario::plan(initialPercent: '30.00');
    Scenario::measured($scenario, '2026-05', 10);
    $june = Scenario::measured($scenario, '2026-06', 5);
    $approvedSnapshot = $june->engineering_snapshot;

    expect(Scenario::progress($scenario)->currentPercent())->toBe('45.00');

    Scenario::returnToEngineering($scenario, $june);

    $june->refresh();
    $invalidation = Activity::query()
        ->where('log_name', 'measurement_workflow')
        ->where('subject_id', $june->id)
        ->where('description', 'measurement_engineering_snapshot_invalidated')
        ->sole();

    expect(Scenario::progress($scenario)->currentPercent())->toBe('40.00')
        ->and($june->engineering_snapshot)->toBeNull()
        ->and($june->reviewForStage(1)?->status)->toBe('pending')
        ->and($scenario['lines']['2026-06']->fresh()->measurement_id)->toBe($june->id)
        ->and($scenario['lines']['2026-06']->fresh()->realized_monthly_percent)->toBe('5.00')
        ->and($invalidation->properties['engineering_snapshot'])->toBe($approvedSnapshot);
});

it('stops counting a measurement that Finalization sends back to Engineering', function () {
    $scenario = MeasurementReceiptEvidenceScenario::open();
    $planSet = $scenario['operation']->planSets()->sole();
    $service = app(MeasurementPhysicalProgressService::class);

    expect($service->forPlanSet($planSet)->currentPercent())->toBe('10.00');

    app(MeasurementWorkflow::class)->returnToStage($scenario['measurement']->fresh(), $scenario['actor'], 1, 'Refazer a medição.');

    expect($service->forPlanSet($planSet)->currentPercent())->toBe('0.00');
});

it('keeps counting a measurement while the later stages decide', function () {
    $scenario = Scenario::plan(initialPercent: '30.00');
    $may = Scenario::measured($scenario, '2026-05', 10);
    $workflow = app(MeasurementWorkflow::class);

    $workflow->approve($may->fresh(), $scenario['actor']);
    $workflow->reject($may->fresh(), $scenario['actor'], 'Compliance devolve para a Gestão.');
    $afterComplianceReturn = Scenario::progress($scenario)->currentPercent();
    $workflow->pause($may->fresh(), $scenario['actor'], 'Aguardando documento da Gestão.');

    expect($may->fresh()->current_stage)->toBe(2)
        ->and($may->fresh()->status)->toBe('paused')
        ->and($afterComplianceReturn)->toBe('40.00')
        ->and(Scenario::progress($scenario)->currentPercent())->toBe('40.00');
});

it('keeps the Finalization of an approved measurement valid after another month is approved', function () {
    // Abril é competência anterior a maio, cadastrada depois dele no cronograma
    // (sequência 2). Com o plano vigente, a linha só entra no cronograma antes da
    // ativação -- uma revisão não acrescenta competência anterior à vigência.
    $scenario = Scenario::plan(['2026-05', '2026-04']);
    $workflow = app(MeasurementWorkflow::class);
    $may = Scenario::measured($scenario, '2026-05', 10);
    $workflow->approve($may->fresh(), $scenario['actor']);
    $workflow->approve($may->fresh(), $scenario['actor']);
    $payment = $workflow->registerPayment($may->fresh(), $scenario['actor'], ['plan_set_id' => $scenario['planSet']->id, 'pay_date' => '2026-05-20', 'amount' => '100000.00', 'method' => 'TED']);
    $workflow->approve($may->fresh(), $scenario['actor']);

    Scenario::measured($scenario, '2026-04', 3);
    $workflow->attachReceipt($payment->fresh(), $scenario['actor'], MeasurementReceiptEvidenceScenario::file());
    MeasurementReceiptEvidenceScenario::approveCurrentReceipt($payment, $scenario['actor']);
    $workflow->finalize($may->fresh(), $scenario['actor']);

    expect($may->fresh()->status)->toBe('finalized')
        ->and($scenario['lines']['2026-04']->fresh()->realized_cumulative_percent)->toBe('3.00')
        ->and(Scenario::progress($scenario)->currentPercent())->toBe('13.00');
});

it('keeps the financial expected amount on the monthly percent regardless of the initial progress', function () {
    $scenario = Scenario::plan(initialPercent: '35.00');

    $may = Scenario::measured($scenario, '2026-05', 10);

    $line = app(MeasurementFinancialReconciliationService::class)->forMeasurement($may)->line($scenario['planSet']->id);

    expect($line->realizedMonthlyPercent)->toBe('10.00')
        ->and($line->expectedAmount)->toBe('100000.00');
});

it('counts a measurement approved again by Engineering exactly once', function (string $percent, string $expected) {
    $scenario = Scenario::plan(initialPercent: '30.00');
    Scenario::measured($scenario, '2026-05', 10);
    $june = Scenario::measured($scenario, '2026-06', 5);

    Scenario::returnToEngineering($scenario, $june);
    Scenario::approveEngineering($scenario, $june, $percent);

    expect(Scenario::progress($scenario)->currentPercent())->toBe($expected)
        ->and($scenario['lines']['2026-06']->fresh()->realized_cumulative_percent)->toBe($expected);
})->with([
    'same percent' => ['5', '45.00'],
    'corrected percent' => ['3', '43.00'],
]);

it('counts a measurement once when it is approved again on another schedule line', function () {
    $scenario = Scenario::plan(initialPercent: '30.00');
    $june = Scenario::measured($scenario, '2026-06', 5);

    Scenario::returnToEngineering($scenario, $june);
    $june->refresh();
    $june->assets()->sole()->update(['plan_line_id' => $scenario['lines']['2026-07']->id]);
    $june->update(['reference_month' => '2026-07-01']);
    Scenario::approveEngineering($scenario, $june, 5);

    $progress = Scenario::progress($scenario);

    expect($progress->currentPercent())->toBe('35.00')
        ->and($scenario['lines']['2026-06']->fresh()->measurement_id)->toBe($june->id)
        ->and($progress->contributionsForLine($scenario['lines']['2026-06']->id))->toBe([])
        ->and($progress->lineClaimant($scenario['lines']['2026-07']->id))->toBe($june->id);
});

it('does not count a measurement twice on repeated reads or a retried approval', function () {
    $scenario = Scenario::plan();
    $may = Scenario::measurement($scenario, '2026-05');
    $revision = $may->workflow_revision;

    Scenario::approveEngineering($scenario, $may, 10);

    expect(fn () => app(MeasurementWorkflow::class)->approve(
        $may->fresh(),
        $scenario['actor'],
        engineeringProgress: [$scenario['planSet']->id => 10],
        expectedStage: MeasurementWorkflow::STAGE_ENGINEERING,
        expectedRevision: $revision,
    ))->toThrow(MeasurementWorkflowException::class);

    expect(Scenario::progress($scenario)->currentPercent())->toBe('10.00')
        ->and(Scenario::progress($scenario)->currentPercent())->toBe('10.00')
        ->and(Scenario::progress($scenario)->contributions)->toHaveCount(1);
});

it('refuses a second measurement on a schedule line already measured by Engineering', function () {
    $scenario = Scenario::plan();
    $first = Scenario::measured($scenario, '2026-05', 10);

    // A ocupação da medição prevista nasce no envio: o arquivo da segunda
    // medição na mesma linha é recusado antes de chegar à Engenharia.
    expect(fn () => Scenario::measurement($scenario, '2026-05'))
        ->toThrow(MeasurementWorkflowException::class, sprintf(MeasurementAsset::LINE_ALREADY_CLAIMED_REFUSAL, '01', '05/2026', 'Plano padrão', $first->id));

    // Arquivo gravado antes de a ocupação existir (`line_claim_key` vazio), na
    // mesma linha: a Engenharia continua recusando a segunda aprovação.
    $second = Scenario::measurement($scenario, '2026-06');
    DB::table('measurement_assets')->where('measurement_id', $second->id)->update([
        'plan_line_id' => $scenario['lines']['2026-05']->id,
        'line_claim_key' => null,
    ]);
    DB::table('measurements')->where('id', $second->id)->update(['reference_month' => '2026-05-01']);

    $error = physicalProgressLimitError(fn () => Scenario::approveEngineering($scenario, $second, 4), $scenario['planSet']->id);

    expect($error)->toBe("A medição 01 (05/2026) do cronograma de Plano padrão já está vinculada à medição #{$first->id}, aprovada pela Engenharia. Recuse esta medição ou corrija a linha do cronograma escolhida no envio.")
        ->and($scenario['lines']['2026-05']->fresh()->measurement_id)->toBe($first->id)
        ->and($second->fresh()->engineering_snapshot)->toBeNull()
        ->and(Scenario::progress($scenario)->currentPercent())->toBe('10.00');
});

// ── Fontes da conta ──────────────────────────────────────────────────────────

it('counts an Engineering approval recorded before the snapshot existed from its schedule line', function () {
    $scenario = Scenario::plan(initialPercent: '20.00');
    $legacy = Measurement::factory()->create([
        'operation_id' => $scenario['operation']->id,
        'reference_month' => '2026-05-01',
        'status' => 'in_review',
        'current_stage' => 2,
        'engineering_snapshot' => null,
    ]);
    $legacy->reviews()->create(['stage' => 1, 'status' => 'approved', 'reviewer_user_id' => $scenario['actor']->id]);
    DB::table('measurement_plan_lines')->where('id', $scenario['lines']['2026-05']->id)->update([
        'measurement_id' => $legacy->id,
        'realized_monthly_percent' => 7,
        'realized_cumulative_percent' => 27,
    ]);

    $progress = Scenario::progress($scenario);

    expect($progress->currentPercent())->toBe('27.00')
        ->and($progress->contributions)->toHaveCount(1)
        ->and($progress->contributions[0])->toBeInstanceOf(MeasurementPhysicalProgressContribution::class)
        ->and($progress->contributions[0]->legacy)->toBeTrue();
});

it('refuses to approve while an approval older than the snapshot left no schedule line for the plan', function () {
    $scenario = Scenario::plan();
    $legacy = Measurement::factory()->create([
        'operation_id' => $scenario['operation']->id,
        'reference_month' => '2026-05-01',
        'status' => 'in_review',
        'current_stage' => 2,
        'storage_path' => null,
        'filename' => null,
        'engineering_snapshot' => null,
    ]);
    Storage::disk('local')->put("nimbus_docs/measurements/assets/legacy-{$legacy->id}.pdf", '%PDF-1.7 legado');
    $legacy->assets()->create(['plan_set_id' => $scenario['planSet']->id, 'plan_line_id' => $scenario['lines']['2026-05']->id, 'storage_path' => "nimbus_docs/measurements/assets/legacy-{$legacy->id}.pdf", 'storage_disk' => 'local']);
    $legacy->reviews()->create(['stage' => 1, 'status' => 'approved', 'reviewer_user_id' => $scenario['actor']->id]);
    $june = Scenario::measurement($scenario, '2026-06');

    $error = physicalProgressLimitError(fn () => Scenario::approveEngineering($scenario, $june, 5), $scenario['planSet']->id);

    expect($error)->toBe("Não foi possível conferir o limite de 100% de Plano padrão: a medição #{$legacy->id} tem a Engenharia aprovada sem o avanço físico registrado.");
});

it('refuses to approve while an approved measurement of the plan has no readable progress', function () {
    $scenario = Scenario::plan();
    $broken = Measurement::factory()->create([
        'operation_id' => $scenario['operation']->id,
        'reference_month' => '2026-05-01',
        'status' => 'in_review',
        'current_stage' => 2,
        'engineering_snapshot' => ['schema_version' => 2, 'plan_sets' => [['plan_set_id' => $scenario['planSet']->id, 'realized_monthly_percent' => 'dez']]],
    ]);
    $broken->reviews()->create(['stage' => 1, 'status' => 'approved', 'reviewer_user_id' => $scenario['actor']->id]);
    $june = Scenario::measurement($scenario, '2026-06');

    $error = physicalProgressLimitError(fn () => Scenario::approveEngineering($scenario, $june, 5), $scenario['planSet']->id);

    expect($error)->toBe("Não foi possível conferir o limite de 100% de Plano padrão: a medição #{$broken->id} tem a Engenharia aprovada sem o avanço físico registrado.");
});

it('keeps the progress and the limit of each development independent', function () {
    $actor = makeAdminUser();
    $actor->givePermissionTo(['measurements.create', 'measurements.review']);
    config()->set('filesystems.private_disk', 'local');
    $operation = Operation::factory()->create(['status' => 'active', 'assigned_user_id' => $actor->id, 'responsible_user_id' => $actor->id]);
    $towerA = MeasurementPlanSet::factory()->default()->withInitialPhysicalProgress('95.00')->create(['operation_id' => $operation->id, 'name' => 'Torre A']);
    $towerB = MeasurementPlanSet::factory()->create(['operation_id' => $operation->id, 'name' => 'Torre B']);
    $measurement = Measurement::factory()->create(['operation_id' => $operation->id, 'reference_month' => '2026-05-01', 'status' => 'pending', 'current_stage' => 1, 'storage_path' => null, 'filename' => null]);

    foreach ([$towerA, $towerB] as $planSet) {
        $line = MeasurementPlanLine::factory()->create([
            'operation_id' => $operation->id, 'plan_set_id' => $planSet->id, 'sequence_number' => 1,
            'measurement_date' => '2026-05-01', 'initial_realized_cumulative_percent' => 0,
            'realized_monthly_percent' => 0, 'realized_cumulative_percent' => 0,
        ]);
        // O plano só recebe medição depois de ativado: a linha entra no rascunho da V1.
        MeasurementPlanVersionFixture::activate($planSet);
        Storage::disk('local')->put("nimbus_docs/measurements/assets/towers-{$planSet->id}.pdf", "%PDF-1.7 torre {$planSet->id}");
        $measurement->assets()->create(['plan_set_id' => $planSet->id, 'plan_line_id' => $line->id, 'storage_path' => "nimbus_docs/measurements/assets/towers-{$planSet->id}.pdf", 'storage_disk' => 'local']);
    }

    app(MeasurementWorkflow::class)->startReview($measurement, $actor);

    try {
        app(MeasurementWorkflow::class)->approve($measurement->fresh(), $actor, engineeringProgress: [$towerA->id => 6, $towerB->id => 10]);
        test()->fail('A Torre A não comporta 6%.');
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(["realized.{$towerA->id}"]);
    }

    app(MeasurementWorkflow::class)->approve($measurement->fresh(), $actor, engineeringProgress: [$towerA->id => 5, $towerB->id => 10]);
    $progress = app(MeasurementPhysicalProgressService::class)->forOperation($operation);

    expect($progress[$towerA->id]->currentPercent())->toBe('100.00')
        ->and($progress[$towerB->id]->currentPercent())->toBe('10.00');
});

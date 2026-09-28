<?php

use App\Enums\MeasurementReconciliationStatus;
use App\Exceptions\MeasurementWorkflowException;
use App\Filament\Resources\Measurements\Pages\ViewMeasurement;
use App\Models\Construction;
use App\Models\Measurement;
use App\Models\MeasurementPayment;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\Operation;
use App\Models\User;
use App\Services\MeasurementEngineeringService;
use App\Services\MeasurementFinancialReconciliationService;
use App\Services\MeasurementWorkflow;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Forms\Components\Placeholder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\MeasurementReceiptEvidenceScenario;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    Notification::fake();
});

function reconciliationService(): MeasurementFinancialReconciliationService
{
    return app(MeasurementFinancialReconciliationService::class);
}

/**
 * Medição com snapshot escrito diretamente: isola a fórmula do fluxo,
 * mantendo o snapshot como única fonte lida pelo serviço.
 *
 * @param  array<int, array{fund: string|null, percent: string, name?: string}>  $planSets
 */
function reconciliationMeasurement(array $planSets): Measurement
{
    $operation = Operation::factory()->create();
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->getKey(),
        'reference_month' => '2026-08-01',
    ]);

    $entries = [];

    foreach ($planSets as $index => $spec) {
        $planSet = MeasurementPlanSet::factory()->create([
            'operation_id' => $operation->getKey(),
            'construction_fund_amount' => $spec['fund'],
        ]);

        $entries[] = [
            'plan_set_id' => (int) $planSet->getKey(),
            'construction_id' => null,
            'construction_name' => $spec['name'] ?? null,
            'plan_set_name' => $planSet->name,
            'is_default' => $index === 0,
            'construction_fund_amount' => $spec['fund'],
            'initial_incurred_amount' => '0.00',
            'realized_monthly_percent' => $spec['percent'],
            'realized_cumulative_percent' => $spec['percent'],
        ];
    }

    $measurement->forceFill([
        'engineering_snapshot' => [
            'schema_version' => MeasurementEngineeringService::SNAPSHOT_SCHEMA_VERSION,
            'measurement_id' => (int) $measurement->getKey(),
            'operation_id' => (int) $operation->getKey(),
            'emission_id' => null,
            'reference_month' => '2026-08-01',
            'plan_sets' => $entries,
        ],
    ])->save();

    return $measurement->fresh();
}

function reconciliationPayment(Measurement $measurement, int $planSetId, string $amount): MeasurementPayment
{
    return MeasurementPayment::factory()->create([
        'operation_id' => $measurement->operation_id,
        'measurement_id' => $measurement->getKey(),
        'plan_set_id' => $planSetId,
        'amount' => $amount,
    ]);
}

/** @return array{plan_set_id: int, line: object} */
function reconciliationSnapshotPlanSetId(Measurement $measurement, int $index = 0): int
{
    return (int) $measurement->engineering_snapshot['plan_sets'][$index]['plan_set_id'];
}

function reconciliationAdmin(): User
{
    $user = User::factory()->withTwoFactor()->create(['is_active' => true, 'approved_at' => now()]);
    $user->assignRole('admin');

    return $user;
}

/**
 * Cenário real: Engenharia aprovada pelo fluxo, medição em `awaiting_payment`.
 *
 * @param  array<int, array{fund: string, percent: float|int}>  $specs
 * @return array{actor: User, operation: Operation, measurement: Measurement, planSets: array<int, MeasurementPlanSet>, constructions: array<int, Construction>}
 */
function reconciliationApprovedScenario(array $specs): array
{
    $actor = reconciliationAdmin();

    $operation = Operation::factory()->create([
        'status' => 'active',
        'assigned_user_id' => $actor->getKey(),
        'responsible_user_id' => $actor->getKey(),
        'stage2_reviewer_user_id' => $actor->getKey(),
        'stage3_reviewer_user_id' => $actor->getKey(),
        'payment_manager_user_id' => $actor->getKey(),
        'payment_receipt_uploader_user_id' => $actor->getKey(),
        'payment_finalizer_user_id' => $actor->getKey(),
    ]);

    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->getKey(),
        'reference_month' => '2026-08-01',
        'status' => 'pending',
        'current_stage' => 1,
        'storage_path' => null,
        'filename' => null,
    ]);

    $planSets = [];
    $constructions = [];

    foreach ($specs as $index => $spec) {
        $construction = Construction::factory()->create();
        $planSet = MeasurementPlanSet::factory()->create([
            'operation_id' => $operation->getKey(),
            'construction_id' => $construction->getKey(),
            'name' => 'Plano '.($index + 1),
            'is_default' => $index === 0,
            'construction_fund_amount' => $spec['fund'],
            'initial_incurred_amount' => '0.00',
        ]);
        $line = MeasurementPlanLine::factory()->create([
            'operation_id' => $operation->getKey(),
            'plan_set_id' => $planSet->getKey(),
            'sequence_number' => 1,
            'initial_realized_cumulative_percent' => 0,
            'measurement_date' => '2026-08-01',
        ]);

        $path = "nimbus_docs/measurements/assets/recon-{$measurement->getKey()}-{$index}.pdf";
        Storage::disk('local')->put($path, "%PDF-1.7\nrecon-{$index}\n%%EOF");

        $measurement->assets()->create([
            'plan_set_id' => $planSet->getKey(),
            'plan_line_id' => $line->getKey(),
            'storage_path' => $path,
            'storage_disk' => 'local',
        ]);

        $planSets[] = $planSet;
        $constructions[] = $construction;
    }

    test()->actingAs($actor);

    $workflow = app(MeasurementWorkflow::class);
    $workflow->startReview($measurement->fresh(), $actor);

    $progress = [];

    foreach ($specs as $index => $spec) {
        $progress[$planSets[$index]->getKey()] = $spec['percent'];
    }

    $workflow->approve($measurement->fresh(), $actor, engineeringProgress: $progress);
    $workflow->approve($measurement->fresh(), $actor);
    $workflow->approve($measurement->fresh(), $actor);

    return [
        'actor' => $actor,
        'operation' => $operation,
        'measurement' => $measurement->fresh(),
        'planSets' => $planSets,
        'constructions' => $constructions,
    ];
}

it('applies the monthly realized percent to the snapshot construction fund', function () {
    $measurement = reconciliationMeasurement([['fund' => '8000000.00', 'percent' => '2.50']]);

    $line = reconciliationService()->forMeasurement($measurement)->lines[0];

    expect($line->fundAmount)->toBe('8000000.00')
        ->and($line->realizedMonthlyPercent)->toBe('2.50')
        ->and($line->expectedAmount)->toBe('200000.00');
});

it('rounds the expected amount with the monetary scale of the module', function () {
    $measurement = reconciliationMeasurement([['fund' => '1234567.89', 'percent' => '3.33']]);

    expect(reconciliationService()->forMeasurement($measurement)->lines[0]->expectedAmount)
        ->toBe('41111.11');
});

it('never produces a binary float from the reconciliation formula', function () {
    $measurement = reconciliationMeasurement([['fund' => '0.10', 'percent' => '70.00']]);

    $line = reconciliationService()->forMeasurement($measurement)->lines[0];

    expect($line->expectedAmount)->toBeString()->toBe('0.07')
        ->and($line->registeredAmount)->toBeString()
        ->and($line->expectedBalance)->toBeString();
});

it('produces a zero expected amount for a zero realized percent without absurd status', function () {
    $measurement = reconciliationMeasurement([['fund' => '8000000.00', 'percent' => '0.00']]);

    $reconciliation = reconciliationService()->forMeasurement($measurement);
    $line = $reconciliation->lines[0];

    expect($line->expectedAmount)->toBe('0.00')
        ->and($line->expectedBalance)->toBe('0.00')
        ->and($line->status)->toBe(MeasurementReconciliationStatus::Matched);
});

it('flags a payment against a zero expected amount as a divergence, not a blocker', function () {
    $measurement = reconciliationMeasurement([['fund' => '8000000.00', 'percent' => '0.00']]);
    $planSetId = reconciliationSnapshotPlanSetId($measurement);

    $line = reconciliationService()->forMeasurement($measurement, [$planSetId => '1000.00'])->line($planSetId);

    expect($line->divergenceAmount)->toBe('1000.00')
        ->and($line->status)->toBe(MeasurementReconciliationStatus::Over);
});

it('reconciles a single payment that matches the expected amount', function () {
    $measurement = reconciliationMeasurement([['fund' => '8000000.00', 'percent' => '2.50']]);
    $planSetId = reconciliationSnapshotPlanSetId($measurement);

    $line = reconciliationService()->forMeasurement($measurement, [$planSetId => '200000.00'])->line($planSetId);

    expect($line->registeredAmount)->toBe('0.00')
        ->and($line->expectedBalance)->toBe('200000.00')
        ->and($line->enteredAmount)->toBe('200000.00')
        ->and($line->divergenceAmount)->toBe('0.00')
        ->and($line->status)->toBe(MeasurementReconciliationStatus::Matched);
});

it('reports a payment below the expected balance as a divergence for less', function () {
    $measurement = reconciliationMeasurement([['fund' => '8000000.00', 'percent' => '2.50']]);
    $planSetId = reconciliationSnapshotPlanSetId($measurement);

    $line = reconciliationService()->forMeasurement($measurement, [$planSetId => '150000.00'])->line($planSetId);

    expect($line->divergenceAmount)->toBe('-50000.00')
        ->and($line->divergencePercent)->toBe('-25.00')
        ->and($line->status)->toBe(MeasurementReconciliationStatus::Under);
});

it('reports a payment above the expected balance as a divergence for more', function () {
    $measurement = reconciliationMeasurement([['fund' => '8000000.00', 'percent' => '2.50']]);
    $planSetId = reconciliationSnapshotPlanSetId($measurement);

    $line = reconciliationService()->forMeasurement($measurement, [$planSetId => '250000.00'])->line($planSetId);

    expect($line->divergenceAmount)->toBe('50000.00')
        ->and($line->divergencePercent)->toBe('25.00')
        ->and($line->status)->toBe(MeasurementReconciliationStatus::Over);
});

it('treats reconciliation as incremental across several payments of the same development', function () {
    $measurement = reconciliationMeasurement([['fund' => '8000000.00', 'percent' => '2.50']]);
    $planSetId = reconciliationSnapshotPlanSetId($measurement);
    reconciliationPayment($measurement, $planSetId, '50000.00');

    $line = reconciliationService()->forMeasurement($measurement->fresh(), [$planSetId => '150000.00'])->line($planSetId);

    expect($line->registeredAmount)->toBe('50000.00')
        ->and($line->expectedBalance)->toBe('150000.00')
        ->and($line->divergenceAmount)->toBe('0.00')
        ->and($line->status)->toBe(MeasurementReconciliationStatus::Matched);
});

it('sums every payment of the same development in the current measurement', function () {
    $measurement = reconciliationMeasurement([['fund' => '8000000.00', 'percent' => '2.50']]);
    $planSetId = reconciliationSnapshotPlanSetId($measurement);
    reconciliationPayment($measurement, $planSetId, '50000.00');
    reconciliationPayment($measurement, $planSetId, '25000.50');

    $line = reconciliationService()->forMeasurement($measurement->fresh())->line($planSetId);

    expect($line->registeredAmount)->toBe('75000.50')
        ->and($line->expectedBalance)->toBe('124999.50');
});

it('ignores payments that belong to another measurement of the same development', function () {
    $measurement = reconciliationMeasurement([['fund' => '8000000.00', 'percent' => '2.50']]);
    $planSetId = reconciliationSnapshotPlanSetId($measurement);
    $other = Measurement::factory()->create(['operation_id' => $measurement->operation_id]);
    reconciliationPayment($other, $planSetId, '90000.00');

    expect(reconciliationService()->forMeasurement($measurement->fresh())->line($planSetId)->registeredAmount)
        ->toBe('0.00');
});

it('keeps a negative expected balance visible when the development is already overpaid', function () {
    $measurement = reconciliationMeasurement([['fund' => '1000000.00', 'percent' => '10.00']]);
    $planSetId = reconciliationSnapshotPlanSetId($measurement);
    reconciliationPayment($measurement, $planSetId, '120000.00');

    $line = reconciliationService()->forMeasurement($measurement->fresh())->line($planSetId);

    expect($line->expectedAmount)->toBe('100000.00')
        ->and($line->registeredAmount)->toBe('120000.00')
        ->and($line->expectedBalance)->toBe('-20000.00')
        ->and($line->status)->toBe(MeasurementReconciliationStatus::Over);
});

it('keeps developments independent inside the same measurement', function () {
    $measurement = reconciliationMeasurement([
        ['fund' => '8000000.00', 'percent' => '2.50', 'name' => 'Alto Bellevue'],
        ['fund' => '2000000.00', 'percent' => '5.00', 'name' => 'Vila Nova'],
    ]);
    $first = reconciliationSnapshotPlanSetId($measurement, 0);
    $second = reconciliationSnapshotPlanSetId($measurement, 1);
    reconciliationPayment($measurement, $first, '80000.00');

    $reconciliation = reconciliationService()->forMeasurement($measurement->fresh(), [$second => '100000.00']);

    expect($reconciliation->line($first)->label)->toBe('Alto Bellevue')
        ->and($reconciliation->line($first)->expectedAmount)->toBe('200000.00')
        ->and($reconciliation->line($first)->registeredAmount)->toBe('80000.00')
        ->and($reconciliation->line($first)->enteredAmount)->toBe('0.00')
        ->and($reconciliation->line($second)->label)->toBe('Vila Nova')
        ->and($reconciliation->line($second)->expectedAmount)->toBe('100000.00')
        ->and($reconciliation->line($second)->registeredAmount)->toBe('0.00')
        ->and($reconciliation->line($second)->status)->toBe(MeasurementReconciliationStatus::Matched);
});

it('aggregates the measurement total without losing the per-development calculation', function () {
    $measurement = reconciliationMeasurement([
        ['fund' => '8000000.00', 'percent' => '2.50'],
        ['fund' => '2000000.00', 'percent' => '5.00'],
    ]);
    $first = reconciliationSnapshotPlanSetId($measurement, 0);
    reconciliationPayment($measurement, $first, '80000.00');

    $reconciliation = reconciliationService()->forMeasurement($measurement->fresh());

    expect($reconciliation->expectedAmount)->toBe('300000.00')
        ->and($reconciliation->registeredAmount)->toBe('80000.00')
        ->and($reconciliation->expectedBalance)->toBe('220000.00')
        ->and($reconciliation->referenceComplete)->toBeTrue()
        ->and($reconciliation->status)->toBe(MeasurementReconciliationStatus::Under)
        ->and($reconciliation->lines)->toHaveCount(2);
});

it('marks the reference as unavailable when the snapshot has no construction fund', function () {
    $measurement = reconciliationMeasurement([['fund' => null, 'percent' => '2.50']]);

    $reconciliation = reconciliationService()->forMeasurement($measurement);

    expect($reconciliation->lines[0]->expectedAmount)->toBeNull()
        ->and($reconciliation->lines[0]->expectedBalance)->toBeNull()
        ->and($reconciliation->lines[0]->divergenceAmount)->toBeNull()
        ->and($reconciliation->lines[0]->status)->toBe(MeasurementReconciliationStatus::ReferenceUnavailable)
        ->and($reconciliation->referenceComplete)->toBeFalse()
        ->and($reconciliation->status)->toBe(MeasurementReconciliationStatus::ReferenceUnavailable);
});

it('never divides by zero when the expected balance is already settled', function () {
    $measurement = reconciliationMeasurement([['fund' => '1000000.00', 'percent' => '10.00']]);
    $planSetId = reconciliationSnapshotPlanSetId($measurement);
    reconciliationPayment($measurement, $planSetId, '100000.00');

    $line = reconciliationService()->forMeasurement($measurement->fresh())->line($planSetId);

    expect($line->expectedBalance)->toBe('0.00')
        ->and($line->divergencePercent)->toBeNull()
        ->and($line->status)->toBe(MeasurementReconciliationStatus::Matched);
});

it('reads the construction fund from the engineering snapshot and not from the current plan', function () {
    $scenario = reconciliationApprovedScenario([['fund' => '8000000.00', 'percent' => 2.5]]);
    $measurement = $scenario['measurement'];
    $planSet = $scenario['planSets'][0];

    expect(reconciliationService()->forMeasurement($measurement)->line($planSet->getKey())->expectedAmount)
        ->toBe('200000.00');

    DB::table('measurement_plan_sets')
        ->where('id', $planSet->getKey())
        ->update(['construction_fund_amount' => '1000.00']);
    DB::table('measurement_plan_lines')
        ->where('plan_set_id', $planSet->getKey())
        ->update(['realized_monthly_percent' => '99.00']);

    expect(reconciliationService()->forMeasurement($measurement->fresh())->line($planSet->getKey())->expectedAmount)
        ->toBe('200000.00');
});

it('changes the expected amount only when the snapshot itself changes', function () {
    $scenario = reconciliationApprovedScenario([['fund' => '8000000.00', 'percent' => 2.5]]);
    $measurement = $scenario['measurement'];
    $planSet = $scenario['planSets'][0];

    $snapshot = $measurement->engineering_snapshot;
    $snapshot['plan_sets'][0]['construction_fund_amount'] = '4000000.00';

    // O próprio modelo recusa reescrever o snapshot de uma Engenharia aprovada;
    // a alteração só chega ao banco por fora do Eloquent, e é exatamente isso
    // que prova que o valor esperado vem do snapshot e não do plano atual.
    expect(fn () => $measurement->forceFill(['engineering_snapshot' => $snapshot])->save())
        ->toThrow(MeasurementWorkflowException::class);

    DB::table('measurements')
        ->where('id', $measurement->getKey())
        ->update(['engineering_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR)]);

    expect(reconciliationService()->forMeasurement($measurement->fresh())->line($planSet->getKey())->expectedAmount)
        ->toBe('100000.00');
});

it('names the development from the snapshot construction identity', function () {
    $scenario = reconciliationApprovedScenario([['fund' => '8000000.00', 'percent' => 2.5]]);

    $line = reconciliationService()->forMeasurement($scenario['measurement'])->line($scenario['planSets'][0]->getKey());

    expect($line->label)->toBe($scenario['constructions'][0]->development_name)
        ->and($line->constructionId)->toBe((int) $scenario['constructions'][0]->getKey());
});

it('registers a justified divergent payment and allows the payment stage approval', function () {
    $scenario = reconciliationApprovedScenario([['fund' => '8000000.00', 'percent' => 2.5]]);
    $workflow = app(MeasurementWorkflow::class);
    $measurement = $scenario['measurement'];
    $planSet = $scenario['planSets'][0];

    expect($measurement->status)->toBe('awaiting_payment');

    $payment = $workflow->registerPayment($measurement->fresh(), $scenario['actor'], [
        'plan_set_id' => $planSet->getKey(),
        'pay_date' => '2026-08-31',
        'amount' => 150000.00,
        'financial_justification' => 'Retenção contratual para conferência do Finalizador.',
    ]);

    $line = reconciliationService()->forMeasurement($measurement->fresh())->line($planSet->getKey());

    expect((string) $payment->amount)->toBe('150000.00')
        ->and($line->status)->toBe(MeasurementReconciliationStatus::Under)
        ->and($line->divergenceAmount)->toBe('-50000.00');

    $workflow->approve($measurement->fresh(), $scenario['actor']);

    expect($measurement->fresh()->status)->toBe('awaiting_receipt');
});

it('resolves the reconciliation of a measurement in a single payment query', function () {
    $measurement = reconciliationMeasurement([
        ['fund' => '8000000.00', 'percent' => '2.50'],
        ['fund' => '2000000.00', 'percent' => '5.00'],
    ]);
    reconciliationPayment($measurement, reconciliationSnapshotPlanSetId($measurement, 0), '80000.00');
    reconciliationPayment($measurement, reconciliationSnapshotPlanSetId($measurement, 1), '10000.00');

    $measurement = $measurement->fresh();
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    reconciliationService()->forMeasurement($measurement);

    expect($queries)->toBe(1);
});

it('normalizes a masked brazilian amount without going through a float', function () {
    expect(reconciliationService()->normalizeAmount('145.000,00'))->toBe('145000.00')
        ->and(reconciliationService()->normalizeAmount('R$ 1.500,25'))->toBe('1500.25')
        ->and(reconciliationService()->normalizeAmount(''))->toBe('0.00')
        ->and(reconciliationService()->normalizeAmount(null))->toBe('0.00');
});

it('formats reconciliation values for display without a float round trip', function () {
    expect(MeasurementFinancialReconciliationService::formatCurrency('200000.00'))->toBe('R$ 200.000,00')
        ->and(MeasurementFinancialReconciliationService::formatCurrency('-20000.50'))->toBe('-R$ 20.000,50')
        ->and(MeasurementFinancialReconciliationService::formatCurrency(null))->toBe('—')
        ->and(MeasurementFinancialReconciliationService::formatPercent('2.50'))->toBe('2,50%');
});

it('shows the financial reconciliation section on the measurement view', function () {
    $scenario = reconciliationApprovedScenario([['fund' => '8000000.00', 'percent' => 2.5]]);
    $workflow = app(MeasurementWorkflow::class);
    $workflow->registerPayment($scenario['measurement']->fresh(), $scenario['actor'], [
        'plan_set_id' => $scenario['planSets'][0]->getKey(),
        'pay_date' => '2026-08-31',
        'amount' => 150000.00,
        'financial_justification' => 'Retenção contratual para conferência do Finalizador.',
    ]);

    Livewire::test(ViewMeasurement::class, ['record' => $scenario['measurement']->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Conciliação Financeira')
        ->assertSee($scenario['constructions'][0]->development_name)
        ->assertSee('R$ 200.000,00')
        ->assertSee('R$ 150.000,00')
        ->assertSee('R$ 50.000,00')
        ->assertSee('Divergência para menos');
});

it('hides the reconciliation section while the engineering snapshot does not exist', function () {
    $measurement = Measurement::factory()->create(['engineering_snapshot' => null]);
    $actor = reconciliationAdmin();
    test()->actingAs($actor);

    Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->assertSuccessful()
        ->assertDontSee('Conciliação Financeira');
});

/**
 * O conteúdo do modal é avaliado no schema, não no HTML da página: em
 * Filament 5 o modal da ação é montado no cliente, então o servidor não
 * renderiza os campos do repeater no primeiro request.
 */
function reconciliationModalContent(Testable $component, string $suffix): string
{
    $name = $component->instance()->getMountedActionSchemaName();

    foreach ($component->instance()->{$name}->getFlatComponents(withHidden: true) as $key => $item) {
        if (str_ends_with($key, $suffix) && $item instanceof Placeholder) {
            return (string) $item->getContent();
        }
    }

    return '';
}

/** A chave do item do repeater é um UUID, nunca o índice. */
function reconciliationRepeaterItemKey(Testable $component): string
{
    $name = $component->instance()->getMountedActionSchemaName();

    foreach (array_keys($component->instance()->{$name}->getFlatComponents(withHidden: true)) as $key) {
        if (str_ends_with($key, '.amount')) {
            return (string) str($key)->after('payments.')->before('.amount');
        }
    }

    return '';
}

it('shows the measurement reference before the amount field in the payment modal', function () {
    $scenario = reconciliationApprovedScenario([['fund' => '8000000.00', 'percent' => 2.5]]);

    $component = Livewire::test(ViewMeasurement::class, ['record' => $scenario['measurement']->getRouteKey()])
        ->mountAction('registerPayment')
        ->assertSchemaComponentExists('payments');

    $reference = reconciliationModalContent($component, 'reconciliation_reference');

    expect($reference)->toContain('Fundo de obra')
        ->toContain('R$ 8.000.000,00')
        ->toContain('Realizado no mês')
        ->toContain('2,50%')
        ->toContain('Valor esperado')
        ->toContain('R$ 200.000,00')
        ->toContain('Já registrado')
        ->toContain('R$ 0,00')
        ->toContain('Saldo esperado');
});

it('updates the divergence in the payment modal when the amount changes', function () {
    $scenario = reconciliationApprovedScenario([['fund' => '8000000.00', 'percent' => 2.5]]);

    $component = Livewire::test(ViewMeasurement::class, ['record' => $scenario['measurement']->getRouteKey()])
        ->mountAction('registerPayment');

    $itemKey = reconciliationRepeaterItemKey($component);

    $component->set("mountedActions.0.data.payments.{$itemKey}.amount", '150.000,00');
    expect(reconciliationModalContent($component, 'reconciliation_divergence'))
        ->toContain('Divergência para menos')
        ->toContain('R$ 50.000,00');

    $component->set("mountedActions.0.data.payments.{$itemKey}.amount", '200.000,00');
    expect(reconciliationModalContent($component, 'reconciliation_divergence'))
        ->toContain('Conciliado');

    $component->set("mountedActions.0.data.payments.{$itemKey}.amount", '250.000,00');
    expect(reconciliationModalContent($component, 'reconciliation_divergence'))
        ->toContain('Divergência para mais')
        ->toContain('R$ 50.000,00');
});

it('takes the already registered amount into account in the modal reference', function () {
    $scenario = reconciliationApprovedScenario([['fund' => '8000000.00', 'percent' => 2.5]]);
    app(MeasurementWorkflow::class)->registerPayment($scenario['measurement']->fresh(), $scenario['actor'], [
        'plan_set_id' => $scenario['planSets'][0]->getKey(),
        'pay_date' => '2026-08-31',
        'amount' => 50000.00,
        'financial_justification' => 'Primeira parcela do pagamento.',
    ]);

    $component = Livewire::test(ViewMeasurement::class, ['record' => $scenario['measurement']->fresh()->getRouteKey()])
        ->mountAction('registerPayment');

    expect(reconciliationModalContent($component, 'reconciliation_reference'))
        ->toContain('Já registrado')
        ->toContain('R$ 50.000,00')
        ->toContain('R$ 150.000,00');

    $itemKey = reconciliationRepeaterItemKey($component);
    $component->set("mountedActions.0.data.payments.{$itemKey}.amount", '150.000,00');

    expect(reconciliationModalContent($component, 'reconciliation_divergence'))->toContain('Conciliado');
});

it('allows finalization of a justified divergence with explicit acceptance', function () {
    $scenario = reconciliationApprovedScenario([['fund' => '8000000.00', 'percent' => 2.5]]);
    $workflow = app(MeasurementWorkflow::class);
    $measurement = $scenario['measurement'];

    $payment = $workflow->registerPayment($measurement->fresh(), $scenario['actor'], [
        'plan_set_id' => $scenario['planSets'][0]->getKey(),
        'pay_date' => '2026-08-31',
        'amount' => 150000.00,
        'financial_justification' => 'Retenção contratual para conferência do Finalizador.',
    ]);
    $workflow->approve($measurement->fresh(), $scenario['actor']);

    $workflow->attachReceipt($payment->fresh(), $scenario['actor'], MeasurementReceiptEvidenceScenario::file());
    MeasurementReceiptEvidenceScenario::approveCurrentReceipt($payment, $scenario['actor']);

    $workflow->finalize($measurement->fresh(), $scenario['actor'], acceptFinancialExceptions: true);

    $line = reconciliationService()->forMeasurement($measurement->fresh())->line($scenario['planSets'][0]->getKey());

    expect($measurement->fresh()->status)->toBe('finalized')
        ->and($line->status)->toBe(MeasurementReconciliationStatus::Under);
});

it('does not write an activity just because the reconciliation diverges', function () {
    $scenario = reconciliationApprovedScenario([['fund' => '8000000.00', 'percent' => 2.5]]);
    $before = Activity::query()->count();

    reconciliationService()->forMeasurement($scenario['measurement'], [
        $scenario['planSets'][0]->getKey() => '1.00',
    ]);

    expect(Activity::query()->count())->toBe($before);
});

it('stays neutral in the modal until an amount is informed', function () {
    $scenario = reconciliationApprovedScenario([['fund' => '8000000.00', 'percent' => 2.5]]);

    $component = Livewire::test(ViewMeasurement::class, ['record' => $scenario['measurement']->getRouteKey()])
        ->mountAction('registerPayment');

    expect(reconciliationModalContent($component, 'reconciliation_divergence'))
        ->toContain('Informe o valor para comparar com o saldo esperado.')
        ->not->toContain('Divergência');
});

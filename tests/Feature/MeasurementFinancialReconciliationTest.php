<?php

use App\Enums\MeasurementPlanRevisionCategory;
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
use App\Services\MeasurementPaymentFinancialService;
use App\Services\MeasurementPlanVersionService;
use App\Services\MeasurementWorkflow;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Forms\Components\Placeholder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\MeasurementPlanVersionFixture;
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
        // O Fundo de Obra é da versão do plano (aqui, a V1), não do plano.
        $planSet = MeasurementPlanSet::factory()->withConstructionFund($spec['fund'])->create([
            'operation_id' => $operation->getKey(),
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
 * `name` fixa o nome do empreendimento quando o teste confere o texto exibido.
 *
 * @param  array<int, array{fund: string|null, percent: float|int, name?: string}>  $specs
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
        $construction = Construction::factory()->create(isset($spec['name']) ? ['development_name' => $spec['name']] : []);
        $planSet = MeasurementPlanSet::factory()->withConstructionFund($spec['fund'])->create([
            'operation_id' => $operation->getKey(),
            'construction_id' => $construction->getKey(),
            'name' => 'Plano '.($index + 1),
            'is_default' => $index === 0,
            'initial_incurred_amount' => '0.00',
        ]);
        $line = MeasurementPlanLine::factory()->create([
            'operation_id' => $operation->getKey(),
            'plan_set_id' => $planSet->getKey(),
            'sequence_number' => 1,
            'initial_realized_cumulative_percent' => 0,
            'measurement_date' => '2026-08-01',
        ]);
        // A medição só é enviada sob a versão que rege a competência: a linha
        // entra no rascunho da V1, que é ativado antes do arquivo.
        MeasurementPlanVersionFixture::activate($planSet);

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

    expect($measurement->engineering_snapshot['plan_sets'][0])->toMatchArray([
        'plan_version_number' => 1,
        'construction_fund_amount' => '8000000.00',
    ])->and(reconciliationService()->forMeasurement($measurement)->line($planSet->getKey())->expectedAmount)
        ->toBe('200000.00');

    // O Fundo de Obra é da versão do plano, e o caminho normal para o fundo
    // atual mudar depois da aprovação é uma revisão de custo ativada. Ela vale
    // a partir do mês da ativação, posterior à competência já medida (08/2026).
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'America/Sao_Paulo'));
    $versions = app(MeasurementPlanVersionService::class);
    $revision = $versions->createRevision($planSet, $scenario['actor'], [
        'revision_category' => MeasurementPlanRevisionCategory::Cost->value,
        'revision_reason' => 'Orçamento da obra revisado pela construtora.',
    ]);
    $revision = $versions->updateDraft($revision, $scenario['actor'], ['construction_fund_amount' => '1000.00'], null, $revision->revision);
    $versions->activate($revision, $scenario['actor'], $revision->revision);

    expect($planSet->fresh()->currentConstructionFundAmount())->toBe('1000.00')
        ->and(reconciliationService()->forMeasurement($measurement->fresh())->line($planSet->getKey())->expectedAmount)
        ->toBe('200000.00');

    // Nem o fundo das versões nem o realizado das linhas, alterados por fora
    // do Eloquent, chegam ao valor esperado: a única fonte é o snapshot.
    DB::table('measurement_plan_versions')
        ->where('plan_set_id', $planSet->getKey())
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

/**
 * Erros de validação exatamente como o domínio os devolve.
 *
 * @return array<string, array<int, string>>
 */
function reconciliationValidationErrors(callable $action): array
{
    try {
        $action();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    test()->fail('A ação deveria ter sido recusada por validação.');
}

/**
 * Dois empreendimentos na mesma medição: Torre Alfa espera R$ 1.000,00
 * (fundo de R$ 10.000,00 a 10%) e Torre Beta segue `$second` -- por padrão,
 * R$ 2.000,00 (fundo de R$ 20.000,00 a 10%).
 *
 * @param  array{fund: string|null, percent: float|int}  $second
 * @return array{actor: User, operation: Operation, measurement: Measurement, planSets: array<int, MeasurementPlanSet>, constructions: array<int, Construction>}
 */
function reconciliationTwoDevelopments(array $second = ['fund' => '20000.00', 'percent' => 10]): array
{
    return reconciliationApprovedScenario([
        ['fund' => '10000.00', 'percent' => 10, 'name' => 'Torre Alfa'],
        $second + ['name' => 'Torre Beta'],
    ]);
}

/**
 * @param  array{actor: User}  $scenario
 * @param  iterable<int, MeasurementPayment>  $payments
 */
function reconciliationApproveReceipts(array $scenario, iterable $payments): void
{
    foreach ($payments as $payment) {
        app(MeasurementWorkflow::class)->attachReceipt(
            $payment->fresh(),
            $scenario['actor'],
            MeasurementReceiptEvidenceScenario::file("comprovante-{$payment->id}.pdf", "%PDF-1.7 comprovante {$payment->id}"),
        );
        MeasurementReceiptEvidenceScenario::approveCurrentReceipt($payment, $scenario['actor']);
    }
}

function reconciliationLastActivity(string $description): Activity
{
    return Activity::query()->where('description', $description)->latest('id')->firstOrFail();
}

it('refuses the payment stage approval while a required development has no payment and no justification', function (string $otherDevelopment) {
    $scenario = reconciliationTwoDevelopments();
    $workflow = app(MeasurementWorkflow::class);
    $workflow->registerPayment($scenario['measurement']->fresh(), $scenario['actor'], match ($otherDevelopment) {
        'matched' => ['plan_set_id' => $scenario['planSets'][0]->getKey(), 'pay_date' => '2026-08-31', 'amount' => '1000.00'],
        'overpaid by the omitted amount' => [
            'plan_set_id' => $scenario['planSets'][0]->getKey(),
            'pay_date' => '2026-08-31',
            'amount' => '3000.00',
            'financial_justification' => 'Valor da Torre Beta antecipado na Torre Alfa.',
        ],
    });
    $revision = (int) $scenario['measurement']->fresh()->workflow_revision;

    foreach ([null, '   '] as $notes) {
        $errors = reconciliationValidationErrors(fn () => $workflow->approve($scenario['measurement']->fresh(), $scenario['actor'], $notes));

        expect($errors['notes'][0] ?? '')
            ->toBe('Justifique a ausência de pagamento nesta competência de: Torre Beta (R$ 2.000,00); o Finalizador precisará aceitar expressamente.');
    }

    $measurement = $scenario['measurement']->fresh();

    expect($measurement->status)->toBe('awaiting_payment')
        ->and($measurement->reviewForStage(MeasurementWorkflow::STAGE_PAYMENT)?->status)->toBe('pending')
        ->and($measurement->workflow_revision)->toBe($revision);
})->with(['matched', 'overpaid by the omitted amount']);

it('requires the finalizer acceptance for a justified development without payment and freezes it in the audit', function () {
    $scenario = reconciliationTwoDevelopments();
    $workflow = app(MeasurementWorkflow::class);
    $justification = 'Torre Beta sem pagamento: pendência documental do construtor.';
    $payment = $workflow->registerPayment($scenario['measurement']->fresh(), $scenario['actor'], [
        'plan_set_id' => $scenario['planSets'][0]->getKey(),
        'pay_date' => '2026-08-31',
        'amount' => '1000.00',
    ]);

    $workflow->approve($scenario['measurement']->fresh(), $scenario['actor'], $justification);
    $approval = reconciliationLastActivity('measurement_stage_approved');

    expect($scenario['measurement']->fresh()->status)->toBe('awaiting_receipt')
        ->and($approval->properties->get('stage'))->toBe(MeasurementWorkflow::STAGE_PAYMENT)
        ->and($approval->properties->get('unpaid_plan_sets'))->toHaveCount(1)
        ->and($approval->properties->get('unpaid_plan_sets')[0] ?? [])->toMatchArray([
            'plan_set_id' => $scenario['planSets'][1]->getKey(),
            'label' => 'Torre Beta',
            'expected_amount' => '2000.00',
            'registered_amount' => '0.00',
            'expected_balance' => '2000.00',
        ]);

    reconciliationApproveReceipts($scenario, [$payment]);

    expect($scenario['measurement']->fresh()->status)->toBe('approved')
        ->and(app(MeasurementPaymentFinancialService::class)->requiresAcceptance($scenario['measurement']->fresh()))->toBeTrue();

    $errors = reconciliationValidationErrors(fn () => $workflow->finalize($scenario['measurement']->fresh(), $scenario['actor']));

    expect($errors['accept_financial_exceptions'][0] ?? '')->toContain('Torre Beta (R$ 2.000,00)')
        ->and($scenario['measurement']->fresh()->status)->toBe('approved');

    $workflow->finalize($scenario['measurement']->fresh(), $scenario['actor'], acceptFinancialExceptions: true);
    $finalization = reconciliationLastActivity('measurement_finalized');
    $accepted = $finalization->properties->get('unpaid_plan_sets_accepted');

    expect($scenario['measurement']->fresh()->status)->toBe('finalized')
        ->and($finalization->properties->get('financial_exceptions_accepted'))->toBe([])
        ->and($accepted)->toHaveCount(1)
        ->and($accepted[0]['plan_set_id'] ?? null)->toBe($scenario['planSets'][1]->getKey())
        ->and($accepted[0]['reference']['expected_amount'] ?? null)->toBe('2000.00')
        ->and($accepted[0]['justification'] ?? null)->toBe($justification)
        ->and($accepted[0]['justified_by'] ?? null)->toBe($scenario['actor']->getKey());
});

it('does not ask for justification or acceptance when no required development is left without payment', function (string $case) {
    $scenario = reconciliationTwoDevelopments(match ($case) {
        'zero realized percent' => ['fund' => '20000.00', 'percent' => 0],
        'no construction fund' => ['fund' => null, 'percent' => 10],
        'expected amount rounded to zero' => ['fund' => '0.10', 'percent' => 1],
        'paid in full' => ['fund' => '20000.00', 'percent' => 10],
    });
    $workflow = app(MeasurementWorkflow::class);
    $financial = app(MeasurementPaymentFinancialService::class);
    $rows = [['plan_set_id' => $scenario['planSets'][0]->getKey(), 'pay_date' => '2026-08-31', 'amount' => '1000.00']];

    if ($case === 'paid in full') {
        $rows[] = ['plan_set_id' => $scenario['planSets'][1]->getKey(), 'pay_date' => '2026-08-31', 'amount' => '2000.00'];
    }

    $payments = $workflow->registerPayments($scenario['measurement']->fresh(), $scenario['actor'], $rows);

    expect($financial->unpaidRequiredPlanSets($scenario['measurement']->fresh()))->toBe([]);

    $workflow->approve($scenario['measurement']->fresh(), $scenario['actor']);
    reconciliationApproveReceipts($scenario, $payments);

    expect($scenario['measurement']->fresh()->status)->toBe('approved')
        ->and($financial->requiresAcceptance($scenario['measurement']->fresh()))->toBeFalse();

    $workflow->finalize($scenario['measurement']->fresh(), $scenario['actor']);

    expect($scenario['measurement']->fresh()->status)->toBe('finalized')
        ->and(reconciliationLastActivity('measurement_stage_approved')->properties->get('unpaid_plan_sets'))->toBe([])
        ->and(reconciliationLastActivity('measurement_finalized')->properties->get('unpaid_plan_sets_accepted'))->toBe([]);
})->with(['zero realized percent', 'no construction fund', 'expected amount rounded to zero', 'paid in full']);

it('treats a partially paid development as a payment divergence and not as an omission', function () {
    $scenario = reconciliationTwoDevelopments();
    $workflow = app(MeasurementWorkflow::class);
    $payments = $workflow->registerPayments($scenario['measurement']->fresh(), $scenario['actor'], [
        [
            'plan_set_id' => $scenario['planSets'][0]->getKey(),
            'pay_date' => '2026-08-31',
            'amount' => '500.00',
            'financial_justification' => 'Retenção contratual de metade do valor da Torre Alfa.',
        ],
        ['plan_set_id' => $scenario['planSets'][1]->getKey(), 'pay_date' => '2026-08-31', 'amount' => '2000.00'],
    ]);

    expect(app(MeasurementPaymentFinancialService::class)->unpaidRequiredPlanSets($scenario['measurement']->fresh()))->toBe([]);

    $workflow->approve($scenario['measurement']->fresh(), $scenario['actor']);
    reconciliationApproveReceipts($scenario, $payments);

    expect(reconciliationValidationErrors(fn () => $workflow->finalize($scenario['measurement']->fresh(), $scenario['actor'])))
        ->toHaveKey('accept_financial_exceptions');

    $workflow->finalize($scenario['measurement']->fresh(), $scenario['actor'], acceptFinancialExceptions: true);
    $finalization = reconciliationLastActivity('measurement_finalized');

    expect($scenario['measurement']->fresh()->status)->toBe('finalized')
        ->and(collect($finalization->properties->get('financial_exceptions_accepted'))->pluck('payment_id')->all())->toBe([$payments[0]->id])
        ->and($finalization->properties->get('unpaid_plan_sets_accepted'))->toBe([]);
});

it('recomputes the omission after the finalizer returns to payment and the missing development is paid', function () {
    $scenario = reconciliationTwoDevelopments();
    $workflow = app(MeasurementWorkflow::class);
    $first = $workflow->registerPayment($scenario['measurement']->fresh(), $scenario['actor'], [
        'plan_set_id' => $scenario['planSets'][0]->getKey(),
        'pay_date' => '2026-08-31',
        'amount' => '1000.00',
    ]);
    $workflow->approve($scenario['measurement']->fresh(), $scenario['actor'], 'Torre Beta será paga depois da conferência.');
    $workflow->returnToStage(
        $scenario['measurement']->fresh(),
        $scenario['actor'],
        MeasurementWorkflow::STAGE_PAYMENT,
        'Registrar o pagamento da Torre Beta.',
    );
    $second = $workflow->registerPayment($scenario['measurement']->fresh(), $scenario['actor'], [
        'plan_set_id' => $scenario['planSets'][1]->getKey(),
        'pay_date' => '2026-09-02',
        'amount' => '2000.00',
    ]);

    $workflow->approve($scenario['measurement']->fresh(), $scenario['actor']);
    reconciliationApproveReceipts($scenario, [$first, $second]);
    $workflow->finalize($scenario['measurement']->fresh(), $scenario['actor']);

    $paymentApprovals = Activity::query()
        ->where('description', 'measurement_stage_approved')
        ->orderBy('id')
        ->get()
        ->filter(fn (Activity $activity): bool => $activity->properties->get('stage') === MeasurementWorkflow::STAGE_PAYMENT)
        ->values();

    expect($scenario['measurement']->fresh()->status)->toBe('finalized')
        ->and($paymentApprovals)->toHaveCount(2)
        ->and(collect($paymentApprovals[0]->properties->get('unpaid_plan_sets'))->pluck('plan_set_id')->all())->toBe([$scenario['planSets'][1]->getKey()])
        ->and($paymentApprovals[1]->properties->get('unpaid_plan_sets'))->toBe([])
        ->and(reconciliationLastActivity('measurement_finalized')->properties->get('unpaid_plan_sets_accepted'))->toBe([]);
});

it('refuses to finalize an omission whose payment stage was approved without justification', function () {
    $scenario = reconciliationTwoDevelopments();
    $workflow = app(MeasurementWorkflow::class);
    $payment = $workflow->registerPayment($scenario['measurement']->fresh(), $scenario['actor'], [
        'plan_set_id' => $scenario['planSets'][0]->getKey(),
        'pay_date' => '2026-08-31',
        'amount' => '1000.00',
    ]);
    $workflow->approve($scenario['measurement']->fresh(), $scenario['actor'], 'Torre Beta sem pagamento nesta competência.');

    // Aprovação da etapa Pagamento anterior à regra: a nota ficou em branco.
    DB::table('measurement_reviews')
        ->where('measurement_id', $scenario['measurement']->getKey())
        ->where('stage', MeasurementWorkflow::STAGE_PAYMENT)
        ->update(['notes' => null]);
    reconciliationApproveReceipts($scenario, [$payment]);
    $refusal = null;

    try {
        $workflow->finalize($scenario['measurement']->fresh(), $scenario['actor'], acceptFinancialExceptions: true);
    } catch (MeasurementWorkflowException $exception) {
        $refusal = $exception->getMessage();
    }

    $measurement = $scenario['measurement']->fresh();

    expect($refusal)->toBe('Há empreendimento sem pagamento nesta competência e sem justificativa da etapa Pagamento: Torre Beta (R$ 2.000,00). Devolva à etapa Pagamento para registrar o pagamento ou justificar a ausência.')
        ->and($measurement->status)->toBe('approved')
        ->and($measurement->reviewForStage(MeasurementWorkflow::STAGE_FINALIZATION)?->status)->toBe('pending');
});

it('shows the missing justification on the comment field of the payment stage approval modal', function () {
    $scenario = reconciliationTwoDevelopments();
    app(MeasurementWorkflow::class)->registerPayment($scenario['measurement']->fresh(), $scenario['actor'], [
        'plan_set_id' => $scenario['planSets'][0]->getKey(),
        'pay_date' => '2026-08-31',
        'amount' => '1000.00',
    ]);

    Livewire::test(ViewMeasurement::class, ['record' => $scenario['measurement']->getRouteKey()])
        ->callAction('approve', data: ['notes' => ''])
        ->assertHasActionErrors(['notes']);

    expect($scenario['measurement']->fresh()->status)->toBe('awaiting_payment');

    Livewire::test(ViewMeasurement::class, ['record' => $scenario['measurement']->getRouteKey()])
        ->callAction('approve', data: ['notes' => 'Torre Beta sem pagamento: pendência documental do construtor.'])
        ->assertHasNoActionErrors();

    expect($scenario['measurement']->fresh()->status)->toBe('awaiting_receipt');
});

it('requires the acceptance checkbox of the finalization modal for a justified development without payment', function () {
    $scenario = reconciliationTwoDevelopments();
    $workflow = app(MeasurementWorkflow::class);
    $payment = $workflow->registerPayment($scenario['measurement']->fresh(), $scenario['actor'], [
        'plan_set_id' => $scenario['planSets'][0]->getKey(),
        'pay_date' => '2026-08-31',
        'amount' => '1000.00',
    ]);
    $workflow->approve($scenario['measurement']->fresh(), $scenario['actor'], 'Torre Beta sem pagamento: pendência documental do construtor.');
    reconciliationApproveReceipts($scenario, [$payment]);

    $page = Livewire::test(ViewMeasurement::class, ['record' => $scenario['measurement']->getRouteKey()])
        ->mountAction('finalize')
        ->assertSet('mountedActions.0.data.accept_financial_exceptions', false)
        ->callMountedAction()
        ->assertHasActionErrors(['accept_financial_exceptions']);

    expect($scenario['measurement']->fresh()->status)->toBe('approved');

    $page->set('mountedActions.0.data.accept_financial_exceptions', true)
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($scenario['measurement']->fresh()->status)->toBe('finalized');
});

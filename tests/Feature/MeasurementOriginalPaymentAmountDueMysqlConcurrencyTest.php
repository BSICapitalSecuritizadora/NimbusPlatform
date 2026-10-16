<?php

use App\Exceptions\MeasurementWorkflowException;
use App\Models\Measurement;
use App\Models\MeasurementPayment;
use App\Services\MeasurementPaymentFinancialService;
use App\Services\MeasurementWorkflow;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\CommittedRowsSweeper;
use Tests\Support\MeasurementNoDisbursementRaceTasks as RaceTasks;
use Tests\Support\MeasurementNoDisbursementScenario as Scenario;

/*
 * O pagamento da medição original sem valor devido contra o MySQL de verdade,
 * com transações em processos próprios. O registro trava a Operation e a
 * medição antes de conferir o valor devido, e a decisão sem desembolso trava a
 * medição: a corrida é na linha da medição. Quem esperou o lock confere a
 * posição de depois do outro -- ou recebe a recusa de estado desatualizado --,
 * e nenhuma ordem paga duas vezes o mesmo saldo, registra pagamento sem valor
 * devido e sem regra, ou deixa pagamento e decisão sem desembolso juntos.
 */
pest()->group('mysql');

beforeEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requer MySQL para validar locks concorrentes. Execute: ./vendor/bin/sail composer test:parity');
    }

    $this->assertStringStartsWith(
        'nimbus_parity_check',
        DB::connection()->getDatabaseName(),
        'Estes testes recriam o banco. Use o banco temporário de composer test:parity.',
    );

    Artisan::call('migrate:fresh', ['--no-interaction' => true]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    // O seeder entra antes da foto: `role_has_permissions` não tem `id`, e o
    // sweeper não saberia limpá-la.
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->committedRows = CommittedRowsSweeper::afterFreshMigration();
    Storage::fake('local');
    Notification::fake();
});

afterEach(function () {
    if (DB::getDriverName() !== 'mysql' || ! isset($this->committedRows)) {
        return;
    }

    $this->committedRows->sweep();

    expect($this->committedRows->leftovers())->toBe([]);
});

/**
 * Roda as duas ações em processos próprios: a primeira segura por 750 ms o
 * primeiro lock que pede na tabela disputada, e a segunda só começa depois
 * disso. Dois registros de pagamento disputam a Operation, que os dois travam
 * primeiro; o registro e a decisão sem desembolso, a linha da medição.
 *
 * @param  array<string, mixed>  $scenario
 * @param  array<string, mixed>  $first
 * @param  array<string, mixed>  $second
 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
 */
function originalDueMysqlRace(array $scenario, array $first, array $second, string $lockTable = RaceTasks::MEASUREMENTS): array
{
    $marker = temporaryTestFilePath('original-due-race-lock', 'lock');
    @unlink($marker);
    $base = [
        'actor_id' => $scenario['actor']->id,
        'storage_root' => Storage::disk('local')->path(''),
        'lock_table' => $lockTable,
    ];

    $results = Concurrency::driver('process')->run([
        originalDueMysqlTask(['lock_marker' => $marker] + $first + $base),
        originalDueMysqlTask(['wait_for_marker' => $marker] + $second + $base),
    ]);
    @unlink($marker);

    return $results;
}

/**
 * @param  array<string, mixed>  $instruction  só escalares: a closure vai serializada para o processo filho
 */
function originalDueMysqlTask(array $instruction): Closure
{
    return static function () use ($instruction): array {
        return RaceTasks::run($instruction);
    };
}

/**
 * O motivo da recusa de um processo filho, para a falha dizer o que houve.
 *
 * @param  array<string, mixed>  $result
 */
function originalDueMysqlReason(array $result): string
{
    return "{$result['exception']}: {$result['message']}";
}

/**
 * Um registro de pagamento sobre a leitura da tela -- a versão do fluxo de
 * agora --, sem regra financeira.
 *
 * @param  array<string, mixed>  $scenario
 * @return array<string, mixed>
 */
function originalDueMysqlPayment(array $scenario, Measurement $measurement, string $amount, string $justification): array
{
    return [
        'action' => 'register_payment',
        'measurement_id' => $measurement->id,
        'revision' => (int) $measurement->fresh()->workflow_revision,
        'payment' => [
            'plan_set_id' => $scenario['planSets'][0]->id,
            'pay_date' => '2026-06-10',
            'amount' => $amount,
            'method' => 'TED',
            'financial_justification' => $justification,
        ],
    ];
}

// A corrida é decidida pela versão do fluxo, conferida antes do valor devido:
// quem esperou o lock é recusado como desatualizado. É essa conferência que
// deixa a leitura do valor devido, depois do lock, livre de corrida.
it('registers only one of two payments of the same amount due on MySQL: the waiter is refused as stale, and a retry on the refreshed page finds nothing due', function () {
    $scenario = Scenario::plan();
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [10]);
    $revisionBefore = (int) $measurement->workflow_revision;

    [$winner, $loser] = originalDueMysqlRace(
        $scenario,
        originalDueMysqlPayment($scenario, $measurement, '100000.00', 'Pagamento da competência.'),
        originalDueMysqlPayment($scenario, $measurement, '100000.00', 'O mesmo pagamento, pela mesma leitura da tela.'),
        'operations',
    );

    expect($winner['success'])->toBeTrue(originalDueMysqlReason($winner))
        ->and($loser['success'])->toBeFalse()
        ->and($loser['exception'])->toBe(MeasurementWorkflowException::class)
        ->and($loser['message'])->toBe('A etapa desta medição foi alterada por outra ação. Atualize a página.')
        ->and($loser['lock_wait_ms'])->toBeGreaterThan(250)
        ->and(MeasurementPayment::query()->where('measurement_id', $measurement->id)->pluck('id')->all())->toBe([$winner['result']])
        ->and($measurement->fresh()->workflow_revision)->toBe($revisionBefore + 1)
        ->and(app(MeasurementPaymentFinancialService::class)->amountDue($measurement->fresh())->isNothingDue())->toBeTrue();

    // Repetido com a página atualizada, o segundo pagamento já não encontra
    // saldo a pagar: acima do saldo, sem regra financeira, é recusado.
    try {
        app(MeasurementWorkflow::class)->registerPayments($measurement->fresh(), $scenario['actor'], [
            originalDueMysqlPayment($scenario, $measurement, '100000.00', 'Repetido depois de atualizar a página.')['payment'],
        ]);
        $retry = null;
    } catch (ValidationException $exception) {
        $retry = $exception->errors()['payments.0.amount'][0] ?? null;
    }

    expect($retry)->toBe('O pagamento excede o saldo autorizado e não possui regra financeira válida para a diferença. Torre A: valor aprovado R$ 100.000,00, já registrado R$ 100.000,00, saldo a pagar R$ 0,00; com este pagamento, o excedente acumulado chega a R$ 100.000,00. Acima do saldo, o pagamento só é registrado com uma regra financeira vigente que cubra o excedente acumulado; a justificativa sozinha não basta.')
        ->and(MeasurementPayment::query()->where('measurement_id', $measurement->id)->count())->toBe(1);
});

it('refuses under the lock, in both processes, two payments without rule on an original with nothing due on MySQL', function () {
    $scenario = Scenario::plan();
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [0]);
    $revisionBefore = (int) $measurement->workflow_revision;
    $refusal = 'O pagamento excede o saldo autorizado e não possui regra financeira válida para a diferença. Torre A: valor aprovado R$ 0,00, já registrado R$ 0,00, saldo a pagar R$ 0,00; com este pagamento, o excedente acumulado chega a R$ 1.000,00. Acima do saldo, o pagamento só é registrado com uma regra financeira vigente que cubra o excedente acumulado; a justificativa sozinha não basta. Sem valor devido em nenhum empreendimento, conclua a etapa Pagamento sem desembolso, com a justificativa obrigatória.';

    [$first, $second] = originalDueMysqlRace(
        $scenario,
        originalDueMysqlPayment($scenario, $measurement, '1000.00', 'Adiantamento pedido pela construtora.'),
        originalDueMysqlPayment($scenario, $measurement, '1000.00', 'O mesmo adiantamento, em outra aba.'),
        'operations',
    );

    // A recusa não muda a versão do fluxo: o segundo processo, depois de
    // esperar o lock, chega à mesma conferência -- e é recusado por ela, não
    // por estado desatualizado.
    expect($first['success'])->toBeFalse()
        ->and($first['exception'])->toBe(ValidationException::class)
        ->and($first['message'])->toBe($refusal)
        ->and($second['success'])->toBeFalse()
        ->and($second['exception'])->toBe(ValidationException::class)
        ->and($second['message'])->toBe($refusal)
        ->and($second['lock_wait_ms'])->toBeGreaterThan(250)
        ->and(MeasurementPayment::query()->where('measurement_id', $measurement->id)->exists())->toBeFalse()
        ->and($measurement->fresh()->workflow_revision)->toBe($revisionBefore)
        ->and(app(MeasurementWorkflow::class)->canConcludeWithoutDisbursement($measurement->fresh(), $scenario['actor']))->toBeTrue();
});

it('keeps the no-disbursement decision as the only outcome against a payment without rule on MySQL', function (bool $paymentFirst) {
    $scenario = Scenario::plan();
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [0]);
    $payment = originalDueMysqlPayment($scenario, $measurement, '1000.00', 'Pagamento concorrente sem regra financeira.');
    $decision = [
        'action' => 'conclude_without_disbursement',
        'measurement_id' => $measurement->id,
        'revision' => (int) $measurement->fresh()->workflow_revision,
        'notes' => Scenario::JUSTIFICATION,
    ];

    [$first, $second] = $paymentFirst
        ? originalDueMysqlRace($scenario, $payment, $decision)
        : originalDueMysqlRace($scenario, $decision, $payment);
    [$paymentResult, $decisionResult] = $paymentFirst ? [$first, $second] : [$second, $first];

    expect($decisionResult['success'])->toBeTrue(originalDueMysqlReason($decisionResult))
        ->and($paymentResult['success'])->toBeFalse()
        ->and($second['lock_wait_ms'])->toBeGreaterThan(250)
        // Primeiro, o pagamento é recusado pela conferência do valor devido;
        // depois da decisão, pelo estado que ela mudou.
        ->and($paymentResult['exception'])->toBe($paymentFirst ? ValidationException::class : MeasurementWorkflowException::class)
        ->and($paymentResult['message'])->toBe($paymentFirst
            ? 'O pagamento excede o saldo autorizado e não possui regra financeira válida para a diferença. Torre A: valor aprovado R$ 0,00, já registrado R$ 0,00, saldo a pagar R$ 0,00; com este pagamento, o excedente acumulado chega a R$ 1.000,00. Acima do saldo, o pagamento só é registrado com uma regra financeira vigente que cubra o excedente acumulado; a justificativa sozinha não basta. Sem valor devido em nenhum empreendimento, conclua a etapa Pagamento sem desembolso, com a justificativa obrigatória.'
            : 'A etapa desta medição foi alterada por outra ação. Atualize a página.')
        ->and(MeasurementPayment::query()->where('measurement_id', $measurement->id)->exists())->toBeFalse()
        ->and($measurement->fresh()->status)->toBe('approved')
        ->and($measurement->fresh()->current_stage)->toBe(MeasurementWorkflow::STAGE_FINALIZATION)
        ->and(app(MeasurementWorkflow::class)->noDisbursementDecision($measurement->fresh())['justification'] ?? null)->toBe(Scenario::JUSTIFICATION);
})->with([
    'payment first' => [true],
    'decision first' => [false],
]);

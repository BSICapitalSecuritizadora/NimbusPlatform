<?php

use App\Exceptions\MeasurementWorkflowException;
use App\Models\Measurement;
use App\Models\MeasurementPause;
use App\Models\MeasurementPayment;
use App\Services\MeasurementWorkflow;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\CommittedRowsSweeper;
use Tests\Support\MeasurementNoDisbursementRaceTasks as RaceTasks;
use Tests\Support\MeasurementNoDisbursementScenario as Scenario;

/*
 * A conclusão da etapa Pagamento sem desembolso (Fase 4A) contra o MySQL de
 * verdade, com transações em processos próprios, sobre a medição original em
 * que nada é devido.
 *
 * A decisão sem desembolso, a pausa da etapa e a devolução da Finalização
 * travam só a linha da medição; o registro de pagamento e a Finalização travam
 * a Operation e logo depois a medição. A corrida é sempre na linha da medição:
 * quem esperou o lock recebe a medição que o outro acabou de commitar, com a
 * versão do fluxo avançada, e recusa a ação como estado desatualizado -- em vez
 * de decidir sobre a leitura que a tela fez antes. Nenhuma ordem deixa a medição
 * com pagamento e decisão sem desembolso ao mesmo tempo, com duas decisões,
 * finalizada e devolvida, nem pausada e decidida. O SQLite serializa escritores
 * e nunca mostra nada disso.
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
    // O seeder entra antes da foto: ele concede permissões às roles em
    // `role_has_permissions`, tabela sem `id` que as migrations já povoam e que
    // o sweeper não sabe limpar. Depois da foto, a contagem dela mudaria e o
    // `afterEach` acusaria sobra que nenhum processo filho deixou.
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
 * lock da linha da medição -- onde as duas disputam --, e a segunda só começa
 * depois disso.
 *
 * @param  array<string, mixed>  $first
 * @param  array<string, mixed>  $second
 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
 */
function p4aMysqlRace(array $scenario, array $first, array $second): array
{
    $marker = temporaryTestFilePath('p4a-race-lock', 'lock');
    @unlink($marker);
    $base = [
        'actor_id' => $scenario['actor']->id,
        'storage_root' => Storage::disk('local')->path(''),
        'lock_table' => RaceTasks::MEASUREMENTS,
    ];

    $results = Concurrency::driver('process')->run([
        p4aMysqlRaceTask(['lock_marker' => $marker] + $first + $base),
        p4aMysqlRaceTask(['wait_for_marker' => $marker] + $second + $base),
    ]);
    @unlink($marker);

    return $results;
}

/**
 * @param  array<string, mixed>  $instruction  só escalares: a closure vai serializada para o processo filho
 */
function p4aMysqlRaceTask(array $instruction): Closure
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
function p4aMysqlRaceReason(array $result): string
{
    return "{$result['exception']}: {$result['message']}";
}

/**
 * A decisão sem desembolso da etapa Pagamento, com a versão do fluxo que a tela
 * leu.
 *
 * @return array<string, mixed>
 */
function p4aMysqlDecision(Measurement $measurement, string $justification): array
{
    return [
        'action' => 'conclude_without_disbursement',
        'measurement_id' => $measurement->id,
        'revision' => (int) $measurement->fresh()->workflow_revision,
        'notes' => $justification,
    ];
}

/**
 * Um pagamento bancário de verdade sobre a mesma leitura da tela: como nada é
 * devido, é divergência para mais e vem justificado.
 *
 * @return array<string, mixed>
 */
function p4aMysqlPayment(array $scenario, Measurement $measurement): array
{
    return [
        'action' => 'register_payment',
        'measurement_id' => $measurement->id,
        'revision' => (int) $measurement->fresh()->workflow_revision,
        'payment' => [
            'plan_set_id' => $scenario['planSets'][0]->id,
            'pay_date' => '2026-06-10',
            'amount' => '1000.00',
            'method' => 'TED',
            'financial_justification' => 'Pagamento concorrente registrado sobre a competência sem valor devido.',
        ],
    ];
}

/**
 * A pausa da etapa pendente, com a etapa e a versão que a tela leu.
 *
 * @return array<string, mixed>
 */
function p4aMysqlPause(Measurement $measurement): array
{
    $measurement = $measurement->fresh();

    return [
        'action' => 'pause',
        'measurement_id' => $measurement->id,
        'stage' => app(MeasurementWorkflow::class)->unifiedStage($measurement),
        'revision' => (int) $measurement->workflow_revision,
        'notes' => 'Pausa concorrente da etapa Pagamento.',
    ];
}

/**
 * A Finalização com a ciência da ausência de pagamento, com a versão e a
 * situação que a tela leu.
 *
 * @return array<string, mixed>
 */
function p4aMysqlFinalize(Measurement $measurement): array
{
    $measurement = $measurement->fresh();

    return [
        'action' => 'finalize_acknowledging',
        'measurement_id' => $measurement->id,
        'revision' => (int) $measurement->workflow_revision,
        'status' => $measurement->status,
    ];
}

/**
 * A devolução da Finalização à etapa Pagamento, com a versão e a situação que
 * a tela leu.
 *
 * @return array<string, mixed>
 */
function p4aMysqlReturnToPayment(Measurement $measurement): array
{
    $measurement = $measurement->fresh();

    return [
        'action' => 'return_to_payment',
        'measurement_id' => $measurement->id,
        'revision' => (int) $measurement->workflow_revision,
        'status' => $measurement->status,
        'notes' => 'Devolução concorrente para rever a decisão sem desembolso.',
    ];
}

/**
 * Os eventos da trilha protegida da medição com esta descrição -- só os da
 * etapa indicada, quando houver --, na ordem.
 *
 * @return Collection<int, Activity>
 */
function p4aMysqlEvents(Measurement $measurement, string $description, ?int $stage = null): Collection
{
    return Activity::query()
        ->where('log_name', 'measurement_workflow')
        ->where('subject_type', $measurement->getMorphClass())
        ->where('subject_id', $measurement->getKey())
        ->where('description', $description)
        ->orderBy('id')
        ->get()
        ->filter(fn (Activity $event): bool => $stage === null || (int) ($event->properties['stage'] ?? 0) === $stage)
        ->values();
}

/**
 * As decisões sem desembolso da etapa Pagamento registradas na trilha protegida.
 *
 * @return Collection<int, Activity>
 */
function p4aMysqlNoDisbursementDecisions(Measurement $measurement): Collection
{
    return p4aMysqlEvents($measurement, 'measurement_stage_approved', MeasurementWorkflow::STAGE_PAYMENT)
        ->filter(fn (Activity $event): bool => ($event->properties['no_disbursement']['decision'] ?? null) === 'no_disbursement')
        ->values();
}

/**
 * Quantos pagamentos a medição tem, contados no banco: nem a decisão sem
 * desembolso nem a recusa de quem perdeu a corrida criam pagamento algum.
 */
function p4aMysqlPaymentCount(Measurement $measurement): int
{
    return MeasurementPayment::query()->where('measurement_id', $measurement->id)->count();
}

it('records a single no-disbursement decision when two people conclude the zero-due Payment stage at the same time on MySQL', function () {
    $scenario = Scenario::plan();
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [0]);
    $revisionBefore = (int) $measurement->workflow_revision;
    $first = 'Primeira decisão: competência sem avanço físico, nada é devido.';
    $second = 'Segunda decisão, sobre a mesma leitura da tela.';

    [$winner, $loser] = p4aMysqlRace($scenario, p4aMysqlDecision($measurement, $first), p4aMysqlDecision($measurement, $second));

    expect($winner['success'])->toBeTrue(p4aMysqlRaceReason($winner))
        ->and($loser['success'])->toBeFalse()
        ->and($loser['exception'])->toBe(MeasurementWorkflowException::class)
        // Quem esperou o lock recebeu a versão do fluxo que a decisão vencedora
        // avançou: a recusa é de estado desatualizado, nunca uma segunda decisão.
        ->and($loser['message'])->toBe('A etapa desta medição foi alterada por outra ação. Atualize a página.')
        ->and($loser['lock_wait_ms'])->toBeGreaterThan(250);

    $decided = $measurement->fresh();
    $decision = p4aMysqlNoDisbursementDecisions($measurement)->sole();
    $paymentReview = $decided->reviews()->where('stage', MeasurementWorkflow::STAGE_PAYMENT)->sole();

    expect($decided->status)->toBe('approved')
        ->and($decided->current_stage)->toBe(MeasurementWorkflow::STAGE_FINALIZATION)
        ->and($decided->workflow_revision)->toBe($revisionBefore + 1)
        ->and(p4aMysqlEvents($measurement, 'measurement_stage_approved', MeasurementWorkflow::STAGE_PAYMENT))->toHaveCount(1)
        ->and($decision->properties['no_disbursement']['justification'])->toBe($first)
        ->and($decision->properties['no_disbursement']['payments_registered'])->toBe(0)
        ->and($decision->properties['no_disbursement']['amount_due']['nothing_due'])->toBeTrue()
        ->and($paymentReview->status)->toBe('approved')
        ->and($paymentReview->notes)->toBe($first)
        ->and(app(MeasurementWorkflow::class)->noDisbursementDecision($decided)['activity_id'])->toBe($decision->id)
        ->and($decided->reviews()->where('stage', MeasurementWorkflow::STAGE_FINALIZATION)->where('status', 'pending')->count())->toBe(1)
        ->and(p4aMysqlPaymentCount($measurement))->toBe(0);
});

it('lets either the payment or the no-disbursement decision of the zero-due Payment stage happen, never both, on MySQL', function (bool $paymentFirst) {
    $scenario = Scenario::plan();
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [0]);
    $payment = p4aMysqlPayment($scenario, $measurement);
    $decision = p4aMysqlDecision($measurement, Scenario::JUSTIFICATION);

    [$winner, $loser] = $paymentFirst
        ? p4aMysqlRace($scenario, $payment, $decision)
        : p4aMysqlRace($scenario, $decision, $payment);

    expect($winner['success'])->toBeTrue(p4aMysqlRaceReason($winner))
        ->and($loser['success'])->toBeFalse()
        ->and($loser['exception'])->toBe(MeasurementWorkflowException::class)
        ->and($loser['message'])->toBe('A etapa desta medição foi alterada por outra ação. Atualize a página.')
        ->and($loser['lock_wait_ms'])->toBeGreaterThan(250);

    $fresh = $measurement->fresh();
    $workflow = app(MeasurementWorkflow::class);

    if ($paymentFirst) {
        // O pagamento ficou, e a decisão não foi tomada sobre a leitura de antes
        // dele: a etapa continua pendente, agora com pagamento a aprovar.
        expect(MeasurementPayment::query()->where('measurement_id', $measurement->id)->pluck('id')->all())->toBe([$winner['result']])
            ->and($fresh->status)->toBe('awaiting_payment')
            ->and($fresh->current_stage)->toBe(MeasurementWorkflow::STAGE_PAYMENT)
            ->and($fresh->reviews()->where('stage', MeasurementWorkflow::STAGE_PAYMENT)->value('status'))->toBe('pending')
            ->and(p4aMysqlEvents($measurement, 'measurement_stage_approved', MeasurementWorkflow::STAGE_PAYMENT))->toBeEmpty()
            ->and(p4aMysqlEvents($measurement, 'measurement_payment_registered'))->toHaveCount(1)
            ->and($workflow->noDisbursementDecision($fresh))->toBeNull()
            ->and($workflow->canConcludeWithoutDisbursement($fresh, $scenario['actor']))->toBeFalse();
    } else {
        // A decisão ficou, e nenhum pagamento nasceu depois dela.
        expect(p4aMysqlPaymentCount($measurement))->toBe(0)
            ->and($fresh->status)->toBe('approved')
            ->and($fresh->current_stage)->toBe(MeasurementWorkflow::STAGE_FINALIZATION)
            ->and(p4aMysqlNoDisbursementDecisions($measurement))->toHaveCount(1)
            ->and(p4aMysqlEvents($measurement, 'measurement_payment_registered'))->toBeEmpty()
            ->and($workflow->noDisbursementDecision($fresh)['justification'])->toBe(Scenario::JUSTIFICATION)
            ->and($workflow->requiresNoDisbursementAcknowledgment($fresh))->toBeTrue()
            ->and($workflow->canRegisterPayment($fresh, $scenario['actor']))->toBeFalse();
    }
})->with([
    'payment first' => [true],
    'decision first' => [false],
]);

it('lets either the acknowledged Finalization or the return to the Payment stage happen, never both, on MySQL', function (bool $finalizationFirst) {
    $scenario = Scenario::plan();
    $measurement = Scenario::concludeWithoutDisbursement($scenario, Scenario::awaitingPayment($scenario, '2026-05', [0]));
    $decision = p4aMysqlNoDisbursementDecisions($measurement)->sole();
    $finalization = p4aMysqlFinalize($measurement);
    $return = p4aMysqlReturnToPayment($measurement);

    [$winner, $loser] = $finalizationFirst
        ? p4aMysqlRace($scenario, $finalization, $return)
        : p4aMysqlRace($scenario, $return, $finalization);

    expect($winner['success'])->toBeTrue(p4aMysqlRaceReason($winner))
        ->and($loser['success'])->toBeFalse()
        ->and($loser['exception'])->toBe(MeasurementWorkflowException::class)
        ->and($loser['message'])->toBe('A etapa desta medição foi alterada por outra ação. Atualize a página.')
        ->and($loser['lock_wait_ms'])->toBeGreaterThan(250);

    $fresh = $measurement->fresh();
    $workflow = app(MeasurementWorkflow::class);

    if ($finalizationFirst) {
        $acknowledgment = p4aMysqlEvents($measurement, 'measurement_finalized')->sole()->properties['no_disbursement_acknowledged'];

        // Finalizada com a ciência da decisão que valia; a devolução não reabriu nada.
        expect($fresh->status)->toBe('finalized')
            ->and($acknowledgment['decision_activity_id'])->toBe($decision->id)
            ->and($acknowledgment['justification'])->toBe(Scenario::JUSTIFICATION)
            ->and($acknowledgment['payments_registered'])->toBe(0)
            ->and($acknowledgment['acknowledged'])->toBe([
                'no_payment_due' => true,
                'no_bank_payment_registered' => true,
                'payment_stage_justification_reviewed' => true,
            ])
            ->and($workflow->noDisbursementAcknowledgment($fresh)['acknowledged_by'])->toBe($scenario['actor']->id)
            ->and(p4aMysqlEvents($measurement, 'measurement_finalization_returned'))->toBeEmpty()
            ->and($fresh->reviews()->where('stage', MeasurementWorkflow::STAGE_PAYMENT)->value('status'))->toBe('approved')
            ->and($fresh->reviews()->where('stage', MeasurementWorkflow::STAGE_FINALIZATION)->value('status'))->toBe('approved');
    } else {
        $returned = p4aMysqlEvents($measurement, 'measurement_finalization_returned')->sole();

        // De volta à etapa Pagamento, a decisão de antes deixa de valer: é
        // preciso decidir de novo, e a Finalização não aconteceu.
        expect($fresh->status)->toBe('awaiting_payment')
            ->and($fresh->current_stage)->toBe(MeasurementWorkflow::STAGE_PAYMENT)
            ->and($returned->properties['target_stage'])->toBe(MeasurementWorkflow::STAGE_PAYMENT)
            ->and(p4aMysqlEvents($measurement, 'measurement_finalized'))->toBeEmpty()
            ->and($fresh->reviews()->where('stage', MeasurementWorkflow::STAGE_PAYMENT)->value('status'))->toBe('pending')
            ->and($fresh->reviews()->where('stage', MeasurementWorkflow::STAGE_FINALIZATION)->value('status'))->toBe('rejected')
            ->and($workflow->noDisbursementDecision($fresh))->toBeNull()
            ->and($workflow->noDisbursementAcknowledgment($fresh))->toBeNull()
            ->and($workflow->canConcludeWithoutDisbursement($fresh, $scenario['actor']))->toBeTrue();
    }

    expect(p4aMysqlPaymentCount($measurement))->toBe(0);
})->with([
    'finalization first' => [true],
    'return first' => [false],
]);

it('lets either the no-disbursement decision or the pause of the Payment stage happen, never both, on MySQL', function (bool $decisionFirst) {
    $scenario = Scenario::plan();
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [0]);
    $decision = p4aMysqlDecision($measurement, Scenario::JUSTIFICATION);
    $pause = p4aMysqlPause($measurement);

    [$winner, $loser] = $decisionFirst
        ? p4aMysqlRace($scenario, $decision, $pause)
        : p4aMysqlRace($scenario, $pause, $decision);

    expect($winner['success'])->toBeTrue(p4aMysqlRaceReason($winner))
        ->and($loser['success'])->toBeFalse()
        ->and($loser['exception'])->toBe(MeasurementWorkflowException::class)
        ->and($loser['message'])->toBe('A etapa desta medição foi alterada por outra ação. Atualize a página.')
        ->and($loser['lock_wait_ms'])->toBeGreaterThan(250);

    $fresh = $measurement->fresh();
    $workflow = app(MeasurementWorkflow::class);
    $paymentReview = $fresh->reviews()->where('stage', MeasurementWorkflow::STAGE_PAYMENT)->sole();

    if ($decisionFirst) {
        // Decidida, a etapa Pagamento terminou: não há mais o que pausar.
        expect($fresh->status)->toBe('approved')
            ->and($fresh->current_stage)->toBe(MeasurementWorkflow::STAGE_FINALIZATION)
            ->and(p4aMysqlNoDisbursementDecisions($measurement))->toHaveCount(1)
            ->and($paymentReview->status)->toBe('approved')
            ->and($paymentReview->paused_at)->toBeNull()
            ->and(MeasurementPause::query()->where('measurement_id', $measurement->id)->count())->toBe(0)
            ->and(p4aMysqlEvents($measurement, 'measurement_stage_paused'))->toBeEmpty()
            ->and($workflow->canPause($fresh, $scenario['actor']))->toBeFalse();
    } else {
        $openPause = MeasurementPause::query()->where('measurement_id', $measurement->id)->sole();

        // Pausada, a etapa Pagamento continua pendente e sem decisão: decidir
        // só depois da retomada.
        expect($fresh->status)->toBe('paused')
            ->and($workflow->unifiedStage($fresh))->toBe(MeasurementWorkflow::STAGE_PAYMENT)
            ->and($openPause->stage)->toBe(MeasurementWorkflow::STAGE_PAYMENT)
            ->and($openPause->paused_operation_status)->toBe('awaiting_payment')
            ->and($openPause->resumed_at)->toBeNull()
            ->and($paymentReview->status)->toBe('pending')
            ->and($paymentReview->paused_at)->not->toBeNull()
            ->and(p4aMysqlEvents($measurement, 'measurement_stage_approved', MeasurementWorkflow::STAGE_PAYMENT))->toBeEmpty()
            ->and(p4aMysqlEvents($measurement, 'measurement_stage_paused'))->toHaveCount(1)
            ->and($workflow->noDisbursementDecision($fresh))->toBeNull()
            ->and($workflow->canConcludeWithoutDisbursement($fresh, $scenario['actor']))->toBeFalse();
    }

    expect(p4aMysqlPaymentCount($measurement))->toBe(0);
})->with([
    'decision first' => [true],
    'pause first' => [false],
]);

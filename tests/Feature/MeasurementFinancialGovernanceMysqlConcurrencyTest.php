<?php

use App\Enums\MeasurementRevisionStatus;
use App\Exceptions\MeasurementSegregationException;
use App\Exceptions\MeasurementWorkflowException;
use App\Models\MeasurementPayment;
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
use Tests\Support\MeasurementGovernanceScenario as Governance;
use Tests\Support\MeasurementNoDisbursementRaceTasks as RaceTasks;

/*
 * A governança financeira da Fase 4B-1 contra o MySQL de verdade, com
 * transações em processos próprios. O limite de uma regra financeira vale para
 * o excedente acumulado do empreendimento: dois pagamentos excepcionais
 * correndo contra o mesmo limite nunca o reaproveitam, e a reavaliação que
 * corre com um pagamento novo mede a exposição do momento do registro. A
 * segregação de funções não depende da ordem: quem fez a revisão ou enviou o
 * comprovante nunca aprova o próprio ato, nem pelo override administrativo,
 * por mais que corra com quem pode aprová-lo.
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
 * As duas ações em processos próprios: a primeira segura por 750 ms o primeiro
 * lock que pede na tabela disputada, e a segunda só começa depois disso.
 *
 * @param  array<string, mixed>  $first
 * @param  array<string, mixed>  $second
 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
 */
function governanceMysqlRace(array $first, array $second, string $lockTable): array
{
    $marker = temporaryTestFilePath('governance-race-lock', 'lock');
    @unlink($marker);
    $base = [
        'storage_root' => Storage::disk('local')->path(''),
        'lock_table' => $lockTable,
    ];

    $results = Concurrency::driver('process')->run([
        governanceMysqlTask(['lock_marker' => $marker] + $first + $base),
        governanceMysqlTask(['wait_for_marker' => $marker] + $second + $base),
    ]);
    @unlink($marker);

    return $results;
}

/**
 * @param  array<string, mixed>  $instruction  só escalares e listas: a closure vai serializada para o processo filho
 */
function governanceMysqlTask(array $instruction): Closure
{
    return static function () use ($instruction): array {
        return RaceTasks::run($instruction);
    };
}

/**
 * @param  array<string, mixed>  $result
 */
function governanceMysqlReason(array $result): string
{
    return "{$result['exception']}: {$result['message']}";
}

/**
 * A linha de um pagamento excepcional coberto pela regra, para o processo
 * filho.
 *
 * @param  array<string, mixed>  $scenario
 * @return array<string, int|string>
 */
function governanceMysqlExceptionalRow(array $scenario, string $amount, int $ruleId): array
{
    return Governance::row($scenario, 0, $amount, [
        'financial_rule_id' => $ruleId,
        'financial_justification' => Governance::JUSTIFICATION,
    ]);
}

it('never lets two exceptional payments racing on MySQL reuse the same rule limit, and the retry sees the cumulative excess', function () {
    $scenario = Governance::plan();
    // Torre Norte: 1.000.000,00 × 10% = 100.000,00 aprovados, pagos por inteiro.
    $measurement = Governance::awaitingPayment($scenario, '2026-05', [10, 10]);
    Governance::pay($scenario, $measurement, [Governance::row($scenario, 0, '100000.00')]);
    $rule = Governance::rule($scenario, ['maximum_difference_amount' => '5000.00']);
    $revision = (int) $measurement->fresh()->workflow_revision;
    $instruction = fn (string $amount): array => [
        'action' => 'register_payment',
        'actor_id' => $scenario['payer']->id,
        'measurement_id' => $measurement->id,
        'revision' => $revision,
        'payment' => governanceMysqlExceptionalRow($scenario, $amount, $rule->id),
    ];

    // Cada um sozinho cabe no limite (R$ 3.000,00 de R$ 5.000,00); os dois
    // juntos, não (R$ 6.000,00).
    [$first, $second] = governanceMysqlRace($instruction('3000.00'), $instruction('3000.00'), RaceTasks::OPERATIONS);
    $winners = collect([$first, $second])->where('success', true);
    $loser = collect([$first, $second])->firstWhere('success', false);

    expect($winners)->toHaveCount(1)
        ->and($loser)->not->toBeNull()
        ->and($loser['exception'])->toBe(MeasurementWorkflowException::class, governanceMysqlReason($loser));

    // A nova tentativa, sobre a página atualizada, mede o excedente acumulado.
    $retry = null;

    try {
        Governance::pay($scenario, $measurement, [governanceMysqlExceptionalRow($scenario, '3000.00', $rule->id)]);
    } catch (ValidationException $exception) {
        $retry = $exception->errors()['payments.0.financial_rule_id'][0] ?? null;
    }

    $paid = MeasurementPayment::query()->where('measurement_id', $measurement->id)->where('plan_set_id', $scenario['planSets'][0]->id)->sum('amount');

    expect($retry)->toContain('A regra escolhida não cobre este pagamento.')
        ->and($retry)->toContain('R$ 6.000,00')
        ->and(bcsub((string) $paid, '100000.00', 2))->toBe('3000.00');
});

it('keeps the registration-time exposure when a reassessment races with a new exceptional payment on MySQL', function () {
    $scenario = Governance::plan();
    $measurement = Governance::awaitingPayment($scenario, '2026-05', [10, 10]);
    $rule = Governance::rule($scenario, ['maximum_difference_amount' => '5000.00']);
    $first = Governance::pay($scenario, $measurement, [governanceMysqlExceptionalRow($scenario, '104000.00', $rule->id)])->sole();
    $revision = (int) $measurement->fresh()->workflow_revision;

    [$reassess, $register] = governanceMysqlRace([
        'action' => 'reassess_payment',
        'actor_id' => $scenario['payer']->id,
        'measurement_id' => $measurement->id,
        'payment_id' => $first->id,
        'revision' => $revision,
        'payment' => ['financial_justification' => 'Justificativa corrigida depois da devolução.'],
    ], [
        'action' => 'register_payment',
        'actor_id' => $scenario['payer']->id,
        'measurement_id' => $measurement->id,
        'revision' => $revision,
        'payment' => governanceMysqlExceptionalRow($scenario, '1000.00', $rule->id),
    ], RaceTasks::MEASUREMENTS);

    // Quem esperou a linha da medição é recusado como desatualizado; repetido
    // sobre a página atualizada, passa -- e nenhum dos dois desfaz o outro.
    expect(collect([$reassess, $register])->where('success', true))->toHaveCount(1);

    if (! $reassess['success']) {
        expect($reassess['exception'])->toBe(MeasurementWorkflowException::class, governanceMysqlReason($reassess));
        app(MeasurementWorkflow::class)->reassessPayment($first->fresh(), $scenario['payer'], ['financial_justification' => 'Justificativa corrigida depois da devolução.'], (int) $measurement->fresh()->workflow_revision);
    } else {
        expect($register['exception'])->toBe(MeasurementWorkflowException::class, governanceMysqlReason($register));
        Governance::pay($scenario, $measurement, [governanceMysqlExceptionalRow($scenario, '1000.00', $rule->id)]);
    }

    $assessments = MeasurementPayment::query()->where('measurement_id', $measurement->id)->orderBy('id')->get()
        ->map(fn (MeasurementPayment $payment): array => $payment->financial_assessment);

    // O primeiro pagamento continua medindo o excedente de quando foi
    // registrado (R$ 4.000,00), com a regra dele; o segundo, o acumulado.
    expect($assessments)->toHaveCount(2)
        ->and($assessments[0]['cumulative_excess_amount'])->toBe('4000.00')
        ->and($assessments[0]['excess_amount'])->toBe('4000.00')
        ->and($assessments[0]['rule']['id'])->toBe($rule->id)
        ->and($assessments[0]['justification'])->toBe('Justificativa corrigida depois da devolução.')
        ->and($assessments[1]['cumulative_excess_amount'])->toBe('5000.00')
        ->and($assessments[1]['excess_amount'])->toBe('1000.00');
});

it('never lets the revision maker approve it at Compliance on MySQL, even by admin override, while the independent approver races', function () {
    $scenario = Governance::plan();
    $base = Governance::finalized($scenario, '2026-05', [10, 10]);
    // O super-admin revisa sem participar da operação e tentaria aprovar a
    // própria revisão pelo override administrativo.
    $revision = Governance::revisionAtCompliance($scenario, $base, [12, 10], $scenario['superAdmin'], overrideReason: 'Correção pedida pela auditoria externa.');
    $workflowRevision = (int) $revision->fresh()->workflow_revision;
    $approve = fn (int $actorId, ?string $reason = null): array => array_filter([
        'action' => 'approve_stage',
        'actor_id' => $actorId,
        'measurement_id' => $revision->id,
        'stage' => 3,
        'revision' => $workflowRevision,
        'override_reason' => $reason,
    ], fn (mixed $value): bool => $value !== null);

    [$maker, $independent] = governanceMysqlRace(
        $approve($scenario['superAdmin']->id, 'Aprovação em nome da Compliance, ausente.'),
        $approve($scenario['compliance']->id),
        RaceTasks::OPERATIONS,
    );

    expect($maker['success'])->toBeFalse()
        ->and($maker['exception'])->toBe(MeasurementSegregationException::class, governanceMysqlReason($maker))
        ->and($independent['success'])->toBeTrue(governanceMysqlReason($independent))
        ->and($revision->fresh()->revisionStatus())->toBe(MeasurementRevisionStatus::Effective)
        ->and($base->fresh()->revisionStatus())->toBe(MeasurementRevisionStatus::Superseded);
});

it('never lets the uploader approve the receipt they sent on MySQL, even by admin override, while the Finalizer approves it', function () {
    $scenario = Governance::plan();
    $measurement = Governance::awaitingPayment($scenario, '2026-05', [10, 10]);
    // Fase 4B-2: o admin registra o pagamento pelo override -- e com ele envia
    // o comprovante -- e tentaria aprová-lo.
    Governance::pay($scenario, $measurement, [Governance::row($scenario, 0, '100000.00'), Governance::row($scenario, 1, '50000.00')], $scenario['admin'], 'Registro em nome do Gestor de Pagamento, ausente.');
    Governance::approvePaymentStage($scenario, $measurement);
    $evidence = $measurement->fresh()->payments()->orderBy('id')->first()->currentReceiptEvidence()->firstOrFail();
    $review = fn (int $actorId, ?string $reason = null): array => array_filter([
        'action' => 'approve_receipt',
        'actor_id' => $actorId,
        'measurement_id' => $measurement->id,
        'evidence_id' => $evidence->id,
        'revision' => (int) $measurement->fresh()->workflow_revision,
        'override_reason' => $reason,
    ], fn (mixed $value): bool => $value !== null);

    [$uploader, $finalizer] = governanceMysqlRace(
        $review($scenario['admin']->id, 'Conferência em nome do Finalizador, ausente.'),
        $review($scenario['finalizer']->id),
        RaceTasks::MEASUREMENTS,
    );

    expect($uploader['success'])->toBeFalse()
        ->and($uploader['exception'])->toBe(MeasurementSegregationException::class, governanceMysqlReason($uploader))
        ->and($finalizer['success'])->toBeTrue(governanceMysqlReason($finalizer))
        ->and($evidence->fresh()->reviewer_user_id)->toBe($scenario['finalizer']->id);
});

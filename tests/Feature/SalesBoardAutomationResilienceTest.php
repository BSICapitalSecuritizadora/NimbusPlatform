<?php

use App\Enums\SalesBoardAutomationAlertType;
use App\Enums\SalesBoardAutomationAttemptOutcome;
use App\Enums\SalesBoardAutomationRunStatus;
use App\Enums\SalesBoardAutomationTargetStatus;
use App\Models\SalesBoardAutomationAlert;
use App\Models\SalesBoardAutomationAttempt;
use App\Models\SalesBoardAutomationRun;
use App\Models\SalesBoardAutomationTarget;
use App\Models\SalesBoardCycle;
use App\Models\User;
use App\Notifications\SalesBoardAutomationNotification;
use App\Services\SalesBoards\SalesBoardAutomationAlertDispatcher;
use App\Services\SalesBoards\SalesBoardAutomationReminderService;
use App\Services\SalesBoards\SalesBoardAutomationRetryPolicy;
use App\Services\SalesBoards\SalesBoardAutomationSuspensionNotifier;
use App\Services\SalesBoards\SalesBoardAutomationTargetProcessor;
use App\Services\SalesBoards\SalesBoardGenerationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Tests\Support\SalesBoards\AutomationFixture;
use Tests\Support\SalesBoards\FixedSalesBoardAutomationRecipientResolver;

/**
 * O que acontece quando a automação não termina como deveria.
 *
 * Processo morto no meio (falta de memória, deploy, reinício), alvo que estoura
 * fora da geração, lembrete que quebra, relógio que escorrega um segundo. Em
 * todos os casos a automação precisa continuar operável sem ninguém ir ao banco.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    AutomationFixture::disable();
    Notification::fake();
});

it('marks a run left executing by a dead process as interrupted, and alerts', function () {
    // 13:00 em UTC, o fuso técnico; 10:00 em Brasília.
    $this->travelTo(CarbonImmutable::parse('2026-09-13 13:00:00', 'UTC'));

    $dead = SalesBoardAutomationRun::factory()->running(now()->subHours(4))->create();

    $construction = AutomationFixture::readyConstruction();
    AutomationFixture::enable([$construction]);
    $recipient = User::factory()->create();
    FixedSalesBoardAutomationRecipientResolver::bind($recipient);

    $run = AutomationFixture::run();

    $dead->refresh();

    expect($dead->status)->toBe(SalesBoardAutomationRunStatus::Interrupted)
        ->and($dead->finished_at)->not->toBeNull()
        ->and($dead->failure_message)->toContain('não terminou')
        ->and($dead->status->isTechnicallyHealthy())->toBeFalse()
        // A execução de agora segue normalmente.
        ->and($run->status)->toBe(SalesBoardAutomationRunStatus::Completed)
        ->and($run->generated_count)->toBe(1);

    $alert = SalesBoardAutomationAlert::query()->where('channel', 'mail')->sole();

    expect($alert->alert_type)->toBe(SalesBoardAutomationAlertType::RunInterrupted)
        ->and($alert->sales_board_automation_run_id)->toBe($dead->id)
        ->and($alert->recipient_user_id)->toBe($recipient->id);

    // O horário do aviso é o de Brasília, não o UTC em que a execução foi gravada.
    Notification::assertSentTo(
        $recipient,
        SalesBoardAutomationNotification::class,
        fn (SalesBoardAutomationNotification $notification): bool => str_contains($notification->constructionName, 'iniciada em 13/09/2026 06:00'),
    );

    // A execução seguinte não reabre nem reavisa.
    AutomationFixture::run();

    expect(SalesBoardAutomationAlert::query()->where('channel', 'mail')->count())->toBe(1);
});

it('leaves a recent executing run alone, because it may still be alive', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-13 13:00:00'));

    $recent = SalesBoardAutomationRun::factory()->running(now()->subMinutes(90))->create();

    $construction = AutomationFixture::readyConstruction();
    AutomationFixture::enable([$construction]);

    AutomationFixture::run();

    expect($recent->fresh()->status)->toBe(SalesBoardAutomationRunStatus::Running)
        ->and(SalesBoardAutomationAlert::query()->count())->toBe(0);
});

it('reserves the attempt before generating, so a dead process still counts it', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-13 13:20:00'));

    $construction = AutomationFixture::readyConstruction();
    AutomationFixture::enable([$construction]);

    $real = app(SalesBoardGenerationService::class);
    $seen = null;

    $mock = Mockery::mock(SalesBoardGenerationService::class);
    $mock->shouldReceive('generateForConstruction')
        ->andReturnUsing(function ($construction, $month, $actor = null, $dryRun = false) use ($real, &$seen) {
            // O que já está gravado quando a geração começa: é o que sobrevive
            // se o processo morrer daqui em diante.
            $seen = SalesBoardAutomationTarget::query()->sole()->only([
                'attempt_count', 'in_flight_run_id', 'next_attempt_at', 'status',
            ]);

            return $real->generateForConstruction($construction, $month, $actor, $dryRun);
        });
    app()->instance(SalesBoardGenerationService::class, $mock);

    $run = AutomationFixture::run();
    $target = SalesBoardAutomationTarget::query()->sole();

    expect($seen['attempt_count'])->toBe(1)
        ->and($seen['in_flight_run_id'])->toBe($run->id)
        // Já com o backoff de uma falha técnica, na hora cheia.
        ->and($seen['next_attempt_at']->toDateTimeString())->toBe('2026-09-13 14:00:00')
        // E, terminada a geração, o desfecho real sobrescreve a reserva.
        ->and($target->status)->toBe(SalesBoardAutomationTargetStatus::Satisfied)
        ->and($target->in_flight_run_id)->toBeNull()
        ->and($target->next_attempt_at)->toBeNull()
        ->and($target->attempt_count)->toBe(1);
});

it('records the attempt a dead process left reserved as an interrupted failure, and moves the queue on', function () {
    $construction = AutomationFixture::readyConstruction();
    AutomationFixture::enable([$construction]);

    // O estado exato que a reserva deixa quando o processo morre na geração.
    $this->travelTo(CarbonImmutable::parse('2026-09-13 10:00:00'));
    $dead = SalesBoardAutomationRun::factory()->running(now())->create();
    $target = SalesBoardAutomationTarget::factory()->create([
        'construction_id' => $construction->id,
        'reference_month' => '2026-08-01',
        'status' => SalesBoardAutomationTargetStatus::Pending,
        'attempt_count' => 1,
        'in_flight_run_id' => $dead->id,
        'first_attempt_at' => now(),
        'last_attempt_at' => now(),
        'next_attempt_at' => now()->addHour(),
    ]);

    // Uma hora depois o morto ainda pode estar vivo: o alvo não é tocado.
    $this->travelTo(CarbonImmutable::parse('2026-09-13 11:00:00'));
    $meanwhile = AutomationFixture::run();

    expect($meanwhile->targets_attempted)->toBe(0)
        ->and($meanwhile->skipped_count)->toBe(1)
        ->and(SalesBoardAutomationAttempt::query()->count())->toBe(0)
        ->and(SalesBoardCycle::query()->count())->toBe(0);

    // Passado o prazo, a execução morta é encerrada e a tentativa dela vira falha.
    $this->travelTo(CarbonImmutable::parse('2026-09-13 14:00:00'));
    $recovery = AutomationFixture::run();

    $attempts = SalesBoardAutomationAttempt::query()->orderBy('id')->get();
    $interrupted = $attempts->first();

    expect($dead->fresh()->status)->toBe(SalesBoardAutomationRunStatus::Interrupted)
        ->and($interrupted->outcome)->toBe(SalesBoardAutomationAttemptOutcome::Failed)
        ->and($interrupted->reason_code)->toBe(SalesBoardAutomationTargetProcessor::INTERRUPTED_CODE)
        ->and($interrupted->sales_board_automation_run_id)->toBe($dead->id)
        ->and($interrupted->attempt_number)->toBe(1)
        // A espera da reserva já venceu: a mesma execução tenta de novo e gera.
        ->and($attempts->pluck('outcome')->all())
        ->toBe([SalesBoardAutomationAttemptOutcome::Failed, SalesBoardAutomationAttemptOutcome::Generated])
        ->and($recovery->generated_count)->toBe(1)
        ->and($target->fresh()->status)->toBe(SalesBoardAutomationTargetStatus::Satisfied)
        ->and($target->fresh()->attempt_count)->toBe(2)
        ->and($target->fresh()->consecutive_failure_count)->toBe(0)
        ->and($target->fresh()->in_flight_run_id)->toBeNull();
});

it('counts consecutive technical failures, not blocked attempts, for backoff and escalation', function () {
    $construction = AutomationFixture::blockedConstruction();
    AutomationFixture::enable([$construction]);
    FixedSalesBoardAutomationRecipientResolver::bind(User::factory()->create());
    config()->set('sales_board.automation.reminders.failed_after_attempts', 3);

    // Cinco dias bloqueado, uma tentativa por dia.
    foreach (['2026-09-13', '2026-09-14', '2026-09-15', '2026-09-16', '2026-09-17'] as $day) {
        $this->travelTo(CarbonImmutable::parse($day.' 13:00:00'));
        AutomationFixture::run($day);
    }

    expect(SalesBoardAutomationTarget::query()->sole()->attempt_count)->toBe(5);

    // A primeira falha técnica da vida do alvo.
    $mock = Mockery::mock(SalesBoardGenerationService::class);
    $mock->shouldReceive('generateForConstruction')->andThrow(new RuntimeException('transitório'));
    app()->instance(SalesBoardGenerationService::class, $mock);

    $this->travelTo(CarbonImmutable::parse('2026-09-18 13:00:00'));
    AutomationFixture::run('2026-09-18');

    $target = SalesBoardAutomationTarget::query()->sole();

    // Antes: degrau de 8 horas e escalação de "falha repetida" na primeira falha.
    expect($target->status)->toBe(SalesBoardAutomationTargetStatus::Failed)
        ->and($target->attempt_count)->toBe(6)
        ->and($target->consecutive_failure_count)->toBe(1)
        ->and($target->next_attempt_at->toDateTimeString())->toBe('2026-09-18 14:00:00')
        ->and(SalesBoardAutomationAlert::query()->where('alert_type', SalesBoardAutomationAlertType::GenerationFailed)->count())->toBe(0);

    // Falhas que de fato se repetem sobem a escada e escalam no limiar.
    foreach (['2026-09-18 14:00:00', '2026-09-18 16:00:00'] as $instant) {
        $this->travelTo(CarbonImmutable::parse($instant));
        AutomationFixture::run('2026-09-18');
    }

    $target->refresh();

    expect($target->consecutive_failure_count)->toBe(3)
        ->and($target->next_attempt_at->toDateTimeString())->toBe('2026-09-18 20:00:00')
        ->and(SalesBoardAutomationAlert::query()->where('alert_type', SalesBoardAutomationAlertType::GenerationFailed)->where('channel', 'mail')->count())->toBe(1);
});

it('resets the failure streak once the source blocks again', function () {
    $construction = AutomationFixture::blockedConstruction();
    AutomationFixture::enable([$construction]);

    $mock = Mockery::mock(SalesBoardGenerationService::class);
    $mock->shouldReceive('generateForConstruction')->andThrow(new RuntimeException('transitório'));
    app()->instance(SalesBoardGenerationService::class, $mock);

    $this->travelTo(CarbonImmutable::parse('2026-09-13 13:00:00'));
    AutomationFixture::run();

    expect(SalesBoardAutomationTarget::query()->sole()->consecutive_failure_count)->toBe(1);

    app()->forgetInstance(SalesBoardGenerationService::class);

    $this->travelTo(CarbonImmutable::parse('2026-09-13 14:00:00'));
    AutomationFixture::run();

    $target = SalesBoardAutomationTarget::query()->sole();

    expect($target->status)->toBe(SalesBoardAutomationTargetStatus::Blocked)
        ->and($target->consecutive_failure_count)->toBe(0);
});

it('retries on the hourly tick even when the previous run started a second late', function () {
    $construction = AutomationFixture::blockedConstruction();
    AutomationFixture::enable([$construction]);

    $this->travelTo(CarbonImmutable::parse('2026-09-13 13:00:01'));
    AutomationFixture::run('2026-09-13');

    expect(SalesBoardAutomationTarget::query()->sole()->next_attempt_at->toDateTimeString())
        ->toBe('2026-09-14 13:00:00');

    $this->travelTo(CarbonImmutable::parse('2026-09-14 13:00:00'));
    $run = AutomationFixture::run('2026-09-14');

    // Antes: 13:00:01 > 13:00:00, e a tentativa escorregava uma hora por dia.
    expect($run->targets_attempted)->toBe(1)
        ->and($run->skipped_count)->toBe(0);
});

it('stops re-inserting the whole history on every hourly run', function () {
    $first = AutomationFixture::readyConstruction('1');
    $second = AutomationFixture::readyConstruction('2');
    AutomationFixture::enable([$first, $second], '2026-05-01');

    $initial = AutomationFixture::run('2026-09-13');

    expect($initial->targets_discovered)->toBe(8)
        ->and(SalesBoardAutomationTarget::query()->where('status', SalesBoardAutomationTargetStatus::Satisfied)->count())->toBe(8);

    $inserts = 0;
    SalesBoardAutomationTarget::creating(function () use (&$inserts): void {
        $inserts++;
    });

    $steady = AutomationFixture::run('2026-09-13');

    // Nada a fazer: nenhuma tentativa de INSERT, e o histórico satisfeito não
    // aparece como "descoberto".
    expect($inserts)->toBe(0)
        ->and($steady->targets_discovered)->toBe(0)
        ->and($steady->targets_attempted)->toBe(0)
        ->and(SalesBoardAutomationTarget::query()->count())->toBe(8);
});

it('keeps processing the batch when one target breaks outside the generation', function () {
    $broken = AutomationFixture::readyConstruction('1');
    $healthy = AutomationFixture::readyConstruction('2');
    AutomationFixture::enable([$broken, $healthy]);

    $real = app(SalesBoardAutomationTargetProcessor::class);
    $mock = Mockery::mock(SalesBoardAutomationTargetProcessor::class);
    $mock->shouldReceive('process')->andReturnUsing(function ($run, $target, $now) use ($real, $broken) {
        if ((int) $target->construction_id === (int) $broken->id) {
            throw new RuntimeException('falha ao gravar o estado do alvo');
        }

        return $real->process($run, $target, $now);
    });
    $mock->shouldReceive('recordUnexpectedFailure')->andReturnUsing(fn (...$arguments) => $real->recordUnexpectedFailure(...$arguments));
    $mock->shouldReceive('recordInterruption')->andReturnUsing(fn (...$arguments) => $real->recordInterruption(...$arguments));
    app()->instance(SalesBoardAutomationTargetProcessor::class, $mock);

    $run = AutomationFixture::run();

    // Antes: a exceção saía do laço, a execução virava "falhou" e o segundo
    // empreendimento ficava sem ser processado naquela hora.
    expect($run->status)->toBe(SalesBoardAutomationRunStatus::CompletedWithFailures)
        ->and($run->failed_count)->toBe(1)
        ->and($run->generated_count)->toBe(1)
        ->and($run->failure_message)->toBeNull()
        ->and(SalesBoardAutomationTarget::query()->where('construction_id', $healthy->id)->sole()->status)
        ->toBe(SalesBoardAutomationTargetStatus::Satisfied);
});

it('records the failure on the target when the state write breaks after the reservation', function () {
    $construction = AutomationFixture::blockedConstruction();
    AutomationFixture::enable([$construction]);

    $policy = Mockery::mock(SalesBoardAutomationRetryPolicy::class)->makePartial();
    $policy->shouldReceive('afterBlocked')->andThrow(new RuntimeException('falha ao gravar o bloqueio'));
    app()->instance(SalesBoardAutomationRetryPolicy::class, $policy);

    $this->travelTo(CarbonImmutable::parse('2026-09-13 13:00:00'));
    $run = AutomationFixture::run();

    $target = SalesBoardAutomationTarget::query()->sole();
    $attempt = SalesBoardAutomationAttempt::query()->sole();

    expect($run->status)->toBe(SalesBoardAutomationRunStatus::CompletedWithFailures)
        ->and($run->failed_count)->toBe(1)
        ->and($target->status)->toBe(SalesBoardAutomationTargetStatus::Failed)
        ->and($target->last_error_code)->toBe('RuntimeException')
        ->and($target->in_flight_run_id)->toBeNull()
        ->and($target->consecutive_failure_count)->toBe(1)
        ->and($attempt->outcome)->toBe(SalesBoardAutomationAttemptOutcome::Failed)
        ->and($attempt->sales_board_automation_run_id)->toBe($run->id);
});

it('treats lock contention with another instance as a skip, not a technical failure', function () {
    $contended = AutomationFixture::readyConstruction('1');
    $healthy = AutomationFixture::readyConstruction('2');
    AutomationFixture::enable([$contended, $healthy]);

    $real = app(SalesBoardAutomationTargetProcessor::class);
    $mock = Mockery::mock(SalesBoardAutomationTargetProcessor::class);
    $mock->shouldReceive('process')->andReturnUsing(function ($run, $target, $now) use ($real, $contended) {
        if ((int) $target->construction_id === (int) $contended->id) {
            throw new QueryException(
                'mysql',
                'select * from `sales_board_automation_targets` where `id` = ? for update',
                [(int) $target->id],
                new PDOException('SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction'),
            );
        }

        return $real->process($run, $target, $now);
    });
    $mock->shouldReceive('recordUnexpectedFailure')->andReturnUsing(fn (...$arguments) => $real->recordUnexpectedFailure(...$arguments));
    $mock->shouldReceive('recordInterruption')->andReturnUsing(fn (...$arguments) => $real->recordInterruption(...$arguments));
    app()->instance(SalesBoardAutomationTargetProcessor::class, $mock);

    $run = AutomationFixture::run();

    expect($run->status)->toBe(SalesBoardAutomationRunStatus::Completed)
        ->and($run->skipped_count)->toBe(1)
        ->and($run->failed_count)->toBe(0)
        ->and($run->generated_count)->toBe(1);
});

it('does not turn a run that generated cycles into a failure when the reminders break', function () {
    $construction = AutomationFixture::readyConstruction();
    AutomationFixture::enable([$construction]);

    $this->mock(SalesBoardAutomationReminderService::class, function ($mock): void {
        $mock->shouldReceive('run')->andThrow(new RuntimeException('lembrete quebrado'));
    });

    $run = AutomationFixture::run();

    expect($run->status)->toBe(SalesBoardAutomationRunStatus::CompletedWithFailures)
        ->and($run->generated_count)->toBe(1)
        ->and($run->failure_message)->toContain('lembretes falharam')
        ->and($run->failure_message)->toContain('terminou normalmente')
        ->and(SalesBoardCycle::query()->count())->toBe(1);
});

it('keeps what was already counted and names the stage when the orchestration breaks', function () {
    $construction = AutomationFixture::readyConstruction();
    AutomationFixture::enable([$construction]);

    $this->mock(SalesBoardAutomationSuspensionNotifier::class, function ($mock): void {
        $mock->shouldReceive('notify')->andThrow(new RuntimeException('falha no meio'));
    });

    $run = AutomationFixture::run();

    // Antes a mensagem dizia "antes de os alvos serem processados" em qualquer caso.
    expect($run->status)->toBe(SalesBoardAutomationRunStatus::Failed)
        ->and($run->targets_discovered)->toBe(1)
        ->and($run->failure_message)->toContain('encerramento de alvos fora do perímetro')
        ->and($run->finished_at)->not->toBeNull();
});

it('records undelivered alerts and missing recipients on the run', function () {
    $construction = AutomationFixture::blockedConstruction();
    AutomationFixture::enable([$construction]);
    config()->set('sales_board.automation.reminders.blocked_after_days', 0);

    // Sem destinatário: aviso que não tinha a quem ir. Não é falha técnica.
    $withoutRecipient = AutomationFixture::run();

    expect($withoutRecipient->alerts_without_recipient)->toBe(1)
        ->and($withoutRecipient->status)->toBe(SalesBoardAutomationRunStatus::CompletedWithBlockers)
        ->and($withoutRecipient->toSummaryArray())->toMatchArray(['alerts_without_recipient' => 1, 'alerts_failed' => 0]);

    // Com destinatário e envio que falha: falha técnica, e o livro-razão devolve o aviso.
    $exploding = new class extends User
    {
        public function notify($instance): void
        {
            throw new RuntimeException('SMTP indisponível');
        }
    };
    $exploding->forceFill(User::factory()->create()->getAttributes());
    $exploding->exists = true;
    FixedSalesBoardAutomationRecipientResolver::bind($exploding);

    SalesBoardAutomationTarget::query()->update(['next_attempt_at' => null]);
    $this->travel(1)->hours();

    $failed = AutomationFixture::run();

    expect($failed->alerts_failed)->toBe(1)
        ->and($failed->alerts_sent)->toBe(0)
        ->and($failed->status)->toBe(SalesBoardAutomationRunStatus::CompletedWithFailures)
        ->and($failed->toSummaryArray()['alerts_failed'])->toBe(1)
        ->and(SalesBoardAutomationAlert::query()->count())->toBe(0);
});

it('logs a missing recipient once per window instead of once per hourly run', function () {
    Log::spy();

    $dispatcher = app(SalesBoardAutomationAlertDispatcher::class);

    foreach (range(1, 3) as $hour) {
        $dispatcher->dispatch(
            SalesBoardAutomationAlertType::ManagementReminder,
            [],
            ['sales_board_cycle_id' => 42],
            '2026-09-13',
            'Empreendimento',
            '08/2026',
        );
    }

    $dispatcher->dispatch(
        SalesBoardAutomationAlertType::ManagementReminder,
        [],
        ['sales_board_cycle_id' => 42],
        '2026-09-14',
        'Empreendimento',
        '08/2026',
    );

    expect($dispatcher->counters()['without_recipient'])->toBe(4);

    Log::shouldHaveReceived('warning')
        ->with('Sales board automation alert has no resolvable recipient', Mockery::any())
        ->twice();
});

it('leaves a satisfied target alone when its construction leaves the perimeter', function () {
    $construction = AutomationFixture::readyConstruction();
    AutomationFixture::enable([$construction]);
    AutomationFixture::run();

    $snapshot = SalesBoardAutomationTarget::query()->sole()->getAttributes();

    AutomationFixture::enable([]);
    AutomationFixture::run();

    // Satisfeito é terminal: sair do perímetro não o transforma em encerrado.
    expect(SalesBoardAutomationTarget::query()->sole()->getAttributes())->toBe($snapshot);
});

<?php

use App\Enums\SalesBoardAutomationAlertType;
use App\Models\SalesBoardAutomationAlert;
use App\Models\SalesBoardAutomationAttempt;
use App\Models\SalesBoardAutomationRun;
use App\Models\SalesBoardAutomationTarget;
use App\Models\SalesBoardBuilderReview;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardManagementReview;
use App\Models\User;
use App\Services\SalesBoards\SalesBoardAutomationDiscoveryService;
use App\Services\SalesBoards\SalesBoardAutomationRecipientResolver;
use App\Services\SalesBoards\SalesBoardAutomationReminderService;
use App\Services\SalesBoards\SalesBoardAutomationTargetProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\SalesBoards\AutomationFixture;
use Tests\Support\SalesBoards\BuilderReviewFixture;
use Tests\Support\SalesBoards\ManagementReviewFixture;

uses(RefreshDatabase::class);

/**
 * Desligado significa desligado.
 *
 * Com `sales_board.automation.enabled=false` o scheduler continua chamando o
 * comando de hora em hora, e o comando precisa voltar sem tocar em nada: nem
 * execução registrada, nem alvo, nem tentativa, nem lembrete, nem notificação.
 * Um interruptor que ainda escreve "só um registro" não é interruptor -- é uma
 * automação que roda devagar.
 *
 * Roda também no MySQL: o que se afirma aqui é ausência de escrita, e ela
 * precisa valer no banco de produção, não só no SQLite.
 */
pest()->group('parity');

beforeEach(function () {
    AutomationFixture::disable();
    Notification::fake();
});

/**
 * Tudo o que a automação poderia ter escrito, por tabela.
 *
 * @return array<string, int>
 */
function salesBoardAutomationFootprint(): array
{
    return collect([
        'sales_board_automation_runs',
        'sales_board_automation_targets',
        'sales_board_automation_attempts',
        'sales_board_automation_alerts',
        'sales_board_cycles',
        'sales_board_cycle_baselines',
        'sales_board_builder_reviews',
        'sales_board_management_reviews',
        'sales_boards',
        'sales_board_publications',
    ])->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
}

/**
 * Um resolvedor que entrega sempre os mesmos destinatários, para que a ausência
 * de aviso só possa ser explicada pelo interruptor.
 */
function resolveKillSwitchRecipientsTo(User ...$users): void
{
    app()->instance(SalesBoardAutomationRecipientResolver::class, new class(array_values($users)) implements SalesBoardAutomationRecipientResolver
    {
        /** @param list<User> $users */
        public function __construct(private readonly array $users) {}

        public function forGenerationBlocked($target): array
        {
            return $this->users;
        }

        public function forGenerationFailed($target): array
        {
            return $this->users;
        }

        public function forBuilderHandoff($cycle): array
        {
            return $this->users;
        }

        public function forBuilderReminder($review): array
        {
            return $this->users;
        }

        public function forManagementReminder($cycle): array
        {
            return $this->users;
        }
    });
}

it('writes nothing at all when the command runs while disabled', function () {
    AutomationFixture::readyConstruction();
    $before = salesBoardAutomationFootprint();

    $this->artisan('sales-boards:automation-run', ['--as-of' => '2026-09-13'])
        ->expectsOutputToContain('A automação do Quadro de Vendas está desligada')
        ->assertExitCode(0);

    expect(salesBoardAutomationFootprint())->toBe($before)
        ->and(SalesBoardAutomationRun::query()->count())->toBe(0);
});

it('returns before discovery, processing or reminders when disabled', function () {
    AutomationFixture::readyConstruction();
    config()->set('sales_board.automation.reminders.blocked_after_days', 0);

    $this->mock(SalesBoardAutomationDiscoveryService::class, function ($mock): void {
        $mock->shouldNotReceive('discover', 'materialize', 'attemptable', 'countWaiting');
    });
    $this->mock(SalesBoardAutomationTargetProcessor::class, fn ($mock) => $mock->shouldNotReceive('process'));
    $this->mock(SalesBoardAutomationReminderService::class, fn ($mock) => $mock->shouldNotReceive('run'));

    $run = AutomationFixture::run();

    expect($run->exists)->toBeFalse()
        ->and($run->targets_discovered)->toBe(0)
        ->and(SalesBoardAutomationRun::query()->count())->toBe(0);
});

it('treats a switch set to an unexpected value at runtime as disabled', function (mixed $value) {
    $construction = AutomationFixture::readyConstruction();
    AutomationFixture::enable([$construction]);
    config()->set('sales_board.automation.enabled', $value);

    $before = salesBoardAutomationFootprint();

    AutomationFixture::run();

    expect(salesBoardAutomationFootprint())->toBe($before);
})->with(['off', 'no', 'false', 'banana', 0, null]);

it('leaves an existing blocked target exactly as it was', function () {
    $construction = AutomationFixture::blockedConstruction();
    AutomationFixture::enable([$construction]);
    AutomationFixture::run();

    $target = SalesBoardAutomationTarget::query()->sole();
    // Janela de retry já vencida: se algo fosse tentado, seria agora.
    $target->forceFill(['next_attempt_at' => now()->subDay()])->save();

    $snapshot = $target->fresh()->getAttributes();
    $attempts = SalesBoardAutomationAttempt::query()->count();
    $runs = SalesBoardAutomationRun::query()->count();

    AutomationFixture::disable();
    $this->travel(1)->hours();

    $this->artisan('sales-boards:automation-run')->assertExitCode(0);

    expect($target->fresh()->getAttributes())->toBe($snapshot)
        ->and(SalesBoardAutomationAttempt::query()->count())->toBe($attempts)
        ->and(SalesBoardAutomationRun::query()->count())->toBe($runs);
});

it('sends no builder reminder while disabled, and does when enabled', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    $review = BuilderReviewFixture::open($scenario['cycle']);
    resolveKillSwitchRecipientsTo(User::factory()->create());
    config()->set('sales_board.automation.reminders.builder_review_after_days', 3);

    $this->travel(4)->days();
    $snapshot = $review->fresh()->getAttributes();

    AutomationFixture::run('2026-08-13');

    expect(SalesBoardAutomationAlert::query()->count())->toBe(0)
        ->and($review->fresh()->getAttributes())->toBe($snapshot);

    Notification::assertNothingSent();

    // Contraprova: o mesmo cenário, com a automação ligada, avisa.
    AutomationFixture::enable([$scenario['construction']], '2026-07-01');
    config()->set('sales_board.automation.reminders.builder_review_after_days', 3);

    AutomationFixture::run('2026-08-13');

    expect(SalesBoardAutomationAlert::query()->sole()->alert_type)->toBe(SalesBoardAutomationAlertType::BuilderReminder);
});

it('sends no management reminder while disabled', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    resolveKillSwitchRecipientsTo(User::factory()->create());
    config()->set('sales_board.automation.reminders.management_review_after_days', 0);

    $cycle = $scenario['cycle']->fresh()->getAttributes();
    $builderReview = $scenario['builderReview']->fresh()->getAttributes();

    AutomationFixture::run('2026-08-13');

    expect(SalesBoardAutomationAlert::query()->count())->toBe(0)
        ->and(SalesBoardManagementReview::query()->count())->toBe(0)
        ->and($scenario['cycle']->fresh()->getAttributes())->toBe($cycle)
        ->and(SalesBoardBuilderReview::query()->findOrFail($scenario['builderReview']->id)->getAttributes())->toBe($builderReview);

    Notification::assertNothingSent();
});

it('runs normally when enabled', function () {
    $construction = AutomationFixture::readyConstruction();
    AutomationFixture::enable([$construction]);

    $run = AutomationFixture::run();

    expect($run->exists)->toBeTrue()
        ->and(SalesBoardAutomationRun::query()->count())->toBe(1)
        ->and($run->targets_discovered)->toBe(1)
        ->and($run->generated_count)->toBe(1)
        ->and(SalesBoardAutomationTarget::query()->count())->toBe(1)
        ->and(SalesBoardAutomationAttempt::query()->count())->toBe(1)
        ->and(SalesBoardCycle::query()->count())->toBe(1);
});

it('reports the disabled state as parseable json, with nothing persisted', function (bool $dryRun) {
    AutomationFixture::readyConstruction();

    $exitCode = Artisan::call('sales-boards:automation-run', ['--json' => true, '--dry-run' => $dryRun, '--as-of' => '2026-09-13']);

    $output = Artisan::output();
    $payload = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($output)->not->toContain("\e[")
        ->and($payload['status'])->toBe('disabled')
        ->and($payload['automation_enabled'])->toBeFalse()
        ->and($payload['run_id'])->toBeNull()
        ->and($payload['dry_run'])->toBe($dryRun)
        ->and($payload['discovered'])->toBe(0)
        ->and(SalesBoardAutomationRun::query()->count())->toBe(0);
})->with(['execution' => false, 'dry run' => true]);

it('gives the disabled switch precedence over the production as-of refusal', function () {
    AutomationFixture::readyConstruction();
    app()->detectEnvironment(fn (): string => 'production');

    $this->artisan('sales-boards:automation-run', ['--as-of' => '2026-09-13'])
        ->expectsOutputToContain('A automação do Quadro de Vendas está desligada')
        ->assertExitCode(0);

    expect(SalesBoardAutomationRun::query()->count())->toBe(0);
});

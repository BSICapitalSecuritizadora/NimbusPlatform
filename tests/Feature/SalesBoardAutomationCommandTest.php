<?php

use App\Enums\SalesBoardAutomationRunStatus;
use App\Models\SalesBoardAutomationAlert;
use App\Models\SalesBoardAutomationAttempt;
use App\Models\SalesBoardAutomationRun;
use App\Models\SalesBoardAutomationTarget;
use App\Models\SalesBoardCycle;
use App\Services\SalesBoards\SalesBoardAutomationEligibilityProvider;
use App\Services\SalesBoards\SalesBoardGenerationService;
use App\Support\SalesBoards\SalesBoardAutomationConfig;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\SalesBoards\AutomationConfigFixture;
use Tests\Support\SalesBoards\AutomationFixture;

uses(RefreshDatabase::class);

beforeEach(fn () => AutomationFixture::disable());

it('reports zero targets and writes nothing while disabled', function () {
    AutomationFixture::readyConstruction();

    $this->artisan('sales-boards:automation-run', ['--dry-run' => true, '--as-of' => '2026-09-13'])
        ->expectsOutputToContain('automação do Quadro de Vendas está desligada')
        ->assertSuccessful();

    expect(SalesBoardAutomationRun::query()->count())->toBe(0)
        ->and(SalesBoardAutomationTarget::query()->count())->toBe(0)
        ->and(SalesBoardCycle::query()->count())->toBe(0);
});

it('previews the due competence without writing anything', function () {
    $construction = AutomationFixture::readyConstruction();
    AutomationFixture::enable([$construction]);

    $this->artisan('sales-boards:automation-run', ['--dry-run' => true, '--as-of' => '2026-09-13'])
        ->expectsOutputToContain('[prévia]')
        ->expectsOutputToContain('08/2026')
        ->assertSuccessful();

    expect(SalesBoardAutomationRun::query()->count())->toBe(0)
        ->and(SalesBoardAutomationTarget::query()->count())->toBe(0)
        ->and(SalesBoardAutomationAttempt::query()->count())->toBe(0)
        ->and(SalesBoardAutomationAlert::query()->count())->toBe(0)
        ->and(SalesBoardCycle::query()->count())->toBe(0);
});

it('generates the competence on a real run', function () {
    $construction = AutomationFixture::readyConstruction();
    AutomationFixture::enable([$construction]);

    $this->artisan('sales-boards:automation-run', ['--as-of' => '2026-09-13'])
        ->expectsOutputToContain('generated=1')
        ->assertSuccessful();

    expect(SalesBoardCycle::query()->count())->toBe(1)
        ->and(SalesBoardAutomationRun::query()->sole()->generated_count)->toBe(1);
});

it('emits parseable json with no decoration', function () {
    $construction = AutomationFixture::readyConstruction();
    AutomationFixture::enable([$construction]);

    Artisan::call('sales-boards:automation-run', ['--json' => true, '--as-of' => '2026-09-13']);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload)->toBeArray()
        ->and($payload['event'])->toBe('sales_board_automation_run')
        ->and($payload['as_of'])->toBe('2026-09-13')
        ->and($payload['latest_due_month'])->toBe('2026-08')
        ->and($payload['generated'])->toBe(1)
        ->and($payload['blocked'])->toBe(0)
        ->and($payload['failed'])->toBe(0)
        ->and($payload['automation_enabled'])->toBeTrue()
        ->and($payload['duration_ms'])->toBeInt();
});

it('lists the previewed targets in json on a dry run', function () {
    $construction = AutomationFixture::readyConstruction();
    AutomationFixture::enable([$construction]);

    Artisan::call('sales-boards:automation-run', ['--json' => true, '--dry-run' => true, '--as-of' => '2026-09-13']);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['run_id'])->toBeNull()
        ->and($payload['dry_run'])->toBeTrue()
        ->and($payload['targets'])->toHaveCount(1)
        ->and($payload['targets'][0]['construction_id'])->toBe($construction->id)
        ->and($payload['targets'][0]['reference_month'])->toBe('2026-08')
        ->and($payload['targets'][0]['due_date'])->toBe('2026-09-13')
        ->and(SalesBoardCycle::query()->count())->toBe(0);
});

it('succeeds even when a target is blocked, because that is not a technical failure', function () {
    $construction = AutomationFixture::blockedConstruction();
    AutomationFixture::enable([$construction]);

    // Código de saída zero: quem precisa de ação é o cadastro, não o scheduler.
    // Fazer o comando falhar aqui ensinaria o time a ignorar o alarme.
    $this->artisan('sales-boards:automation-run', ['--as-of' => '2026-09-13'])
        ->expectsOutputToContain('blocked=1')
        ->assertSuccessful();

    expect(SalesBoardAutomationRun::query()->sole()->blocked_count)->toBe(1);
});

it('refuses a mutating as-of run in production', function () {
    $construction = AutomationFixture::readyConstruction();
    AutomationFixture::enable([$construction]);

    app()->detectEnvironment(fn (): string => 'production');

    $this->artisan('sales-boards:automation-run', ['--as-of' => '2026-09-13'])
        ->expectsOutputToContain('não é permitida em produção')
        ->assertFailed();

    expect(SalesBoardCycle::query()->count())->toBe(0)
        ->and(SalesBoardAutomationRun::query()->count())->toBe(0);
});

it('still allows a preview with as-of in production', function () {
    $construction = AutomationFixture::readyConstruction();
    AutomationFixture::enable([$construction]);

    app()->detectEnvironment(fn (): string => 'production');

    $this->artisan('sales-boards:automation-run', ['--as-of' => '2026-09-13', '--dry-run' => true])
        ->assertSuccessful();

    expect(SalesBoardCycle::query()->count())->toBe(0);
});

it('registers the automation on the scheduler exactly once, hourly', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'sales-boards:automation-run'));

    expect($events)->toHaveCount(1);

    $event = $events->first();

    expect($event->expression)->toBe('0 * * * *')
        ->and($event->timezone)->toBe('America/Sao_Paulo')
        // Lock de scheduler é economia, não correção: a garantia é do banco.
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->withoutOverlapping)->toBeTrue()
        // O lock sobrevive a um processo morto: com as 24 horas do padrão, um
        // deploy no meio da execução silenciava a automação por um dia.
        ->and($event->expiresAt)->toBe(SalesBoardAutomationConfig::OVERLAP_LOCK_MINUTES)
        ->and($event->expiresAt)->toBeLessThanOrEqual(120);
});

/**
 * Estes dois testes conferiam `config()->set()` contra `config()` e o
 * `json_decode` do PHP -- passavam com qualquer código. Agora avaliam o arquivo
 * de configuração de verdade, com o ambiente trocado, e rodam o comando sobre o
 * resultado.
 */
it('ignores any targets variable in the environment, because eligibility comes only from the rollout', function (string $raw) {
    $construction = AutomationFixture::readyConstruction();

    $raw = str_replace('{id}', (string) $construction->id, $raw);

    config()->set('sales_board', AutomationConfigFixture::under([
        'SALES_BOARD_AUTOMATION_ENABLED' => 'true',
        'SALES_BOARD_AUTOMATION_TARGETS' => $raw,
    ]));

    // A variável não chega à configuração, e o provider de produção (o de
    // banco, amarrado pelo beforeEach) não vê Emissão automatizada nenhuma.
    expect(config('sales_board.automation'))->not->toHaveKey('targets')
        ->and(config('sales_board.automation.enabled'))->toBeTrue();

    $this->artisan('sales-boards:automation-run', ['--as-of' => '2026-09-13'])
        ->expectsOutputToContain('discovered=0')
        ->assertSuccessful();

    expect(SalesBoardAutomationTarget::query()->count())->toBe(0)
        ->and(SalesBoardCycle::query()->count())->toBe(0);
})->with([
    'readable json' => ['[{"construction_id":{id},"start_reference_month":"2026-08-01"}]'],
    'unreadable json' => ['nao-e-json'],
]);

it('falls back to the default retry cadence when the configured value is unreadable', function () {
    $construction = AutomationFixture::blockedConstruction();
    AutomationFixture::enable([$construction]);

    $config = AutomationConfigFixture::under([
        'SALES_BOARD_AUTOMATION_ENABLED' => 'true',
        'SALES_BOARD_AUTOMATION_BLOCKED_RETRY_HOURS' => 'vinte e quatro',
    ]);
    config()->set('sales_board.automation.retry', $config['automation']['retry']);

    $this->travelTo(CarbonImmutable::parse('2026-09-13 13:00:00'));

    $this->artisan('sales-boards:automation-run', ['--as-of' => '2026-09-13'])
        ->expectsOutputToContain('blocked=1')
        ->assertSuccessful();

    // Um valor ilegível volta às 24 horas documentadas, e não a uma hora.
    expect(SalesBoardAutomationTarget::query()->sole()->next_attempt_at->toDateTimeString())
        ->toBe('2026-09-14 13:00:00');
});

it('exits with failure when the run is not technically healthy', function () {
    $construction = AutomationFixture::readyConstruction();
    AutomationFixture::enable([$construction]);

    $mock = Mockery::mock(SalesBoardGenerationService::class);
    $mock->shouldReceive('generateForConstruction')->andThrow(new RuntimeException('transitório'));
    app()->instance(SalesBoardGenerationService::class, $mock);

    // Falha técnica de alvo: o monitoramento por código de saída precisa ver.
    $this->artisan('sales-boards:automation-run', ['--as-of' => '2026-09-13'])
        ->expectsOutputToContain('Concluída com falhas')
        ->assertFailed();

    expect(SalesBoardAutomationRun::query()->sole()->status)
        ->toBe(SalesBoardAutomationRunStatus::CompletedWithFailures);
});

it('exits with failure when the orchestration itself fails', function () {
    config()->set('sales_board.automation.enabled', true);

    app()->instance(SalesBoardAutomationEligibilityProvider::class, new class implements SalesBoardAutomationEligibilityProvider
    {
        public function eligibleTargets(): array
        {
            throw new RuntimeException('banco indisponível na descoberta');
        }
    });

    $this->artisan('sales-boards:automation-run')->assertFailed();

    $run = SalesBoardAutomationRun::query()->sole();

    expect($run->status)->toBe(SalesBoardAutomationRunStatus::Failed)
        ->and($run->failure_message)->toContain('descoberta');
});

it('raises its own memory limit, because the scheduler does not pass php flags to it', function () {
    $previous = ini_get('memory_limit');
    ini_set('memory_limit', '256M');

    try {
        $construction = AutomationFixture::readyConstruction();
        AutomationFixture::enable([$construction]);
        config()->set('sales_board.automation.memory_limit', '640M');

        $this->artisan('sales-boards:automation-run', ['--as-of' => '2026-09-13'])->assertSuccessful();

        expect(ini_get('memory_limit'))->toBe('640M');

        // Só sobe: um limite maior (ou ilimitado) já configurado fica como está.
        ini_set('memory_limit', '2G');
        $this->artisan('sales-boards:automation-run', ['--as-of' => '2026-09-13'])->assertSuccessful();

        expect(ini_get('memory_limit'))->toBe('2G');
    } finally {
        ini_set('memory_limit', $previous);
    }
});

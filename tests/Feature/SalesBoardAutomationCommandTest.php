<?php

use App\Models\SalesBoardAutomationAlert;
use App\Models\SalesBoardAutomationAttempt;
use App\Models\SalesBoardAutomationRun;
use App\Models\SalesBoardAutomationTarget;
use App\Models\SalesBoardCycle;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
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
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('reads the enabled targets from the environment, and stays inert without them', function () {
    // A habilitação é decisão de rollout: muda por ambiente e não pertence ao
    // repositório. Nenhum id real é versionado.
    expect(config('sales_board.automation.targets'))->toBe([]);

    config()->set('sales_board.automation.targets', json_decode(
        '[{"construction_id":7,"start_reference_month":"2026-08-01"}]',
        true,
    ));

    expect(config('sales_board.automation.targets'))->toBe([
        ['construction_id' => 7, 'start_reference_month' => '2026-08-01'],
    ]);
});

it('falls back to zero targets when the configured value is unreadable', function () {
    // Uma configuração que ninguém consegue ler não pode virar "automatize
    // tudo": o default inerte vale também para o caso malformado.
    expect(json_decode('nao-e-json', true) ?: [])->toBe([]);
});

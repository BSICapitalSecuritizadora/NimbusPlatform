<?php

use App\Enums\SalesBoardAutomationAttemptOutcome;
use App\Enums\SalesBoardAutomationRunStatus;
use App\Enums\SalesBoardAutomationSatisfiedVia;
use App\Enums\SalesBoardAutomationTargetStatus;
use App\Enums\SalesBoardCycleStatus;
use App\Models\SalesBoard;
use App\Models\SalesBoardAutomationAlert;
use App\Models\SalesBoardAutomationAttempt;
use App\Models\SalesBoardAutomationRun;
use App\Models\SalesBoardAutomationTarget;
use App\Models\SalesBoardBuilderReview;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardPublication;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SalesBoards\AutomationFixture;
use Tests\Support\SalesBoards\CycleFixture;

uses(RefreshDatabase::class);

beforeEach(fn () => AutomationFixture::disable());

it('does nothing at all while the automation is disabled', function () {
    AutomationFixture::readyConstruction();

    $run = AutomationFixture::run();

    expect($run->targets_discovered)->toBe(0)
        ->and(SalesBoardAutomationTarget::query()->count())->toBe(0)
        ->and(SalesBoardCycle::query()->count())->toBe(0)
        ->and(SalesBoardAutomationAttempt::query()->count())->toBe(0);
});

it('does nothing when enabled with no targets', function () {
    AutomationFixture::readyConstruction();
    AutomationFixture::enable([]);

    $run = AutomationFixture::run();

    expect($run->targets_discovered)->toBe(0)
        ->and($run->status)->toBe(SalesBoardAutomationRunStatus::Completed)
        ->and(SalesBoardAutomationTarget::query()->count())->toBe(0)
        ->and(SalesBoardCycle::query()->count())->toBe(0);
});

it('discovers nothing before the thirteenth', function () {
    $construction = AutomationFixture::readyConstruction();
    AutomationFixture::enable([$construction]);

    $run = AutomationFixture::run(AutomationFixture::BEFORE_DUE_DATE);

    expect($run->targets_discovered)->toBe(0)
        ->and($run->latest_due_reference_month->format('Y-m'))->toBe('2026-07')
        ->and(SalesBoardCycle::query()->count())->toBe(0);
});

it('generates the due competence from the thirteenth on', function () {
    $construction = AutomationFixture::readyConstruction();
    AutomationFixture::enable([$construction]);

    $run = AutomationFixture::run();

    $target = SalesBoardAutomationTarget::query()->sole();
    $cycle = SalesBoardCycle::query()->sole();
    $attempt = SalesBoardAutomationAttempt::query()->sole();

    expect($run->status)->toBe(SalesBoardAutomationRunStatus::Completed)
        ->and($run->targets_discovered)->toBe(1)
        ->and($run->targets_attempted)->toBe(1)
        ->and($run->generated_count)->toBe(1)
        ->and($run->latest_due_reference_month->format('Y-m'))->toBe('2026-08')
        ->and($target->status)->toBe(SalesBoardAutomationTargetStatus::Satisfied)
        ->and($target->satisfied_via)->toBe(SalesBoardAutomationSatisfiedVia::Generated)
        ->and($target->sales_board_cycle_id)->toBe($cycle->id)
        ->and($target->attempt_count)->toBe(1)
        ->and($target->next_attempt_at)->toBeNull()
        ->and($cycle->reference_month->format('Y-m'))->toBe('2026-08')
        ->and($cycle->status)->toBe(SalesBoardCycleStatus::Generated)
        ->and($cycle->currentBaseline->version)->toBe(1)
        ->and($attempt->outcome)->toBe(SalesBoardAutomationAttemptOutcome::Generated)
        ->and($attempt->sales_board_cycle_id)->toBe($cycle->id);
});

it('never generates the same competence twice', function () {
    $construction = AutomationFixture::readyConstruction();
    AutomationFixture::enable([$construction]);

    AutomationFixture::run();
    $second = AutomationFixture::run();

    expect(SalesBoardCycle::query()->count())->toBe(1)
        ->and(SalesBoardAutomationTarget::query()->count())->toBe(1)
        // O alvo satisfeito nem sequer é tentado de novo.
        ->and($second->targets_attempted)->toBe(0)
        ->and(SalesBoardAutomationAttempt::query()->count())->toBe(1);
});

it('adopts a cycle created by hand before the due date', function () {
    $construction = AutomationFixture::readyConstruction();
    AutomationFixture::enable([$construction]);

    // O operador gerou manualmente antes do dia 13.
    CycleFixture::generate($construction, AutomationFixture::DEFAULT_MONTH);
    $manual = SalesBoardCycle::query()->sole();

    $run = AutomationFixture::run();
    $target = SalesBoardAutomationTarget::query()->sole();

    expect(SalesBoardCycle::query()->count())->toBe(1)
        ->and($run->existing_count)->toBe(1)
        ->and($run->generated_count)->toBe(0)
        ->and($target->status)->toBe(SalesBoardAutomationTargetStatus::Satisfied)
        ->and($target->satisfied_via)->toBe(SalesBoardAutomationSatisfiedVia::Existing)
        ->and($target->sales_board_cycle_id)->toBe($manual->id)
        ->and(SalesBoardAutomationAttempt::query()->sole()->outcome)
        ->toBe(SalesBoardAutomationAttemptOutcome::Existing);
});

it('records a blocker without failing, and schedules the retry', function () {
    $construction = AutomationFixture::blockedConstruction();
    AutomationFixture::enable([$construction]);

    $run = AutomationFixture::run();
    $target = SalesBoardAutomationTarget::query()->sole();

    expect($run->status)->toBe(SalesBoardAutomationRunStatus::CompletedWithBlockers)
        ->and($run->blocked_count)->toBe(1)
        ->and($run->failed_count)->toBe(0)
        ->and($target->status)->toBe(SalesBoardAutomationTargetStatus::Blocked)
        ->and($target->blockerCodes())->toBe(['UNIT_VALUE_MISSING'])
        ->and($target->last_blocker_message)->toContain('incompleta')
        ->and($target->last_error_code)->toBeNull()
        ->and($target->next_attempt_at)->not->toBeNull()
        ->and(SalesBoardCycle::query()->count())->toBe(0)
        ->and(SalesBoardAutomationAttempt::query()->sole()->outcome)
        ->toBe(SalesBoardAutomationAttemptOutcome::Blocked);
});

it('does not derive again before the scheduled retry', function () {
    $construction = AutomationFixture::blockedConstruction();
    AutomationFixture::enable([$construction]);

    AutomationFixture::run();
    $second = AutomationFixture::run();

    expect(SalesBoardAutomationAttempt::query()->count())->toBe(1)
        ->and($second->targets_attempted)->toBe(0)
        ->and($second->skipped_count)->toBe(1);
});

it('tries again once the retry window has passed, and satisfies the target', function () {
    $construction = AutomationFixture::blockedConstruction();
    AutomationFixture::enable([$construction]);

    AutomationFixture::run();

    // A fonte é corrigida e o relógio avança além da janela de retry.
    $construction->units()->whereNull('base_value')->update([
        'base_value' => '500000.00',
        'base_value_reference_date' => '2026-01-01',
    ]);

    SalesBoardAutomationTarget::query()->sole()->forceFill([
        'next_attempt_at' => CarbonImmutable::now()->subHour(),
    ])->save();

    $run = AutomationFixture::run();
    $target = SalesBoardAutomationTarget::query()->sole();

    expect($run->generated_count)->toBe(1)
        ->and($target->status)->toBe(SalesBoardAutomationTargetStatus::Satisfied)
        ->and($target->attempt_count)->toBe(2)
        ->and($target->last_blocker_codes)->toBeNull()
        ->and($target->last_blocker_message)->toBeNull()
        // O histórico das duas investidas continua inteiro.
        ->and(SalesBoardAutomationAttempt::query()->count())->toBe(2)
        ->and(SalesBoardAutomationAttempt::query()->orderBy('attempt_number')->pluck('outcome')->all())
        ->toBe([SalesBoardAutomationAttemptOutcome::Blocked, SalesBoardAutomationAttemptOutcome::Generated]);
});

it('keeps every target independent within one run', function () {
    $ready = AutomationFixture::readyConstruction('1');
    $blocked = AutomationFixture::blockedConstruction('2');
    $existingConstruction = AutomationFixture::readyConstruction('3');

    CycleFixture::generate($existingConstruction, AutomationFixture::DEFAULT_MONTH);

    AutomationFixture::enable([$ready, $blocked, $existingConstruction]);

    $run = AutomationFixture::run();

    expect($run->targets_discovered)->toBe(3)
        ->and($run->targets_attempted)->toBe(3)
        ->and($run->generated_count)->toBe(1)
        ->and($run->existing_count)->toBe(1)
        ->and($run->blocked_count)->toBe(1)
        ->and($run->failed_count)->toBe(0)
        ->and($run->status)->toBe(SalesBoardAutomationRunStatus::CompletedWithBlockers)
        ->and(SalesBoardCycle::query()->count())->toBe(2);
});

it('catches up on every competence due since activation', function () {
    $construction = AutomationFixture::readyConstruction();
    AutomationFixture::enable([$construction], '2026-08-01');

    // O scheduler ficou fora do ar: só volta em 15/11.
    $run = AutomationFixture::run('2026-11-15');

    expect($run->targets_discovered)->toBe(3)
        ->and(SalesBoardAutomationTarget::query()->orderBy('reference_month')->pluck('reference_month')
            ->map(fn ($month): string => CarbonImmutable::parse($month)->format('Y-m'))->all())
        ->toBe(['2026-08', '2026-09', '2026-10'])
        ->and(SalesBoardCycle::query()->count())->toBe(3);
});

it('never reaches back before the activation month', function () {
    $construction = AutomationFixture::readyConstruction();
    AutomationFixture::enable([$construction], '2026-08-01');

    $run = AutomationFixture::run('2026-12-20');

    $months = SalesBoardAutomationTarget::query()->orderBy('reference_month')->pluck('reference_month')
        ->map(fn ($month): string => CarbonImmutable::parse($month)->format('Y-m'))->all();

    expect($months)->toBe(['2026-08', '2026-09', '2026-10', '2026-11'])
        ->and($months)->not->toContain('2026-07')
        ->and($months)->not->toContain('2026-06');
});

it('discards a configured target that never declared an activation month', function () {
    $construction = AutomationFixture::readyConstruction();

    config()->set('sales_board.automation.enabled', true);
    config()->set('sales_board.automation.targets', [
        ['construction_id' => $construction->id],
    ]);

    $run = AutomationFixture::run();

    // Sem ativação declarada não existe "desde sempre": o alvo é descartado.
    expect($run->targets_discovered)->toBe(0)
        ->and(SalesBoardCycle::query()->count())->toBe(0);
});

it('writes absolutely nothing on a dry run', function () {
    $construction = AutomationFixture::readyConstruction();
    AutomationFixture::enable([$construction]);

    $run = AutomationFixture::run(dryRun: true);

    expect($run->exists)->toBeFalse()
        ->and($run->targets_discovered)->toBe(1)
        ->and(SalesBoardAutomationRun::query()->count())->toBe(0)
        ->and(SalesBoardAutomationTarget::query()->count())->toBe(0)
        ->and(SalesBoardAutomationAttempt::query()->count())->toBe(0)
        ->and(SalesBoardAutomationAlert::query()->count())->toBe(0)
        ->and(SalesBoardCycle::query()->count())->toBe(0);
});

it('never publishes, approves or opens anything beyond the cycle', function () {
    $construction = AutomationFixture::readyConstruction();
    AutomationFixture::enable([$construction]);

    AutomationFixture::run();

    expect(SalesBoard::query()->count())->toBe(0)
        ->and(SalesBoardPublication::query()->count())->toBe(0)
        ->and(SalesBoardBuilderReview::query()->count())->toBe(0)
        ->and(SalesBoardCycle::query()->sole()->status)->toBe(SalesBoardCycleStatus::Generated);
});

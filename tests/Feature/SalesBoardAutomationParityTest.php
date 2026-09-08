<?php

use App\Enums\SalesBoardAutomationAlertType;
use App\Enums\SalesBoardAutomationAttemptOutcome;
use App\Enums\SalesBoardAutomationRunStatus;
use App\Enums\SalesBoardAutomationRunTrigger;
use App\Enums\SalesBoardAutomationSatisfiedVia;
use App\Enums\SalesBoardAutomationTargetStatus;
use App\Models\SalesBoardAutomationAlert;
use App\Models\SalesBoardAutomationAttempt;
use App\Models\SalesBoardAutomationTarget;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SalesBoards\AutomationFixture;

uses(RefreshDatabase::class);

/**
 * As garantias da automação que dependem de o banco ser um banco específico.
 */
pest()->group('parity');

beforeEach(fn () => AutomationFixture::disable());

/**
 * O bug do hardening não se repete: no SQLite um valor longo demais passa, e no
 * MySQL ele é truncado. As colunas de enum desta fase são conferidas uma a uma.
 */
it('keeps every persisted enum value within its column width', function () {
    $limits = [
        [SalesBoardAutomationTargetStatus::cases(), 30, 'target status'],
        [SalesBoardAutomationSatisfiedVia::cases(), 20, 'satisfied via'],
        [SalesBoardAutomationAttemptOutcome::cases(), 20, 'attempt outcome'],
        [SalesBoardAutomationRunTrigger::cases(), 20, 'run trigger'],
        [SalesBoardAutomationRunStatus::cases(), 30, 'run status'],
        [SalesBoardAutomationAlertType::cases(), 40, 'alert type'],
    ];

    foreach ($limits as [$cases, $max, $label]) {
        foreach ($cases as $case) {
            expect(strlen($case->value))->toBeLessThanOrEqual($max, $label.' '.$case->name);
        }
    }
});

it('persists every enum value without truncation', function () {
    $construction = AutomationFixture::readyConstruction();
    AutomationFixture::enable([$construction]);
    AutomationFixture::run();

    $target = DB::table('sales_board_automation_targets')->sole();
    $attempt = DB::table('sales_board_automation_attempts')->sole();
    $run = DB::table('sales_board_automation_runs')->sole();

    expect($target->status)->toBe(SalesBoardAutomationTargetStatus::Satisfied->value)
        ->and($target->satisfied_via)->toBe(SalesBoardAutomationSatisfiedVia::Generated->value)
        ->and($attempt->outcome)->toBe(SalesBoardAutomationAttemptOutcome::Generated->value)
        ->and($run->trigger)->toBe(SalesBoardAutomationRunTrigger::Scheduled->value)
        ->and($run->status)->toBe(SalesBoardAutomationRunStatus::Completed->value)
        // Reler pelo model prova que o gravado ainda resolve o enum.
        ->and(SalesBoardAutomationTarget::query()->sole()->status)
        ->toBe(SalesBoardAutomationTargetStatus::Satisfied);
});

it('allows a single automation target per construction and competence', function () {
    $construction = AutomationFixture::readyConstruction();
    AutomationFixture::enable([$construction]);
    AutomationFixture::run();

    SalesBoardAutomationTarget::factory()->create([
        'construction_id' => $construction->id,
        'reference_month' => AutomationFixture::DEFAULT_MONTH,
    ]);
})->throws(UniqueConstraintViolationException::class);

it('allows a single alert per dedupe key', function () {
    $key = hash('sha256', 'chave-fixa-de-teste');

    SalesBoardAutomationAlert::factory()->create(['dedupe_key' => $key]);
    SalesBoardAutomationAlert::factory()->create(['dedupe_key' => $key]);
})->throws(UniqueConstraintViolationException::class);

it('refuses to delete a construction that has an automation target', function () {
    $construction = AutomationFixture::readyConstruction();
    AutomationFixture::enable([$construction]);
    AutomationFixture::run();

    DB::table('constructions')->where('id', $construction->id)->delete();
})->throws(QueryException::class);

it('refuses to delete a cycle that an automation target points at', function () {
    $construction = AutomationFixture::readyConstruction();
    AutomationFixture::enable([$construction]);
    AutomationFixture::run();

    DB::table('sales_board_cycles')
        ->where('id', SalesBoardAutomationTarget::query()->sole()->sales_board_cycle_id)
        ->delete();
})->throws(QueryException::class);

it('refuses to delete a run that has attempts', function () {
    $construction = AutomationFixture::readyConstruction();
    AutomationFixture::enable([$construction]);
    AutomationFixture::run();

    DB::table('sales_board_automation_runs')
        ->where('id', SalesBoardAutomationAttempt::query()->sole()->sales_board_automation_run_id)
        ->delete();
})->throws(QueryException::class);

it('round-trips the dates and the json blocker codes', function () {
    $construction = AutomationFixture::blockedConstruction();
    AutomationFixture::enable([$construction]);
    AutomationFixture::run();

    $persisted = DB::table('sales_board_automation_targets')->sole();
    $target = SalesBoardAutomationTarget::query()->sole();

    expect(substr((string) $persisted->reference_month, 0, 10))->toBe('2026-08-01')
        ->and(substr((string) $persisted->due_date, 0, 10))->toBe('2026-09-13')
        ->and($target->reference_month->format('Y-m-d'))->toBe('2026-08-01')
        ->and($target->due_date->format('Y-m-d'))->toBe('2026-09-13')
        // O JSON volta como lista de códigos estruturados, não como texto.
        ->and($target->blockerCodes())->toBe(['UNIT_VALUE_MISSING'])
        ->and(json_decode((string) $persisted->last_blocker_codes, true))->toBe(['UNIT_VALUE_MISSING']);
});

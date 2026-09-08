<?php

use App\Enums\SalesBoardAutomationAttemptOutcome;
use App\Enums\SalesBoardAutomationRunStatus;
use App\Enums\SalesBoardAutomationTargetStatus;
use App\Enums\SalesBoardBuilderReviewStatus;
use App\Enums\SalesBoardCycleStatus;
use App\Models\ConstructionUnit;
use App\Models\Contract;
use App\Models\ContractInstallment;
use App\Models\SalesBoard;
use App\Models\SalesBoardAutomationAttempt;
use App\Models\SalesBoardAutomationTarget;
use App\Models\SalesBoardBuilderReview;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleBaseline;
use App\Models\SalesBoardPublication;
use App\Models\SalesDiscountPolicy;
use App\Services\SalesBoards\SalesBoardBuilderReviewOpeningService;
use App\Services\SalesBoards\SalesBoardGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SalesBoards\AutomationFixture;
use Tests\Support\SalesBoards\CycleFixture;
use Tests\Support\SalesBoards\ManagementReviewFixture;

uses(RefreshDatabase::class);

beforeEach(fn () => AutomationFixture::disable());

it('records a technical failure without aborting the rest of the batch', function () {
    $failing = AutomationFixture::readyConstruction('1');
    $healthy = AutomationFixture::readyConstruction('2');

    AutomationFixture::enable([$failing, $healthy]);

    $real = app(SalesBoardGenerationService::class);

    // Só o primeiro empreendimento estoura; o segundo segue o caminho real.
    $mock = Mockery::mock(SalesBoardGenerationService::class);
    $mock->shouldReceive('generateForConstruction')
        ->andReturnUsing(function ($construction, $month, $actor = null, $dryRun = false) use ($real, $failing) {
            if ((int) $construction->getKey() === (int) $failing->getKey()) {
                throw new RuntimeException('conexão perdida com senha=secreta no dsn');
            }

            return $real->generateForConstruction($construction, $month, $actor, $dryRun);
        });

    app()->instance(SalesBoardGenerationService::class, $mock);

    $run = AutomationFixture::run();

    $failed = SalesBoardAutomationTarget::query()->where('construction_id', $failing->id)->sole();
    $ok = SalesBoardAutomationTarget::query()->where('construction_id', $healthy->id)->sole();

    expect($run->status)->toBe(SalesBoardAutomationRunStatus::CompletedWithFailures)
        ->and($run->failed_count)->toBe(1)
        ->and($run->generated_count)->toBe(1)
        ->and($failed->status)->toBe(SalesBoardAutomationTargetStatus::Failed)
        ->and($failed->last_error_code)->toBe('RuntimeException')
        ->and($failed->next_attempt_at)->not->toBeNull()
        // O segundo empreendimento foi processado apesar da falha do primeiro.
        ->and($ok->status)->toBe(SalesBoardAutomationTargetStatus::Satisfied)
        ->and(SalesBoardCycle::query()->count())->toBe(1);
});

it('never persists the technical detail of a failure', function () {
    $construction = AutomationFixture::readyConstruction();
    AutomationFixture::enable([$construction]);

    $mock = Mockery::mock(SalesBoardGenerationService::class);
    $mock->shouldReceive('generateForConstruction')
        ->andThrow(new RuntimeException('SQLSTATE[08006] host=db user=sail password=hunter2 token=abc123'));

    app()->instance(SalesBoardGenerationService::class, $mock);

    AutomationFixture::run();

    $target = SalesBoardAutomationTarget::query()->sole();
    $attempt = SalesBoardAutomationAttempt::query()->sole();

    // A mensagem guardada explica o que houve sem carregar credencial nenhuma.
    foreach ([$target->last_error_message, $attempt->reason_message] as $message) {
        expect($message)->toContain('Falha técnica')
            ->and($message)->not->toContain('password')
            ->and($message)->not->toContain('hunter2')
            ->and($message)->not->toContain('token')
            ->and($message)->not->toContain('SQLSTATE');
    }
});

it('leaves every operational source untouched', function () {
    $construction = AutomationFixture::readyConstruction();

    SalesDiscountPolicy::factory()->forConstruction($construction)
        ->effectiveFrom('2019-01-01')->allowing('5.00')->create();

    $unit = $construction->units()->firstOrFail();
    $contract = Contract::factory()->create([
        'construction_unit_id' => $unit->id,
        'sale_date' => '2026-05-10',
        'sale_value' => '600000.00',
    ]);
    ContractInstallment::factory()->create([
        'contract_id' => $contract->id, 'number' => '001',
        'due_date' => '2026-12-10', 'expected_value' => '600000.00',
    ]);

    AutomationFixture::enable([$construction]);

    $before = [
        'contracts' => Contract::query()->orderBy('id')->get()->toJson(),
        'installments' => ContractInstallment::query()->orderBy('id')->get()->toJson(),
        'units' => ConstructionUnit::query()->orderBy('id')->get()->toJson(),
        'policies' => SalesDiscountPolicy::query()->orderBy('id')->get()->toJson(),
    ];

    AutomationFixture::run();

    expect(Contract::query()->orderBy('id')->get()->toJson())->toBe($before['contracts'])
        ->and(ContractInstallment::query()->orderBy('id')->get()->toJson())->toBe($before['installments'])
        ->and(ConstructionUnit::query()->orderBy('id')->get()->toJson())->toBe($before['units'])
        ->and(SalesDiscountPolicy::query()->orderBy('id')->get()->toJson())->toBe($before['policies']);
});

it('never recalculates a stale cycle by itself', function () {
    $construction = AutomationFixture::readyConstruction();
    AutomationFixture::enable([$construction]);

    AutomationFixture::run();

    $cycle = SalesBoardCycle::query()->sole();
    $fingerprint = $cycle->currentBaseline->snapshot_fingerprint;

    // A fonte muda materialmente depois da geração.
    ConstructionUnit::factory()->create([
        'construction_id' => $construction->id,
        'block' => '01', 'unit' => '199',
        'base_value' => '900000.00', 'base_value_reference_date' => '2026-01-01',
    ]);

    AutomationFixture::run();

    expect(SalesBoardCycleBaseline::query()->count())->toBe(1)
        ->and($cycle->fresh()->currentBaseline->snapshot_fingerprint)->toBe($fingerprint)
        ->and($cycle->fresh()->currentBaseline->version)->toBe(1);
});

it('leaves an approved cycle completely alone', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $review = ManagementReviewFixture::open($scenario['cycle']);
    ManagementReviewFixture::approve($review);

    AutomationFixture::enable([$scenario['construction']], '2026-07-01');

    $boards = SalesBoard::query()->count();
    $run = AutomationFixture::run('2026-08-13');

    expect($run->existing_count)->toBe(1)
        ->and(SalesBoardAutomationTarget::query()->sole()->status)
        ->toBe(SalesBoardAutomationTargetStatus::Satisfied)
        ->and($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::Approved)
        ->and(SalesBoard::query()->count())->toBe($boards)
        ->and(SalesBoardCycleBaseline::query()->where('sales_board_cycle_id', $scenario['cycle']->id)->count())
        ->toBe(1);
});

it('never decides or approves a management review', function () {
    $scenario = ManagementReviewFixture::submittedCycleWithNonConformSale();
    $review = ManagementReviewFixture::open($scenario['cycle']);

    AutomationFixture::enable([$scenario['construction']], '2026-07-01');
    AutomationFixture::run('2026-08-13');

    expect($review->fresh()->status->value)->toBe('em_analise')
        ->and($review->fresh()->nonconformities->pluck('decision')->pluck('value')->unique()->all())
        ->toBe(['pendente'])
        ->and($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::ManagementReview)
        ->and(SalesBoardPublication::query()->count())->toBe(0)
        ->and(SalesBoard::query()->count())->toBe(0);
});

it('adopts a cancelled cycle instead of creating a second one', function () {
    $construction = AutomationFixture::readyConstruction();
    CycleFixture::generate($construction, AutomationFixture::DEFAULT_MONTH);

    $cycle = SalesBoardCycle::query()->sole();
    $cycle->forceFill(['status' => SalesBoardCycleStatus::Cancelled])->save();

    AutomationFixture::enable([$construction]);
    $run = AutomationFixture::run();

    // A competência já tem identidade: a unique recusaria outro ciclo, e criar
    // um "porque aquele foi cancelado" é decisão que ninguém tomou.
    expect(SalesBoardCycle::query()->count())->toBe(1)
        ->and($run->existing_count)->toBe(1)
        ->and($cycle->fresh()->status)->toBe(SalesBoardCycleStatus::Cancelled)
        ->and(SalesBoardAutomationTarget::query()->sole()->status)
        ->toBe(SalesBoardAutomationTargetStatus::Satisfied);
});

it('leaves the cycle with the builder when auto open is off', function () {
    $construction = AutomationFixture::readyConstruction();
    AutomationFixture::enable([$construction], autoOpenBuilderReview: false);

    AutomationFixture::run();

    expect(SalesBoardCycle::query()->sole()->status)->toBe(SalesBoardCycleStatus::Generated)
        ->and(SalesBoardBuilderReview::query()->count())->toBe(0);
});

it('opens a draft builder review when auto open is explicitly on', function () {
    $construction = AutomationFixture::readyConstruction();
    AutomationFixture::enable([$construction], autoOpenBuilderReview: true);

    AutomationFixture::run();

    $review = SalesBoardBuilderReview::query()->sole();

    expect($review->status)->toBe(SalesBoardBuilderReviewStatus::Draft)
        ->and($review->attempt)->toBe(1)
        ->and($review->sections)->toHaveCount(7)
        // Sem ator humano inventado: a procedência é a trilha da automação.
        ->and($review->opened_by_user_id)->toBeNull()
        // E nunca submetida.
        ->and($review->submitted_at)->toBeNull()
        ->and($review->divergences)->toHaveCount(0)
        ->and(SalesBoardCycle::query()->sole()->status)->toBe(SalesBoardCycleStatus::BuilderReview);
});

it('keeps the cycle when the handoff to the builder fails', function () {
    $construction = AutomationFixture::readyConstruction();
    AutomationFixture::enable([$construction], autoOpenBuilderReview: true);

    $mock = Mockery::mock(SalesBoardBuilderReviewOpeningService::class);
    $mock->shouldReceive('open')->andThrow(new RuntimeException('handoff indisponível'));
    app()->instance(SalesBoardBuilderReviewOpeningService::class, $mock);

    AutomationFixture::run();

    // A apuração é o produto da automação e não é desfeita por uma falha de
    // passagem de bastão.
    expect(SalesBoardCycle::query()->count())->toBe(1)
        ->and(SalesBoardCycle::query()->sole()->status)->toBe(SalesBoardCycleStatus::Generated)
        ->and(SalesBoardBuilderReview::query()->count())->toBe(0)
        ->and(SalesBoardAutomationTarget::query()->sole()->status)
        ->toBe(SalesBoardAutomationTargetStatus::Satisfied);
});

it('never lets an attempt be rewritten or deleted', function () {
    $construction = AutomationFixture::readyConstruction();
    AutomationFixture::enable([$construction]);
    AutomationFixture::run();

    $attempt = SalesBoardAutomationAttempt::query()->sole();

    expect(fn () => $attempt->forceFill(['outcome' => SalesBoardAutomationAttemptOutcome::Failed])->save())
        ->toThrow(LogicException::class, 'append-only')
        ->and(fn () => $attempt->delete())->toThrow(LogicException::class, 'append-only');
});

it('never lets a target change the competence it represents', function () {
    $construction = AutomationFixture::readyConstruction();
    AutomationFixture::enable([$construction]);
    AutomationFixture::run();

    $target = SalesBoardAutomationTarget::query()->sole();

    expect(fn () => $target->forceFill(['reference_month' => '2026-09-01'])->save())
        ->toThrow(LogicException::class, 'immutable')
        ->and(fn () => $target->delete())->toThrow(LogicException::class, 'cannot be deleted');
});

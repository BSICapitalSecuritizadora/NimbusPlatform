<?php

use App\Enums\SalesBoardAutomationRunTrigger;
use App\Enums\SalesBoardAutomationSatisfiedVia;
use App\Enums\SalesBoardAutomationTargetStatus;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\Emission;
use App\Models\SalesBoardAutomationAttempt;
use App\Models\SalesBoardAutomationTarget;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleBaseline;
use App\Models\SalesDiscountPolicy;
use App\Services\SalesBoards\ConfiguredSalesBoardAutomationEligibilityProvider;
use App\Services\SalesBoards\SalesBoardAutomationEligibilityProvider;
use App\Services\SalesBoards\SalesBoardAutomationService;
use App\Services\SalesBoards\SalesBoardGenerationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;

/**
 * As corridas da automação, em conexões reais.
 *
 * A propriedade que estes testes protegem é a que o prompt da fase chama de
 * correção: **o banco é a fonte de verdade, e o lock do scheduler é otimização**.
 * Por isso nenhum deles usa `onOneServer()` -- os processos chamam o serviço
 * direto, exatamente como duas instâncias que atravessassem o lock fariam.
 */
beforeEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requer MySQL para observar duas conexões disputando o mesmo alvo.');
    }

    Artisan::call('migrate:fresh', ['--no-interaction' => true]);
});

/**
 * Os processos filhos commitam fora de qualquer transação de teste.
 */
afterEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        return;
    }

    DB::table('sales_board_automation_alerts')->delete();
    DB::table('sales_board_automation_attempts')->delete();
    DB::table('sales_board_automation_targets')->delete();
    DB::table('sales_board_automation_runs')->delete();
    DB::table('sales_board_cycles')->update(['current_baseline_id' => null]);
    DB::table('sales_board_cycle_movements')->delete();
    DB::table('sales_board_cycle_lines')->delete();
    DB::table('sales_board_cycle_baselines')->delete();
    DB::table('sales_board_cycles')->delete();
});

function automationRaceConstruction(): Construction
{
    $construction = Construction::factory()->create([
        'emission_id' => Emission::factory()->create(['status' => 'active'])->id,
    ]);

    SalesDiscountPolicy::factory()->forConstruction($construction)
        ->effectiveFrom('2020-01-01')->allowing('10.00')->create();

    foreach (['101', '102'] as $unit) {
        ConstructionUnit::factory()->create([
            'construction_id' => $construction->id,
            'block' => '01', 'unit' => $unit,
            'base_value' => '500000.00', 'base_value_reference_date' => '2026-01-01',
        ]);
    }

    return $construction;
}

/**
 * @param  array{action: string, construction_id: int, as_of?: string}  $instruction
 */
function automationTask(array $instruction): Closure
{
    return static function () use ($instruction): array {
        config()->set('sales_board.automation.enabled', true);

        /**
         * O processo filho arranca do zero e precisa amarrar o provider de
         * configuração: depois da Fase G o binding normal é o de banco --
         * rollout por Emissão --, e estes cenários continuam sendo o que sempre
         * foram, testes do **motor** da automação sob concorrência real.
         * De onde a elegibilidade vem é assunto da suíte da Fase G.
         */
        app()->bind(
            SalesBoardAutomationEligibilityProvider::class,
            ConfiguredSalesBoardAutomationEligibilityProvider::class,
        );

        config()->set('sales_board.automation.targets', [[
            'construction_id' => $instruction['construction_id'],
            'start_reference_month' => '2026-08-01',
            'auto_open_builder_review' => false,
        ]]);

        try {
            if ($instruction['action'] === 'generate') {
                $outcome = app(SalesBoardGenerationService::class)->generateForConstruction(
                    Construction::query()->findOrFail($instruction['construction_id']),
                    CarbonImmutable::parse('2026-08-01'),
                )->outcome->value;

                return ['success' => true, 'outcome' => $outcome, 'exception' => null];
            }

            $run = app(SalesBoardAutomationService::class)->run(
                trigger: SalesBoardAutomationRunTrigger::Scheduled,
                asOf: CarbonImmutable::parse($instruction['as_of'] ?? '2026-09-13'),
            );

            return [
                'success' => true,
                'outcome' => $run->generated_count.'/'.$run->existing_count,
                'exception' => null,
            ];
        } catch (Throwable $exception) {
            return ['success' => false, 'outcome' => null, 'exception' => $exception::class];
        }
    };
}

it('never materializes two targets for the same competence', function () {
    $construction = automationRaceConstruction();

    $results = Concurrency::driver('process')->run([
        automationTask(['action' => 'run', 'construction_id' => $construction->id]),
        automationTask(['action' => 'run', 'construction_id' => $construction->id]),
    ]);

    // Nenhum SQLSTATE atravessa: a corrida pela unique vira releitura.
    expect(collect($results)->where('success', true))->toHaveCount(2)
        ->and(collect($results)->pluck('exception')->filter()->all())->toBe([])
        ->and(SalesBoardAutomationTarget::query()->count())->toBe(1);
})->group('mysql');

it('never generates two cycles when two schedulers run at once', function () {
    $construction = automationRaceConstruction();

    $results = Concurrency::driver('process')->run([
        automationTask(['action' => 'run', 'construction_id' => $construction->id]),
        automationTask(['action' => 'run', 'construction_id' => $construction->id]),
        automationTask(['action' => 'run', 'construction_id' => $construction->id]),
    ]);

    $target = SalesBoardAutomationTarget::query()->sole();

    // Três instâncias, um ciclo, uma versão, um alvo satisfeito.
    expect(collect($results)->where('success', true))->toHaveCount(3)
        ->and(SalesBoardCycle::query()->count())->toBe(1)
        ->and(SalesBoardCycleBaseline::query()->count())->toBe(1)
        ->and($target->status)->toBe(SalesBoardAutomationTargetStatus::Satisfied)
        ->and($target->satisfied_via)->toBeIn([
            SalesBoardAutomationSatisfiedVia::Generated,
            SalesBoardAutomationSatisfiedVia::Existing,
        ])
        ->and($target->sales_board_cycle_id)->toBe(SalesBoardCycle::query()->sole()->id);
})->group('mysql');

it('never duplicates the cycle when a manual generation races the scheduler', function () {
    $construction = automationRaceConstruction();

    $results = Concurrency::driver('process')->run([
        automationTask(['action' => 'generate', 'construction_id' => $construction->id]),
        automationTask(['action' => 'run', 'construction_id' => $construction->id]),
    ]);

    expect(collect($results)->where('success', true))->toHaveCount(2)
        ->and(SalesBoardCycle::query()->count())->toBe(1)
        ->and(SalesBoardCycleBaseline::query()->count())->toBe(1)
        ->and(SalesBoardAutomationTarget::query()->sole()->status)
        ->toBe(SalesBoardAutomationTargetStatus::Satisfied);
})->group('mysql');

it('records every attempt honestly while keeping one cycle', function () {
    $construction = automationRaceConstruction();

    Concurrency::driver('process')->run([
        automationTask(['action' => 'run', 'construction_id' => $construction->id]),
        automationTask(['action' => 'run', 'construction_id' => $construction->id]),
    ]);

    $attempts = SalesBoardAutomationAttempt::query()->get();

    // As tentativas podem refletir a corrida -- é o que elas existem para
    // contar. O que não pode variar é o estado final.
    expect($attempts->count())->toBeGreaterThanOrEqual(1)
        ->and($attempts->pluck('outcome')->pluck('value')->unique()->diff(['gerado', 'ja_existente'])->all())
        ->toBe([])
        ->and(SalesBoardCycle::query()->count())->toBe(1);
})->group('mysql');

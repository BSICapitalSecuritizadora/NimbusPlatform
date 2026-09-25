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
use App\Models\User;
use App\Services\SalesBoards\SalesBoardAutomationEligibilityProvider;
use App\Services\SalesBoards\SalesBoardAutomationService;
use App\Services\SalesBoards\SalesBoardGenerationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\SalesBoards\ConfiguredSalesBoardAutomationEligibilityProvider;
use Tests\Support\SalesBoards\RolloutFixture;

/**
 * As corridas da automação, em conexões reais.
 *
 * A propriedade que estes testes protegem é a que o prompt da fase chama de
 * correção: **o banco é a fonte de verdade, e o lock do scheduler é otimização**.
 * Por isso nenhum deles usa `onOneServer()` -- os processos chamam o serviço
 * direto, exatamente como duas instâncias que atravessassem o lock fariam.
 *
 * Os cenários de serialização usam barreira: um processo segura o lock do alvo
 * e avisa por um arquivo; o outro só começa depois do aviso. Sem isso, dois
 * processos que por acaso rodassem em série dariam o mesmo resultado de um lock
 * que funciona, e uma regressão no lock passaria de vez em quando.
 */
beforeEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requer MySQL para observar duas conexões disputando o mesmo alvo.');
    }

    Artisan::call('migrate:fresh', ['--no-interaction' => true]);

    $this->automationRaceBaseline = automationRaceBaseline();
});

/**
 * Tudo aqui commita fora de transação de teste -- o processo pai (fixtures) e os
 * filhos (automação). Sem limpar, o arquivo seguinte da suíte começaria com as
 * Emissões, os usuários e os ciclos daqui, e só a suíte inteira denunciaria.
 *
 * A limpeza apaga, em todas as tabelas, o que surgiu depois do retrato tirado
 * logo após as migrations -- inclusive o que os filhos gravaram -- e preserva os
 * dados de referência que as próprias migrations semeiam. É pelo query builder,
 * com as chaves estrangeiras suspensas só nesta conexão, porque os models
 * recusam exclusão e é assim que devem se comportar em produção.
 */
afterEach(function () {
    if (DB::getDriverName() !== 'mysql' || ! isset($this->automationRaceBaseline)) {
        return;
    }

    DB::statement('SET FOREIGN_KEY_CHECKS=0');

    try {
        foreach ($this->automationRaceBaseline as $table => $baseline) {
            if ($baseline['max_id'] !== null) {
                DB::table($table)->where('id', '>', $baseline['max_id'])->delete();
            } elseif ($baseline['count'] === 0) {
                DB::table($table)->delete();
            }
        }
    } finally {
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }
});

/**
 * O retrato do banco recém-migrado: por tabela, o maior id numérico (ou a
 * contagem, quando a chave não é numérica).
 *
 * @return array<string, array{max_id: int|null, count: int}>
 */
function automationRaceBaseline(): array
{
    $baseline = [];

    /**
     * Só o banco desta conexão: sem o schema explícito, o MySQL lista as
     * tabelas de todos os bancos do servidor.
     */
    foreach (Schema::getTables(DB::getDatabaseName()) as $table) {
        $name = $table['name'];

        if ($name === 'migrations') {
            continue;
        }

        $numericId = collect(Schema::getColumns($name))
            ->contains(fn (array $column): bool => $column['name'] === 'id'
                && str_contains(strtolower((string) $column['type_name']), 'int'));

        $baseline[$name] = [
            'max_id' => $numericId ? (int) (DB::table($name)->max('id') ?? 0) : null,
            'count' => DB::table($name)->count(),
        ];
    }

    return $baseline;
}

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
 * Uma Emissão homologada e ativada pelo rollout, como em produção.
 *
 * @return list<Construction>
 */
function automationRaceActivatedEmission(): array
{
    $scenario = RolloutFixture::emission(2, 'R');

    foreach ($scenario['constructions'] as $construction) {
        RolloutFixture::legacyBoard($construction);
    }

    $actor = User::factory()->create();
    $homologation = RolloutFixture::approvedHomologation($scenario['emission'], $actor);
    RolloutFixture::activate($scenario['emission'], $homologation);

    return $scenario['constructions'];
}

/**
 * @param  array{action: string, construction_id?: int, as_of?: string, provider?: string, lock_marker?: string, wait_for_marker?: string, hold_after_lock_ms?: int}  $instruction
 */
function automationTask(array $instruction): Closure
{
    return static function () use ($instruction): array {
        config()->set('sales_board.automation.enabled', true);

        /**
         * O provider de configuração é o dos testes do **motor**: amarrado
         * explicitamente, porque o processo filho arranca do zero com o binding
         * de produção. O provider de banco -- rollout por Emissão -- é o
         * `database`, e não precisa de nada: é o binding da aplicação.
         */
        if (($instruction['provider'] ?? 'config') === 'config') {
            app()->bind(
                SalesBoardAutomationEligibilityProvider::class,
                ConfiguredSalesBoardAutomationEligibilityProvider::class,
            );

            config()->set('sales_board.automation.targets', [[
                'construction_id' => $instruction['construction_id'],
                'start_reference_month' => '2026-08-01',
                'auto_open_builder_review' => false,
            ]]);
        }

        if (isset($instruction['lock_marker'])) {
            DB::listen(static function (QueryExecuted $query) use ($instruction): void {
                $sql = strtolower($query->sql);

                if (! str_contains($sql, 'sales_board_automation_targets') || ! str_contains($sql, 'for update')) {
                    return;
                }

                file_put_contents($instruction['lock_marker'], 'locked');
                usleep(((int) ($instruction['hold_after_lock_ms'] ?? 0)) * 1000);
            });
        }

        try {
            if (isset($instruction['wait_for_marker'])) {
                $deadline = microtime(true) + 10;

                while (! is_file($instruction['wait_for_marker']) && microtime(true) < $deadline) {
                    usleep(10_000);
                }

                if (! is_file($instruction['wait_for_marker'])) {
                    throw new RuntimeException('O processo concorrente não confirmou a aquisição do lock do alvo.');
                }
            }

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
                'generated' => $run->generated_count,
                'skipped' => $run->skipped_count,
                'status' => $run->status->value,
                'exception' => null,
            ];
        } catch (Throwable $exception) {
            return ['success' => false, 'outcome' => null, 'exception' => $exception::class];
        }
    };
}

function automationRaceMarker(string $name): string
{
    $marker = temporaryTestFilePath($name, 'lock');
    @unlink($marker);

    return $marker;
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

it('lets the second scheduler skip the target the first one has reserved', function () {
    $construction = automationRaceConstruction();
    $marker = automationRaceMarker('sales-board-automation-reserve-lock');

    $results = Concurrency::driver('process')->run([
        automationTask([
            'action' => 'run',
            'construction_id' => $construction->id,
            'lock_marker' => $marker,
            'hold_after_lock_ms' => 400,
        ]),
        automationTask([
            'action' => 'run',
            'construction_id' => $construction->id,
            'wait_for_marker' => $marker,
        ]),
    ]);

    @unlink($marker);

    [$first, $second] = $results;

    /**
     * O segundo só começa com o alvo travado pelo primeiro. A reserva dele
     * espera o commit e encontra a tentativa já reservada: não deriva de novo,
     * não conta tentativa, não falha -- ignora.
     */
    expect(collect($results)->pluck('exception')->filter()->all())->toBe([])
        ->and($first['generated'])->toBe(1)
        ->and($second['generated'])->toBe(0)
        ->and($second['skipped'])->toBe(1)
        ->and($second['status'])->toBe('concluido')
        ->and(SalesBoardAutomationAttempt::query()->count())->toBe(1)
        ->and(SalesBoardCycle::query()->count())->toBe(1)
        ->and(SalesBoardAutomationTarget::query()->sole()->in_flight_run_id)->toBeNull();
})->group('mysql');

it('attempts each competence exactly once when two schedulers race through the rollout eligibility', function () {
    $constructions = automationRaceActivatedEmission();
    $marker = automationRaceMarker('sales-board-automation-rollout-lock');

    $results = Concurrency::driver('process')->run([
        automationTask([
            'action' => 'run',
            'provider' => 'database',
            'lock_marker' => $marker,
            'hold_after_lock_ms' => 300,
        ]),
        automationTask([
            'action' => 'run',
            'provider' => 'database',
            'wait_for_marker' => $marker,
        ]),
    ]);

    @unlink($marker);

    $targets = SalesBoardAutomationTarget::query()->orderBy('construction_id')->get();

    // O caminho de produção: elegibilidade pelo rollout, não por configuração.
    expect(collect($results)->pluck('exception')->filter()->all())->toBe([])
        ->and(collect($results)->sum('generated'))->toBe(2)
        ->and($targets->pluck('construction_id')->all())->toBe(collect($constructions)->pluck('id')->all())
        ->and($targets->pluck('status')->unique()->all())->toBe([SalesBoardAutomationTargetStatus::Satisfied])
        ->and($targets->pluck('in_flight_run_id')->filter()->all())->toBe([])
        // Uma tentativa por competência: a reserva impede a segunda derivação.
        ->and(SalesBoardAutomationAttempt::query()->count())->toBe(2)
        ->and(SalesBoardCycle::query()->count())->toBe(2)
        ->and(SalesBoardCycleBaseline::query()->count())->toBe(2);
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

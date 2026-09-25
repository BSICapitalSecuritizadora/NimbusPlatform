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
use Tests\Support\SalesBoards\AutomationFixture;
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
 * Sem barreira, dois processos que por acaso rodassem em série dariam o mesmo
 * resultado de um lock que funciona, e uma regressão passaria de vez em quando.
 * Por isso cada cenário tem a sua, sinalizada por arquivo:
 *
 * - **lock do alvo** (reserva e rollout): um processo segura o lock e avisa; o
 *   outro só começa depois do aviso. A intercalação é forçada;
 * - **leitura da descoberta** (alvo materializado uma vez): os dois leem os alvos
 *   existentes e só então algum deles insere. Os dois viram "não existe", e a
 *   unique é quem decide. A intercalação é forçada;
 * - **largada conjunta** (os demais): todos os processos sobem e esperam uns
 *   pelos outros antes de começar. A sobreposição no tempo é garantida; a
 *   ordem fina dentro dela é do banco, e o que esses cenários provam é o estado
 *   final. A serialização em si é provada pelos cenários de intercalação
 *   forçada.
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
        'emission_id' => Emission::factory()->withAutomatedSalesBoard()->create(['status' => 'active'])->id,
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
 * @param  array{action: string, construction_id?: int, as_of?: string, provider?: string, lock_marker?: string, wait_for_marker?: string, hold_after_lock_ms?: int, start_barrier?: array{arrive: string, await: list<string>}, discovery_barrier?: array{arrive: string, await: list<string>}}  $instruction
 */
function automationTask(array $instruction): Closure
{
    return static function () use ($instruction): array {
        config()->set('sales_board.automation.enabled', true);

        /**
         * A espera da barreira, dentro do processo filho: as funções do arquivo
         * de teste não existem lá, só o que viaja na closure.
         *
         * @param  list<string>  $markers
         */
        $await = static function (array $markers, string $failure): void {
            $deadline = microtime(true) + 10;

            while (microtime(true) < $deadline) {
                if (collect($markers)->every(fn (string $marker): bool => is_file($marker))) {
                    return;
                }

                usleep(10_000);
            }

            throw new RuntimeException($failure);
        };

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

        $reachedDiscovery = false;

        if (isset($instruction['discovery_barrier'])) {
            DB::listen(static function (QueryExecuted $query) use ($instruction, $await, &$reachedDiscovery): void {
                $sql = strtolower($query->sql);

                /**
                 * A leitura dos alvos existentes da descoberta: a primeira
                 * consulta aos alvos filtrada por competência. A recuperação,
                 * antes dela, filtra pela tentativa em voo, não por competência.
                 */
                if ($reachedDiscovery
                    || ! str_starts_with($sql, 'select')
                    || ! str_contains($sql, 'sales_board_automation_targets')
                    || ! str_contains($sql, 'reference_month')
                    || str_contains($sql, 'for update')) {
                    return;
                }

                $reachedDiscovery = true;

                file_put_contents($instruction['discovery_barrier']['arrive'], 'read');
                $await($instruction['discovery_barrier']['await'], 'O processo concorrente não chegou à leitura da descoberta.');
            });
        }

        try {
            if (isset($instruction['start_barrier'])) {
                file_put_contents($instruction['start_barrier']['arrive'], 'ready');
                $await($instruction['start_barrier']['await'], 'O processo concorrente não chegou à largada.');
            }

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
                'discovery_barrier_crossed' => $reachedDiscovery,
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

/**
 * Uma barreira para N processos: cada um marca a chegada no próprio arquivo e
 * espera os arquivos de todos os outros.
 *
 * @return list<array{arrive: string, await: list<string>}>
 */
function automationRaceRendezvous(string $name, int $parties): array
{
    $markers = array_map(
        fn (int $party): string => automationRaceMarker($name.'-'.$party),
        range(1, $parties),
    );

    return array_map(fn (string $marker): array => [
        'arrive' => $marker,
        'await' => array_values(array_diff($markers, [$marker])),
    ], $markers);
}

/**
 * @param  list<array{arrive: string, await: list<string>}>  $rendezvous
 */
function automationRaceCleanup(array $rendezvous): void
{
    foreach ($rendezvous as $party) {
        @unlink($party['arrive']);
    }
}

it('never materializes two targets for the same competence', function () {
    $construction = automationRaceConstruction();
    $barrier = automationRaceRendezvous('sales-board-automation-discovery', 2);

    $results = Concurrency::driver('process')->run(array_map(
        fn (array $party): Closure => automationTask([
            'action' => 'run',
            'construction_id' => $construction->id,
            'discovery_barrier' => $party,
        ]),
        $barrier,
    ));

    automationRaceCleanup($barrier);

    /**
     * Os dois leram "não existe" antes de qualquer um inserir -- a barreira
     * foi de fato atravessada, e não pulada por uma consulta que mudou de
     * forma. Nenhum SQLSTATE atravessa: a corrida pela unique vira releitura.
     */
    expect(collect($results)->pluck('discovery_barrier_crossed')->all())->toBe([true, true])
        ->and(collect($results)->where('success', true))->toHaveCount(2)
        ->and(collect($results)->pluck('exception')->filter()->all())->toBe([])
        ->and(SalesBoardAutomationTarget::query()->count())->toBe(1);
})->group('mysql');

it('never generates two cycles when two schedulers run at once', function () {
    $construction = automationRaceConstruction();
    $barrier = automationRaceRendezvous('sales-board-automation-three-schedulers', 3);

    $results = Concurrency::driver('process')->run(array_map(
        fn (array $party): Closure => automationTask([
            'action' => 'run',
            'construction_id' => $construction->id,
            'start_barrier' => $party,
        ]),
        $barrier,
    ));

    automationRaceCleanup($barrier);

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
    [$manual, $scheduler] = $barrier = automationRaceRendezvous('sales-board-automation-manual-race', 2);

    $results = Concurrency::driver('process')->run([
        automationTask(['action' => 'generate', 'construction_id' => $construction->id, 'start_barrier' => $manual]),
        automationTask(['action' => 'run', 'construction_id' => $construction->id, 'start_barrier' => $scheduler]),
    ]);

    automationRaceCleanup($barrier);

    $target = SalesBoardAutomationTarget::query()->sole();

    expect(collect($results)->where('success', true))->toHaveCount(2)
        ->and(SalesBoardCycle::query()->count())->toBe(1)
        ->and(SalesBoardCycleBaseline::query()->count())->toBe(1)
        ->and($target->status)->toBe(SalesBoardAutomationTargetStatus::Satisfied)
        // Quem perdeu a corrida sabe por qual ciclo o alvo ficou satisfeito.
        ->and($target->sales_board_cycle_id)->toBe(SalesBoardCycle::query()->sole()->id);
})->group('mysql');

/**
 * A mesma corrida, com a intercalação forçada em vez de sorteada.
 *
 * A automação lê o ciclo da competência antes de gerar, e sob `REPEATABLE READ`
 * essa leitura fixa o snapshot da transação do alvo. O ciclo manual é commitado
 * por outra conexão exatamente entre essa leitura e o INSERT da automação: o
 * INSERT perde para a unique, e a releitura do vencedor precisa enxergar uma
 * linha que o snapshot não enxerga.
 */
it('links the target to the cycle a manual generation committed while the scheduler was deriving', function () {
    $construction = automationRaceConstruction();
    AutomationFixture::enable([$construction]);

    config()->set('database.connections.manual_side', config('database.connections.mysql'));

    $manualCycleId = null;

    SalesBoardCycle::creating(function () use (&$manualCycleId, $construction): void {
        if ($manualCycleId !== null) {
            return;
        }

        $manualCycleId = (int) DB::connection('manual_side')->table('sales_board_cycles')->insertGetId([
            'emission_id' => $construction->emission_id,
            'construction_id' => $construction->id,
            'reference_month' => '2026-08-01',
            'position_date' => '2026-08-31',
            'status' => 'gerado',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    AutomationFixture::run();

    DB::connection('manual_side')->disconnect();

    $target = SalesBoardAutomationTarget::query()->sole();
    $attempt = SalesBoardAutomationAttempt::query()->sole();

    expect($manualCycleId)->not->toBeNull()
        ->and(SalesBoardCycle::query()->count())->toBe(1)
        ->and($target->status)->toBe(SalesBoardAutomationTargetStatus::Satisfied)
        ->and($target->satisfied_via)->toBe(SalesBoardAutomationSatisfiedVia::Existing)
        ->and($target->sales_board_cycle_id)->toBe($manualCycleId)
        ->and($attempt->sales_board_cycle_id)->toBe($manualCycleId);
})->group('mysql');

it('records every attempt honestly while keeping one cycle', function () {
    $construction = automationRaceConstruction();
    $barrier = automationRaceRendezvous('sales-board-automation-honest-attempts', 2);

    $results = Concurrency::driver('process')->run(array_map(
        fn (array $party): Closure => automationTask([
            'action' => 'run',
            'construction_id' => $construction->id,
            'start_barrier' => $party,
        ]),
        $barrier,
    ));

    automationRaceCleanup($barrier);

    $attempts = SalesBoardAutomationAttempt::query()->get();

    // As tentativas podem refletir a corrida -- é o que elas existem para
    // contar. O que não pode variar é o estado final.
    expect(collect($results)->pluck('exception')->filter()->all())->toBe([])
        ->and($attempts->count())->toBeGreaterThanOrEqual(1)
        ->and($attempts->pluck('outcome')->pluck('value')->unique()->diff(['gerado', 'ja_existente'])->all())
        ->toBe([])
        ->and(SalesBoardCycle::query()->count())->toBe(1);
})->group('mysql');

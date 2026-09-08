<?php

use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\Contract;
use App\Models\ContractInstallment;
use App\Models\Emission;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleBaseline;
use App\Models\SalesBoardCycleLine;
use App\Models\SalesDiscountPolicy;
use App\Services\SalesBoards\SalesBoardGenerationService;
use App\Services\SalesBoards\SalesBoardRecalculationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;

/**
 * As corridas que o SQLite não consegue mostrar.
 *
 * O SQLite serializa escritores, então dois processos nunca chegam juntos ao
 * mesmo ponto -- e a garantia que interessa aqui é exatamente o que acontece
 * quando eles chegam. Estes testes abrem conexões concorrentes de verdade e só
 * rodam no MySQL, pelo `parity-check.sh`.
 */
beforeEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requer MySQL para observar duas conexões disputando o mesmo ciclo.');
    }

    Artisan::call('migrate:fresh', ['--no-interaction' => true]);
});

/**
 * Os processos concorrentes escrevem em conexões próprias e commitam: nada disso
 * é desfeito por transação de teste. Sem esta limpeza, o arquivo seguinte da
 * suíte começaria com ciclos de outra pessoa no banco.
 *
 * A remoção é pelo query builder porque os models recusam exclusão -- e é assim
 * que devem se comportar em produção.
 */
afterEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        return;
    }

    DB::table('sales_board_cycles')->update(['current_baseline_id' => null]);
    DB::table('sales_board_cycle_movements')->delete();
    DB::table('sales_board_cycle_lines')->delete();
    DB::table('sales_board_cycle_baselines')->delete();
    DB::table('sales_board_cycles')->delete();
});

/**
 * Um empreendimento pronto, com uma venda na competência.
 *
 * @return array{construction: Construction, contract: Contract}
 */
function salesBoardCycleRaceScenario(): array
{
    $emission = Emission::factory()->create(['status' => 'active']);
    $construction = Construction::factory()->create(['emission_id' => $emission->id]);

    SalesDiscountPolicy::factory()
        ->forConstruction($construction)
        ->effectiveFrom('2020-01-01')
        ->allowing('20.00')
        ->create();

    $units = collect(range(1, 4))->map(fn (int $number): ConstructionUnit => ConstructionUnit::factory()->create([
        'construction_id' => $construction->id,
        'block' => '01',
        'unit' => (string) (100 + $number),
        'base_value' => '500000.00',
        'base_value_reference_date' => '2026-01-01',
    ]));

    $contract = Contract::factory()->create([
        'construction_unit_id' => $units->first()->id,
        'sale_date' => '2026-07-05',
        'sale_value' => '600000.00',
    ]);

    ContractInstallment::factory()->create([
        'contract_id' => $contract->id,
        'number' => '001',
        'due_date' => '2026-08-10',
        'expected_value' => '600000.00',
        'payment_date' => null,
        'paid_value' => null,
    ]);

    return ['construction' => $construction, 'contract' => $contract];
}

/**
 * @param  array{action: string, construction_id?: int, cycle_id?: int, reason?: string, expected_baseline_id?: int, lock_marker?: string, wait_for_marker?: string, hold_after_lock_ms?: int}  $instruction
 */
function salesBoardCycleTask(array $instruction): Closure
{
    return static function () use ($instruction): array {
        if (isset($instruction['lock_marker'])) {
            DB::listen(static function (QueryExecuted $query) use ($instruction): void {
                $sql = strtolower($query->sql);

                if (! str_contains($sql, 'sales_board_cycles') || ! str_contains($sql, 'for update')) {
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
                    throw new RuntimeException('O processo concorrente não confirmou a aquisição do lock do ciclo.');
                }
            }

            $outcome = match ($instruction['action']) {
                'generate' => app(SalesBoardGenerationService::class)->generateForConstruction(
                    Construction::query()->findOrFail($instruction['construction_id']),
                    CarbonImmutable::parse('2026-07-01'),
                )->outcome->value,
                'recalculate' => app(SalesBoardRecalculationService::class)->recalculate(
                    SalesBoardCycle::query()->findOrFail($instruction['cycle_id']),
                    null,
                    $instruction['reason'] ?? 'Recálculo concorrente.',
                    $instruction['expected_baseline_id'] ?? null,
                )->outcome->value,
            };

            return ['success' => true, 'outcome' => $outcome, 'exception' => null];
        } catch (Throwable $exception) {
            return ['success' => false, 'outcome' => null, 'exception' => $exception::class];
        }
    };
}

it('never produces two cycles for the same construction and competência', function () {
    $scenario = salesBoardCycleRaceScenario();

    $results = Concurrency::driver('process')->run([
        salesBoardCycleTask(['action' => 'generate', 'construction_id' => $scenario['construction']->id]),
        salesBoardCycleTask(['action' => 'generate', 'construction_id' => $scenario['construction']->id]),
    ]);

    // Nenhum dos dois erra: o perdedor da unique relê o ciclo do vencedor em vez
    // de devolver um SQLSTATE para quem clicou num botão.
    expect(collect($results)->where('success', true))->toHaveCount(2)
        ->and(collect($results)->pluck('outcome')->sort()->values()->all())
        ->toBe(['gerado', 'ja_existente'])
        ->and(SalesBoardCycle::query()->count())->toBe(1)
        ->and(SalesBoardCycleBaseline::query()->count())->toBe(1)
        ->and(SalesBoardCycleLine::query()->count())->toBe(4);
})->group('mysql');

it('never produces two second versions from the same source', function () {
    $scenario = salesBoardCycleRaceScenario();

    app(SalesBoardGenerationService::class)->generateForConstruction(
        $scenario['construction'],
        CarbonImmutable::parse('2026-07-01'),
    );

    $cycle = SalesBoardCycle::query()->sole();
    $scenario['contract']->update(['sale_value' => '610000.00']);

    $marker = temporaryTestFilePath('sales-board-cycle-recalculate-lock', 'lock');
    @unlink($marker);

    $results = Concurrency::driver('process')->run([
        salesBoardCycleTask([
            'action' => 'recalculate',
            'cycle_id' => $cycle->id,
            'reason' => 'Primeiro recálculo.',
            'lock_marker' => $marker,
            'hold_after_lock_ms' => 400,
        ]),
        salesBoardCycleTask([
            'action' => 'recalculate',
            'cycle_id' => $cycle->id,
            'reason' => 'Segundo recálculo.',
            'wait_for_marker' => $marker,
        ]),
    ]);

    @unlink($marker);

    // Um cria a V2; o outro, serializado pelo lock, relê a versão vigente já
    // atualizada, encontra a mesma fonte e não cria uma V3 idêntica.
    expect(collect($results)->where('success', true))->toHaveCount(2)
        ->and(collect($results)->pluck('outcome')->sort()->values()->all())
        ->toBe(['recalculado', 'sem_alteracao'])
        ->and(SalesBoardCycleBaseline::query()->orderBy('version')->pluck('version')->all())->toBe([1, 2])
        ->and($cycle->fresh()->current_baseline_id)
        ->toBe(SalesBoardCycleBaseline::query()->where('version', 2)->value('id'));
})->group('mysql');

it('never lets a stale screen overwrite the pointer of a newer version', function () {
    $scenario = salesBoardCycleRaceScenario();

    app(SalesBoardGenerationService::class)->generateForConstruction(
        $scenario['construction'],
        CarbonImmutable::parse('2026-07-01'),
    );

    $cycle = SalesBoardCycle::query()->sole();
    $firstVersionId = (int) $cycle->current_baseline_id;

    $scenario['contract']->update(['sale_value' => '610000.00']);

    $marker = temporaryTestFilePath('sales-board-cycle-pointer-lock', 'lock');
    @unlink($marker);

    $results = Concurrency::driver('process')->run([
        salesBoardCycleTask([
            'action' => 'recalculate',
            'cycle_id' => $cycle->id,
            'reason' => 'Recálculo de quem estava com a tela atualizada.',
            'lock_marker' => $marker,
            'hold_after_lock_ms' => 400,
        ]),
        salesBoardCycleTask([
            'action' => 'recalculate',
            'cycle_id' => $cycle->id,
            'reason' => 'Recálculo de quem abriu a tela antes.',
            'expected_baseline_id' => $firstVersionId,
            'wait_for_marker' => $marker,
        ]),
    ]);

    @unlink($marker);

    expect(collect($results)->pluck('outcome')->sort()->values()->all())
        ->toBe(['bloqueado', 'recalculado'])
        ->and(SalesBoardCycleBaseline::query()->count())->toBe(2)
        ->and($cycle->fresh()->current_baseline_id)->not->toBe($firstVersionId);
})->group('mysql');

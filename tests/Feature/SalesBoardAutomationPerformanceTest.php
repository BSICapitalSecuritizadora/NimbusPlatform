<?php

use App\Models\Construction;
use App\Models\Emission;
use App\Models\SalesBoardAutomationTarget;
use App\Models\SalesBoardCycle;
use App\Models\SalesDiscountPolicy;
use App\Services\SalesBoards\SalesBoardAutomationDiscoveryService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SalesBoards\AutomationFixture;
use Tests\Support\SalesBoards\DerivationFixture;

uses(RefreshDatabase::class);

/**
 * O custo da automação, medido e não estimado.
 *
 * Opt-in: `NIMBUS_BENCH=1`. Tempo em suíte comum vira teste instável que o time
 * aprende a reexecutar até passar, e um teste que se ignora não mede nada.
 */
beforeEach(function () {
    if (! env('NIMBUS_BENCH')) {
        $this->markTestSkipped('Benchmark opt-in: defina NIMBUS_BENCH=1.');
    }

    AutomationFixture::disable();
});

/**
 * @return list<Construction>
 */
function benchConstructions(int $count): array
{
    $emission = Emission::factory()->create(['status' => 'active']);
    $constructions = [];

    for ($i = 0; $i < $count; $i++) {
        $construction = Construction::factory()->create(['emission_id' => $emission->id]);

        SalesDiscountPolicy::factory()->forConstruction($construction)
            ->effectiveFrom('2020-01-01')->allowing('10.00')->create();

        DerivationFixture::unit($construction, '101');
        DerivationFixture::unit($construction, '102');

        $constructions[] = $construction;
    }

    return $constructions;
}

it('keeps discovery flat as the number of targets grows', function (int $count) {
    $constructions = benchConstructions($count);
    AutomationFixture::enable($constructions);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $started = microtime(true);
    $candidates = app(SalesBoardAutomationDiscoveryService::class)
        ->discover(CarbonImmutable::parse('2026-09-13'));
    $elapsed = (microtime(true) - $started) * 1000;

    dump([
        'targets' => $count,
        'discovered' => count($candidates),
        'discovery_queries' => $queries,
        'discovery_ms' => round($elapsed, 1),
    ]);

    /**
     * A descoberta não pode consultar um empreendimento por alvo: ela resolve o
     * lote inteiro numa consulta só, e é isso que este limite protege.
     */
    expect(count($candidates))->toBe($count)
        ->and($queries)->toBeLessThanOrEqual(3);
})->with([20, 100]);

it('measures a full run end to end', function (int $count) {
    $constructions = benchConstructions($count);
    AutomationFixture::enable($constructions);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $started = microtime(true);
    $memory = memory_get_usage(true);

    $run = AutomationFixture::run();

    dump([
        'targets' => $count,
        'generated' => $run->generated_count,
        'total_queries' => $queries,
        'duration_ms' => round((microtime(true) - $started) * 1000, 1),
        'memory_mb' => round((memory_get_usage(true) - $memory) / 1048576, 1),
        'queries_per_target' => round($queries / max($count, 1), 1),
    ]);

    expect($run->generated_count)->toBe($count)
        ->and(SalesBoardCycle::query()->count())->toBe($count)
        ->and(SalesBoardAutomationTarget::query()->count())->toBe($count);
})->with([20, 100]);

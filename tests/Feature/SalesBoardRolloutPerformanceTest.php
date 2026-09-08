<?php

use App\Enums\SalesBoardRolloutHomologationStatus;
use App\Enums\SalesBoardSource;
use App\Models\Emission;
use App\Models\SalesBoardRolloutHomologation;
use App\Services\SalesBoards\SalesBoardAutomationEligibilityProvider;
use App\Services\SalesBoards\SalesBoardRolloutAssessmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SalesBoards\RolloutFixture;

uses(RefreshDatabase::class);

/**
 * O custo do provider de banco, medido e não estimado.
 *
 * Opt-in: `NIMBUS_BENCH=1`. Tempo em suíte comum vira teste instável que o time
 * aprende a reexecutar até passar.
 */
beforeEach(function () {
    if (! env('NIMBUS_BENCH')) {
        $this->markTestSkipped('Benchmark opt-in: defina NIMBUS_BENCH=1.');
    }

    config()->set('sales_board.automation.enabled', true);
});

it('keeps the eligibility provider flat as the portfolio grows', function (int $emissions, int $perEmission) {
    $assessment = app(SalesBoardRolloutAssessmentService::class);

    for ($i = 0; $i < $emissions; $i++) {
        $scenario = RolloutFixture::emission($perEmission, 'P'.$i);

        // Ativação pelo caminho de dados: o benchmark mede a descoberta, não a
        // homologação -- que é ato raro e deliberado.
        $homologation = SalesBoardRolloutHomologation::factory()->create([
            'emission_id' => $scenario['emission']->id,
            'status' => SalesBoardRolloutHomologationStatus::Approved,
            'construction_scope_hash' => $assessment->scopeHash(
                collect($scenario['constructions'])->map(fn ($c): int => $c->id)->all()
            ),
        ]);

        $scenario['emission']->forceFill([
            'sales_board_source' => SalesBoardSource::Automated,
            'sales_board_automation_start_reference_month' => '2026-08-01',
            'sales_board_active_homologation_id' => $homologation->id,
        ])->save();
    }

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $started = microtime(true);
    $targets = app(SalesBoardAutomationEligibilityProvider::class)->eligibleTargets();
    $elapsed = (microtime(true) - $started) * 1000;

    dump([
        'emissions' => $emissions,
        'constructions' => $emissions * $perEmission,
        'eligible_targets' => count($targets),
        'provider_queries' => $queries,
        'provider_ms' => round($elapsed, 1),
    ]);

    /**
     * Uma consulta de Emissões, uma de empreendimentos, mais o eager load da
     * homologação. Uma consulta por Emissão -- ou pior, por empreendimento --
     * transformaria a descoberta horária em dezenas de idas ao banco antes de
     * qualquer apuração.
     */
    expect(count($targets))->toBe($emissions * $perEmission)
        ->and($queries)->toBeLessThanOrEqual(5)
        ->and(Emission::query()->where('sales_board_source', SalesBoardSource::Automated)->count())
        ->toBe($emissions);
})->with([[20, 5], [20, 1]]);

<?php

use App\Models\Construction;
use App\Models\SalesBoardRolloutHomologation;
use App\Services\SalesBoards\SalesBoardDerivationService;
use App\Services\SalesBoards\SalesBoardFingerprintService;
use App\Services\SalesBoards\SalesBoardRolloutAssessmentService;
use App\Support\SalesBoards\RolloutPosition;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SalesBoards\DerivationFixture;
use Tests\Support\SalesBoards\RolloutFixture;

uses(RefreshDatabase::class);

/**
 * A avaliação do rollout roda na requisição web -- abrir, reavaliar, aprovar e
 * ativar --, e a Emissão pode ter várias obras maduras. Derivar e observar todas
 * num lote só mantinha as parcelas de todas elas em memória ao mesmo tempo: três
 * obras que cabem sozinhas nos 256 MB estouravam juntas. Aqui se prova a forma
 * (um empreendimento por vez, com o mesmo resultado do lote); o pico medido
 * está em `SalesBoardVolumePerformanceTest`.
 */

/**
 * Registra quais empreendimentos cada chamada de lote recebeu.
 *
 * Classe nomeada, e não anônima: o container resolve as dependências dela como
 * resolve as do serviço real, e o teste não precisa repetir o construtor.
 */
class RolloutScaleDerivationSpy extends SalesBoardDerivationService
{
    /** @var list<list<int>> */
    public array $batches = [];

    public function deriveForConstructions(iterable $constructions, CarbonInterface $referenceMonth): array
    {
        $constructions = collect($constructions);
        $this->batches[] = $constructions->map(fn (Construction $construction): int => (int) $construction->getKey())->values()->all();

        return parent::deriveForConstructions($constructions, $referenceMonth);
    }
}

class RolloutScaleFingerprintSpy extends SalesBoardFingerprintService
{
    /** @var list<list<int>> */
    public array $batches = [];

    public function observeForConstructions(iterable $constructions, CarbonInterface $referenceMonth): array
    {
        $constructions = collect($constructions);
        $this->batches[] = $constructions->map(fn (Construction $construction): int => (int) $construction->getKey())->values()->all();

        return parent::observeForConstructions($constructions, $referenceMonth);
    }
}

/**
 * Uma Emissão com três empreendimentos, cada um com uma venda e cronograma, para
 * que derivação e observação tenham parcelas a carregar.
 *
 * @return array{homologation: SalesBoardRolloutHomologation, constructions: list<Construction>}
 */
function rolloutScaleScenario(): array
{
    $scenario = RolloutFixture::emission(3);

    foreach ($scenario['constructions'] as $index => $construction) {
        $unit = DerivationFixture::unit($construction, 'S'.$index);
        $contract = DerivationFixture::contract($unit, '2026-03-10', '480000.00');

        DerivationFixture::installment($contract, '001', '2026-04-10', '240000.00', '2026-04-10', '240000.00');
        DerivationFixture::installment($contract, '002', '2026-09-10', '240000.00');
    }

    $homologation = SalesBoardRolloutHomologation::factory()->create([
        'emission_id' => $scenario['emission']->id,
        'comparison_reference_month' => RolloutFixture::COMPARISON_MONTH,
        'proposed_start_reference_month' => RolloutFixture::START_MONTH,
    ]);

    return ['homologation' => $homologation, 'constructions' => $scenario['constructions']];
}

it('derives and observes the emission one development at a time', function () {
    ['homologation' => $homologation, 'constructions' => $constructions] = rolloutScaleScenario();

    $derivation = app(RolloutScaleDerivationSpy::class);
    $fingerprint = app(RolloutScaleFingerprintSpy::class);

    app()->instance(SalesBoardDerivationService::class, $derivation);
    app()->instance(SalesBoardFingerprintService::class, $fingerprint);

    $observation = app(SalesBoardRolloutAssessmentService::class)->observe($homologation);

    $expected = collect($constructions)->map(fn (Construction $construction): array => [(int) $construction->id])->all();

    expect($derivation->batches)->toBe($expected)
        ->and($fingerprint->batches)->toBe($expected)
        ->and(array_keys($observation->constructionRows))->toBe(collect($constructions)->pluck('id')->all());
});

it('assesses each development exactly as the whole-emission batch would', function () {
    ['homologation' => $homologation, 'constructions' => $constructions] = rolloutScaleScenario();
    $month = CarbonImmutable::parse(RolloutFixture::COMPARISON_MONTH);

    $batchPositions = app(SalesBoardDerivationService::class)->deriveForConstructions($constructions, $month);
    $batchObservations = app(SalesBoardFingerprintService::class)->observeForConstructions($constructions, $month);

    $observation = app(SalesBoardRolloutAssessmentService::class)->observe($homologation);

    foreach ($constructions as $construction) {
        $row = $observation->constructionRows[$construction->id];

        expect($row['source_fingerprint'])->toBe($batchObservations[$construction->id]->fingerprint())
            ->and($row['derived_position'])->toBe(RolloutPosition::fromDerived($batchPositions[$construction->id]));
    }

    expect(app(SalesBoardRolloutAssessmentService::class)->observe($homologation->fresh())->assessmentHash)
        ->toBe($observation->assessmentHash);
});

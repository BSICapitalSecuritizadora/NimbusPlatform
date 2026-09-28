<?php

use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\Contract;
use App\Models\ContractInstallment;
use App\Models\Emission;
use App\Models\SalesBoardCycle;
use App\Models\SalesDiscountPolicy;
use App\Services\SalesBoards\SalesBoardDerivationService;
use App\Services\SalesBoards\SalesBoardFingerprintService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Uma emissão com vários empreendimentos, cada um com unidades, contratos e
 * cronogramas -- o formato que a carteira real tem.
 *
 * As vendas caem em dias diferentes de propósito: a política comercial pode
 * mudar entre elas, e resolver isso venda a venda era exatamente a armadilha
 * que a API de lote precisa evitar.
 *
 * @return array{0: Emission, 1: Collection<int, Construction>}
 */
function cycleBatchDataset(int $constructions, int $unitsEach): array
{
    $emission = Emission::factory()->withAutomatedSalesBoard()->create(['status' => 'active']);
    $now = now();

    $created = collect();

    foreach (range(1, $constructions) as $index) {
        $construction = Construction::factory()->create([
            'emission_id' => $emission->id,
            'development_name' => 'Empreendimento '.$index,
        ]);

        SalesDiscountPolicy::factory()
            ->forConstruction($construction)
            ->effectiveFrom('2020-01-01')
            ->allowing('20.00')
            ->create();

        $unitRows = [];

        foreach (range(1, $unitsEach) as $number) {
            $unitRows[] = [
                'construction_id' => $construction->id,
                'block' => '01',
                'unit' => (string) $number,
                'base_value' => '500000.00',
                'base_value_reference_date' => '2026-01-01',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        ConstructionUnit::query()->insert($unitRows);
        $created->push($construction);
    }

    $unitIds = ConstructionUnit::query()
        ->whereIn('construction_id', $created->pluck('id'))
        ->orderBy('id')
        ->get(['id', 'construction_id']);

    $contractRows = [];

    foreach ($unitIds as $offset => $unit) {
        if (($offset % 3) !== 0) {
            continue;
        }

        $contractRows[] = [
            'construction_unit_id' => $unit->id,
            'construction_id' => $unit->construction_id,
            'code' => 'CT-'.$unit->id,
            'code_normalized' => 'CT-'.$unit->id,
            'sale_date' => ($offset % 9 === 0) ? '2026-07-0'.(($offset % 5) + 1) : '2026-02-10',
            'sale_value' => '450000.00',
            'status' => 'ativo',
            'cancellation_date' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    foreach (array_chunk($contractRows, 500) as $chunk) {
        Contract::query()->insert($chunk);
    }

    $installmentRows = [];

    $contractIds = Contract::query()
        ->whereIn('construction_id', $created->pluck('id'))
        ->orderBy('id')
        ->pluck('id');

    foreach ($contractIds as $contractId) {
        foreach (range(1, 6) as $number) {
            $installmentRows[] = [
                'contract_id' => $contractId,
                'number' => str_pad((string) $number, 3, '0', STR_PAD_LEFT),
                'number_normalized' => str_pad((string) $number, 3, '0', STR_PAD_LEFT),
                'due_date' => '2026-'.str_pad((string) $number, 2, '0', STR_PAD_LEFT).'-10',
                'expected_value' => '75000.00',
                'payment_date' => null,
                'paid_value' => null,
                'cancellation_date' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
    }

    foreach (array_chunk($installmentRows, 500) as $chunk) {
        ContractInstallment::query()->insert($chunk);
    }

    return [$emission, $created];
}

function countQueries(Closure $callback): int
{
    DB::enableQueryLog();
    DB::flushQueryLog();

    $callback();

    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

it('derives twenty developments without paying six loads per development', function () {
    [, $constructions] = cycleBatchDataset(20, 50);

    $service = app(SalesBoardDerivationService::class);
    $month = CarbonImmutable::parse('2026-07-01');
    $fresh = Construction::query()->whereIn('id', $constructions->pluck('id'))->get();

    $positions = [];

    $queries = countQueries(function () use ($service, $fresh, $month, &$positions): void {
        $positions = $service->deriveForConstructions($fresh, $month);
    });

    expect($positions)->toHaveCount(20)
        ->and(collect($positions)->sum(fn ($position): int => $position->unitsTotal))->toBe(1_000)
        // Seis carregamentos, os mesmos de um único empreendimento: unidades,
        // contratos, permutas, parcelas, valores e políticas.
        ->and($queries)->toBe(6);
});

it('keeps the batch query count flat as the portfolio doubles', function () {
    $service = app(SalesBoardDerivationService::class);
    $month = CarbonImmutable::parse('2026-07-01');

    [, $small] = cycleBatchDataset(4, 10);
    $smallQueries = countQueries(fn () => $service->deriveForConstructions(
        Construction::query()->whereIn('id', $small->pluck('id'))->get(),
        $month,
    ));

    [, $large] = cycleBatchDataset(8, 40);
    $largeQueries = countQueries(fn () => $service->deriveForConstructions(
        Construction::query()->whereIn('id', $large->pluck('id'))->get(),
        $month,
    ));

    expect($largeQueries)->toBe($smallQueries);
});

it('produces exactly the same position through the batch API as one by one', function () {
    [, $constructions] = cycleBatchDataset(3, 8);

    $service = app(SalesBoardDerivationService::class);
    $month = CarbonImmutable::parse('2026-07-01');
    $fresh = Construction::query()->whereIn('id', $constructions->pluck('id'))->orderBy('id')->get();

    $batch = $service->deriveForConstructions($fresh, $month);

    foreach ($fresh as $construction) {
        $single = $service->deriveForConstruction($construction, $month);
        $one = $batch[$construction->id];

        expect($one->toArray(withLines: true))->toBe($single->toArray(withLines: true));
    }
});

it('observes the material source of the whole emission in a constant number of loads', function () {
    [, $constructions] = cycleBatchDataset(20, 50);

    $service = app(SalesBoardFingerprintService::class);
    $fresh = Construction::query()->whereIn('id', $constructions->pluck('id'))->get();

    $observations = [];

    $queries = countQueries(function () use ($service, $fresh, &$observations): void {
        $observations = $service->observeForConstructions($fresh, CarbonImmutable::parse('2026-07-01'));
    });

    expect($observations)->toHaveCount(20)
        ->and($queries)->toBe(6);
});

/**
 * O custo marginal de congelar mais um empreendimento.
 *
 * O congelamento de uma emissão inteira apura **um empreendimento por vez**:
 * seis carregamentos para derivar e seis para observar a fonte de cada um, e
 * depois a escrita do ciclo na sua própria transação. A derivação em lote da
 * emissão inteira, que deixava a apuração constante, somava na memória as
 * parcelas de todos os empreendimentos e derrubava o processo antes do primeiro
 * ciclo; o preço de não fazer isso são estas consultas por empreendimento.
 * Continua sendo um custo fixo por ciclo, sem relação com o número de unidades,
 * contratos ou parcelas -- o teste seguinte prova essa metade.
 */
it('freezes a whole emission at a bounded marginal cost per development', function () {
    [$smallEmission] = cycleBatchDataset(5, 25);
    $smallQueries = countQueries(fn () => Artisan::call('sales-boards:generate-cycle', [
        '--emission' => $smallEmission->id,
        '--reference-month' => '07/2026',
        '--json' => true,
    ]));

    [$largeEmission] = cycleBatchDataset(15, 25);
    $largeQueries = countQueries(fn () => Artisan::call('sales-boards:generate-cycle', [
        '--emission' => $largeEmission->id,
        '--reference-month' => '07/2026',
        '--json' => true,
    ]));

    $marginalPerConstruction = ($largeQueries - $smallQueries) / 10;

    expect(SalesBoardCycle::query()->count())->toBe(20)
        /**
         * Doze leituras de apuração e a escrita -- transação, cabeçalho,
         * linhas, movimentos, ponteiro da versão e o comparativo com o quadro
         * publicado.
         */
        ->and($marginalPerConstruction)->toBeLessThanOrEqual(25.0);
});

/**
 * A prova de que a apuração do lote não junta a emissão inteira na memória:
 * cada carga de unidades -- a da derivação e a da observação da fonte -- pede um
 * empreendimento só.
 */
it('reads the source of one development at a time when freezing a whole emission', function () {
    [$emission, $constructions] = cycleBatchDataset(3, 5);

    $unitLoads = [];

    DB::listen(function (QueryExecuted $query) use (&$unitLoads): void {
        if (str_contains($query->sql, 'from "construction_units" where "construction_id" in')) {
            $unitLoads[] = $query->bindings;
        }
    });

    Artisan::call('sales-boards:generate-cycle', [
        '--emission' => $emission->id,
        '--reference-month' => '07/2026',
        '--json' => true,
    ]);

    expect(SalesBoardCycle::query()->count())->toBe(3)
        ->and($unitLoads)->toHaveCount(6)
        ->and(collect($unitLoads)->every(fn (array $bindings): bool => count($bindings) === 1))->toBeTrue()
        ->and(collect($unitLoads)->flatten()->unique()->sort()->values()->all())
        ->toBe($constructions->pluck('id')->sort()->values()->all());
});

it('does not pay more to freeze a development just because it has more units', function () {
    [$fewUnits] = cycleBatchDataset(3, 10);
    $fewQueries = countQueries(fn () => Artisan::call('sales-boards:generate-cycle', [
        '--emission' => $fewUnits->id,
        '--reference-month' => '07/2026',
        '--json' => true,
    ]));

    [$manyUnits] = cycleBatchDataset(3, 60);
    $manyQueries = countQueries(fn () => Artisan::call('sales-boards:generate-cycle', [
        '--emission' => $manyUnits->id,
        '--reference-month' => '07/2026',
        '--json' => true,
    ]));

    expect($manyQueries)->toBe($fewQueries);
});

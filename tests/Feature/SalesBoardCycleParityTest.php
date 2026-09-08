<?php

use App\DTOs\SalesBoards\SalesBoardComparableSnapshot;
use App\Enums\SalesBoardMovementType;
use App\Enums\SalesBoardUnitClassification;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\Contract;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleBaseline;
use App\Models\SalesBoardCycleLine;
use App\Models\SalesBoardCycleMovement;
use App\Support\Money\IntegerMoney;
use App\Support\SalesBoards\CanonicalDigest;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SalesBoards\CycleFixture;
use Tests\Support\SalesBoards\DerivationFixture;

uses(RefreshDatabase::class);

/**
 * As garantias que dependem de o banco ser um banco específico.
 *
 * Unique, RESTRICT e o formato com que uma coluna `decimal` volta da leitura não
 * são a mesma coisa no SQLite e no MySQL, e o fingerprint de uma competência é
 * calculado exatamente sobre esses valores lidos de volta. Uma diferença aqui
 * faria a mesma carteira produzir hashes diferentes conforme o banco, e a
 * detecção de alterações passaria a acusar mudanças que não existiram.
 */
pest()->group('parity');

/**
 * @return array{cycle: SalesBoardCycle, baseline: SalesBoardCycleBaseline, construction: Construction, units: list<ConstructionUnit>, contract: Contract}
 */
function parityCycle(): array
{
    [$construction, $units] = CycleFixture::readyConstruction(3);

    $contract = DerivationFixture::contract($units[0], '2026-07-05', '612345.67');
    DerivationFixture::installment($contract, '001', '2026-08-10', '612345.67');

    /**
     * O ciclo vem do resultado da geração, e não de um `sole()` global: os
     * testes de concorrência que rodam antes deste arquivo commitam dados em
     * conexões próprias, fora de qualquer transação de teste, e uma consulta
     * que presuma "só existe um ciclo no banco" quebra por causa deles.
     */
    $cycle = CycleFixture::generate($construction)->cycle;

    return [
        'cycle' => $cycle,
        'baseline' => CycleFixture::currentBaseline($cycle),
        'construction' => $construction,
        'units' => $units,
        'contract' => $contract,
    ];
}

it('allows a single cycle per construction and competência', function () {
    $scenario = parityCycle();

    SalesBoardCycle::query()->create([
        'emission_id' => $scenario['construction']->emission_id,
        'construction_id' => $scenario['construction']->id,
        'reference_month' => '2026-07-01',
        'position_date' => '2026-07-31',
        'status' => 'gerado',
    ]);
})->throws(UniqueConstraintViolationException::class);

it('allows a single baseline per version of a cycle', function () {
    $scenario = parityCycle();

    SalesBoardCycleBaseline::factory()->create([
        'sales_board_cycle_id' => $scenario['cycle']->id,
        'version' => 1,
    ]);
})->throws(UniqueConstraintViolationException::class);

it('allows a single line per unit of a baseline', function () {
    $scenario = parityCycle();

    SalesBoardCycleLine::factory()->create([
        'sales_board_cycle_baseline_id' => $scenario['baseline']->id,
        'construction_unit_id' => $scenario['units'][0]->id,
    ]);
})->throws(UniqueConstraintViolationException::class);

it('allows a single movement per type and contract of a baseline', function () {
    $scenario = parityCycle();

    SalesBoardCycleMovement::factory()->create([
        'sales_board_cycle_baseline_id' => $scenario['baseline']->id,
        'movement_type' => SalesBoardMovementType::Sale,
        'construction_unit_id' => $scenario['units'][0]->id,
        'contract_id' => $scenario['contract']->id,
    ]);
})->throws(UniqueConstraintViolationException::class);

it('refuses to delete a construction unit referenced by a frozen line', function () {
    $scenario = parityCycle();

    $scenario['units'][1]->delete();
})->throws(QueryException::class);

it('refuses to delete a contract referenced by a frozen line', function () {
    $scenario = parityCycle();

    $scenario['contract']->forceDelete();
})->throws(QueryException::class);

it('refuses to delete a construction that has a cycle', function () {
    $scenario = parityCycle();

    $scenario['construction']->delete();
})->throws(QueryException::class);

it('refuses to delete a baseline referenced as the current version', function () {
    $scenario = parityCycle();

    SalesBoardCycleBaseline::query()->whereKey($scenario['baseline']->id)->delete();
})->throws(QueryException::class);

it('reads money back as the exact decimal it wrote, in either engine', function () {
    $scenario = parityCycle();

    $line = $scenario['baseline']->lines->firstWhere('contract_id', $scenario['contract']->id);
    $movement = $scenario['baseline']->movements->firstWhere('contract_id', $scenario['contract']->id);

    expect($line->contract_sale_value)->toBe('612345.67')
        ->and(IntegerMoney::cents($line->contract_sale_value))->toBe(61_234_567)
        ->and($movement->sale_value)->toBe('612345.67')
        ->and($scenario['baseline']->financed_value)->toBe('612345.67')
        ->and($scenario['baseline']->stock_value)->toBe('1000000.00');
});

it('reads dates back as the same civil day, in either engine', function () {
    $scenario = parityCycle();

    $line = $scenario['baseline']->lines->firstWhere('contract_id', $scenario['contract']->id);

    expect($line->contract_sale_date->toDateString())->toBe('2026-07-05')
        ->and($line->unit_reference_value_effective_from->toDateString())->toBe('2026-01-01')
        ->and($scenario['cycle']->reference_month->toDateString())->toBe('2026-07-01')
        ->and($scenario['cycle']->position_date->toDateString())->toBe('2026-07-31');
});

it('rebuilds the very same snapshot fingerprint from what was persisted', function () {
    $scenario = parityCycle();

    $rebuilt = SalesBoardComparableSnapshot::fromBaseline($scenario['baseline']->fresh());

    expect($rebuilt->snapshot->fingerprint())->toBe($scenario['baseline']->snapshot_fingerprint)
        ->and($rebuilt->snapshot->linesByUnit())->toHaveCount(3)
        ->and($rebuilt->snapshot->lines[0]->classification)->toBeInstanceOf(SalesBoardUnitClassification::class);
});

it('hashes a canonical document identically regardless of the database', function () {
    // Valor fixo: a canonicalização é PHP puro e não pode depender do banco,
    // do fuso, da localidade nem da ordem de carregamento.
    expect(CanonicalDigest::of([
        'units' => [CanonicalDigest::row([1, '01', '101', 50_000_000, '2026-01-01'])],
        'contracts' => [CanonicalDigest::row([7, 1, 'CT-7', '2026-07-05', 61_234_567, null, 'ativo'])],
    ]))->toBe(hash('sha256', implode('', [
        "#units\n",
        "1|01|101|50000000|2026-01-01\n",
        "#contracts\n",
        "7|1|CT-7|2026-07-05|61234567|~|ativo\n",
    ])));
});

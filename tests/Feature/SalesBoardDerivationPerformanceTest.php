<?php

use App\Models\ConstructionUnit;
use App\Models\ConstructionUnitExchange;
use App\Models\ConstructionUnitValue;
use App\Models\Contract;
use App\Models\ContractInstallment;
use App\Models\SalesDiscountPolicy;
use App\Services\SalesBoards\SalesBoardDerivationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SalesBoards\DerivationFixture;

uses(RefreshDatabase::class);

/**
 * Builds a development with the given number of units and returns it.
 *
 * Roughly a third of the units carry a contract, each contract carries a
 * schedule, and a handful are exchanged -- the shape a real development has.
 */
function derivationDataset(int $units): array
{
    $construction = DerivationFixture::construction();

    SalesDiscountPolicy::factory()->forConstruction($construction)->effectiveFrom('2020-01-01')->allowing('5.00')->create();

    $unitRows = [];
    $now = now();

    foreach (range(1, $units) as $number) {
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

    $unitIds = ConstructionUnit::query()->where('construction_id', $construction->id)->orderBy('id')->pluck('id')->all();

    $valueRows = [];
    foreach (array_slice($unitIds, 0, (int) ($units / 2)) as $unitId) {
        $valueRows[] = [
            'construction_unit_id' => $unitId,
            'value' => '550000.00',
            'effective_from' => '2026-05-01',
            'source' => 'manual',
            'reason' => 'Reajuste',
            'created_by_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
    ConstructionUnitValue::query()->insert($valueRows);

    $contractCount = (int) ($units * 0.75);
    $contractRows = [];

    foreach (array_slice($unitIds, 0, $contractCount) as $index => $unitId) {
        $contractRows[] = [
            'construction_unit_id' => $unitId,
            'construction_id' => $construction->id,
            'code' => 'CT-'.$construction->id.'-'.$index,
            'code_normalized' => 'CT-'.$construction->id.'-'.$index,
            'sale_date' => $index % 10 === 0 ? '2026-07-05' : '2026-02-10',
            'sale_value' => '600000.00',
            'status' => 'ativo',
            'cancellation_date' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
    Contract::query()->insert($contractRows);

    $contractIds = Contract::query()->where('construction_id', $construction->id)->orderBy('id')->pluck('id')->all();

    $installmentRows = [];
    foreach ($contractIds as $contractId) {
        foreach (range(1, 12) as $number) {
            $installmentRows[] = [
                'contract_id' => $contractId,
                'number' => str_pad((string) $number, 3, '0', STR_PAD_LEFT),
                'number_normalized' => str_pad((string) $number, 3, '0', STR_PAD_LEFT),
                'due_date' => '2026-'.str_pad((string) $number, 2, '0', STR_PAD_LEFT).'-10',
                'expected_value' => '50000.00',
                'payment_date' => $number <= 6 ? '2026-0'.$number.'-10' : null,
                'paid_value' => $number <= 6 ? '50000.00' : null,
                'cancellation_date' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
    }

    foreach (array_chunk($installmentRows, 500) as $chunk) {
        ContractInstallment::query()->insert($chunk);
    }

    foreach (array_slice($unitIds, -5) as $unitId) {
        ConstructionUnitExchange::factory()->create([
            'construction_unit_id' => $unitId,
            'effective_from' => '2026-01-01',
        ]);
    }

    return [$construction, count($contractIds), count($installmentRows)];
}

it('derives a development without a query per unit, contract, installment or sale', function () {
    [$construction, $contracts, $installments] = derivationDataset(200);

    expect($contracts)->toBe(150)
        ->and($installments)->toBe(1_800);

    $construction = $construction->fresh();

    DB::enableQueryLog();
    DB::flushQueryLog();

    $position = app(SalesBoardDerivationService::class)
        ->deriveForConstruction($construction, CarbonImmutable::parse('2026-07-01'));

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($position->unitsTotal)->toBe(200)
        ->and($position->bucketsBalance())->toBeTrue()
        // Six loads: units, contracts, exchanges, installments, unit values, policies.
        ->and($queries)->toHaveCount(6);
});

it('keeps the same query count as the development grows', function () {
    [$small] = derivationDataset(50);
    [$large] = derivationDataset(500);

    $service = app(SalesBoardDerivationService::class);
    $month = CarbonImmutable::parse('2026-07-01');

    $count = function ($construction) use ($service, $month): int {
        DB::enableQueryLog();
        DB::flushQueryLog();

        $service->deriveForConstruction($construction->fresh(), $month);

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    // Constant in the number of units, contracts and installments -- the shape
    // of the derivation, not its size, decides how many loads it takes.
    expect($count($small))->toBe($count($large));
});

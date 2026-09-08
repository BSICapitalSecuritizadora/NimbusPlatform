<?php

use App\DTOs\SalesBoards\SalesBoardBaselineDiff;
use App\DTOs\SalesBoards\SalesBoardComparableSnapshot;
use App\Enums\ContractStatus;
use App\Enums\SalesBoardDiffCode;
use App\Enums\SalesPriceConformityStatus;
use App\Models\ConstructionUnitValue;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleBaseline;
use App\Models\SalesDiscountPolicy;
use App\Services\SalesBoards\SalesBoardBaselineDiffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SalesBoards\CycleFixture;
use Tests\Support\SalesBoards\DerivationFixture;

uses(RefreshDatabase::class);

function diffBetweenVersions(SalesBoardCycleBaseline $before, SalesBoardCycleBaseline $after): SalesBoardBaselineDiff
{
    return app(SalesBoardBaselineDiffService::class)->compare(
        SalesBoardComparableSnapshot::fromBaseline($before->fresh()),
        SalesBoardComparableSnapshot::fromBaseline($after->fresh()),
    );
}

it('names the unit and both contracts when a unit changes hands', function () {
    [$construction, $units] = CycleFixture::readyConstruction(2);

    $first = DerivationFixture::contract($units[0], '2026-05-01', '600000.00');
    DerivationFixture::installment($first, '001', '2026-08-10', '600000.00');

    CycleFixture::generate($construction);
    $cycle = SalesBoardCycle::sole();
    $v1 = CycleFixture::currentBaseline($cycle);

    // A unidade é revendida dentro da competência: o contrato antigo é
    // distratado e outro assume no mesmo mês.
    $first->update(['cancellation_date' => '2026-07-10', 'status' => ContractStatus::Cancelled]);
    $second = DerivationFixture::contract($units[0], '2026-07-10', '640000.00');
    DerivationFixture::installment($second, '001', '2026-09-10', '640000.00');

    $result = CycleFixture::recalculate($cycle, 'Revenda registrada na competência.');
    $diff = diffBetweenVersions($v1, $result->baseline);

    $line = collect($diff->lines)->firstWhere('constructionUnitId', $units[0]->id);
    $codes = collect($line->changes)->pluck('code')->all();

    expect($codes)->toContain(SalesBoardDiffCode::ContractChanged)
        ->and($line->changes[0]->before)->toBe($first->code)
        ->and($line->changes[0]->after)->toBe($second->code)
        // O contrato antigo foi vendido em maio, então na competência de julho
        // ele aparece só pelo distrato; o novo, só pela venda.
        ->and(collect($diff->movements)->map(fn ($movement): string => $movement->type->value.'@'.$movement->contractId)->all())
        ->toEqualCanonicalizing(['distrato@'.$first->id, 'venda@'.$second->id]);
});

it('names the sale whose conformity changed after a retroactive policy', function () {
    [$construction, $units] = CycleFixture::readyConstruction(1);

    $contract = DerivationFixture::contract($units[0], '2026-07-05', '460000.00');
    DerivationFixture::installment($contract, '001', '2026-08-10', '460000.00');

    CycleFixture::generate($construction);
    $cycle = SalesBoardCycle::sole();
    $v1 = CycleFixture::currentBaseline($cycle);

    expect($v1->movements->first()->conformity_status)->toBe(SalesPriceConformityStatus::Conform);

    // Política mais restritiva com vigência anterior à venda: o mínimo sobe de
    // 450.000 para 475.000 e a mesma venda passa a estar fora da política.
    SalesDiscountPolicy::factory()
        ->forConstruction($construction)
        ->effectiveFrom('2026-07-01')
        ->allowing('5.00')
        ->create();

    $result = CycleFixture::recalculate($cycle, 'Política comercial retroativa registrada.');
    $diff = diffBetweenVersions($v1, $result->baseline);

    $movement = collect($diff->movements)->firstWhere('contractId', $contract->id);

    expect($movement)->not->toBeNull()
        ->and($movement->changes[0]->code)->toBe(SalesBoardDiffCode::MovementChanged)
        ->and($movement->changes[0]->before)->toContain('Conforme')
        ->and($movement->changes[0]->after)->toContain('Não conforme')
        ->and($result->baseline->movements->first()->conformity_status)->toBe(SalesPriceConformityStatus::NonConform)
        ->and($v1->fresh()->movements->first()->conformity_status)->toBe(SalesPriceConformityStatus::Conform);
});

it('names the unit whose reference value changed, with both amounts', function () {
    [$construction, $units] = CycleFixture::readyConstruction(2);

    CycleFixture::generate($construction);
    $cycle = SalesBoardCycle::sole();
    $v1 = CycleFixture::currentBaseline($cycle);

    ConstructionUnitValue::factory()->create([
        'construction_unit_id' => $units[1]->id,
        'value' => '575000.00',
        'effective_from' => '2026-07-15',
    ]);

    $result = CycleFixture::recalculate($cycle, 'Reajuste de tabela lançado.');
    $diff = diffBetweenVersions($v1, $result->baseline);

    $line = collect($diff->lines)->firstWhere('constructionUnitId', $units[1]->id);

    expect($line->changes[0]->code)->toBe(SalesBoardDiffCode::UnitReferenceValueChanged)
        ->and($line->changes[0]->before)->toBe('R$ 500.000,00')
        ->and($line->changes[0]->after)->toBe('R$ 575.000,00')
        ->and(collect($diff->changedBuckets())->pluck('bucket')->all())->toBe(['stock'])
        ->and(collect($diff->buckets)->firstWhere('bucket', 'stock')->valueCentsDelta())->toBe(7_500_000);
});

it('says two identical versions are identical', function () {
    [$construction] = CycleFixture::readyConstruction(2);
    CycleFixture::generate($construction);

    $baseline = CycleFixture::currentBaseline(SalesBoardCycle::sole());

    expect(diffBetweenVersions($baseline, $baseline)->isEmpty())->toBeTrue();
});

it('summarises the diff for a screen without losing the numbers', function () {
    [$construction, $units] = CycleFixture::readyConstruction(2);

    CycleFixture::generate($construction);
    $cycle = SalesBoardCycle::sole();
    $v1 = CycleFixture::currentBaseline($cycle);

    $contract = DerivationFixture::contract($units[0], '2026-07-05', '600000.00');
    DerivationFixture::installment($contract, '001', '2026-08-10', '600000.00');

    $result = CycleFixture::recalculate($cycle, 'Venda lançada com atraso.');
    $summary = diffBetweenVersions($v1, $result->baseline)->summary();

    expect($summary)->toContain('1 unidade(s) com alteração')
        ->and($summary)->toContain('1 movimento(s) com alteração')
        ->and($summary)->toContain('Estoque -1 un.')
        ->and($summary)->toContain('Financiado +1 un.');
});

it('describes the diff line by line, naming the unit and the contract', function () {
    [$construction, $units] = CycleFixture::readyConstruction(2);

    $contract = DerivationFixture::contract($units[0], '2026-07-05', '600000.00');
    DerivationFixture::installment($contract, '001', '2026-08-10', '600000.00');

    CycleFixture::generate($construction);
    $cycle = SalesBoardCycle::sole();
    $v1 = CycleFixture::currentBaseline($cycle);

    $contract->update(['sale_value' => '610000.00']);
    $v2 = CycleFixture::recalculate($cycle, 'Correção do valor de venda.')->baseline;

    $described = diffBetweenVersions($v1, $v2)->describe();

    expect($described)->toContain('Unidade 01 / 101')
        ->and($described)->toContain('Valor da venda alterado: R$ 600.000,00 → R$ 610.000,00')
        ->and($described)->toContain('Venda do contrato '.$contract->code);
});

it('says there is nothing to describe when the versions match', function () {
    [$construction] = CycleFixture::readyConstruction(1);
    CycleFixture::generate($construction);

    $baseline = CycleFixture::currentBaseline(SalesBoardCycle::sole());

    expect(diffBetweenVersions($baseline, $baseline)->describe())->toBe('Nenhuma diferença.');
});

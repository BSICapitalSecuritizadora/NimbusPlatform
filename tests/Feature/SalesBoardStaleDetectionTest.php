<?php

use App\Enums\ContractStatus;
use App\Enums\SalesBoardDiffCode;
use App\Enums\SalesBoardStaleImpact;
use App\Models\Client;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\ConstructionUnitExchange;
use App\Models\ConstructionUnitValue;
use App\Models\Contract;
use App\Models\ContractInstallment;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleBaseline;
use App\Models\SalesDiscountPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SalesBoards\CycleFixture;
use Tests\Support\SalesBoards\DerivationFixture;

uses(RefreshDatabase::class);

/**
 * Um ciclo gerado com estoque, uma venda da competência e um contrato antigo.
 *
 * @return array{cycle: SalesBoardCycle, construction: Construction, units: list<ConstructionUnit>, sold: Contract, older: Contract}
 */
function generatedCycle(): array
{
    [$construction, $units] = CycleFixture::readyConstruction(3);

    $sold = DerivationFixture::contract($units[0], '2026-07-05', '600000.00');
    DerivationFixture::installment($sold, '001', '2026-08-10', '600000.00');

    $older = DerivationFixture::contract($units[1], '2026-02-10', '550000.00');
    DerivationFixture::installment($older, '001', '2026-03-10', '300000.00', '2026-03-09', '300000.00');
    DerivationFixture::installment($older, '002', '2026-09-10', '250000.00');

    CycleFixture::generate($construction);

    return [
        'cycle' => SalesBoardCycle::sole(),
        'construction' => $construction,
        'units' => $units,
        'sold' => $sold,
        'older' => $older,
    ];
}

it('reports no change when nothing moved', function () {
    $scenario = generatedCycle();

    $assessment = CycleFixture::check($scenario['cycle']);

    expect($assessment->impact)->toBe(SalesBoardStaleImpact::None)
        ->and($assessment->isStale())->toBeFalse()
        ->and($assessment->sourceChanged)->toBeFalse()
        ->and($assessment->snapshotChanged)->toBeFalse()
        ->and($assessment->diff->isEmpty())->toBeTrue()
        ->and($assessment->baseline->fresh()->is_stale)->toBeFalse()
        ->and($assessment->baseline->fresh()->stale_detected_at)->toBeNull()
        ->and($assessment->baseline->fresh()->last_checked_at)->not->toBeNull();
});

it('flags a material change when a sale value changes', function () {
    $scenario = generatedCycle();
    $scenario['sold']->update(['sale_value' => '610000.00']);

    $assessment = CycleFixture::check($scenario['cycle']);

    expect($assessment->impact)->toBe(SalesBoardStaleImpact::Material)
        ->and($assessment->sourceChanged)->toBeTrue()
        ->and($assessment->snapshotChanged)->toBeTrue()
        ->and($assessment->baseline->fresh()->is_stale)->toBeTrue()
        ->and($assessment->baseline->fresh()->stale_detected_at)->not->toBeNull()
        ->and(collect($assessment->diff->lines)->pluck('constructionUnitId')->all())
        ->toContain($scenario['units'][0]->id);
});

it('flags a material change when a payment settles a contract inside the month', function () {
    $scenario = generatedCycle();

    ContractInstallment::query()
        ->where('contract_id', $scenario['older']->id)
        ->where('number', '002')
        ->firstOrFail()
        ->update(['payment_date' => '2026-07-15', 'paid_value' => '250000.00']);

    $assessment = CycleFixture::check($scenario['cycle']);

    expect($assessment->impact)->toBe(SalesBoardStaleImpact::Material)
        ->and(collect($assessment->diff->movements)->pluck('contractId')->all())->toContain($scenario['older']->id)
        ->and(collect($assessment->diff->changedBuckets())->pluck('bucket')->all())
        ->toEqualCanonicalizing(['financed', 'settled']);
});

it('flags a material change when a cancellation date lands in the competência', function () {
    $scenario = generatedCycle();
    $scenario['sold']->update(['cancellation_date' => '2026-07-20', 'status' => ContractStatus::Cancelled]);

    $assessment = CycleFixture::check($scenario['cycle']);

    expect($assessment->impact)->toBe(SalesBoardStaleImpact::Material)
        ->and(collect($assessment->diff->movements)->pluck('type.value')->all())->toContain('distrato');
});

it('flags a material change when the value of a stock unit changes', function () {
    $scenario = generatedCycle();

    ConstructionUnitValue::factory()->create([
        'construction_unit_id' => $scenario['units'][2]->id,
        'value' => '620000.00',
        'effective_from' => '2026-07-01',
    ]);

    $assessment = CycleFixture::check($scenario['cycle']);

    expect($assessment->impact)->toBe(SalesBoardStaleImpact::Material)
        ->and(collect($assessment->diff->lines)->firstWhere('constructionUnitId', $scenario['units'][2]->id))
        ->not->toBeNull();
});

it('flags a material change when a policy that reaches the sale lands', function () {
    $scenario = generatedCycle();

    SalesDiscountPolicy::factory()
        ->forConstruction($scenario['construction'])
        ->effectiveFrom('2026-07-01')
        ->allowing('0.00')
        ->create();

    $assessment = CycleFixture::check($scenario['cycle']);

    expect($assessment->impact)->toBe(SalesBoardStaleImpact::Material)
        ->and(collect($assessment->diff->movements)->pluck('contractId')->all())->toContain($scenario['sold']->id);
});

it('flags a material change when an exchange becomes effective on the position date', function () {
    $scenario = generatedCycle();

    ConstructionUnitExchange::factory()->create([
        'construction_unit_id' => $scenario['units'][2]->id,
        'exchange_value' => '480000.00',
        'effective_from' => '2026-06-01',
    ]);

    $assessment = CycleFixture::check($scenario['cycle']);

    expect($assessment->impact)->toBe(SalesBoardStaleImpact::Material)
        ->and(collect($assessment->diff->lines)->firstWhere('constructionUnitId', $scenario['units'][2]->id)->changes)
        ->toHaveCount(2);
});

it('flags a material change when a unit is added to the development', function () {
    $scenario = generatedCycle();

    $added = DerivationFixture::unit($scenario['construction'], '999');

    $assessment = CycleFixture::check($scenario['cycle']);

    expect($assessment->impact)->toBe(SalesBoardStaleImpact::Material)
        ->and($assessment->diff->unitsTotalDelta())->toBe(1);

    $diff = collect($assessment->diff->lines)->firstWhere('constructionUnitId', $added->id);

    expect($diff->changes[0]->code)->toBe(SalesBoardDiffCode::UnitAdded);
});

it('does not flag anything when only client contact data changes', function () {
    $scenario = generatedCycle();

    $client = Client::factory()->create();
    $scenario['sold']->clients()->attach($client->id);
    $client->update(['phone' => '(11) 98888-1111', 'email' => 'novo@exemplo.com']);

    $assessment = CycleFixture::check($scenario['cycle']);

    expect($assessment->impact)->toBe(SalesBoardStaleImpact::None)
        ->and($assessment->baseline->fresh()->is_stale)->toBeFalse();
});

it('does not flag anything when only an installment due date is rescheduled', function () {
    $scenario = generatedCycle();

    ContractInstallment::query()
        ->where('contract_id', $scenario['older']->id)
        ->where('number', '002')
        ->firstOrFail()
        ->update(['due_date' => '2027-01-10']);

    expect(CycleFixture::check($scenario['cycle'])->impact)->toBe(SalesBoardStaleImpact::None);
});

/**
 * Fonte material alterada, posição derivada idêntica.
 *
 * O contrato foi distratado dentro da competência, então a unidade fecha o mês
 * em estoque e o snapshot não guarda nada do cronograma dele. Corrigir o valor
 * esperado de uma parcela desse contrato é mexer num fato material -- ele entra
 * na decisão de quitação -- que nesta competência não move número nenhum.
 */
it('classifies a source change with no effect on the position as source only', function () {
    [$construction, $units] = CycleFixture::readyConstruction(2);

    $cancelled = DerivationFixture::contract(
        $units[0],
        '2026-03-01',
        '500000.00',
        cancellationDate: '2026-07-10',
        status: ContractStatus::Cancelled,
    );
    $installment = DerivationFixture::installment($cancelled, '001', '2026-04-10', '500000.00');

    CycleFixture::generate($construction);
    $cycle = SalesBoardCycle::sole();
    $baseline = CycleFixture::currentBaseline($cycle);

    $installment->update(['expected_value' => '499000.00']);

    $assessment = CycleFixture::check($cycle);

    expect($assessment->impact)->toBe(SalesBoardStaleImpact::SourceOnly)
        ->and($assessment->sourceChanged)->toBeTrue()
        ->and($assessment->snapshotChanged)->toBeFalse()
        ->and($assessment->baseline->fresh()->is_stale)->toBeTrue()
        ->and($assessment->baseline->fresh()->snapshot_fingerprint)->toBe($baseline->snapshot_fingerprint)
        ->and(collect($assessment->diff->lines)->firstWhere('constructionUnitId', $units[0]->id)->changes[0]->code)
        ->toBe(SalesBoardDiffCode::LineSourceOnlyChanged);
});

it('classifies a source that stopped passing readiness as blocking, keeping the version', function () {
    $scenario = generatedCycle();

    // O contrato passa a ter cronograma indecidível: a unidade fica indeterminada.
    ContractInstallment::query()->where('contract_id', $scenario['older']->id)->delete();

    $assessment = CycleFixture::check($scenario['cycle']);

    expect($assessment->impact)->toBe(SalesBoardStaleImpact::Blocking)
        ->and($assessment->readiness->isReady())->toBeFalse()
        ->and($assessment->baseline->fresh()->is_stale)->toBeTrue()
        ->and($assessment->baseline->fresh()->last_observed_snapshot_fingerprint)->toBeNull()
        ->and(SalesBoardCycleBaseline::query()->count())->toBe(1)
        ->and($scenario['cycle']->fresh()->current_baseline_id)->toBe($assessment->baseline->id);
});

it('clears the stale flag when the source returns to what it was, keeping the record that it diverged', function () {
    $scenario = generatedCycle();

    $scenario['sold']->update(['sale_value' => '610000.00']);
    $first = CycleFixture::check($scenario['cycle']);

    expect($first->isStale())->toBeTrue();

    $detectedAt = $first->baseline->fresh()->stale_detected_at;

    $scenario['sold']->update(['sale_value' => '600000.00']);
    $second = CycleFixture::check($scenario['cycle']);

    expect($second->impact)->toBe(SalesBoardStaleImpact::None)
        ->and($second->baseline->fresh()->is_stale)->toBeFalse()
        ->and($second->baseline->fresh()->stale_detected_at?->toDateTimeString())->toBe($detectedAt?->toDateTimeString());
});

it('never recalculates while checking', function () {
    $scenario = generatedCycle();
    $scenario['sold']->update(['sale_value' => '610000.00']);

    CycleFixture::check($scenario['cycle']);

    expect(SalesBoardCycleBaseline::query()->count())->toBe(1)
        ->and(CycleFixture::currentBaseline($scenario['cycle'])->version)->toBe(1);
});

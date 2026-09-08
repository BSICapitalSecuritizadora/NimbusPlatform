<?php

use App\DTOs\SalesBoards\SalesBoardComparableSnapshot;
use App\Enums\ContractStatus;
use App\Enums\SalesBoardDiffCode;
use App\Enums\SalesBoardRecalculationOutcome;
use App\Enums\SalesBoardStaleImpact;
use App\Enums\SalesBoardUnitClassification;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\Contract;
use App\Models\ContractInstallment;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleBaseline;
use App\Models\SalesBoardCycleLine;
use App\Models\User;
use App\Services\SalesBoards\SalesBoardBaselineDiffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SalesBoards\CycleFixture;
use Tests\Support\SalesBoards\DerivationFixture;

uses(RefreshDatabase::class);

/**
 * @return array{cycle: SalesBoardCycle, construction: Construction, units: list<ConstructionUnit>, sold: Contract}
 */
function recalculableCycle(): array
{
    [$construction, $units] = CycleFixture::readyConstruction(3);

    $sold = DerivationFixture::contract($units[0], '2026-07-05', '600000.00');
    DerivationFixture::installment($sold, '001', '2026-08-10', '600000.00');

    CycleFixture::generate($construction);

    return ['cycle' => SalesBoardCycle::sole(), 'construction' => $construction, 'units' => $units, 'sold' => $sold];
}

it('creates a second version and leaves the first untouched', function () {
    $scenario = recalculableCycle();
    $first = CycleFixture::currentBaseline($scenario['cycle']);
    $actor = User::factory()->create();

    $scenario['sold']->update(['sale_value' => '610000.00']);

    $result = CycleFixture::recalculate($scenario['cycle'], 'Correção do valor de venda informada pela construtora.', $actor);

    expect($result->outcome)->toBe(SalesBoardRecalculationOutcome::Recalculated)
        ->and($result->baseline?->version)->toBe(2)
        ->and($result->previousBaseline->id)->toBe($first->id)
        ->and($result->baseline?->reason)->toBe('Correção do valor de venda informada pela construtora.')
        ->and($result->baseline?->computed_by_id)->toBe($actor->id)
        ->and($scenario['cycle']->fresh()->current_baseline_id)->toBe($result->baseline?->id)
        ->and(SalesBoardCycleBaseline::query()->count())->toBe(2);

    $frozenLine = $first->fresh()->lines->firstWhere('contract_id', $scenario['sold']->id);
    $newLine = $result->baseline->fresh()->lines->firstWhere('contract_id', $scenario['sold']->id);

    expect($frozenLine->contract_sale_value)->toBe('600000.00')
        ->and($newLine->contract_sale_value)->toBe('610000.00')
        ->and($first->fresh()->financed_value)->toBe('600000.00');
});

it('keeps every version queryable, each with its own lines', function () {
    $scenario = recalculableCycle();
    $scenario['sold']->update(['sale_value' => '610000.00']);
    CycleFixture::recalculate($scenario['cycle']);

    expect(SalesBoardCycleLine::query()->count())->toBe(6)
        ->and(SalesBoardCycleBaseline::query()->orderBy('version')->pluck('version')->all())->toBe([1, 2]);
});

it('refuses to recalculate without a reason', function () {
    $scenario = recalculableCycle();

    CycleFixture::recalculate($scenario['cycle'], '   ');
})->throws(InvalidArgumentException::class, 'O recálculo exige um motivo.');

it('does not create a useless version when nothing changed', function () {
    $scenario = recalculableCycle();

    $result = CycleFixture::recalculate($scenario['cycle']);

    expect($result->outcome)->toBe(SalesBoardRecalculationOutcome::Unchanged)
        ->and($result->baseline)->toBeNull()
        ->and(SalesBoardCycleBaseline::query()->count())->toBe(1)
        ->and(CycleFixture::currentBaseline($scenario['cycle'])->version)->toBe(1);
});

it('creates a version when only the material source changed', function () {
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
    $first = CycleFixture::currentBaseline($cycle);

    $installment->update(['expected_value' => '499000.00']);

    $result = CycleFixture::recalculate($cycle, 'Correção do cronograma do contrato distratado.');

    expect($result->outcome)->toBe(SalesBoardRecalculationOutcome::Recalculated)
        ->and($result->baseline?->version)->toBe(2)
        ->and($result->baseline?->snapshot_fingerprint)->toBe($first->snapshot_fingerprint)
        ->and($result->baseline?->source_fingerprint)->not->toBe($first->source_fingerprint)
        ->and($result->diff?->hasMaterialChange())->toBeFalse();
});

it('refuses to recalculate when the current source is not ready', function () {
    $scenario = recalculableCycle();
    $first = CycleFixture::currentBaseline($scenario['cycle']);

    ContractInstallment::query()->where('contract_id', $scenario['sold']->id)->delete();

    $result = CycleFixture::recalculate($scenario['cycle'], 'Tentativa após remoção do cronograma.');

    expect($result->outcome)->toBe(SalesBoardRecalculationOutcome::Blocked)
        ->and($result->blockedReason)->toContain('SETTLEMENT_UNDETERMINED')
        ->and($result->baseline)->toBeNull()
        ->and(SalesBoardCycleBaseline::query()->count())->toBe(1)
        ->and($scenario['cycle']->fresh()->current_baseline_id)->toBe($first->id);
});

it('increments the version deterministically across recalculations', function () {
    $scenario = recalculableCycle();

    $scenario['sold']->update(['sale_value' => '610000.00']);
    CycleFixture::recalculate($scenario['cycle'], 'Primeira correção.');

    $scenario['sold']->fresh()->update(['sale_value' => '620000.00']);
    CycleFixture::recalculate($scenario['cycle'], 'Segunda correção.');

    expect(SalesBoardCycleBaseline::query()->orderBy('version')->pluck('version')->all())->toBe([1, 2, 3])
        ->and(CycleFixture::currentBaseline($scenario['cycle'])->version)->toBe(3);
});

it('refuses to overwrite a pointer that moved to a newer version', function () {
    $scenario = recalculableCycle();
    $stale = CycleFixture::currentBaseline($scenario['cycle']);

    $scenario['sold']->update(['sale_value' => '610000.00']);
    CycleFixture::recalculate($scenario['cycle'], 'Recálculo de outra pessoa.');

    $scenario['sold']->fresh()->update(['sale_value' => '620000.00']);

    $result = CycleFixture::recalculate(
        $scenario['cycle'],
        'Recálculo com a tela desatualizada.',
        expectedBaselineId: $stale->id,
    );

    expect($result->outcome)->toBe(SalesBoardRecalculationOutcome::Blocked)
        ->and($result->blockedReason)->toContain('A versão vigente mudou')
        ->and(SalesBoardCycleBaseline::query()->count())->toBe(2);
});

it('clears the stale flag of the version it replaces by pointing at a fresh one', function () {
    $scenario = recalculableCycle();

    $scenario['sold']->update(['sale_value' => '610000.00']);
    CycleFixture::check($scenario['cycle']);

    expect(CycleFixture::currentBaseline($scenario['cycle'])->is_stale)->toBeTrue();

    $result = CycleFixture::recalculate($scenario['cycle'], 'Recálculo após conferência.');

    expect($result->baseline?->fresh()->is_stale)->toBeFalse()
        ->and($result->baseline?->fresh()->stale_impact)->toBe(SalesBoardStaleImpact::None)
        ->and($result->previousBaseline->fresh()->is_stale)->toBeTrue();
});

it('locates the change down to the unit and the contract', function () {
    $scenario = recalculableCycle();
    $first = CycleFixture::currentBaseline($scenario['cycle']);

    $scenario['sold']->update(['sale_value' => '610000.00']);
    $result = CycleFixture::recalculate($scenario['cycle'], 'Correção.');

    $diff = app(SalesBoardBaselineDiffService::class)->compare(
        SalesBoardComparableSnapshot::fromBaseline($first->fresh()),
        SalesBoardComparableSnapshot::fromBaseline($result->baseline->fresh()),
    );

    $line = collect($diff->lines)->firstWhere('constructionUnitId', $scenario['units'][0]->id);
    $movement = collect($diff->movements)->firstWhere('contractId', $scenario['sold']->id);

    expect($line)->not->toBeNull()
        ->and(collect($line->changes)->pluck('code')->all())->toContain(SalesBoardDiffCode::ContractSaleValueChanged)
        ->and($line->changes[0]->before)->toBe('R$ 600.000,00')
        ->and($line->changes[0]->after)->toBe('R$ 610.000,00')
        ->and($movement)->not->toBeNull()
        ->and($movement->changes[0]->code)->toBe(SalesBoardDiffCode::MovementChanged);
});

it('reports a classification change with both sides named', function () {
    $scenario = recalculableCycle();
    $first = CycleFixture::currentBaseline($scenario['cycle']);

    ContractInstallment::query()
        ->where('contract_id', $scenario['sold']->id)
        ->firstOrFail()
        ->update(['payment_date' => '2026-07-25', 'paid_value' => '600000.00']);

    $result = CycleFixture::recalculate($scenario['cycle'], 'Quitação registrada.');

    $diff = app(SalesBoardBaselineDiffService::class)->compare(
        SalesBoardComparableSnapshot::fromBaseline($first->fresh()),
        SalesBoardComparableSnapshot::fromBaseline($result->baseline->fresh()),
    );

    $line = collect($diff->lines)->firstWhere('constructionUnitId', $scenario['units'][0]->id);

    expect(collect($line->changes)->pluck('code')->all())->toContain(SalesBoardDiffCode::ClassificationChanged)
        ->and($line->changes[0]->before)->toBe(SalesBoardUnitClassification::Financed->label())
        ->and($line->changes[0]->after)->toBe(SalesBoardUnitClassification::Settled->label())
        ->and(collect($diff->movements)->pluck('changes.0.code')->all())->toContain(SalesBoardDiffCode::MovementAdded);
});

it('reports a movement that disappeared from the competência', function () {
    $scenario = recalculableCycle();
    $first = CycleFixture::currentBaseline($scenario['cycle']);

    $scenario['sold']->update(['sale_date' => '2026-06-05']);
    $result = CycleFixture::recalculate($scenario['cycle'], 'Data da venda corrigida para junho.');

    $diff = app(SalesBoardBaselineDiffService::class)->compare(
        SalesBoardComparableSnapshot::fromBaseline($first->fresh()),
        SalesBoardComparableSnapshot::fromBaseline($result->baseline->fresh()),
    );

    expect(collect($diff->movements)->pluck('changes.0.code')->all())->toContain(SalesBoardDiffCode::MovementRemoved)
        ->and(collect($diff->lines)->firstWhere('constructionUnitId', $scenario['units'][0]->id)->changes)
        ->not->toBeEmpty();
});

it('reports the delta of every bucket', function () {
    $scenario = recalculableCycle();

    ContractInstallment::query()
        ->where('contract_id', $scenario['sold']->id)
        ->firstOrFail()
        ->update(['payment_date' => '2026-07-25', 'paid_value' => '600000.00']);

    $assessment = CycleFixture::check($scenario['cycle']);
    $buckets = collect($assessment->diff->buckets)->keyBy('bucket');

    expect($buckets['financed']->unitsDelta())->toBe(-1)
        ->and($buckets['financed']->valueCentsDelta())->toBe(-60_000_000)
        ->and($buckets['settled']->unitsDelta())->toBe(1)
        ->and($buckets['settled']->valueCentsDelta())->toBe(60_000_000)
        ->and($buckets['stock']->hasChange())->toBeFalse()
        ->and($buckets['exchanged']->hasChange())->toBeFalse()
        ->and($assessment->diff->undeterminedDelta())->toBe(0);
});

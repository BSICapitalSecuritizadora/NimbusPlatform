<?php

use App\DTOs\SalesBoards\SalesBoardSnapshot;
use App\DTOs\SalesBoards\SalesBoardSnapshotMovement;
use App\Enums\ContractStatus;
use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardGenerationOutcome;
use App\Enums\SalesBoardMovementType;
use App\Enums\SalesBoardStaleImpact;
use App\Enums\SalesBoardUnitClassification;
use App\Enums\SalesPriceConformityStatus;
use App\Models\ConstructionUnitValue;
use App\Models\Emission;
use App\Models\SalesBoard;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleBaseline;
use App\Models\SalesBoardCycleLine;
use App\Models\SalesBoardCycleMovement;
use App\Models\SalesBoardHistory;
use App\Models\User;
use App\Support\Money\IntegerMoney;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SalesBoards\CycleFixture;
use Tests\Support\SalesBoards\DerivationFixture;

uses(RefreshDatabase::class);

it('freezes the derived position into a first baseline', function () {
    [$construction, $units] = CycleFixture::readyConstruction(3);
    $contract = DerivationFixture::contract($units[0], '2026-07-05');
    DerivationFixture::installment($contract, '001', '2026-08-10', '600000.00');

    $result = CycleFixture::generate($construction);

    expect($result->outcome)->toBe(SalesBoardGenerationOutcome::Generated);

    $cycle = SalesBoardCycle::sole();
    $baseline = CycleFixture::currentBaseline($cycle);

    expect($cycle->construction_id)->toBe($construction->id)
        ->and($cycle->emission_id)->toBe($construction->emission_id)
        ->and($cycle->reference_month->toDateString())->toBe('2026-07-01')
        ->and($cycle->position_date->toDateString())->toBe('2026-07-31')
        ->and($cycle->status)->toBe(SalesBoardCycleStatus::Generated)
        ->and($cycle->current_baseline_id)->toBe($baseline->id)
        ->and($baseline->version)->toBe(1)
        ->and($baseline->reason)->toBeNull()
        ->and($baseline->is_stale)->toBeFalse()
        ->and($baseline->source_fingerprint)->toHaveLength(64)
        ->and($baseline->snapshot_fingerprint)->toHaveLength(64)
        ->and($baseline->source_fingerprint)->not->toBe($baseline->snapshot_fingerprint);
});

it('persists the totals exactly as the derivation apurou them', function () {
    [$construction, $units] = CycleFixture::readyConstruction(3);
    $contract = DerivationFixture::contract($units[0], '2026-02-10', '600000.00');
    DerivationFixture::installment($contract, '001', '2026-03-10', '600000.00');

    $position = DerivationFixture::derive($construction);
    CycleFixture::generate($construction);

    $baseline = CycleFixture::currentBaseline(SalesBoardCycle::sole());

    expect($baseline->units_total)->toBe($position->unitsTotal)
        ->and($baseline->stock_units)->toBe($position->stockUnits)
        ->and($baseline->financed_units)->toBe($position->financedUnits)
        ->and($baseline->settled_units)->toBe($position->settledUnits)
        ->and($baseline->exchanged_units)->toBe($position->exchangedUnits)
        ->and($baseline->undetermined_units)->toBe($position->undeterminedUnits)
        ->and(IntegerMoney::cents($baseline->stock_value))->toBe($position->stockValueCents)
        ->and(IntegerMoney::cents($baseline->financed_value))->toBe($position->financedValueCents)
        ->and(IntegerMoney::cents($baseline->settled_value))->toBe($position->settledValueCents)
        ->and(IntegerMoney::cents($baseline->exchanged_value))->toBe($position->exchangedValueCents)
        ->and($baseline->is_complete)->toBe($position->isComplete());
});

it('persists one line per construction unit', function () {
    [$construction, $units] = CycleFixture::readyConstruction(5);

    CycleFixture::generate($construction);

    $baseline = CycleFixture::currentBaseline(SalesBoardCycle::sole());

    expect(SalesBoardCycleLine::query()->where('sales_board_cycle_baseline_id', $baseline->id)->count())->toBe(5)
        ->and($baseline->lines->pluck('construction_unit_id')->sort()->values()->all())
        ->toBe(collect($units)->pluck('id')->sort()->values()->all());
});

it('refuses to persist a second line for the same unit in a baseline', function () {
    [$construction] = CycleFixture::readyConstruction(1);
    CycleFixture::generate($construction);

    $line = SalesBoardCycleLine::query()->firstOrFail();

    SalesBoardCycleLine::query()->insert([
        'sales_board_cycle_baseline_id' => $line->sales_board_cycle_baseline_id,
        'construction_unit_id' => $line->construction_unit_id,
        'classification' => SalesBoardUnitClassification::Financed->value,
        'source_fingerprint' => str_repeat('0', 64),
        'snapshot_fingerprint' => str_repeat('1', 64),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->throws(UniqueConstraintViolationException::class);

it('writes nothing when readiness is blocked', function () {
    $construction = CycleFixture::construction();
    // Unidade sem valor base: estoque sem valor de referência bloqueia.
    DerivationFixture::unit($construction, '101', baseValue: null);

    $result = CycleFixture::generate($construction);

    expect($result->outcome)->toBe(SalesBoardGenerationOutcome::Blocked)
        ->and($result->blockedReason)->toContain('UNIT_VALUE_MISSING')
        ->and($result->readiness?->isReady())->toBeFalse()
        ->and(SalesBoardCycle::query()->count())->toBe(0)
        ->and(SalesBoardCycleBaseline::query()->count())->toBe(0)
        ->and(SalesBoardCycleLine::query()->count())->toBe(0)
        ->and(SalesBoardCycleMovement::query()->count())->toBe(0);
});

it('is idempotent: generating twice leaves a single cycle and a single baseline', function () {
    [$construction] = CycleFixture::readyConstruction(2);

    $first = CycleFixture::generate($construction);
    $second = CycleFixture::generate($construction);

    expect($first->outcome)->toBe(SalesBoardGenerationOutcome::Generated)
        ->and($second->outcome)->toBe(SalesBoardGenerationOutcome::AlreadyExists)
        ->and($second->cycle?->id)->toBe($first->cycle?->id)
        ->and(SalesBoardCycle::query()->count())->toBe(1)
        ->and(SalesBoardCycleBaseline::query()->count())->toBe(1)
        ->and(SalesBoardCycleLine::query()->count())->toBe(2);
});

it('refuses to generate while the emission is still in draft', function () {
    [$construction] = CycleFixture::readyConstruction(2, Emission::STATUS_DRAFT);

    $result = CycleFixture::generate($construction);

    expect($result->outcome)->toBe(SalesBoardGenerationOutcome::Blocked)
        ->and($result->blockedReason)->toContain('Em Elaboração')
        ->and(SalesBoardCycle::query()->count())->toBe(0);
});

it('generates for closed and default emissions when asked explicitly', function (string $status) {
    [$construction] = CycleFixture::readyConstruction(1, $status);

    expect(CycleFixture::generate($construction)->outcome)->toBe(SalesBoardGenerationOutcome::Generated);
})->with(['closed', 'default', 'active']);

it('freezes sales, settlements and cancellations of the competência', function () {
    [$construction, $units] = CycleFixture::readyConstruction(3);

    $sold = DerivationFixture::contract($units[0], '2026-07-05', '600000.00');
    DerivationFixture::installment($sold, '001', '2026-08-10', '600000.00');

    $settling = DerivationFixture::contract($units[1], '2026-02-10', '550000.00');
    DerivationFixture::installment($settling, '001', '2026-03-10', '550000.00', '2026-07-20', '550000.00');

    $cancelled = DerivationFixture::contract($units[2], '2026-03-01', '500000.00', cancellationDate: '2026-07-10', status: ContractStatus::Cancelled);
    DerivationFixture::installment($cancelled, '001', '2026-04-10', '500000.00');

    CycleFixture::generate($construction);
    $baseline = CycleFixture::currentBaseline(SalesBoardCycle::sole());

    $movements = $baseline->movements->keyBy(fn ($movement): string => $movement->movement_type->value);

    expect($movements)->toHaveCount(3)
        ->and($movements[SalesBoardMovementType::Sale->value]->contract_id)->toBe($sold->id)
        ->and($movements[SalesBoardMovementType::Sale->value]->event_date->toDateString())->toBe('2026-07-05')
        ->and($movements[SalesBoardMovementType::Settlement->value]->contract_id)->toBe($settling->id)
        ->and($movements[SalesBoardMovementType::Settlement->value]->event_date)->toBeNull()
        ->and($movements[SalesBoardMovementType::Settlement->value]->settlement_installments_total)->toBe(1)
        ->and($movements[SalesBoardMovementType::Cancellation->value]->contract_id)->toBe($cancelled->id)
        ->and($movements[SalesBoardMovementType::Cancellation->value]->event_date->toDateString())->toBe('2026-07-10');
});

it('keeps a sale and its cancellation in the same month even though the unit ends in stock', function () {
    [$construction, $units] = CycleFixture::readyConstruction(1);

    $contract = DerivationFixture::contract($units[0], '2026-07-05', '600000.00', cancellationDate: '2026-07-20', status: ContractStatus::Cancelled);
    DerivationFixture::installment($contract, '001', '2026-08-10', '600000.00');

    CycleFixture::generate($construction);
    $baseline = CycleFixture::currentBaseline(SalesBoardCycle::sole());

    expect($baseline->stock_units)->toBe(1)
        ->and($baseline->lines->first()->classification)->toBe(SalesBoardUnitClassification::Stock)
        ->and($baseline->lines->first()->contract_id)->toBeNull()
        ->and($baseline->movements->pluck('movement_type')->all())
        ->toEqualCanonicalizing([SalesBoardMovementType::Sale, SalesBoardMovementType::Cancellation])
        ->and($baseline->movements->firstWhere('movement_type', SalesBoardMovementType::Sale)->contract_id)->toBe($contract->id)
        ->and($baseline->movements->firstWhere('movement_type', SalesBoardMovementType::Cancellation)->contract_id)->toBe($contract->id);
});

it('freezes the conformity verdict of a sale with the table and policy that produced it', function () {
    [$construction, $units] = CycleFixture::readyConstruction(1);

    ConstructionUnitValue::factory()->create([
        'construction_unit_id' => $units[0]->id,
        'value' => '500000.00',
        'effective_from' => '2026-06-01',
    ]);

    // 10% autorizados sobre 500.000 => mínimo 450.000. Vendida por 400.000.
    $contract = DerivationFixture::contract($units[0], '2026-07-05', '400000.00');
    DerivationFixture::installment($contract, '001', '2026-08-10', '400000.00');

    CycleFixture::generate($construction);

    $sale = SalesBoardCycleMovement::query()->where('movement_type', SalesBoardMovementType::Sale)->sole();

    expect($sale->conformity_status)->toBe(SalesPriceConformityStatus::NonConform)
        ->and($sale->unit_reference_value)->toBe('500000.00')
        ->and($sale->authorized_discount_basis_points)->toBe(1_000)
        ->and($sale->minimum_authorized_value)->toBe('450000.00')
        ->and($sale->effective_discount_basis_points)->toBe(2_000)
        ->and($sale->difference_value)->toBe('-50000.00')
        ->and($sale->sales_discount_policy_id)->not->toBeNull();
});

it('generates even when a sale is out of policy, because that is an apurado fact', function () {
    [$construction, $units] = CycleFixture::readyConstruction(1);
    $contract = DerivationFixture::contract($units[0], '2026-07-05', '100000.00');
    DerivationFixture::installment($contract, '001', '2026-08-10', '100000.00');

    $result = CycleFixture::generate($construction);

    expect($result->outcome)->toBe(SalesBoardGenerationOutcome::Generated)
        ->and($result->readiness?->warnings)->not->toBeEmpty();
});

it('never publishes to sales_boards nor to sales_board_histories', function () {
    [$construction, $units] = CycleFixture::readyConstruction(2);
    $contract = DerivationFixture::contract($units[0], '2026-07-05');
    DerivationFixture::installment($contract, '001', '2026-08-10', '600000.00');

    CycleFixture::generate($construction);

    expect(SalesBoard::query()->count())->toBe(0)
        ->and(SalesBoardHistory::query()->count())->toBe(0);
});

it('records the actor that generated the cycle', function () {
    [$construction] = CycleFixture::readyConstruction(1);
    $actor = User::factory()->create();

    CycleFixture::generate($construction, actor: $actor);

    $cycle = SalesBoardCycle::sole();

    expect($cycle->created_by_id)->toBe($actor->id)
        ->and(CycleFixture::currentBaseline($cycle)->computed_by_id)->toBe($actor->id);
});

it('does not write anything on a dry run', function () {
    [$construction] = CycleFixture::readyConstruction(2);

    $result = CycleFixture::generate($construction, dryRun: true);

    expect($result->outcome)->toBe(SalesBoardGenerationOutcome::Generated)
        ->and($result->dryRun)->toBeTrue()
        ->and($result->cycle)->toBeNull()
        ->and(SalesBoardCycle::query()->count())->toBe(0)
        ->and(SalesBoardCycleBaseline::query()->count())->toBe(0);
});

it('starts the baseline as not stale and already checked', function () {
    [$construction] = CycleFixture::readyConstruction(1);
    CycleFixture::generate($construction);

    $baseline = CycleFixture::currentBaseline(SalesBoardCycle::sole());

    expect($baseline->is_stale)->toBeFalse()
        ->and($baseline->stale_impact)->toBe(SalesBoardStaleImpact::None)
        ->and($baseline->stale_detected_at)->toBeNull()
        ->and($baseline->last_checked_at)->not->toBeNull()
        ->and($baseline->last_observed_source_fingerprint)->toBe($baseline->source_fingerprint)
        ->and($baseline->last_observed_snapshot_fingerprint)->toBe($baseline->snapshot_fingerprint);
});

it('persists movements that match the derived DTO one for one', function () {
    [$construction, $units] = CycleFixture::readyConstruction(3);

    $sold = DerivationFixture::contract($units[0], '2026-07-05', '600000.00');
    DerivationFixture::installment($sold, '001', '2026-08-10', '600000.00');

    $settling = DerivationFixture::contract($units[1], '2026-02-10', '550000.00');
    DerivationFixture::installment($settling, '001', '2026-03-10', '550000.00', '2026-07-20', '550000.00');

    $cancelled = DerivationFixture::contract(
        $units[2],
        '2026-03-01',
        '500000.00',
        cancellationDate: '2026-07-10',
        status: ContractStatus::Cancelled,
    );
    DerivationFixture::installment($cancelled, '001', '2026-04-10', '500000.00');

    $position = DerivationFixture::derive($construction);
    CycleFixture::generate($construction);

    $expected = SalesBoardSnapshot::fromDerivedPosition($position);
    $persisted = SalesBoardSnapshot::fromBaseline(
        CycleFixture::currentBaseline(SalesBoardCycle::sole()),
    );

    expect(array_map(
        fn (SalesBoardSnapshotMovement $movement): string => $movement->canonicalRow(),
        $persisted->movements,
    ))->toEqualCanonicalizing(array_map(
        fn (SalesBoardSnapshotMovement $movement): string => $movement->canonicalRow(),
        $expected->movements,
    ))
        ->and($persisted->fingerprint())->toBe($expected->fingerprint());
});

it('explains the whole competência without reading a single live source row', function () {
    [$construction, $units] = CycleFixture::readyConstruction(4);

    $financed = DerivationFixture::contract($units[0], '2026-02-10', '600000.00');
    DerivationFixture::installment($financed, '001', '2026-09-10', '600000.00');

    $settled = DerivationFixture::contract($units[1], '2026-01-10', '550000.00');
    DerivationFixture::installment($settled, '001', '2026-02-10', '550000.00', '2026-07-20', '550000.00');

    $soldInMonth = DerivationFixture::contract($units[2], '2026-07-05', '470000.00');
    DerivationFixture::installment($soldInMonth, '001', '2026-09-10', '470000.00');

    CycleFixture::generate($construction);

    $baseline = CycleFixture::currentBaseline(SalesBoardCycle::sole())
        ->load(['lines', 'movements', 'cycle']);

    DB::enableQueryLog();
    DB::flushQueryLog();

    $snapshot = SalesBoardSnapshot::fromBaseline($baseline);

    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    $financedLines = collect($snapshot->lines)
        ->filter(fn ($line): bool => $line->classification === SalesBoardUnitClassification::Financed);
    $sale = collect($snapshot->movements)
        ->firstWhere('type', SalesBoardMovementType::Sale);

    expect($queries)->toBeEmpty()
        // Quais eram as unidades financiadas, e por quais contratos e valores.
        // Duas: a de fevereiro e a vendida dentro da própria competência.
        ->and($financedLines)->toHaveCount(2)
        ->and($financedLines->pluck('contractCode')->all())
        ->toEqualCanonicalizing([$financed->code, $soldInMonth->code])
        ->and($financedLines->firstWhere('contractCode', $financed->code)->contractSaleValueCents)
        ->toBe(60_000_000)
        // Quais foram quitadas na competência, e com quantas parcelas.
        ->and(collect($snapshot->movements)->firstWhere('type', SalesBoardMovementType::Settlement)->contractId)
        ->toBe($settled->id)
        // Qual venda aconteceu, sob qual tabela e qual política.
        ->and($sale->contractId)->toBe($soldInMonth->id)
        ->and($sale->unitReferenceValueCents)->toBe(50_000_000)
        ->and($sale->salesDiscountPolicyId)->not->toBeNull()
        ->and($sale->authorizedDiscountBasisPoints)->toBe(1_000)
        // 10% autorizados sobre 500.000 => mínimo 450.000; vendida por 470.000.
        ->and($sale->minimumAuthorizedValueCents)->toBe(45_000_000)
        ->and($sale->conformityStatus)->toBe(SalesPriceConformityStatus::Conform)
        // E os totais continuam fechando com as linhas.
        ->and($snapshot->unitsTotal)->toBe(4)
        ->and($snapshot->stockUnits + $snapshot->financedUnits + $snapshot->settledUnits + $snapshot->exchangedUnits + $snapshot->undeterminedUnits)
        ->toBe($snapshot->unitsTotal);
});

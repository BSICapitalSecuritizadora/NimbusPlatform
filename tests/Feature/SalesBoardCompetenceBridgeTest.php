<?php

use App\DTOs\SalesBoards\SalesBoardBridgeBucketRow;
use App\DTOs\SalesBoards\SalesBoardCompetenceBridge;
use App\Enums\ContractStatus;
use App\Enums\SalesBoardUnitClassification;
use App\Filament\Resources\SalesBoardCycles\Pages\ViewSalesBoardCycle;
use App\Models\ConstructionUnitExchange;
use App\Models\SalesBoardCycle;
use App\Services\SalesBoards\SalesBoardCompetenceBridgeBuilder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SalesBoards\CycleFixture;
use Tests\Support\SalesBoards\DerivationFixture;
use Tests\Support\SalesBoards\ExtemporaneousFixture;
use Tests\Support\SalesBoards\ManagementReviewFixture;

uses(RefreshDatabase::class);

/**
 * A "Ponte com a competência anterior": da posição congelada da anterior até a
 * desta versão, unidade a unidade, só pelo que foi congelado. O que sobra sem
 * venda, distrato, quitação, permuta, inclusão ou baixa que explique fica
 * listado para a Gestão conferir.
 */
function bridgeOf(SalesBoardCycle $cycle): SalesBoardCompetenceBridge
{
    return app(SalesBoardCompetenceBridgeBuilder::class)->forBaseline(CycleFixture::currentBaseline($cycle));
}

function bridgeBucket(SalesBoardCompetenceBridge $bridge, SalesBoardUnitClassification $classification): SalesBoardBridgeBucketRow
{
    return collect($bridge->buckets)->firstOrFail(fn (SalesBoardBridgeBucketRow $bucket): bool => $bucket->classification === $classification);
}

it('closes July into August unit by unit with the sales, settlements and distratos of the month', function () {
    $scenario = ExtemporaneousFixture::publishedJuly();
    [, $saleUnit, $cashUnit] = $scenario['units'];

    $scenario['financed']->forceFill(['cancellation_date' => '2026-08-12', 'status' => ContractStatus::Cancelled])->save();
    ExtemporaneousFixture::sale($saleUnit, '2026-08-05');
    ExtemporaneousFixture::cashSale($cashUnit, '2026-08-10');

    $august = ExtemporaneousFixture::generateAugust($scenario['construction']);
    $bridge = bridgeOf($august);

    $stock = bridgeBucket($bridge, SalesBoardUnitClassification::Stock);
    $financed = bridgeBucket($bridge, SalesBoardUnitClassification::Financed);
    $settled = bridgeBucket($bridge, SalesBoardUnitClassification::Settled);

    expect($bridge->anchorKind)->toBe(SalesBoardCompetenceBridge::ANCHOR_LINES)
        ->and($bridge->previousLabel)->toBe('07/2026')
        ->and($bridge->previousPublished)->toBeTrue()
        ->and($bridge->unexplainedUnits)->toBe([])
        ->and($bridge->explanations)->toEqual(['Vendas' => 2, 'Quitações' => 1, 'Distratos' => 1])
        ->and([$stock->previousUnits, $stock->entries, $stock->exits, $stock->unexplained, $stock->currentUnits])->toBe([3, 1, 2, 0, 2])
        ->and([$financed->previousUnits, $financed->entries, $financed->exits, $financed->currentUnits])->toBe([1, 1, 1, 1])
        ->and([$settled->previousUnits, $settled->entries, $settled->exits, $settled->currentUnits])->toBe([0, 1, 0, 1])
        ->and(collect($bridge->buckets)->every(fn (SalesBoardBridgeBucketRow $bucket): bool => $bucket->balances()))->toBeTrue();

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(makeAdminUser());

    Livewire::test(ViewSalesBoardCycle::class, ['record' => $august->getKey()])
        ->assertOk()
        ->assertSee('Ponte com a competência anterior')
        ->assertSee('Nenhuma unidade: a posição de 07/2026 chega a esta versão pelos movimentos congelados.');
});

it('explains with the late movements the variation that used to be left over', function () {
    $scenario = ExtemporaneousFixture::publishedJuly();

    // O probe da auditoria: a venda de julho lançada depois da publicação.
    ExtemporaneousFixture::sale($scenario['units'][1], '2026-07-18');

    $bridge = bridgeOf(ExtemporaneousFixture::generateAugust($scenario['construction']));
    $stock = bridgeBucket($bridge, SalesBoardUnitClassification::Stock);
    $financed = bridgeBucket($bridge, SalesBoardUnitClassification::Financed);

    expect($bridge->lateMovementsCount)->toBe(1)
        ->and($bridge->unexplainedCount())->toBe(0)
        ->and($bridge->explanations)->toBe(['Vendas' => 1])
        ->and($stock->unitsDifference())->toBe(-1)
        ->and([$stock->exits, $stock->unexplained])->toBe([1, 0])
        ->and($financed->unitsDifference())->toBe(1)
        ->and([$financed->entries, $financed->unexplained])->toBe([1, 0]);
});

it('explains a unit registered after July as an inclusion and an exchange started in August', function () {
    $scenario = ExtemporaneousFixture::publishedJuly();

    $included = DerivationFixture::unit($scenario['construction'], '105');
    ConstructionUnitExchange::factory()->forUnit($scenario['units'][3])->effectiveFrom('2026-08-10')->worth('450000.00')->create();

    $august = ExtemporaneousFixture::generateAugust($scenario['construction']);
    $bridge = bridgeOf($august);
    $stock = bridgeBucket($bridge, SalesBoardUnitClassification::Stock);
    $exchanged = bridgeBucket($bridge, SalesBoardUnitClassification::Exchanged);

    expect($bridge->unexplainedUnits)->toBe([])
        ->and($bridge->explanations)->toEqual(['Inclusões no inventário' => 1, 'Início de permuta' => 1])
        ->and([$stock->previousUnits, $stock->entries, $stock->exits, $stock->currentUnits])->toBe([3, 1, 1, 3])
        ->and([$exchanged->previousUnits, $exchanged->entries, $exchanged->currentUnits])->toBe([0, 1, 1])
        ->and(CycleFixture::currentBaseline($august)->lines->firstWhere('construction_unit_id', $included->id)?->classification)
        ->toBe(SalesBoardUnitClassification::Stock);
});

it('lists a reversed settlement and a sale whose date moved as units without a movement that explains them', function () {
    [$construction, $units] = CycleFixture::readyConstruction(3);

    $cash = DerivationFixture::contract($units[0], '2026-03-10', '480000.00');
    $payment = DerivationFixture::installment($cash, '001', '2026-03-10', '480000.00', '2026-03-10', '480000.00');
    $moved = ExtemporaneousFixture::sale($units[1], '2026-07-10');

    $july = CycleFixture::generate($construction, '2026-07-01')->cycle;
    ExtemporaneousFixture::publish($july);

    // O pagamento é estornado e a data da venda vai para agosto, depois da publicação.
    $payment->forceFill(['payment_date' => null, 'paid_value' => null])->save();
    $moved->forceFill(['sale_date' => '2026-08-05'])->save();

    $bridge = bridgeOf(ExtemporaneousFixture::generateAugust($construction));
    $rows = collect($bridge->unexplainedUnits);

    expect($rows->pluck('constructionUnitId')->all())->toBe([$units[0]->id, $units[1]->id])
        ->and($rows->first()->previousClassification)->toBe(SalesBoardUnitClassification::Settled)
        ->and($rows->first()->currentClassification)->toBe(SalesBoardUnitClassification::Financed)
        // A venda movida termina no balde de onde partiu, e mesmo assim não fecha.
        ->and($rows->last()->previousClassification)->toBe(SalesBoardUnitClassification::Financed)
        ->and($rows->last()->currentClassification)->toBe(SalesBoardUnitClassification::Financed)
        ->and(bridgeBucket($bridge, SalesBoardUnitClassification::Settled)->unexplained)->toBe(-1)
        ->and(bridgeBucket($bridge, SalesBoardUnitClassification::Financed)->unexplained)->toBe(1);
});

it('compares only the bucket totals when the previous competence was registered by hand', function () {
    [$construction] = CycleFixture::readyConstruction(2);
    $august = ExtemporaneousFixture::generateAugust($construction);

    ManagementReviewFixture::manualBoardBeforeAutomation(
        $august,
        [
            'reference_month' => '2026-07-01',
            'stock_units' => 1,
            'stock_value' => '500000.00',
            'financed_units' => 1,
            'financed_value' => '480000.00',
            'paid_units' => 0,
            'paid_value' => '0.00',
            'exchanged_units' => 0,
            'exchanged_value' => '0.00',
            'total_units' => 2,
        ],
    );

    $bridge = bridgeOf($august);
    $stock = bridgeBucket($bridge, SalesBoardUnitClassification::Stock);

    expect($bridge->isManualAnchor())->toBeTrue()
        ->and($bridge->summary())->toBe('Competência anterior (07/2026) registrada manualmente: sem conciliação por unidade.')
        ->and([$stock->previousUnits, $stock->currentUnits, $stock->unitsDifference()])->toBe([1, 2, 1])
        ->and([$stock->entries, $stock->exits, $stock->unexplained])->toBe([null, null, null])
        ->and($bridge->unexplainedUnits)->toBe([]);
});

it('reads only frozen tables to build the bridge', function () {
    $scenario = ExtemporaneousFixture::publishedJuly();
    ExtemporaneousFixture::sale($scenario['units'][1], '2026-07-18');
    $august = ExtemporaneousFixture::generateAugust($scenario['construction']);
    $baseline = CycleFixture::currentBaseline($august);

    DB::enableQueryLog();
    DB::flushQueryLog();

    $bridge = app(SalesBoardCompetenceBridgeBuilder::class)->forBaseline($baseline);

    $tables = collect(DB::getQueryLog())
        ->map(fn (array $query): string => strtolower($query['query']))
        ->flatMap(function (string $sql): array {
            preg_match_all('/\b(?:from|join)\s+"([a-z_]+)"/', $sql, $matches);

            return $matches[1];
        })
        ->unique()
        ->values();
    DB::disableQueryLog();

    expect($bridge->lateMovementsCount)->toBe(1)
        ->and($tables)->not->toBeEmpty()
        ->and($tables->intersect(['contracts', 'contract_installments', 'construction_unit_exchanges', 'construction_unit_values', 'construction_units']))->toBeEmpty();
});

it('uses the current publication of a July rectified after the August version, and says so', function () {
    $scenario = ExtemporaneousFixture::rectifiableJuly();
    $august = ExtemporaneousFixture::generateAugust($scenario['construction']);

    ExtemporaneousFixture::rectify($scenario['july']);
    ExtemporaneousFixture::publish($scenario['july']);

    $bridge = bridgeOf($august);

    expect($bridge->anchorChangedSinceVersion)->toBeTrue()
        ->and($bridge->previousVersionLabel)->toBe('V2')
        ->and($bridge->previousPublished)->toBeTrue()
        ->and($bridge->summary())->toContain('a competência anterior foi retificada depois desta versão');

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(makeAdminUser());

    Livewire::test(ViewSalesBoardCycle::class, ['record' => $august->getKey()])
        ->assertOk()
        ->assertSee('A competência anterior foi retificada depois desta versão');
});

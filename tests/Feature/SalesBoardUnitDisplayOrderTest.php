<?php

use App\DTOs\SalesBoards\SalesBoardBuilderWorkspaceUnitRow;
use App\DTOs\SalesBoards\SalesBoardSnapshot;
use App\Enums\SalesBoardBuilderReviewSection as SectionEnum;
use App\Filament\Resources\SalesBoardCycles\Pages\BuilderReviewWorkspace;
use App\Filament\Resources\SalesBoardCycles\Pages\ViewSalesBoardCycle;
use App\Filament\Resources\SalesBoardCycles\RelationManagers\SalesBoardCycleLinesRelationManager;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleLine;
use App\Models\SalesDiscountPolicy;
use App\Services\SalesBoards\SalesBoardBuilderReviewWorkspaceBuilder;
use App\Support\SalesBoards\UnitDisplayOrder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SalesBoards\BuilderReviewFixture;
use Tests\Support\SalesBoards\CycleFixture;
use Tests\Support\SalesBoards\DerivationFixture;

/**
 * As unidades na ordem em que a obra as numera, nas duas telas.
 *
 * A ordem alfabética do banco punha "1010" entre "101" e "102" e "Torre 10"
 * antes de "Torre 2". A regra de {@see UnitDisplayOrder} é a mesma em PHP (as
 * seções da Validação) e em SQL (a aba Unidades do ciclo), e por isso o arquivo
 * roda também no MySQL. Só a exibição muda: snapshot e fingerprint continuam
 * ordenados pela unidade cadastrada.
 */
pest()->group('parity');

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(makeAdminUser());
});

/**
 * Um ciclo gerado com as unidades cadastradas fora da ordem natural.
 *
 * @param  list<array{0: string, 1: string}>  $units  pares de bloco e unidade, na ordem de cadastro
 */
function displayOrderCycle(array $units): SalesBoardCycle
{
    $construction = CycleFixture::construction();

    SalesDiscountPolicy::factory()
        ->forConstruction($construction)
        ->effectiveFrom('2020-01-01')
        ->closedPeriod()
        ->allowing('10.00')
        ->create();

    foreach ($units as [$block, $unit]) {
        DerivationFixture::unit($construction, $unit)->forceFill(['block' => $block])->save();
    }

    return CycleFixture::generate($construction)->cycle;
}

/**
 * @return list<string> "bloco / unidade" na ordem dada
 */
function displayOrderLabels(iterable $lines): array
{
    $labels = [];

    foreach ($lines as $line) {
        $labels[] = $line->block.' / '.$line->unit;
    }

    return $labels;
}

it('orders units naturally within the block on the units tab', function () {
    $cycle = displayOrderCycle([['01', '101'], ['01', '1001'], ['01', '102'], ['01', '1010'], ['01', '1002']]);

    $ordered = SalesBoardCycleLine::query()
        ->where('sales_board_cycle_baseline_id', $cycle->current_baseline_id)
        ->get()
        ->sortBy(fn (SalesBoardCycleLine $line): int => array_search($line->unit, ['101', '102', '1001', '1002', '1010'], true))
        ->values();

    Livewire::test(SalesBoardCycleLinesRelationManager::class, [
        'ownerRecord' => $cycle,
        'pageClass' => ViewSalesBoardCycle::class,
    ])
        ->assertCanSeeTableRecords($ordered, inOrder: true)
        ->sortTable('unit', 'desc')
        ->assertCanSeeTableRecords($ordered->reverse()->values(), inOrder: true);

    expect(displayOrderLabels(UnitDisplayOrder::applyTo(SalesBoardCycleLine::query()->where('sales_board_cycle_baseline_id', $cycle->current_baseline_id))->get()))
        ->toBe(['01 / 101', '01 / 102', '01 / 1001', '01 / 1002', '01 / 1010']);
});

it('orders blocks naturally', function () {
    $cycle = displayOrderCycle([['Torre 10', '101'], ['Torre 2', '102'], ['Torre 1', '103']]);

    expect(displayOrderLabels(UnitDisplayOrder::applyTo(SalesBoardCycleLine::query()->where('sales_board_cycle_baseline_id', $cycle->current_baseline_id))->get()))
        ->toBe(['Torre 1 / 103', 'Torre 2 / 102', 'Torre 10 / 101'])
        ->and(UnitDisplayOrder::compare('Torre 2', '1', 'Torre 10', '1'))->toBeLessThan(0)
        ->and(UnitDisplayOrder::compare('01', '1010', '01', '101'))->toBeGreaterThan(0);
});

it('shows the validation rows and the divergence options in the same order as the units tab', function () {
    $cycle = displayOrderCycle([['01', '1001'], ['01', '101'], ['01', '1010'], ['01', '102']]);
    $review = BuilderReviewFixture::open($cycle);
    $stock = BuilderReviewFixture::section($review, SectionEnum::PositionStock);

    $tabOrder = displayOrderLabels(UnitDisplayOrder::applyTo(SalesBoardCycleLine::query()->where('sales_board_cycle_baseline_id', $cycle->current_baseline_id))->get());

    $rows = app(SalesBoardBuilderReviewWorkspaceBuilder::class)
        ->build($review->fresh())
        ->section(SectionEnum::PositionStock)
        ->rows;

    expect($tabOrder)->toBe(['01 / 101', '01 / 102', '01 / 1001', '01 / 1010'])
        ->and(array_map(fn (SalesBoardBuilderWorkspaceUnitRow $row): string => $row->displayName(), $rows))->toBe($tabOrder);

    $page = Livewire::test(BuilderReviewWorkspace::class, ['record' => $cycle->getKey()])
        ->assertSeeInOrder($tabOrder);

    // O select da divergência herda a ordem das linhas da seção.
    $form = (fn (int $sectionId): array => $this->divergenceForm($sectionId))->call($page->instance(), $stock->id);
    $select = collect($form)->first(fn (mixed $component): bool => $component instanceof Select && $component->getName() === 'sales_board_cycle_line_id');

    expect(array_values($select->getOptions()))->toBe($tabOrder);
});

it('keeps the snapshot fingerprint independent of the display order', function () {
    $cycle = displayOrderCycle([['01', '1010'], ['01', '101'], ['01', '1001'], ['01', '102']]);
    $baseline = CycleFixture::currentBaseline($cycle);

    $byRegistration = $baseline->lines()->reorder()->orderBy('construction_unit_id')->get();
    $byDisplay = UnitDisplayOrder::applyTo($baseline->lines()->reorder()->getQuery())->get();

    // As duas ordens são diferentes de verdade...
    expect(displayOrderLabels($byRegistration))->not->toBe(displayOrderLabels($byDisplay));

    // ...e o resumo da posição é o mesmo nas duas.
    foreach ([$byRegistration, $byDisplay] as $lines) {
        $loaded = $baseline->fresh()->setRelation('lines', $lines);

        expect(SalesBoardSnapshot::fromBaseline($loaded)->fingerprint())->toBe($baseline->snapshot_fingerprint);
    }
});

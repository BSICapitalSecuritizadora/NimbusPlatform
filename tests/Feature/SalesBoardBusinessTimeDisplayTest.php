<?php

use App\Filament\Resources\Constructions\Pages\EditConstruction;
use App\Filament\Resources\Constructions\RelationManagers\SalesDiscountPoliciesRelationManager;
use App\Filament\Resources\ConstructionUnits\Pages\ViewConstructionUnit;
use App\Filament\Resources\ConstructionUnits\RelationManagers\ConstructionUnitExchangesRelationManager;
use App\Filament\Resources\ConstructionUnits\RelationManagers\ConstructionUnitRetirementsRelationManager;
use App\Filament\Resources\ConstructionUnits\RelationManagers\ConstructionUnitValuesRelationManager;
use App\Filament\Resources\SalesBoardCycles\Pages\ListSalesBoardCycles;
use App\Filament\Resources\SalesBoardCycles\Pages\ViewSalesBoardCycle;
use App\Filament\Resources\SalesBoardCycles\RelationManagers\SalesBoardCycleBaselinesRelationManager;
use App\Filament\Resources\SalesBoardCycles\RelationManagers\SalesBoardCycleBuilderReviewsRelationManager;
use App\Filament\Resources\SalesBoardRollouts\Pages\ManageSalesBoardRollout;
use App\Filament\Resources\SalesBoards\Pages\ViewSalesBoard;
use App\Filament\Resources\SalesBoards\RelationManagers\SalesBoardHistoriesRelationManager;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\ConstructionUnitExchange;
use App\Models\ConstructionUnitValue;
use App\Models\Emission;
use App\Models\SalesDiscountPolicy;
use App\Services\SalesBoards\ConstructionUnitRetirementService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SalesBoards\BuilderReviewFixture;
use Tests\Support\SalesBoards\CycleFixture;
use Tests\Support\SalesBoards\DerivationFixture;
use Tests\Support\SalesBoards\ExtemporaneousFixture;
use Tests\Support\SalesBoards\GovernanceFixture;
use Tests\Support\SalesBoards\ManagementReviewFixture;
use Tests\Support\SalesBoards\RolloutFixture;

/**
 * As telas do Quadro gravam em UTC e mostram os horários no fuso de negócio,
 * America/Sao_Paulo -- como o painel da retificação, o Rollout e a Automação já
 * faziam. Antes, a mesma tela mostrava dois eventos do mesmo minuto com três
 * horas de diferença.
 *
 * Os relógios ficam entre 00:00 e 02:59 UTC, quando o dia em Brasília ainda é o
 * anterior: em UTC sairiam outro dia e outra hora. Os textos esperados são
 * literais, calculados à mão, de propósito -- derivá-los do fuso configurado
 * faria o teste deslizar junto com o código.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00', 'UTC'));
});

function businessTimeUnit(): ConstructionUnit
{
    $construction = Construction::factory()->create([
        'emission_id' => Emission::factory()->create(['status' => 'active'])->id,
    ]);

    return DerivationFixture::unit($construction, '101');
}

it('shows when the version was computed and when the source was checked in Brasília time', function () {
    // 01:46 UTC de 02/10 ainda é 22:46 de 01/10 em Brasília.
    $this->travelTo(CarbonImmutable::parse('2026-10-02 01:46:00', 'UTC'));

    $scenario = BuilderReviewFixture::generatedCycle();
    $cycle = $scenario['cycle'];
    $scenario['contracts']['soldInMonth']->update(['sale_value' => '499999.00']);
    CycleFixture::check($cycle);

    $this->actingAs(makeAdminUser());

    Livewire::test(ViewSalesBoardCycle::class, ['record' => $cycle->getKey()])
        ->assertOk()
        ->assertSee('Situação da fonte na última verificação, em 01/10/2026 às 22:46.')
        ->assertSeeInOrder(['Apurada em', '01/10/2026 às 22:46'])
        ->assertSeeInOrder(['Última verificação', '01/10/2026 às 22:46'])
        ->assertSeeInOrder(['Primeira divergência', '01/10/2026 às 22:46'])
        ->assertSeeHtml('title="01/10/2026 às 22:46"')
        ->assertDontSee('02/10/2026 às 01:46');

    Livewire::test(ListSalesBoardCycles::class)
        ->assertSee('01/10/2026 22:46')
        ->assertDontSee('02/10/2026 01:46');

    Livewire::test(SalesBoardCycleBaselinesRelationManager::class, [
        'ownerRecord' => $cycle->fresh(),
        'pageClass' => ViewSalesBoardCycle::class,
    ])
        ->assertSee('01/10/2026 22:46')
        ->assertSee('divergiu em 01/10/2026')
        ->assertDontSee('02/10/2026');
});

it('shows when the builder validation was sent in Brasília time', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    $review = BuilderReviewFixture::open($scenario['cycle']);
    BuilderReviewFixture::confirmAll($review);

    // 00:20 UTC de 02/10 ainda é 21:20 de 01/10 em Brasília.
    $this->travelTo(CarbonImmutable::parse('2026-10-02 00:20:00', 'UTC'));
    BuilderReviewFixture::submit($review);

    $this->actingAs(makeAdminUser());

    Livewire::test(SalesBoardCycleBuilderReviewsRelationManager::class, [
        'ownerRecord' => $scenario['cycle']->fresh(),
        'pageClass' => ViewSalesBoardCycle::class,
    ])
        ->assertSee('01/10/2026 21:20')
        ->assertDontSee('02/10/2026 00:20');
});

it('shows when the board was published and rectified in Brasília time', function () {
    // Publicado às 01:27 UTC de 02/10: 22:27 de 01/10 em Brasília.
    $this->travelTo(CarbonImmutable::parse('2026-10-02 01:27:00', 'UTC'));
    $scenario = ExtemporaneousFixture::publishedJuly();
    $board = $scenario['publication']->salesBoard;

    $this->actingAs(makeAdminUser());

    Livewire::test(ViewSalesBoard::class, ['record' => $board->getKey()])
        ->assertOk()
        ->assertSee('Publicado em 01/10/2026 às 22:27')
        ->assertSeeInOrder(['Atualizado em', '01/10/2026 às 22:27'])
        ->assertDontSee('02/10/2026 às 01:27');

    Livewire::test(SalesBoardHistoriesRelationManager::class, [
        'ownerRecord' => $board->fresh(),
        'pageClass' => ViewSalesBoard::class,
    ])
        ->assertSee('01/10/2026 22:27')
        ->assertDontSee('02/10/2026 01:27');

    $scenario['financed']->forceFill(['sale_value' => '650000.00'])->save();
    ExtemporaneousFixture::rectify($scenario['july']);
    $review = ExtemporaneousFixture::analysis($scenario['july']);
    ManagementReviewFixture::decideAll($review);

    // Retificado às 02:10 UTC de 03/10: 23:10 de 02/10 em Brasília.
    $this->travelTo(CarbonImmutable::parse('2026-10-03 02:10:00', 'UTC'));
    ManagementReviewFixture::approve($review);

    Livewire::test(ViewSalesBoard::class, ['record' => $board->getKey()])
        ->assertSee('Publicado em 02/10/2026 às 23:10')
        ->assertSee('Retificada em 02/10/2026 às 23:10')
        ->assertDontSee('03/10/2026 às 02:10');
});

it('shows when a retirement was registered and reactivated in Brasília time', function () {
    $unit = businessTimeUnit();
    $service = app(ConstructionUnitRetirementService::class);

    // Registrada às 01:17 UTC de 02/10: 22:17 de 01/10 em Brasília.
    $this->travelTo(CarbonImmutable::parse('2026-10-02 01:17:00', 'UTC'));
    $retirement = $service->retire($unit, GovernanceFixture::approver(), CarbonImmutable::parse('2026-09-15'), 'Unidade cadastrada em duplicidade na carga inicial.');

    // Reativada às 02:05 UTC de 02/10: 23:05 de 01/10 em Brasília.
    $this->travelTo(CarbonImmutable::parse('2026-10-02 02:05:00', 'UTC'));
    $service->reactivate($retirement, GovernanceFixture::approver(), CarbonImmutable::parse('2026-09-22'), 'Baixa registrada por engano: a unidade existe.');

    $this->actingAs(makeAdminUser());

    Livewire::test(ConstructionUnitRetirementsRelationManager::class, [
        'ownerRecord' => $unit,
        'pageClass' => ViewConstructionUnit::class,
    ])
        ->assertSee('01/10/2026 22:17')
        ->assertDontSee('02/10/2026 01:17')
        ->mountAction(TestAction::make('viewReason')->table($retirement->fresh()))
        ->assertMountedActionModalSee('01/10/2026 22:17')
        ->assertMountedActionModalSee('em 01/10/2026 23:05.')
        ->assertMountedActionModalDontSee('02/10/2026');
});

it('shows when the exchange, the unit value and the discount policy were registered in Brasília time', function () {
    $unit = businessTimeUnit();

    // 02:40 UTC de 02/10: 23:40 de 01/10 em Brasília.
    $this->travelTo(CarbonImmutable::parse('2026-10-02 02:40:00', 'UTC'));

    ConstructionUnitExchange::factory()->forUnit($unit)->create(['exchange_value' => '700000.00']);
    ConstructionUnitValue::factory()->forUnit($unit)->effectiveFrom('2026-01-01')->create();
    SalesDiscountPolicy::factory()->forConstruction($unit->construction)
        ->effectiveFrom('2026-01-01')->closedPeriod()->allowing('10.00')->create();

    $this->actingAs(makeAdminUser());

    foreach ([ConstructionUnitExchangesRelationManager::class, ConstructionUnitValuesRelationManager::class] as $relationManager) {
        Livewire::test($relationManager, ['ownerRecord' => $unit->fresh(), 'pageClass' => ViewConstructionUnit::class])
            ->assertSee('01/10/2026 23:40')
            ->assertDontSee('02/10/2026 02:40');
    }

    Livewire::test(SalesDiscountPoliciesRelationManager::class, [
        'ownerRecord' => $unit->construction->fresh(),
        'pageClass' => EditConstruction::class,
    ])
        ->assertSee('01/10/2026 23:40')
        ->assertDontSee('02/10/2026 02:40');
});

it('shows when the automation was activated in Brasília time on the rollout', function () {
    ['emission' => $emission, 'constructions' => $constructions] = RolloutFixture::emission();

    foreach ($constructions as $construction) {
        RolloutFixture::legacyBoard($construction);
    }

    $homologation = RolloutFixture::approvedHomologation($emission);

    // Ativada às 01:05 UTC de 02/10: 22:05 de 01/10 em Brasília.
    $this->travelTo(CarbonImmutable::parse('2026-10-02 01:05:00', 'UTC'));
    RolloutFixture::activate($emission, $homologation);

    $this->actingAs(makeAdminUser());

    Livewire::test(ManageSalesBoardRollout::class, ['record' => $emission->getKey()])
        ->assertOk()
        ->assertSee('ativada em 01/10/2026')
        ->assertSee('01/10/2026 22:05')
        ->assertDontSee('02/10/2026 01:05');
});

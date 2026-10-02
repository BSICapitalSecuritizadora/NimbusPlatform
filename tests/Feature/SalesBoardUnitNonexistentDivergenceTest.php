<?php

use App\DTOs\SalesBoards\SalesBoardBuilderDivergenceInput;
use App\Enums\SalesBoardApprovalOutcome;
use App\Enums\SalesBoardBuilderDivergenceType;
use App\Enums\SalesBoardBuilderReviewSection as SectionEnum;
use App\Enums\SalesBoardBuilderReviewStatus;
use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardManagementReviewStatus;
use App\Enums\SalesBoardNonconformityDecision;
use App\Enums\SalesBoardNonconformityOrigin;
use App\Enums\SalesBoardRecalculationOutcome;
use App\Enums\SalesBoardStaleImpact;
use App\Exceptions\SalesBoardBuilderReviewException;
use App\Exceptions\SalesBoardManagementReviewException;
use App\Filament\Resources\ConstructionUnits\ConstructionUnitResource;
use App\Filament\Resources\SalesBoardCycles\Pages\BuilderReviewWorkspace;
use App\Filament\Resources\SalesBoardCycles\Pages\ManagementReviewWorkspace;
use App\Models\ConstructionUnit;
use App\Models\SalesBoardBuilderDivergence;
use App\Models\SalesBoardBuilderReview;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardManagementReview;
use App\Models\SalesBoardPublication;
use App\Models\User;
use App\Services\SalesBoards\ConstructionUnitRetirementService;
use App\Services\SalesBoards\SalesBoardManagementApprovalService;
use App\Services\SalesBoards\SalesBoardManagementReviewWorkspaceBuilder;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Forms\Components\Select;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SalesBoards\BuilderReviewFixture;
use Tests\Support\SalesBoards\CycleFixture;
use Tests\Support\SalesBoards\ExtemporaneousFixture;
use Tests\Support\SalesBoards\GovernanceFixture;
use Tests\Support\SalesBoards\ManagementReviewFixture;

/**
 * "Unidade que não existe": a construtora aponta a unidade inexistente na
 * posição, a Gestão decide "Correção necessária" e a saída é a baixa da unidade
 * e o recálculo -- a competência volta à construtora sem a unidade e publica o
 * estoque certo.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-08-15 12:00:00'));
});

const NONEXISTENT_UNIT_REASON = 'A unidade 103 não existe no memorial de incorporação: foi cadastrada por engano.';

/**
 * Três unidades em estoque, 07/2026 congelada e a validação aberta.
 *
 * @return array{cycle: SalesBoardCycle, units: list<ConstructionUnit>, review: SalesBoardBuilderReview}
 */
function nonexistentUnitScenario(): array
{
    [$construction, $units] = CycleFixture::readyConstruction(3);
    $cycle = CycleFixture::generate($construction)->cycle;

    return ['cycle' => $cycle, 'units' => $units, 'review' => BuilderReviewFixture::open($cycle)];
}

function declareNonexistentUnit(SalesBoardBuilderReview $review, ConstructionUnit $unit): SalesBoardBuilderDivergence
{
    return BuilderReviewFixture::declare($review, SectionEnum::PositionStock, new SalesBoardBuilderDivergenceInput(
        type: SalesBoardBuilderDivergenceType::UnitNonexistent,
        reason: NONEXISTENT_UNIT_REASON,
        lineId: BuilderReviewFixture::lineFor($review, $unit)->id,
    ));
}

/**
 * A análise aberta sobre a validação que apontou a unidade inexistente.
 *
 * @return array{cycle: SalesBoardCycle, units: list<ConstructionUnit>, review: SalesBoardBuilderReview, management: SalesBoardManagementReview}
 */
function nonexistentUnitManagementScenario(): array
{
    $scenario = nonexistentUnitScenario();

    declareNonexistentUnit($scenario['review'], $scenario['units'][2]);
    BuilderReviewFixture::confirmAll($scenario['review']);
    BuilderReviewFixture::submit($scenario['review']);

    return [...$scenario, 'management' => ManagementReviewFixture::open($scenario['cycle'])];
}

it('lets the builder declare the unit that does not exist, anchored to its frozen line', function () {
    $scenario = nonexistentUnitScenario();

    $divergence = declareNonexistentUnit($scenario['review'], $scenario['units'][2]);

    expect($divergence->type)->toBe(SalesBoardBuilderDivergenceType::UnitNonexistent)
        ->and($divergence->line->construction_unit_id)->toBe($scenario['units'][2]->id)
        ->and(fn () => BuilderReviewFixture::declare($scenario['review'], SectionEnum::PositionStock, new SalesBoardBuilderDivergenceInput(
            type: SalesBoardBuilderDivergenceType::UnitNonexistent,
            reason: NONEXISTENT_UNIT_REASON,
        )))->toThrow(SalesBoardBuilderReviewException::class, 'precisa apontar a unidade a que se refere')
        ->and(fn () => BuilderReviewFixture::declare($scenario['review'], SectionEnum::MovementSales, new SalesBoardBuilderDivergenceInput(
            type: SalesBoardBuilderDivergenceType::UnitNonexistent,
            reason: NONEXISTENT_UNIT_REASON,
        )))->toThrow(SalesBoardBuilderReviewException::class, 'não pertence à seção');
});

it('offers the type on the position sections only, keeping the default type of each section', function (SectionEnum $section, bool $offered, string $default) {
    $scenario = nonexistentUnitScenario();
    $this->actingAs(makeAdminUser());

    Livewire::test(BuilderReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
        ->mountAction('declareDivergence', ['section' => BuilderReviewFixture::section($scenario['review'], $section)->id])
        ->assertFormFieldExists('type', fn (Select $field): bool => array_key_exists(SalesBoardBuilderDivergenceType::UnitNonexistent->value, $field->getOptions()) === $offered)
        ->assertActionDataSet(['type' => $default]);
})->with([
    'estoque' => [SectionEnum::PositionStock, true, SalesBoardBuilderDivergenceType::CancellationMismatch->value],
    'permutado' => [SectionEnum::PositionExchanged, true, SalesBoardBuilderDivergenceType::StockMismatch->value],
    'vendas do mês' => [SectionEnum::MovementSales, false, SalesBoardBuilderDivergenceType::SaleMissing->value],
]);

it('tells management how to correct it, from the frozen fact only', function () {
    $scenario = nonexistentUnitManagementScenario();

    // O portão consulta a fonte de propósito; aqui ele é tirado da frente para
    // provar que a linha da Gestão não lê o cadastro vivo.
    $this->mock(SalesBoardManagementApprovalService::class, function ($mock): void {
        $mock->shouldReceive('gate')->andReturn(['ready' => false, 'checks' => [], 'impact' => null]);
    });

    $tables = [];

    DB::listen(function (QueryExecuted $query) use (&$tables): void {
        foreach (['construction_units', 'construction_unit_retirements'] as $table) {
            if (str_contains(strtolower($query->sql), $table)) {
                $tables[] = $table;
            }
        }
    });

    $workspace = app(SalesBoardManagementReviewWorkspaceBuilder::class)->build($scenario['management']->fresh());
    $row = $workspace->nonconformitiesOf(SalesBoardNonconformityOrigin::BuilderDeclared)[0];

    expect($tables)->toBe([])
        ->and($row->typeLabel)->toBe('Unidade que não existe')
        ->and($row->builderStatement)->toBe('A unidade não existe')
        ->and($row->correctionGuidance)->toContain('registre a baixa da unidade 01 / 103 com efeito a partir de 01/07/2026')
        ->and($row->correctionGuidance)->toContain('A nova versão volta à construtora.')
        ->and($row->unitToRetireId)->toBe($scenario['units'][2]->id);
});

it('shows the guidance on the analysis screen, with the link to the unit only for whoever views the register', function (array $permissions, bool $linked) {
    $scenario = nonexistentUnitManagementScenario();

    $user = User::factory()->create();
    $user->givePermissionTo($permissions);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs($user->fresh());

    $page = Livewire::test(ManagementReviewWorkspace::class, ['record' => $scenario['cycle']->getKey()])
        ->assertSee('Unidade que não existe')
        ->assertSee('A unidade não existe')
        ->assertSee('registre a baixa da unidade 01 / 103 com efeito a partir de 01/07/2026');

    $url = ConstructionUnitResource::getUrl('view', ['record' => $scenario['units'][2]->id]);

    $linked
        ? $page->assertSee('Abrir a unidade')->assertSeeHtml($url)
        : $page->assertDontSee('Abrir a unidade');
})->with([
    'com o cadastro de unidades' => [['sales-boards.view', 'emissions.view', 'sales-boards.approve', 'constructions.view'], true],
    'sem o cadastro de unidades' => [['sales-boards.view', 'emissions.view', 'sales-boards.approve'], false],
]);

it('takes "correction required" to publication through the retirement and the recalculation', function () {
    $scenario = nonexistentUnitManagementScenario();
    $cycle = $scenario['cycle'];
    $management = $scenario['management'];

    ManagementReviewFixture::decide(
        ManagementReviewFixture::nonconformityOf($management, SalesBoardNonconformityOrigin::BuilderDeclared),
        SalesBoardNonconformityDecision::CorrectionRequired,
    );

    expect(fn () => ManagementReviewFixture::approve($management))
        ->toThrow(SalesBoardManagementReviewException::class, 'exigem correção da fonte');

    app(ConstructionUnitRetirementService::class)->retire(
        $scenario['units'][2],
        GovernanceFixture::approver(),
        CarbonImmutable::parse('2026-07-01'),
        NONEXISTENT_UNIT_REASON,
    );

    $assessment = CycleFixture::check($cycle);
    $recalculation = CycleFixture::recalculate($cycle, 'Baixa da unidade inexistente registrada pela Gestão.');

    expect($assessment->impact)->toBe(SalesBoardStaleImpact::Material)
        ->and($recalculation->outcome)->toBe(SalesBoardRecalculationOutcome::Recalculated)
        ->and($recalculation->baseline?->lines()->count())->toBe(2)
        ->and($management->fresh()->status)->toBe(SalesBoardManagementReviewStatus::Superseded)
        ->and($scenario['review']->fresh()->status)->toBe(SalesBoardBuilderReviewStatus::Superseded)
        ->and($cycle->fresh()->status)->toBe(SalesBoardCycleStatus::BuilderReview);

    $round = BuilderReviewFixture::open($cycle->fresh());
    BuilderReviewFixture::confirmAll($round);
    BuilderReviewFixture::submit($round);

    $newManagement = ManagementReviewFixture::open($cycle->fresh());

    expect($newManagement->fresh()->nonconformities)->toHaveCount(0);

    $result = ManagementReviewFixture::approve($newManagement);

    expect($result->outcome)->toBe(SalesBoardApprovalOutcome::Approved)
        ->and($cycle->fresh()->status)->toBe(SalesBoardCycleStatus::Approved)
        ->and((int) $result->salesBoard->stock_units)->toBe(2)
        ->and((string) $result->salesBoard->stock_value)->toBe('1000000.00');
});

/**
 * Com a competência anterior também em aberto, a baixa no mês do ciclo deixaria
 * a unidade inexistente nela: a orientação sugere o primeiro dia ainda não
 * publicado da obra, o mesmo que o cadastro da baixa recomenda.
 */
it('suggests the first day not yet published when an earlier competence is still open', function () {
    $scenario = nonexistentUnitManagementScenario();

    $may = SalesBoardCycle::factory()->forConstruction($scenario['cycle']->construction)->referenceMonth('2026-05-01')->create([
        'status' => SalesBoardCycleStatus::Approved,
    ]);
    SalesBoardPublication::factory()->create(['sales_board_cycle_id' => $may->id]);

    $this->mock(SalesBoardManagementApprovalService::class, function ($mock): void {
        $mock->shouldReceive('gate')->andReturn(['ready' => false, 'checks' => [], 'impact' => null]);
    });

    $row = app(SalesBoardManagementReviewWorkspaceBuilder::class)->build($scenario['management']->fresh())
        ->nonconformitiesOf(SalesBoardNonconformityOrigin::BuilderDeclared)[0];

    expect($row->correctionGuidance)->toContain('registre a baixa da unidade 01 / 103 com efeito a partir de 01/06/2026');
});

/**
 * Na retificação a competência já foi publicada, e a baixa não vale dentro
 * dela. A orientação não manda a Gestão por esse caminho: diz que a baixa vale
 * só a partir da competência seguinte e que, aqui, resta "Não procede" ou
 * desistir da retificação.
 */
it('does not send management to a retirement inside the competence under rectification', function () {
    $scenario = ExtemporaneousFixture::rectifiableJuly();
    $unit = $scenario['units'][2];
    ExtemporaneousFixture::rectify($scenario['july']);

    $round = BuilderReviewFixture::open($scenario['july']->fresh());
    declareNonexistentUnit($round, $unit);
    BuilderReviewFixture::confirmAll($round);
    BuilderReviewFixture::submit($round);

    $management = ManagementReviewFixture::open($scenario['july']->fresh());

    $row = app(SalesBoardManagementReviewWorkspaceBuilder::class)->build($management->fresh())
        ->nonconformitiesOf(SalesBoardNonconformityOrigin::BuilderDeclared)[0];

    expect($row->correctionGuidance)
        ->toContain('Esta competência já foi publicada e está em retificação')
        ->toContain('só a partir de 01/08/2026')
        ->toContain('decida "Não procede"')
        ->toContain('ou desista da retificação')
        ->not->toContain('com efeito a partir de 01/07/2026');

    // O caminho que a orientação deixa de indicar continua recusado.
    expect(fn () => app(ConstructionUnitRetirementService::class)->retire($unit, GovernanceFixture::approver(), CarbonImmutable::parse('2026-07-01'), NONEXISTENT_UNIT_REASON))
        ->toThrow(Exception::class, 'A data precisa ser posterior a 31/07/2026.');
});

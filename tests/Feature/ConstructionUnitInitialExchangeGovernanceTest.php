<?php

use App\Enums\ConstructionUnitExchangeKind;
use App\Enums\SalesBoardSource;
use App\Exceptions\ConstructionUnitExchangeException;
use App\Filament\Resources\ConstructionUnits\Pages\ViewConstructionUnit;
use App\Filament\Resources\ConstructionUnits\RelationManagers\ConstructionUnitExchangesRelationManager;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\ConstructionUnitExchange;
use App\Models\Emission;
use App\Models\User;
use App\Services\SalesBoards\ConstructionUnitExchangeService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SalesBoards\CycleFixture;
use Tests\Support\SalesBoards\DerivationFixture;
use Tests\Support\SalesBoards\GovernanceFixture;

/**
 * A posição inicial de permuta congela quando o Quadro usa a obra.
 *
 * O que torna a permuta "inicial" é o Quadro ainda não ter congelado nenhuma
 * posição da obra, e não o status da Emissão. Uma Emissão devolvida a "Em
 * Elaboração" depois de ter competência apurada não reabre a permuta ao
 * editor -- e por isso a Gestão registra a extraordinária mesmo com a Emissão
 * em elaboração. Sem nada congelado, a declaração continua de quem estrutura a
 * operação, agora pelo serviço, com motivo e as mesmas conferências.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function initialExchangeReason(): string
{
    return 'Permuta acordada na estruturação da operação com a construtora.';
}

/**
 * Quem estrutura a operação: vê e edita empreendimentos, sem a Gestão.
 */
function initialExchangeEditor(): User
{
    $editor = User::factory()->create();
    $editor->givePermissionTo(['constructions.view', 'constructions.update']);

    return $editor;
}

/**
 * Uma unidade de obra com competência apurada, cuja Emissão voltou ao legado e
 * à elaboração -- como a consolidação permite.
 */
function unitOfDraftEmissionWithCompetence(): ConstructionUnit
{
    [$construction, $units] = CycleFixture::readyConstruction(2);
    CycleFixture::generate($construction);

    DB::table('emissions')->where('id', $construction->emission_id)->update([
        'sales_board_source' => SalesBoardSource::Legacy->value,
        'sales_board_automation_start_reference_month' => null,
    ]);

    Emission::query()->findOrFail($construction->emission_id)->update(['status' => Emission::STATUS_DRAFT]);

    return $units[0]->fresh();
}

function unitOfOpenDraftEmission(): ConstructionUnit
{
    $emission = Emission::factory()->create(['status' => Emission::STATUS_DRAFT]);
    $construction = Construction::factory()->create(['emission_id' => $emission->id]);

    return DerivationFixture::unit($construction, '101');
}

function declareInitialExchange(ConstructionUnit $unit, ?User $actor, string $effectiveFrom = '2026-01-01', ?int $contractId = null, ?string $reason = null): ConstructionUnitExchange
{
    return app(ConstructionUnitExchangeService::class)->declareBaseline(
        $unit,
        $actor,
        '450000.00',
        CarbonImmutable::parse($effectiveFrom),
        $contractId,
        $reason ?? initialExchangeReason(),
    );
}

function initialExchangePage(ConstructionUnit $unit): mixed
{
    return Livewire::test(ConstructionUnitExchangesRelationManager::class, [
        'ownerRecord' => $unit,
        'pageClass' => ViewConstructionUnit::class,
    ]);
}

it('refuses the initial exchange once the construction has a competence, even after the emission returns to draft', function () {
    $unit = unitOfDraftEmissionWithCompetence();
    $editor = initialExchangeEditor();
    $this->actingAs($editor);

    expect($unit->construction->emission->isInDraft())->toBeTrue()
        ->and($unit->construction->emission->usesAutomatedSalesBoard())->toBeFalse()
        ->and(app(ConstructionUnitExchangeService::class)->initialPositionIsFrozen($unit))->toBeTrue();

    initialExchangePage($unit)
        ->assertActionHidden(TestAction::make('declareBaseline')->table())
        ->mountAction(TestAction::make('declareBaseline')->table())
        ->assertSet('mountedActions', [])
        ->assertSee('A posição inicial de permuta já foi usada pelo Quadro de Vendas');

    expect(fn () => declareInitialExchange($unit, $editor))
        ->toThrow(ConstructionUnitExchangeException::class, ConstructionUnitExchangeException::initialPositionFrozen()->getMessage());

    expect(ConstructionUnitExchange::query()->count())->toBe(0);
});

it('refuses the initial exchange once the emission is automated even before the first cycle', function () {
    $emission = Emission::factory()
        ->withAutomatedSalesBoard(CycleFixture::AUTOMATION_START)
        ->create(['status' => Emission::STATUS_DRAFT]);
    $construction = Construction::factory()->create(['emission_id' => $emission->id]);
    $unit = DerivationFixture::unit($construction, '101');
    $editor = initialExchangeEditor();

    expect(app(ConstructionUnitExchangeService::class)->initialPositionIsFrozen($unit))->toBeTrue()
        ->and(fn () => declareInitialExchange($unit, $editor))
        ->toThrow(ConstructionUnitExchangeException::class, ConstructionUnitExchangeException::initialPositionFrozen()->getMessage());

    $this->actingAs($editor);

    initialExchangePage($unit)->assertActionHidden(TestAction::make('declareBaseline')->table());
});

it('lets management register an extraordinary exchange on a draft emission whose construction already has a competence', function () {
    $unit = unitOfDraftEmissionWithCompetence();

    $exchange = app(ConstructionUnitExchangeService::class)->registerExtraordinary(
        $unit,
        GovernanceFixture::approver(),
        '300000.00',
        CarbonImmutable::parse('2026-08-01'),
        null,
        'Permuta extraordinária acertada em aditivo com a construtora.',
    );

    expect($exchange->kind)->toBe(ConstructionUnitExchangeKind::Extraordinary)
        ->and($exchange->construction_unit_id)->toBe($unit->id);

    $this->actingAs(makeAdminUser());

    initialExchangePage($unit)
        ->assertActionVisible(TestAction::make('registerExtraordinary')->table())
        ->assertActionHidden(TestAction::make('declareBaseline')->table());
});

it('still declares the initial exchange while the emission is in draft and nothing was frozen', function () {
    $unit = unitOfOpenDraftEmission();
    $editor = initialExchangeEditor();
    $this->actingAs($editor);

    expect(app(ConstructionUnitExchangeService::class)->initialPositionIsFrozen($unit))->toBeFalse();

    initialExchangePage($unit)
        ->assertActionVisible(TestAction::make('declareBaseline')->table())
        ->assertActionHidden(TestAction::make('registerExtraordinary')->table())
        ->callAction(TestAction::make('declareBaseline')->table(), [
            'exchange_value' => '450.000,00',
            'effective_from' => '2026-01-01',
            'reason' => initialExchangeReason(),
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('Permuta declarada.');

    $exchange = ConstructionUnitExchange::query()->sole();

    expect($exchange->kind)->toBe(ConstructionUnitExchangeKind::Baseline)
        ->and($exchange->exchange_value)->toBe('450000.00')
        ->and($exchange->created_by_id)->toBe($editor->id)
        ->and($exchange->reason)->toBe(initialExchangeReason());

    // E a extraordinária continua recusada enquanto nada foi congelado.
    expect(fn () => app(ConstructionUnitExchangeService::class)->registerExtraordinary(
        $unit,
        GovernanceFixture::approver(),
        '300000.00',
        CarbonImmutable::parse('2026-08-01'),
        null,
        'Permuta extraordinária acertada em aditivo com a construtora.',
    ))->toThrow(ConstructionUnitExchangeException::class, ConstructionUnitExchangeException::initialPositionStillOpen()->getMessage());
});

it('requires constructions.update and a reason of at least ten characters for the initial exchange', function () {
    $unit = unitOfOpenDraftEmission();

    $viewer = User::factory()->create();
    $viewer->givePermissionTo(['constructions.view']);

    expect(fn () => declareInitialExchange($unit, null))
        ->toThrow(ConstructionUnitExchangeException::class, ConstructionUnitExchangeException::actorRequired()->getMessage())
        ->and(fn () => declareInitialExchange($unit, $viewer))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => declareInitialExchange($unit, GovernanceFixture::approver()))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => declareInitialExchange($unit, initialExchangeEditor(), reason: 'curto'))
        ->toThrow(ConstructionUnitExchangeException::class, 'Informe o motivo');

    $this->actingAs(initialExchangeEditor());

    initialExchangePage($unit)
        ->callAction(TestAction::make('declareBaseline')->table(), [
            'exchange_value' => '450.000,00',
            'effective_from' => '2026-01-01',
            'reason' => 'curto',
        ])
        ->assertHasActionErrors(['reason']);

    $this->actingAs($viewer);

    initialExchangePage($unit)->assertActionHidden(TestAction::make('declareBaseline')->table());

    expect(ConstructionUnitExchange::query()->count())->toBe(0);
});

it('refuses an initial exchange that overlaps another or points to a contract of another unit', function () {
    $unit = unitOfOpenDraftEmission();
    $editor = initialExchangeEditor();

    $otherUnit = DerivationFixture::unit($unit->construction, '102');
    $contractOfOtherUnit = DerivationFixture::contract($otherUnit, '2026-01-10', '480000.00');

    expect(fn () => declareInitialExchange($unit, $editor, contractId: $contractOfOtherUnit->id))
        ->toThrow(ConstructionUnitExchangeException::class, ConstructionUnitExchangeException::contractOfAnotherUnit()->getMessage());

    declareInitialExchange($unit, $editor, '2026-01-01');

    expect(fn () => declareInitialExchange($unit, $editor, '2026-03-01'))
        ->toThrow(ConstructionUnitExchangeException::class, 'já tem permuta vigente');

    expect(ConstructionUnitExchange::query()->where('construction_unit_id', $unit->id)->count())->toBe(1);
});

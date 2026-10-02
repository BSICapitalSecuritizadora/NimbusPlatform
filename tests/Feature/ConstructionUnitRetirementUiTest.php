<?php

use App\Enums\SalesBoardCycleStatus;
use App\Filament\Resources\ConstructionUnits\ConstructionUnitResource;
use App\Filament\Resources\ConstructionUnits\Pages\ListConstructionUnits;
use App\Filament\Resources\ConstructionUnits\Pages\ViewConstructionUnit;
use App\Filament\Resources\ConstructionUnits\RelationManagers\ConstructionUnitRetirementsRelationManager;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\ConstructionUnitRetirement;
use App\Models\Emission;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardPublication;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SalesBoards\DerivationFixture;

/**
 * A aba "Baixas" da unidade: a Gestão dá baixa e reativa; quem opera o cadastro
 * vê as ações desabilitadas, com o motivo; quem só visualiza vê o histórico.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:00'));
});

const RETIREMENT_UI_REASON = 'Unidade cadastrada em duplicidade na carga inicial.';

function retirementUiUnit(): ConstructionUnit
{
    $construction = Construction::factory()->create([
        'emission_id' => Emission::factory()->create(['status' => 'active', 'name' => 'CRI Horizonte'])->id,
        'development_name' => 'Residencial Horizonte',
    ]);

    return DerivationFixture::unit($construction, '305');
}

function retirementsTab(ConstructionUnit $unit): mixed
{
    return Livewire::test(ConstructionUnitRetirementsRelationManager::class, [
        'ownerRecord' => $unit,
        'pageClass' => ViewConstructionUnit::class,
    ]);
}

/**
 * @param  list<string>  $permissions
 */
function retirementUiUser(array $permissions): User
{
    $user = User::factory()->create();
    $user->givePermissionTo($permissions);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->fresh();
}

/**
 * O corpo da notificação, lido antes do `assertNotified()` -- que só compara o
 * título e esvazia a sessão.
 */
function retirementNotificationBody(string $title): ?string
{
    $body = collect(session()->get('filament.claimed_notifications') ?? session()->get('filament.notifications') ?? [])
        ->firstWhere('title', $title)['body'] ?? null;

    return $body === null ? null : (string) $body;
}

it('shows the retirement disabled to whoever operates the register, and the server refuses a forged mount', function () {
    $unit = retirementUiUnit();
    $editor = tap(User::factory()->create())->assignRole('editor');
    $this->actingAs($editor);

    retirementsTab($unit)
        ->assertActionVisible(TestAction::make('retireUnit')->table())
        ->assertActionDisabled(TestAction::make('retireUnit')->table())
        ->assertActionExists(TestAction::make('retireUnit')->table(), fn (Action $action): bool => $action->getTooltip()
            === 'A baixa de unidade é decisão da Gestão: exige a permissão de aprovação do Quadro de Vendas.')
        ->call('mountAction', 'retireUnit', [], ['table' => true])
        ->assertSet('mountedActions', []);

    expect(ConstructionUnitRetirement::query()->count())->toBe(0);
});

it('shows the history and hides every action to whoever only views the register', function () {
    $unit = retirementUiUnit();
    $retirement = ConstructionUnitRetirement::factory()->forUnit($unit)->retiredOn('2026-08-10')->create();
    $this->actingAs(retirementUiUser(['constructions.view']));

    expect(ConstructionUnitRetirementsRelationManager::canViewForRecord($unit, ViewConstructionUnit::class))->toBeTrue();

    retirementsTab($unit)
        ->assertSee('Baixas')
        ->assertCanSeeTableRecords([$retirement])
        ->assertActionHidden(TestAction::make('retireUnit')->table())
        ->assertActionHidden(TestAction::make('reactivateUnit')->table($retirement))
        ->assertActionVisible(TestAction::make('viewReason')->table($retirement));
});

it('records the retirement from the tab, defaulting to the first day no published competence reaches', function () {
    $unit = retirementUiUnit();
    $cycle = SalesBoardCycle::factory()->forConstruction($unit->construction)->referenceMonth('2026-07-01')->create(['status' => SalesBoardCycleStatus::Approved]);
    SalesBoardPublication::factory()->create(['sales_board_cycle_id' => $cycle->id]);
    $open = SalesBoardCycle::factory()->forConstruction($unit->construction)->referenceMonth('2026-08-01')->create(['status' => SalesBoardCycleStatus::Generated]);

    $admin = makeAdminUser();
    $this->actingAs($admin);

    $tab = retirementsTab($unit)
        ->mountAction(TestAction::make('retireUnit')->table())
        ->assertActionDataSet(function (array $state): array {
            expect(substr((string) $state['retired_on'], 0, 10))->toBe('2026-08-01');

            return [];
        })
        ->assertMountedActionModalSee('A data precisa ser posterior a 31/07/2026, a última competência publicada.')
        ->setActionData(['reason' => RETIREMENT_UI_REASON])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $body = retirementNotificationBody('Baixa registrada.');

    $tab->assertNotified('Baixa registrada.');

    $retirement = ConstructionUnitRetirement::query()->sole();

    expect($retirement->retired_on->toDateString())->toBe('2026-08-01')
        ->and($retirement->retired_by_id)->toBe($admin->id)
        ->and($retirement->reason)->toBe(RETIREMENT_UI_REASON)
        ->and($body)->toContain('deixa de compor o Quadro de Vendas a partir de 01/08/2026')
        ->and($body)->toContain('Competências em aberto alcançadas: 08/2026')
        ->and($body)->toContain('“Recalcular posição”')
        ->and($open->fresh()->status)->toBe(SalesBoardCycleStatus::Generated);
});

it('refuses a short reason and a date after today with the mapped messages', function () {
    $unit = retirementUiUnit();
    $this->actingAs(makeAdminUser());

    $tab = retirementsTab($unit)->callAction(TestAction::make('retireUnit')->table(), [
        'retired_on' => '2026-09-21',
        'reason' => 'curto',
    ]);

    $tab->assertHasActionErrors(['retired_on', 'reason']);

    $errors = $tab->errors();

    expect($errors->first('mountedActions.0.data.retired_on'))->toBe('A baixa é um fato, não um agendamento: a data não pode ser posterior a hoje.')
        ->and($errors->first('mountedActions.0.data.reason'))->toBe('Informe o motivo com pelo menos 10 caracteres.')
        ->and(ConstructionUnitRetirement::query()->count())->toBe(0);
});

it('keeps the modal open with the refusal when the domain says no', function () {
    $unit = retirementUiUnit();
    DerivationFixture::contract($unit, '2026-05-10');
    $this->actingAs(makeAdminUser());

    $tab = retirementsTab($unit)->callAction(TestAction::make('retireUnit')->table(), [
        'retired_on' => '2026-08-10',
        'reason' => RETIREMENT_UI_REASON,
    ]);

    $body = retirementNotificationBody('Baixa não registrada.');

    $tab->assertNotified('Baixa não registrada.');

    expect($tab->instance()->mountedActions)->not->toBe([])
        ->and($body)->toContain('a baixa só pode valer a partir do distrato')
        ->and(ConstructionUnitRetirement::query()->count())->toBe(0);
});

it('swaps the header action for the row reactivation, and closes or annuls the retirement', function (string $reactivatedOn, string $situation) {
    $unit = retirementUiUnit();
    $this->actingAs(makeAdminUser());

    $tab = retirementsTab($unit)
        ->callAction(TestAction::make('retireUnit')->table(), [
            'retired_on' => '2026-08-10',
            'reason' => RETIREMENT_UI_REASON,
        ])
        ->assertHasNoActionErrors();

    $retirement = ConstructionUnitRetirement::query()->sole();

    $tab->assertActionHidden(TestAction::make('retireUnit')->table())
        ->assertActionVisible(TestAction::make('reactivateUnit')->table($retirement))
        ->assertTableColumnStateSet('situation', 'Vigente', $retirement)
        ->callAction(TestAction::make('reactivateUnit')->table($retirement), [
            'reactivated_on' => $reactivatedOn,
            'reactivation_reason' => 'Baixa registrada por engano: a unidade existe.',
        ])
        ->assertHasNoActionErrors()
        ->assertTableColumnStateSet('situation', $situation, $retirement->fresh())
        ->assertActionHidden(TestAction::make('reactivateUnit')->table($retirement->fresh()))
        ->assertActionVisible(TestAction::make('retireUnit')->table());

    expect($retirement->fresh()->reactivated_on->toDateString())->toBe($reactivatedOn);
})->with([
    'reativação' => ['2026-09-01', 'Encerrada'],
    'anulação' => ['2026-08-10', 'Anulada'],
]);

it('shows the situation on the record, its subtitle and the list, and filters the retired units', function () {
    $retired = retirementUiUnit();
    $active = DerivationFixture::unit($retired->construction, '306');
    ConstructionUnitRetirement::factory()->forUnit($retired)->retiredOn('2026-08-10')->create();

    $this->actingAs(makeAdminUser());

    $page = Livewire::test(ViewConstructionUnit::class, ['record' => $retired->getRouteKey()])
        ->assertSeeInOrder(['Situação', 'Baixada desde 10/08/2026', 'Não compõe o Quadro de Vendas. Veja a aba Baixas.']);

    expect($page->instance()->getSubheading())->toBe('CRI Horizonte · Residencial Horizonte · Bloco 01 · Unidade 305 · Baixada desde 10/08/2026');

    Livewire::test(ViewConstructionUnit::class, ['record' => $active->getRouteKey()])
        ->assertSeeInOrder(['Situação', 'Ativa'])
        ->assertDontSee('Baixada desde');

    Livewire::test(ListConstructionUnits::class)
        ->assertTableColumnStateSet('situation', 'Baixada', $retired)
        ->assertTableColumnStateSet('situation', 'Ativa', $active)
        ->filterTable('situation', 'retired')
        ->assertCanSeeTableRecords([$retired])
        ->assertCanNotSeeTableRecords([$active])
        ->filterTable('situation', 'active')
        ->assertCanSeeTableRecords([$active])
        ->assertCanNotSeeTableRecords([$retired]);
});

it('refuses to delete a retired unit, pointing to the retirement tab', function () {
    $unit = retirementUiUnit();
    ConstructionUnitRetirement::factory()->forUnit($unit)->create();

    $this->actingAs(makeAdminUser());

    expect(ConstructionUnitResource::getDeleteAuthorizationResponse($unit)->message())
        ->toBe('A unidade não pode ser excluída: tem baixa registrada. Para que ela deixe de compor o Quadro de Vendas, registre a baixa na aba "Baixas" da unidade.');
});

it('shows reason, author and reactivation in "Ver motivo", and stops rendering it once the view permission is gone', function () {
    $unit = retirementUiUnit();
    $author = User::factory()->create(['name' => 'Gestora Responsável']);
    $retirement = ConstructionUnitRetirement::factory()
        ->forUnit($unit)
        ->retiredOn('2026-08-10')
        ->retiredBy($author)
        ->reactivatedOn('2026-09-01', 'A unidade existe no memorial de incorporação.')
        ->create();

    $viewer = retirementUiUser(['constructions.view']);
    $this->actingAs($viewer);

    $tab = retirementsTab($unit)
        ->mountAction(TestAction::make('viewReason')->table($retirement))
        ->assertMountedActionModalSee(RETIREMENT_UI_REASON)
        ->assertMountedActionModalSee('Gestora Responsável')
        ->assertMountedActionModalSee('A unidade existe no memorial de incorporação.')
        ->assertMountedActionModalSee('A partir de 01/09/2026');

    $viewer->revokePermissionTo('constructions.view');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs($viewer->fresh());

    $tab->call('$refresh')->assertForbidden();

    expect(ConstructionUnitRetirementsRelationManager::canViewForRecord($unit, ViewConstructionUnit::class))->toBeFalse();
});

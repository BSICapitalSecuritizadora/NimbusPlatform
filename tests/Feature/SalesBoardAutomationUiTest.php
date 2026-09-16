<?php

use App\Filament\Resources\SalesBoardAutomationTargets\Pages\ListSalesBoardAutomationTargets;
use App\Filament\Resources\SalesBoardAutomationTargets\SalesBoardAutomationTargetResource;
use App\Models\SalesBoardAutomationTarget;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SalesBoards\AutomationFixture;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(makeAdminUser());
    AutomationFixture::disable();
});

it('shows what the automation could not do', function () {
    $blocked = AutomationFixture::blockedConstruction();
    AutomationFixture::enable([$blocked]);
    AutomationFixture::run();

    Livewire::test(ListSalesBoardAutomationTargets::class)
        ->assertOk()
        ->assertCanSeeTableRecords(SalesBoardAutomationTarget::query()->get())
        ->assertSee('Bloqueado pela fonte')
        ->assertSee('UNIT_VALUE_MISSING')
        ->assertSee('08/2026');
});

it('says whether the scheduler has run at all', function () {
    Livewire::test(ListSalesBoardAutomationTargets::class)
        ->assertOk()
        ->assertSee('Nenhuma execução registrada');

    $construction = AutomationFixture::readyConstruction();
    AutomationFixture::enable([$construction]);
    AutomationFixture::run();

    Livewire::test(ListSalesBoardAutomationTargets::class)
        ->assertOk()
        ->assertSee('Última execução em')
        ->assertSee('competência limite 08/2026');
});

it('says the automation is switched off instead of pretending the scheduler died', function () {
    Livewire::test(ListSalesBoardAutomationTargets::class)
        ->assertOk()
        ->assertSee('Automação desligada')
        ->assertSee('Nenhuma execução registrada');

    $construction = AutomationFixture::readyConstruction();
    AutomationFixture::enable([$construction]);

    Livewire::test(ListSalesBoardAutomationTargets::class)
        ->assertOk()
        ->assertDontSee('Automação desligada');
});

it('separates what needs action from what is done', function () {
    $ready = AutomationFixture::readyConstruction('1');
    $blocked = AutomationFixture::blockedConstruction('2');
    AutomationFixture::enable([$ready, $blocked]);
    AutomationFixture::run();

    Livewire::test(ListSalesBoardAutomationTargets::class)
        ->assertOk()
        ->assertCountTableRecords(1)
        ->set('activeTab', 'satisfeitos')
        ->assertCountTableRecords(1)
        ->set('activeTab', 'todos')
        ->assertCountTableRecords(2);
});

it('offers no way to create, edit or delete operational state', function () {
    $construction = AutomationFixture::readyConstruction();
    AutomationFixture::enable([$construction]);
    AutomationFixture::run();

    $target = SalesBoardAutomationTarget::query()->sole();

    expect(SalesBoardAutomationTargetResource::canCreate())->toBeFalse()
        ->and(SalesBoardAutomationTargetResource::canEdit($target))->toBeFalse()
        ->and(SalesBoardAutomationTargetResource::canDelete($target))->toBeFalse()
        ->and(SalesBoardAutomationTargetResource::canDeleteAny())->toBeFalse()
        ->and(array_keys(SalesBoardAutomationTargetResource::getPages()))->toBe(['index']);
});

it('denies the screen to a user without sales board permission', function () {
    $this->actingAs(User::factory()->create());

    expect(SalesBoardAutomationTargetResource::canViewAny())->toBeFalse();
});

it('explains the empty state instead of looking broken', function () {
    Livewire::test(ListSalesBoardAutomationTargets::class)
        ->assertOk()
        ->assertSee('Nenhuma competência sob automação')
        ->assertSee('automação nasce desligada');
});

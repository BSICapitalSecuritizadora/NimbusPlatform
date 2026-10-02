<?php

use App\Filament\Resources\SalesBoardAutomationTargets\Pages\ListSalesBoardAutomationTargets;
use App\Filament\Resources\SalesBoardAutomationTargets\SalesBoardAutomationTargetResource;
use App\Models\SalesBoardAutomationRun;
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

it('shows how long the last run took on the automation screen', function () {
    SalesBoardAutomationRun::factory()->create(['duration_ms' => 125_000]);

    Livewire::test(ListSalesBoardAutomationTargets::class)
        ->assertOk()
        ->assertSee('durou 2 min 05 s');

    SalesBoardAutomationRun::factory()->create(['started_at' => now()->addMinute(), 'duration_ms' => 840]);

    Livewire::test(ListSalesBoardAutomationTargets::class)
        ->assertOk()
        ->assertSee('durou 0,8 s');
});

it('shows the reminder policy in force, item by item', function () {
    Livewire::test(ListSalesBoardAutomationTargets::class)
        ->assertOk()
        ->assertSee('Avisos: bloqueio pela fonte no mesmo dia; falha técnica a partir de 3 falhas seguidas; '
            .'posição pronta para a construtora no mesmo dia; validação da construtora — lembrete com 5 dias e escalação com 10; '
            .'análise da Gestão — lembrete com 3 dias e escalação com 7 (dias corridos). Execução interrompida, suspensão por escopo '
            .'e automação encerrada por liquidação são avisadas sempre.');

    // Um item desligado -- por `off` ou por valor ilegível -- aparece como tal.
    config()->set('sales_board.automation.reminders.builder_review_escalation_after_days', null);

    Livewire::test(ListSalesBoardAutomationTargets::class)
        ->assertOk()
        ->assertSee('Avisos: bloqueio pela fonte no mesmo dia; falha técnica a partir de 3 falhas seguidas; '
            .'posição pronta para a construtora no mesmo dia; validação da construtora — lembrete com 5 dias e escalação desligada; '
            .'análise da Gestão — lembrete com 3 dias e escalação com 7 (dias corridos).');
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

<?php

use App\Filament\Pages\Settings;
use App\Filament\Pages\SpreadsheetTemplates;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function makeSettingsSuperAdminUser(): User
{
    $user = User::factory()->withTwoFactor()->create([
        'email' => fake()->unique()->safeEmail(),
    ]);
    $user->assignRole('super-admin');
    $user->givePermissionTo(Permission::all());

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->fresh();
}

function makeSettingsRestrictedUser(array $permissions = []): User
{
    $user = User::factory()->withTwoFactor()->create([
        'email' => fake()->unique()->safeEmail(),
    ]);

    if (! empty($permissions)) {
        $user->givePermissionTo($permissions);
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->fresh();
}

it('allows super admin to view the settings hub with all three cards', function () {
    $superAdmin = makeSettingsSuperAdminUser();

    $this->actingAs($superAdmin)
        ->get(Settings::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Configurações')
        ->assertSee('Gerencie acessos, permissões e parâmetros administrativos do sistema.')
        ->assertSee('Acesso e Segurança')
        ->assertSee('Usuários')
        ->assertSee('Perfis de acesso')
        ->assertSee('Operação e Templates')
        ->assertSee('Templates de Planilhas')
        ->assertSee(UserResource::getUrl(panel: 'admin'))
        ->assertSee(RoleResource::getUrl(panel: 'admin'))
        ->assertSee(SpreadsheetTemplates::getUrl(panel: 'admin'));
});

it('shows only spreadsheet templates card when user only has settings.view permission', function () {
    $user = makeSettingsRestrictedUser(['settings.view']);

    $this->actingAs($user)
        ->get(Settings::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Operação e Templates')
        ->assertSee('Templates de Planilhas')
        ->assertSee(SpreadsheetTemplates::getUrl(panel: 'admin'))
        ->assertDontSee('Acesso e Segurança')
        ->assertDontSee('Usuários')
        ->assertDontSee('Perfis de acesso');
});

it('forbids access to settings hub when user has no administration permissions', function () {
    $user = makeSettingsRestrictedUser([]);

    $this->actingAs($user)
        ->get(Settings::getUrl(panel: 'admin'))
        ->assertForbidden();
});

it('registers correct navigation hierarchy for settings and its children', function () {
    expect(Settings::getNavigationGroup())->toBe('Administração')
        ->and(Settings::getNavigationParentItem())->toBeNull()
        ->and(Settings::getNavigationLabel())->toBe('Configurações')
        ->and(Settings::getNavigationSort())->toBe(90)
        ->and(UserResource::getNavigationGroup())->toBe('Administração')
        ->and(UserResource::getNavigationParentItem())->toBe('Configurações')
        ->and(UserResource::getNavigationSort())->toBe(91)
        ->and(RoleResource::getNavigationGroup())->toBe('Administração')
        ->and(RoleResource::getNavigationParentItem())->toBe('Configurações')
        ->and(RoleResource::getNavigationSort())->toBe(92)
        ->and(SpreadsheetTemplates::getNavigationGroup())->toBe('Administração')
        ->and(SpreadsheetTemplates::getNavigationParentItem())->toBe('Configurações')
        ->and(SpreadsheetTemplates::getNavigationSort())->toBe(93);
});

it('provides proper breadcrumbs on spreadsheet templates page linking back to settings hub', function () {
    $page = new SpreadsheetTemplates;
    $breadcrumbs = $page->getBreadcrumbs();

    expect($breadcrumbs)->toHaveKey(Settings::getUrl(panel: 'admin'))
        ->and($breadcrumbs[Settings::getUrl(panel: 'admin')])->toBe('Configurações')
        ->and($breadcrumbs)->toContain('Templates de Planilhas');
});

it('redirects portuguese routes to standard settings routes', function () {
    $superAdmin = makeSettingsSuperAdminUser();

    $this->actingAs($superAdmin)
        ->get('/admin/configuracoes')
        ->assertRedirect('/admin/settings');

    $this->actingAs($superAdmin)
        ->get('/admin/configuracoes/templates')
        ->assertRedirect('/admin/settings/templates');
});

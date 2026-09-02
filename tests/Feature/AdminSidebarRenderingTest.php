<?php

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

it('renders the admin sidebar without broken class attribute text in HTML', function () {
    $user = User::factory()->withTwoFactor()->create([
        'email' => 'admin-sidebar-test@bsicapital.com.br',
    ]);
    $user->assignRole('super-admin');
    $user->givePermissionTo(Permission::all());

    $response = $this->actingAs($user)->get('/admin');

    $response->assertOk();

    $content = $response->getContent();

    // Must NOT contain the broken textual artifact anywhere in the rendered page
    expect($content)->not->toContain('class="fi-sidebar-item-btn" >')
        ->and($content)->not->toContain('class="fi-sidebar-item-btn">')
        ->and($content)->not->toMatch('/<\!--\[if ENDBLOCK\]><\!\[endif\]-->\s*class="fi-sidebar-item-btn"/');

    // Must contain proper sidebar structure
    expect($content)->toContain('fi-sidebar')
        ->and($content)->toContain('fi-sidebar-header')
        ->and($content)->toContain('fi-sidebar-nav')
        ->and($content)->toContain('fi-sidebar-header-compact-logo-ctn')
        ->and($content)->toContain('fi-sidebar-header-toggle-ctn');
});

it('renders navigation items with properly formatted attributes and tooltips', function () {
    $user = User::factory()->withTwoFactor()->create([
        'email' => 'admin-nav-test@bsicapital.com.br',
    ]);
    $user->assignRole('super-admin');
    $user->givePermissionTo(Permission::all());

    $response = $this->actingAs($user)->get('/admin');

    $response->assertOk();

    $content = $response->getContent();

    // Check that items contain proper link buttons with classes and tooltips
    expect($content)->toContain('class="fi-sidebar-item-btn"')
        ->and($content)->toContain('x-tooltip.html="tooltip"')
        ->and($content)->toContain('fi-sidebar-item-icon-ctn')
        ->and($content)->toContain('fi-sidebar-item-label');
});

it('renders both expanded and collapsed logo elements cleanly in sidebar header without topbar duplication', function () {
    $user = User::factory()->withTwoFactor()->create([
        'email' => 'admin-logo-test@bsicapital.com.br',
    ]);
    $user->assignRole('super-admin');
    $user->givePermissionTo(Permission::all());

    $response = $this->actingAs($user)->get('/admin');

    $response->assertOk();

    $content = $response->getContent();

    // Expanded logo container and collapsed compact monogram container exist in sidebar
    expect($content)->toContain('fi-sidebar-header-logo-ctn')
        ->and($content)->toContain('fi-sidebar-header-compact-logo-ctn')
        ->and($content)->toContain('logo_sidebar.png')
        ->and($content)->toContain('fi-sidebar-compact-logo-img')
        ->and($content)->toContain('fi-sidebar-open-collapse-sidebar-btn')
        ->and($content)->toContain('fi-sidebar-close-collapse-sidebar-btn');

    // Must NOT contain duplicate collapse button container in topbar
    expect($content)->not->toContain('fi-topbar-collapse-sidebar-btn-ctn');

    // Topbar mobile buttons must be hidden on desktop (lg:hidden)
    expect($content)->toContain('fi-topbar-open-sidebar-btn lg:hidden')
        ->and($content)->toContain('fi-topbar-close-sidebar-btn lg:hidden');
});

it('renders the collapsed header controls with proper tooltips and accessibility attributes', function () {
    $user = User::factory()->withTwoFactor()->create([
        'email' => 'admin-collapsed-test@bsicapital.com.br',
    ]);
    $user->assignRole('super-admin');
    $user->givePermissionTo(Permission::all());

    $response = $this->actingAs($user)->get('/admin');

    $response->assertOk();

    $content = $response->getContent();

    expect($content)->toContain('Abrir barra lateral')
        ->and($content)->toContain('Recolher barra lateral')
        ->and($content)->toContain('fi-sidebar-compact-logo-link')
        ->and($content)->toContain('aria-label="BSI Capital"');
});

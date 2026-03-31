<?php

use App\Filament\Pages\Nimbus\NimbusDashboard;
use App\Filament\Resources\Nimbus\Submissions\SubmissionResource;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('registers a dedicated Nimbus dashboard route inside the admin panel', function () {
    $this->get(route('filament.admin.pages.nimbus-dashboard'))
        ->assertRedirect('/admin/login');
});

it('renders the Nimbus dashboard for authenticated admin users', function () {
    $user = User::factory()->withTwoFactor()->create([
        'email' => 'nimbus-admin@example.com',
    ]);
    $user->assignRole('admin');

    $this->actingAs($user)
        ->get(NimbusDashboard::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Visão Geral')
        ->assertSee('Dashboard')
        ->assertSee('Envios e Solicitações');
});

it('loads the application vite theme for the admin panel', function () {
    expect(Filament::getPanel('admin')->getViteTheme())
        ->toBe('resources/css/filament/admin/theme.css');
});

it('organizes NimbusDocs navigation under the Visão Geral subsection', function () {
    expect(NimbusDashboard::getNavigationParentItem())->toBe('Visão Geral')
        ->and(SubmissionResource::getNavigationParentItem())->toBe('Visão Geral')
        ->and(SubmissionResource::getNavigationLabel())->toBe('Envios e Solicitações')
        ->and(collect(Filament::getPanel('admin')->getNavigationItems())->first(fn ($item) => $item->getLabel() === 'Visão Geral'))
        ->not->toBeNull();
});

it('makes the Visão Geral subsection clickable', function () {
    $overviewItem = collect(Filament::getPanel('admin')->getNavigationItems())
        ->first(fn ($item) => $item->getLabel() === 'Visão Geral');

    expect($overviewItem)->not->toBeNull()
        ->and($overviewItem->getUrl())->toBe(NimbusDashboard::getUrl(panel: 'admin'));
});

it('renders the submissions list in Portuguese without exposing creation to internal users', function () {
    $user = User::factory()->withTwoFactor()->create([
        'email' => 'nimbus-submissions@example.com',
    ]);
    $user->assignRole('admin');

    expect(SubmissionResource::canCreate())->toBeFalse()
        ->and(SubmissionResource::hasPage('create'))->toBeFalse();

    $this->actingAs($user)
        ->get(SubmissionResource::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Envios e Solicitações')
        ->assertDontSee('Criar envio e solicitação')
        ->assertSee('Usuário do portal Nimbus')
        ->assertSee('Código de referência')
        ->assertSee('Tipo de envio')
        ->assertSee('Nome do responsável');
});

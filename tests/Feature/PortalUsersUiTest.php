<?php

use App\Filament\Resources\Nimbus\PortalUsers\Pages\ListPortalUsers;
use App\Filament\Resources\Nimbus\PortalUsers\PortalUserResource;
use App\Models\Nimbus\PortalUser;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->withTwoFactor()->create([
        'email' => 'admin-portal-users@bsicapital.com.br',
    ]);
    $this->admin->assignRole('admin');
});

it('renders portal users list page with subheading and dark cockpit layout', function () {
    $this->actingAs($this->admin)
        ->get(PortalUserResource::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Usuários do Portal')
        ->assertSee('Gerencie os usuários externos, seus dados de acesso e métodos de autenticação.')
        ->assertSee('bsi-cockpit-page')
        ->assertSee('bsi-portal-users-list-page')
        ->assertSee('Novo usuário');
});

it('renders quick status tabs with badges and real counts', function () {
    PortalUser::query()->create([
        'full_name' => 'Usuário Ativo',
        'email' => 'ativo@empresa.com.br',
        'status' => 'ACTIVE',
    ]);

    PortalUser::query()->create([
        'full_name' => 'Usuário Aguardando',
        'email' => 'aguardando@empresa.com.br',
        'status' => 'INVITED',
    ]);

    PortalUser::query()->create([
        'full_name' => 'Usuário Suspenso',
        'email' => 'suspenso@empresa.com.br',
        'status' => 'BLOCKED',
    ]);

    PortalUser::query()->create([
        'full_name' => 'Usuário Inativo',
        'email' => 'inativo@empresa.com.br',
        'status' => 'INACTIVE',
    ]);

    Livewire::actingAs($this->admin)
        ->test(ListPortalUsers::class)
        ->assertSee('Todos')
        ->assertSee('Ativos')
        ->assertSee('Aguardando Cadastro')
        ->assertSee('Suspensos')
        ->assertSee('Inativos')
        ->set('activeTab', 'ativos')
        ->assertCanSeeTableRecords([PortalUser::where('status', 'ACTIVE')->first()])
        ->assertCanNotSeeTableRecords([PortalUser::where('status', 'INVITED')->first()]);
});

it('formats user identity, masked CPF, phone and authentication methods properly', function () {
    $portalUser = PortalUser::query()->create([
        'full_name' => 'Ana Clara Menezes',
        'email' => 'ana.clara@bsicapital.com.br',
        'document_number' => '12345678909',
        'phone_number' => '11987654321',
        'status' => 'ACTIVE',
        'last_login_at' => now()->setDate(2026, 8, 27)->setTime(14, 32),
        'last_login_method' => 'ACCESS_CODE',
        'external_id' => 'EXT-998877',
    ]);

    Livewire::actingAs($this->admin)
        ->test(ListPortalUsers::class)
        ->assertCanSeeTableRecords([$portalUser])
        ->assertSee('Ana Clara Menezes')
        ->assertSee('ana.clara@bsicapital.com.br')
        ->assertSee('123.456.789-09')
        ->assertSee('(11) 98765-4321')
        ->assertSee('Ativo')
        ->assertSee('27/08/2026 · 14:32')
        ->assertSee('Chave de Acesso');
});

it('displays placeholder for user who has never logged in', function () {
    $neverLoggedIn = PortalUser::query()->create([
        'full_name' => 'Carlos Nunca Logou',
        'email' => 'carlos.nunca@bsicapital.com.br',
        'status' => 'INVITED',
        'last_login_at' => null,
        'last_login_method' => null,
    ]);

    Livewire::actingAs($this->admin)
        ->test(ListPortalUsers::class)
        ->assertCanSeeTableRecords([$neverLoggedIn])
        ->assertSee('Nunca acessou')
        ->assertSee('Aguardando Cadastro');
});

it('has action group with generate token and edit actions', function () {
    $portalUser = PortalUser::query()->create([
        'full_name' => 'Juliana Rocha',
        'email' => 'juliana.rocha@bsicapital.com.br',
        'status' => 'ACTIVE',
    ]);

    Livewire::actingAs($this->admin)
        ->test(ListPortalUsers::class)
        ->assertTableActionExists('generate_token')
        ->assertTableActionExists('edit');
});

it('renders clean empty state when no portal users exist', function () {
    Livewire::actingAs($this->admin)
        ->test(ListPortalUsers::class)
        ->assertSee('Nenhum usuário do portal cadastrado')
        ->assertSee('Cadastre um usuário para disponibilizar acesso aos recursos do portal.');
});

it('renders create portal user form with full-width cockpit layout and 2-column balanced grid', function () {
    $this->actingAs($this->admin)
        ->get(PortalUserResource::getUrl('create', panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Novo Usuário')
        ->assertSee('Cadastre um usuário externo e configure suas informações de acesso ao portal.')
        ->assertSee('bsi-cockpit-page')
        ->assertSee('bsi-portal-user-form-page')
        ->assertSee('Dados Cadastrais')
        ->assertSee('Status da Conta')
        ->assertSee('Nome Completo')
        ->assertSee('E-mail')
        ->assertSee('CPF')
        ->assertSee('Telefone/Celular')
        ->assertSee('Situação')
        ->assertSee('Criar usuário')
        ->assertSee('Salvar e criar outro')
        ->assertSee('Cancelar');
});

it('renders edit portal user form with full-width cockpit layout and subheading', function () {
    $portalUser = PortalUser::query()->create([
        'full_name' => 'Eduardo Ferreira',
        'email' => 'eduardo.ferreira@bsicapital.com.br',
        'status' => 'ACTIVE',
    ]);

    $this->actingAs($this->admin)
        ->get(PortalUserResource::getUrl('edit', ['record' => $portalUser], panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Editar Usuário do Portal')
        ->assertSee('Atualize os dados cadastrais e o status de acesso do usuário no portal.')
        ->assertSee('bsi-cockpit-page')
        ->assertSee('bsi-portal-user-form-page')
        ->assertSee('Dados Cadastrais')
        ->assertSee('Status da Conta');
});

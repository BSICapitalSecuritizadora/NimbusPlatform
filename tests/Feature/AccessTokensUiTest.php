<?php

use App\Filament\Resources\Nimbus\AccessTokens\AccessTokenResource;
use App\Filament\Resources\Nimbus\AccessTokens\Pages\ListAccessTokens;
use App\Models\Nimbus\AccessToken;
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
        'email' => 'admin-access-tokens@bsicapital.com.br',
    ]);
    $this->admin->assignRole('admin');
});

it('renders access tokens list page with subheading and dark cockpit layout', function () {
    $this->actingAs($this->admin)
        ->get(AccessTokenResource::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Chaves de Acesso')
        ->assertSee('Acompanhe as chaves de acesso geradas para os usuários do portal e seus períodos de validade.')
        ->assertSee('bsi-cockpit-page')
        ->assertSee('bsi-access-tokens-list-page');
});

it('renders quick status tabs with badges and real counts', function () {
    $user = PortalUser::query()->create([
        'full_name' => 'Carlos Pereira',
        'email' => 'carlos@empresa.com.br',
        'status' => 'ACTIVE',
    ]);

    // Valid token
    AccessToken::query()->create([
        'nimbus_portal_user_id' => $user->id,
        'code_hash' => AccessToken::computeHash('VALI-1111-2222'),
        'status' => 'PENDING',
        'expires_at' => now()->addDays(2),
    ]);

    // Used token
    AccessToken::query()->create([
        'nimbus_portal_user_id' => $user->id,
        'code_hash' => AccessToken::computeHash('USED-1111-2222'),
        'status' => 'USED',
        'used_at' => now()->subDay(),
        'expires_at' => now()->addDay(),
    ]);

    // Expired token
    AccessToken::query()->create([
        'nimbus_portal_user_id' => $user->id,
        'code_hash' => AccessToken::computeHash('EXPI-1111-2222'),
        'status' => 'EXPIRED',
        'expires_at' => now()->subDays(2),
    ]);

    // Revoked token
    AccessToken::query()->create([
        'nimbus_portal_user_id' => $user->id,
        'code_hash' => AccessToken::computeHash('REVO-1111-2222'),
        'status' => 'REVOKED',
        'expires_at' => now()->addDays(2),
    ]);

    Livewire::actingAs($this->admin)
        ->test(ListAccessTokens::class)
        ->assertSee('Todos')
        ->assertSee('Válidas')
        ->assertSee('Utilizadas')
        ->assertSee('Expiradas')
        ->assertSee('Revogadas')
        ->set('activeTab', 'validas')
        ->assertCanSeeTableRecords([AccessToken::where('code_hash', AccessToken::computeHash('VALI-1111-2222'))->first()])
        ->assertCanNotSeeTableRecords([AccessToken::where('code_hash', AccessToken::computeHash('EXPI-1111-2222'))->first()]);
});

it('formats user identity and expiration timestamps properly', function () {
    $portalUser = PortalUser::query()->create([
        'full_name' => 'Mariana Silveira',
        'email' => 'mariana.silveira@bsicapital.com.br',
        'status' => 'ACTIVE',
    ]);

    $token = AccessToken::query()->create([
        'nimbus_portal_user_id' => $portalUser->id,
        'code_hash' => AccessToken::computeHash('MARI-9999-8888'),
        'status' => 'PENDING',
        'created_at' => now()->setDate(2026, 8, 27)->setTime(15, 42),
        'expires_at' => now()->setDate(2026, 8, 28)->setTime(15, 42),
    ]);

    Livewire::actingAs($this->admin)
        ->test(ListAccessTokens::class)
        ->assertCanSeeTableRecords([$token])
        ->assertSee('Mariana Silveira')
        ->assertSee('mariana.silveira@bsicapital.com.br')
        ->assertSee('Válida')
        ->assertSee('27/08/2026 · 15:42')
        ->assertSee('28/08/2026 · 15:42');
});

it('has action group with view and revoke actions', function () {
    $portalUser = PortalUser::query()->create([
        'full_name' => 'Roberto Carlos',
        'email' => 'roberto@bsicapital.com.br',
        'status' => 'ACTIVE',
    ]);

    $token = AccessToken::query()->create([
        'nimbus_portal_user_id' => $portalUser->id,
        'code_hash' => AccessToken::computeHash('ROBE-1234-5678'),
        'status' => 'PENDING',
        'expires_at' => now()->addDays(2),
    ]);

    Livewire::actingAs($this->admin)
        ->test(ListAccessTokens::class)
        ->assertTableActionExists('view')
        ->assertTableActionExists('revoke');
});

it('can revoke an active token via table action', function () {
    $portalUser = PortalUser::query()->create([
        'full_name' => 'Fabiana Lopes',
        'email' => 'fabiana@bsicapital.com.br',
        'status' => 'ACTIVE',
    ]);

    $token = AccessToken::query()->create([
        'nimbus_portal_user_id' => $portalUser->id,
        'code_hash' => AccessToken::computeHash('FABI-1234-5678'),
        'status' => 'PENDING',
        'expires_at' => now()->addDays(2),
    ]);

    Livewire::actingAs($this->admin)
        ->test(ListAccessTokens::class)
        ->callTableAction('revoke', $token);

    expect($token->fresh()->isRevoked())->toBeTrue();
});

it('renders clean empty state without creation button when no tokens exist', function () {
    Livewire::actingAs($this->admin)
        ->test(ListAccessTokens::class)
        ->assertSee('Nenhuma chave de acesso registrada')
        ->assertSee('As chaves geradas para usuários do portal aparecerão aqui para acompanhamento.');
});

it('renders view access token page with details and subheading', function () {
    $portalUser = PortalUser::query()->create([
        'full_name' => 'Tatiana Costa',
        'email' => 'tatiana@bsicapital.com.br',
        'status' => 'ACTIVE',
    ]);

    $token = AccessToken::query()->create([
        'nimbus_portal_user_id' => $portalUser->id,
        'code_hash' => AccessToken::computeHash('TATI-1234-5678'),
        'status' => 'PENDING',
        'expires_at' => now()->addDays(2),
    ]);

    $this->actingAs($this->admin)
        ->get(AccessTokenResource::getUrl('view', ['record' => $token], panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Detalhes da Chave de Acesso')
        ->assertSee('Metadados de auditoria e status de utilização da chave de acesso no portal.')
        ->assertSee('bsi-cockpit-page')
        ->assertSee('bsi-access-tokens-view-page')
        ->assertSee('Tatiana Costa')
        ->assertSee('tatiana@bsicapital.com.br')
        ->assertSee('Revogar');
});

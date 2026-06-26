<?php

use App\Filament\Resources\IndexRates\Pages\ListIndexRates;
use App\Models\IndexRate;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    // Uma requisição por sincronização e sem espera de backoff, para testes rápidos e determinísticos.
    config(['pu_indexes.bcb.chunk_months' => 240, 'pu_indexes.bcb.retries' => 1, 'pu_indexes.bcb.retry_sleep_ms' => 0]);
});

function actingAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    test()->actingAs($admin);

    return $admin;
}

it('shows sync actions for an admin', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);

    Livewire::test(ListIndexRates::class)
        ->assertOk()
        ->assertActionVisible('syncCdi')
        ->assertActionVisible('syncIpca');
});

it('hides sync actions from users without pu.index.sync', function () {
    // Pode visualizar a tela (pu.dashboard.view), mas não tem pu.index.sync.
    $user = User::factory()->create();
    $user->givePermissionTo('pu.dashboard.view');
    $this->actingAs($user);

    Livewire::test(ListIndexRates::class)
        ->assertOk()
        ->assertActionHidden('syncCdi')
        ->assertActionHidden('syncIpca');
});

it('syncs IPCA without blocking and notifies success when records are created', function () {
    actingAdmin();

    Http::fake(['api.bcb.gov.br/*' => Http::response([
        ['data' => '01/01/2024', 'valor' => '0.50'],
    ], 200)]);

    Livewire::test(ListIndexRates::class)
        ->callAction('syncIpca', ['dry_run' => false])
        ->assertHasNoActionErrors();

    Notification::assertNotified('Sincronização do IPCA concluída.');

    // Base âncora (2023-12) auto-criada + competência consultada (2024-01), sem bloqueio.
    expect(IndexRate::query()->where('indexer', 'IPCA')->count())->toBe(2)
        ->and(IndexRate::query()->where('indexer', 'IPCA')->whereDate('rate_date', '2024-01-01')->value('rate_value'))->toEqual('100.50000000');
});

it('notifies "no new records" when every IPCA row already exists', function () {
    actingAdmin();

    Http::fake(['api.bcb.gov.br/*' => Http::response([
        ['data' => '01/01/2024', 'valor' => '0.50'],
    ], 200)]);

    $component = Livewire::test(ListIndexRates::class);
    $component->callAction('syncIpca', ['dry_run' => false]);
    $component->callAction('syncIpca', ['dry_run' => false]);

    Notification::assertNotified('Sincronização do IPCA concluída — nenhum novo registro criado.');
});

it('notifies a Banco Central failure on a connection error', function () {
    actingAdmin();

    Http::fake(fn () => throw new ConnectionException('Operation timed out'));

    Livewire::test(ListIndexRates::class)
        ->callAction('syncIpca', ['dry_run' => false]);

    Notification::assertNotified('Falha na sincronização com o Banco Central.');

    expect(IndexRate::query()->where('indexer', 'IPCA')->count())->toBe(0);
});

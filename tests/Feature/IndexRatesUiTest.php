<?php

use App\Filament\Resources\IndexRates\IndexRateResource;
use App\Filament\Resources\IndexRates\Pages\ListIndexRates;
use App\Models\IndexRate;
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
        'email' => 'admin-index-rates@bsicapital.com.br',
    ]);
    $this->admin->assignRole('admin');
});

it('renders index rates list page with heading, subheading, and dark cockpit layout', function () {
    $this->actingAs($this->admin)
        ->get(IndexRateResource::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Índices (CDI/IPCA)')
        ->assertSee('Valores publicados e projetados utilizados nos cálculos e curvas da plataforma.')
        ->assertSee('bsi-cockpit-page')
        ->assertSee('bsi-index-rates-list-page');
});

it('renders sync overview widget with CDI and IPCA operational summary cards', function () {
    IndexRate::query()->create([
        'indexer' => 'CDI',
        'rate_date' => '2026-08-27',
        'rate_value' => '13.90000000',
        'source' => 'bcb_sgs',
        'external_series_code' => '4389',
        'is_projected' => false,
        'fetched_at' => now()->setDate(2026, 8, 27)->setTime(12, 2),
    ]);

    IndexRate::query()->create([
        'indexer' => 'IPCA',
        'rate_date' => '2026-08-01',
        'rate_value' => '6966.50000000',
        'source' => 'bcb_sgs',
        'external_series_code' => '433',
        'is_projected' => false,
        'fetched_at' => now()->setDate(2026, 8, 27)->setTime(12, 2),
    ]);

    $this->actingAs($this->admin)
        ->get(IndexRateResource::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Resumo de Sincronização')
        ->assertSee('Taxa CDI (DI over)')
        ->assertSee('IPCA (Preços ao Consumidor)')
        ->assertSee('Série SGS 4389')
        ->assertSee('Série SGS 433')
        ->assertSee('13,90000000 % a.a.')
        ->assertSee('6.966,50000000')
        ->assertSee('Sincronizado');
});

it('renders quick indexer tabs with badges and real counts', function () {
    IndexRate::query()->create([
        'indexer' => 'CDI',
        'rate_date' => '2026-08-27',
        'rate_value' => '13.90000000',
        'source' => 'bcb_sgs',
        'is_projected' => false,
    ]);

    IndexRate::query()->create([
        'indexer' => 'IPCA',
        'rate_date' => '2026-08-01',
        'rate_value' => '6966.50000000',
        'source' => 'bcb_sgs',
        'is_projected' => false,
    ]);

    IndexRate::query()->create([
        'indexer' => 'IPCA',
        'rate_date' => '2026-09-01',
        'rate_value' => '7010.20000000',
        'source' => 'anbima',
        'is_projected' => true,
    ]);

    Livewire::actingAs($this->admin)
        ->test(ListIndexRates::class)
        ->assertSee('Todos')
        ->assertSee('CDI')
        ->assertSee('IPCA')
        ->assertSee('Publicados')
        ->assertSee('Projetados')
        ->set('activeTab', 'cdi')
        ->assertCanSeeTableRecords([IndexRate::where('indexer', 'CDI')->first()])
        ->assertCanNotSeeTableRecords([IndexRate::where('indexer', 'IPCA')->first()]);
});

it('formats rate values with full precision and proper indexer suffixes', function () {
    $cdi = IndexRate::query()->create([
        'indexer' => 'CDI',
        'rate_date' => '2026-08-26',
        'rate_value' => '13.90000000',
        'source' => 'bcb_sgs',
        'is_projected' => false,
    ]);

    $ipca = IndexRate::query()->create([
        'indexer' => 'IPCA',
        'rate_date' => '2026-08-01',
        'rate_value' => '6966.50000000',
        'source' => 'bcb_sgs',
        'is_projected' => false,
    ]);

    Livewire::actingAs($this->admin)
        ->test(ListIndexRates::class)
        ->assertCanSeeTableRecords([$cdi, $ipca])
        ->assertSee('13,90000000 % a.a.')
        ->assertSee('6.966,50000000')
        ->assertSee('Publicado')
        ->assertSee('Banco Central');
});

it('groups sync, import and legacy calendar header actions properly', function () {
    Livewire::actingAs($this->admin)
        ->test(ListIndexRates::class)
        ->assertActionExists('syncCdi')
        ->assertActionExists('syncIpca')
        ->assertActionExists('importPublished')
        ->assertActionExists('importProjectedSeries')
        ->assertActionExists('seedBusinessCalendar');
});

it('renders clean technical empty state when no rates exist', function () {
    Livewire::actingAs($this->admin)
        ->test(ListIndexRates::class)
        ->assertSee('Nenhum índice registrado')
        ->assertSee('Sincronize ou importe índices econômicos para acompanhar seus valores históricos e projetados.');
});

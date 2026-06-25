<?php

use App\Models\Emission;
use App\Models\Expense;
use App\Models\Fund;
use App\Models\Negotiation;
use App\Models\Receivable;
use App\Models\SalesBoard;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('generates the monthly report PDF for an emission with data', function () {
    $this->actingAs(makeAdminUser());

    $emission = Emission::factory()->create([
        'current_pu' => '1011.03301900',
        'issued_quantity' => 10000,
        'integralized_quantity' => 10000,
    ]);

    Fund::factory()->create(['emission_id' => $emission->id]);

    Expense::factory()->create([
        'emission_id' => $emission->id,
        'period' => Expense::PERIOD_MONTHLY,
        'start_date' => '2026-01-01',
        'end_date' => null,
    ]);

    Receivable::factory()->create([
        'emission_id' => $emission->id,
        'reference_month' => '2026-05-01',
    ]);

    Negotiation::factory()->create([
        'emission_id' => $emission->id,
        'reference_month' => '2026-05-01',
    ]);

    SalesBoard::factory()->create([
        'emission_id' => $emission->id,
        'reference_month' => '2026-05-01',
    ]);

    $response = $this->get(route('admin.emissions.monthly-report.pdf', [
        'emission' => $emission->id,
        'reference_month' => '2026-05',
    ]));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
});

it('generates the report even when monthly data is missing', function () {
    $this->actingAs(makeAdminUser());

    $emission = Emission::factory()->create();

    $response = $this->get(route('admin.emissions.monthly-report.pdf', [
        'emission' => $emission->id,
        'reference_month' => '2026-05',
    ]));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
});

it('renders the reports page with the generation form', function () {
    $this->actingAs(makeAdminUser());

    Livewire::test(\App\Filament\Pages\Reports::class)
        ->assertOk()
        ->assertSee('Relatório Mensal por Emissão');
});

it('forbids users without the reports.view permission', function () {
    $user = User::factory()->withTwoFactor()->create();
    $user->assignRole('commercial-representative');
    $this->actingAs($user);

    $emission = Emission::factory()->create();

    $this->get(route('admin.emissions.monthly-report.pdf', [
        'emission' => $emission->id,
        'reference_month' => '2026-05',
    ]))->assertForbidden();
});

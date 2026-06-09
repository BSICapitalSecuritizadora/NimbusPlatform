<?php

use App\Filament\Resources\Negotiations\NegotiationResource;
use App\Filament\Resources\Negotiations\Pages\CreateNegotiation;
use App\Filament\Resources\Negotiations\Pages\EditNegotiation;
use App\Filament\Resources\Negotiations\Pages\ListNegotiations;
use App\Filament\Resources\Negotiations\Pages\ViewNegotiation;
use App\Filament\Resources\Negotiations\Schemas\NegotiationForm;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\Negotiation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('shows the create and view actions with the expected filters on the list page', function () {
    $this->actingAs(makeNegotiationAdminUser());

    [$emission, $construction] = makeNegotiationEmissionAndConstruction();
    $negotiation = Negotiation::factory()->forEmissionAndConstruction($emission, $construction)->create();

    Livewire::test(ListNegotiations::class)
        ->assertActionExists('create')
        ->assertActionHasLabel('create', 'Cadastrar Negociação')
        ->assertTableActionExists('view', null, $negotiation)
        ->assertTableActionHasLabel('view', 'Visualizar')
        ->assertTableActionHasUrl('view', NegotiationResource::getUrl('view', ['record' => $negotiation]), $negotiation)
        ->assertTableFilterExists('emission_id')
        ->assertTableFilterExists('construction_id')
        ->assertTableFilterExists('reference_month');
});

it('renders each negotiation form section on its own row', function () {
    $schema = NegotiationForm::configure(Schema::make(new CreateNegotiation));
    $sections = collect($schema->getComponents())
        ->filter(fn (mixed $component): bool => $component instanceof Section)
        ->mapWithKeys(fn (Section $section): array => [$section->getHeading() => $section]);

    expect($sections->keys()->all())->toBe([
        'Dados da Negociação',
        'Negociações do Mês',
    ]);
});

it('creates a monthly negotiation linked to emission and construction', function () {
    $this->actingAs(makeNegotiationAdminUser());

    [$emission, $construction] = makeNegotiationEmissionAndConstruction();

    Livewire::test(CreateNegotiation::class)
        ->fillForm([
            'emission_id' => $emission->id,
            'construction_id' => $construction->id,
            'reference_month' => '04/2026',
            'sales' => 12,
            'cancellations' => 2,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $negotiation = Negotiation::query()->first();

    expect($negotiation)->not->toBeNull()
        ->and($negotiation?->emission_id)->toBe($emission->id)
        ->and($negotiation?->construction_id)->toBe($construction->id)
        ->and($negotiation?->reference_month?->toDateString())->toBe('2026-04-01')
        ->and($negotiation?->sales)->toBe(12)
        ->and($negotiation?->cancellations)->toBe(2);
});

it('prevents duplicate negotiation records for the same monthly competency', function () {
    $this->actingAs(makeNegotiationAdminUser());

    [$emission, $construction] = makeNegotiationEmissionAndConstruction();

    Negotiation::factory()->forEmissionAndConstruction($emission, $construction)->create([
        'reference_month' => '2026-04-01',
    ]);

    Livewire::test(CreateNegotiation::class)
        ->fillForm([
            'emission_id' => $emission->id,
            'construction_id' => $construction->id,
            'reference_month' => '04/2026',
            'sales' => 1,
            'cancellations' => 1,
        ])
        ->call('create')
        ->assertHasFormErrors(['reference_month']);
});

it('requires a construction linked to the selected emission', function () {
    $this->actingAs(makeNegotiationAdminUser());

    $selectedEmission = Emission::factory()->create();
    $otherEmission = Emission::factory()->create();
    $constructionFromOtherEmission = Construction::factory()->create([
        'emission_id' => $otherEmission->id,
    ]);

    Livewire::test(CreateNegotiation::class)
        ->fillForm([
            'emission_id' => $selectedEmission->id,
            'construction_id' => $constructionFromOtherEmission->id,
            'reference_month' => '04/2026',
            'sales' => 1,
            'cancellations' => 1,
        ])
        ->call('create')
        ->assertHasFormErrors(['construction_id']);

    expect(Negotiation::query()->count())->toBe(0);
});

it('requires the negotiation mandatory fields', function () {
    $this->actingAs(makeNegotiationAdminUser());

    Livewire::test(CreateNegotiation::class)
        ->call('create')
        ->assertHasFormErrors([
            'emission_id' => 'required',
            'construction_id' => 'required',
            'reference_month' => 'required',
        ]);
});

it('shows the saved values on the read-only view page', function () {
    $this->actingAs(makeNegotiationAdminUser());

    [$emission, $construction] = makeNegotiationEmissionAndConstruction();
    $negotiation = Negotiation::factory()->forEmissionAndConstruction($emission, $construction)->create([
        'reference_month' => '2026-04-01',
        'sales' => 18,
        'cancellations' => 3,
    ]);

    Livewire::test(ViewNegotiation::class, [
        'record' => $negotiation->getRouteKey(),
    ])
        ->assertFormSet([
            'reference_month' => '04/2026',
            'sales' => 18,
            'cancellations' => 3,
        ]);
});

it('updates an existing negotiation', function () {
    $this->actingAs(makeNegotiationAdminUser());

    [$emission, $construction] = makeNegotiationEmissionAndConstruction();
    $negotiation = Negotiation::factory()->forEmissionAndConstruction($emission, $construction)->create([
        'reference_month' => '2026-04-01',
        'sales' => 10,
        'cancellations' => 1,
    ]);

    Livewire::test(EditNegotiation::class, [
        'record' => $negotiation->getRouteKey(),
    ])
        ->fillForm([
            'sales' => 14,
            'cancellations' => 4,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $negotiation->refresh();

    expect($negotiation->sales)->toBe(14)
        ->and($negotiation->cancellations)->toBe(4);
});

function makeNegotiationEmissionAndConstruction(): array
{
    $emission = Emission::factory()->create([
        'name' => 'CRI Negociações',
    ]);
    $construction = Construction::factory()->create([
        'emission_id' => $emission->id,
        'development_name' => 'Residencial Negociações',
    ]);

    return [$emission, $construction];
}

function makeNegotiationAdminUser(): User
{
    $user = User::factory()->withTwoFactor()->create([
        'email' => fake()->unique()->safeEmail(),
    ]);
    $user->assignRole('admin');

    return $user;
}

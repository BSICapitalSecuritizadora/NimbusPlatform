<?php

use App\Enums\ClientPersonType;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Pages\CreateClient;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Models\Client;
use App\Models\Contract;
use Database\Factories\ClientFactory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

const VALID_CPF = '52998224725';
const VALID_CPF_MASKED = '529.982.247-25';
const VALID_CNPJ = '11222333000181';
const VALID_CNPJ_MASKED = '11.222.333/0001-81';

function fillClientForm(array $overrides = []): array
{
    return [
        'person_type' => ClientPersonType::Individual->value,
        'name' => 'João da Silva',
        'document' => VALID_CPF,
        'email' => 'joao@email.com',
        'phone' => '21999999999',
        ...$overrides,
    ];
}

it('creates an individual with a valid cpf', function () {
    $this->actingAs(makeAdminUser());

    Livewire::test(CreateClient::class)
        ->fillForm(fillClientForm())
        ->call('create')
        ->assertHasNoFormErrors();

    $client = Client::query()->sole();

    expect($client->person_type)->toBe(ClientPersonType::Individual)
        ->and($client->name)->toBe('João da Silva')
        ->and($client->document)->toBe(VALID_CPF)
        ->and($client->email)->toBe('joao@email.com')
        ->and($client->phone)->toBe('21999999999')
        ->and($client->formatted_document)->toBe(VALID_CPF_MASKED)
        ->and($client->formatted_phone)->toBe('(21) 99999-9999');
});

it('creates a company with a valid cnpj and trade name', function () {
    $this->actingAs(makeAdminUser());

    Livewire::test(CreateClient::class)
        ->fillForm(fillClientForm([
            'person_type' => ClientPersonType::Company->value,
            'name' => 'Empresa Exemplo Ltda',
            'trade_name' => 'Exemplo',
            'document' => VALID_CNPJ,
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    $client = Client::query()->sole();

    expect($client->person_type)->toBe(ClientPersonType::Company)
        ->and($client->trade_name)->toBe('Exemplo')
        ->and($client->document)->toBe(VALID_CNPJ)
        ->and($client->formatted_document)->toBe(VALID_CNPJ_MASKED);
});

it('accepts documents typed with or without punctuation', function () {
    $this->actingAs(makeAdminUser());

    Livewire::test(CreateClient::class)
        ->fillForm(fillClientForm(['document' => VALID_CPF_MASKED]))
        ->call('create')
        ->assertHasNoFormErrors();

    Livewire::test(CreateClient::class)
        ->fillForm(fillClientForm([
            'person_type' => ClientPersonType::Company->value,
            'name' => 'Empresa Exemplo Ltda',
            'document' => VALID_CNPJ_MASKED,
            'email' => 'contato@empresa.com',
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Client::query()->pluck('document')->all())->toBe([VALID_CPF, VALID_CNPJ]);
});

it('rejects an invalid cpf for an individual', function () {
    $this->actingAs(makeAdminUser());

    Livewire::test(CreateClient::class)
        ->fillForm(fillClientForm(['document' => '12345678900']))
        ->call('create')
        ->assertHasFormErrors(['document' => 'Informe um CPF válido.']);

    expect(Client::query()->count())->toBe(0);
});

it('rejects an invalid cnpj for a company', function () {
    $this->actingAs(makeAdminUser());

    Livewire::test(CreateClient::class)
        ->fillForm(fillClientForm([
            'person_type' => ClientPersonType::Company->value,
            'document' => '12345678000199',
        ]))
        ->call('create')
        ->assertHasFormErrors(['document' => 'Informe um CNPJ válido.']);
});

it('rejects a cnpj informed for an individual', function () {
    $this->actingAs(makeAdminUser());

    Livewire::test(CreateClient::class)
        ->fillForm(fillClientForm(['document' => VALID_CNPJ]))
        ->call('create')
        ->assertHasFormErrors(['document' => 'Pessoa Física exige um CPF com 11 dígitos.']);
});

it('rejects a cpf informed for a company', function () {
    $this->actingAs(makeAdminUser());

    Livewire::test(CreateClient::class)
        ->fillForm(fillClientForm([
            'person_type' => ClientPersonType::Company->value,
            'document' => VALID_CPF,
        ]))
        ->call('create')
        ->assertHasFormErrors(['document' => 'Pessoa Jurídica exige um CNPJ com 14 dígitos.']);
});

it('rejects a document already registered', function () {
    $this->actingAs(makeAdminUser());

    Client::factory()->create(['document' => VALID_CPF]);

    Livewire::test(CreateClient::class)
        ->fillForm(fillClientForm(['email' => 'outro@email.com']))
        ->call('create')
        ->assertHasFormErrors(['document' => 'Já existe um cliente cadastrado com este CPF.']);

    expect(Client::query()->count())->toBe(1);
});

it('rejects a document belonging to a soft deleted client', function () {
    $this->actingAs(makeAdminUser());

    $client = Client::factory()->create(['document' => VALID_CPF]);
    $client->delete();

    Livewire::test(CreateClient::class)
        ->fillForm(fillClientForm())
        ->call('create')
        ->assertHasFormErrors([
            'document' => 'Já existe um cliente excluído cadastrado com este CPF. Restaure o cadastro existente em vez de criar um novo.',
        ]);

    expect(Client::query()->count())->toBe(0)
        ->and(Client::withTrashed()->count())->toBe(1);
});

it('lets a client keep its own document while editing', function () {
    $this->actingAs(makeAdminUser());

    $client = Client::factory()->create(['document' => VALID_CPF, 'name' => 'João da Silva']);

    Livewire::test(EditClient::class, ['record' => $client->getRouteKey()])
        ->assertFormSet(['document' => VALID_CPF_MASKED])
        ->fillForm(['name' => 'João da Silva Júnior'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($client->refresh()->name)->toBe('João da Silva Júnior');
});

it('keeps the client as a historic record when deleted', function () {
    $this->actingAs(makeAdminUser());

    $client = Client::factory()->create();
    $clientId = $client->getKey();

    $client->delete();

    expect(Client::query()->count())->toBe(0)
        ->and(Client::withTrashed()->whereKey($clientId)->exists())->toBeTrue();

    $client->restore();

    expect(Client::query()->whereKey($clientId)->exists())->toBeTrue();
});

it('searches clients by name, document with and without mask, email and phone', function () {
    $this->actingAs(makeAdminUser());

    $target = Client::factory()->create([
        'name' => 'João da Silva',
        'document' => VALID_CPF,
        'email' => 'joao@email.com',
        'phone' => '21999999999',
    ]);
    $other = Client::factory()->create([
        'name' => 'Maria Souza',
        'document' => ClientFactory::validCpf(),
        'email' => 'maria@email.com',
        'phone' => '11888887777',
    ]);

    $component = Livewire::test(ListClients::class);

    foreach (['João', VALID_CPF, VALID_CPF_MASKED, 'joao@email.com', '21999999999'] as $term) {
        $component->searchTable($term)
            ->assertCanSeeTableRecords([$target])
            ->assertCanNotSeeTableRecords([$other]);
    }
});

it('filters clients by person type', function () {
    $this->actingAs(makeAdminUser());

    $individual = Client::factory()->create();
    $company = Client::factory()->company()->create();

    Livewire::test(ListClients::class)
        ->filterTable('person_type', ClientPersonType::Individual->value)
        ->assertCanSeeTableRecords([$individual])
        ->assertCanNotSeeTableRecords([$company])
        ->filterTable('person_type', ClientPersonType::Company->value)
        ->assertCanSeeTableRecords([$company])
        ->assertCanNotSeeTableRecords([$individual]);
});

it('hides soft deleted clients from the default listing', function () {
    $this->actingAs(makeAdminUser());

    $active = Client::factory()->create();
    $deleted = Client::factory()->create();
    $deleted->delete();

    Livewire::test(ListClients::class)
        ->assertCanSeeTableRecords([$active])
        ->assertCanNotSeeTableRecords([$deleted]);
});

it('shows the client details on the view page', function () {
    $this->actingAs(makeAdminUser());

    $client = Client::factory()->create([
        'name' => 'João da Silva',
        'document' => VALID_CPF,
        'email' => 'joao@email.com',
        'phone' => '21999999999',
    ]);

    Livewire::test(ViewClient::class, ['record' => $client->getRouteKey()])
        ->assertSee('Dados Cadastrais')
        ->assertSee('Pessoa Física')
        ->assertSee('João da Silva')
        ->assertSee(VALID_CPF_MASKED)
        ->assertSee('joao@email.com')
        ->assertSee('(21) 99999-9999');
});

it('never writes the document in full to the activity log', function () {
    $this->actingAs(makeAdminUser());

    $client = Client::factory()->create(['document' => VALID_CPF]);

    $activity = Activity::query()->where('subject_type', Client::class)->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and(json_encode($activity->properties))->not->toContain(VALID_CPF)
        ->and($activity->properties['document_hash'])->not->toBeNull();

    expect($client->document)->toBe(VALID_CPF);
});

it('gates the resource behind the clients permissions', function () {
    $role = Role::firstOrCreate(['name' => 'clients-reader']);
    $role->syncPermissions([Permission::firstOrCreate(['name' => 'clients.view'])]);

    $reader = makeAdminUser();
    $reader->syncRoles([$role]);

    $this->actingAs($reader);

    $client = Client::factory()->create();

    expect(ClientResource::canViewAny())->toBeTrue()
        ->and(ClientResource::canCreate())->toBeFalse()
        ->and(ClientResource::canEdit($client))->toBeFalse()
        ->and(ClientResource::canDelete($client))->toBeFalse()
        ->and(ClientResource::canRestore($client))->toBeFalse()
        ->and(ClientResource::canForceDelete($client))->toBeFalse();
});

it('allows restoring only a trashed client and only with the permission', function () {
    $this->actingAs(makeAdminUser());

    $client = Client::factory()->create();

    expect(ClientResource::canRestore($client))->toBeFalse();

    $client->delete();

    expect(ClientResource::canRestore($client))->toBeTrue()
        ->and(ClientResource::canEdit($client))->toBeFalse();
});

it('denies the template download without the view permission', function () {
    $role = Role::firstOrCreate(['name' => 'no-clients']);
    $role->syncPermissions([]);

    $user = makeAdminUser();
    $user->syncRoles([$role]);

    $this->actingAs($user)
        ->get(route('admin.clients.template.download'))
        ->assertForbidden();
});

it('separates active, deleted and all clients into tabs', function () {
    $this->actingAs(makeAdminUser());

    $active = Client::factory()->create();
    $deleted = Client::factory()->create();
    $deleted->delete();

    $component = Livewire::test(ListClients::class);

    expect($component->instance()->activeTab)->toBe(ListClients::TAB_ACTIVE);

    $component
        ->assertCanSeeTableRecords([$active])
        ->assertCanNotSeeTableRecords([$deleted])
        ->set('activeTab', ListClients::TAB_TRASHED)
        ->assertCanSeeTableRecords([$deleted])
        ->assertCanNotSeeTableRecords([$active])
        ->set('activeTab', ListClients::TAB_ALL)
        ->assertCanSeeTableRecords([$active, $deleted]);
});

it('flags the deleted registration in the listing only where trashed clients show up', function () {
    $this->actingAs(makeAdminUser());

    $deleted = Client::factory()->create();
    $deleted->delete();

    Livewire::test(ListClients::class)
        ->assertTableColumnHidden('deleted_at')
        ->set('activeTab', ListClients::TAB_TRASHED)
        ->assertTableColumnVisible('deleted_at')
        ->assertTableColumnStateSet('deleted_at', 'Excluído', $deleted);
});

it('restores a deleted client from the listing without creating a new record', function () {
    $this->actingAs(makeAdminUser());

    $client = Client::factory()->create(['document' => VALID_CPF]);
    $contract = Contract::factory()->forClient($client)->create();
    $client->delete();

    Livewire::test(ListClients::class)
        ->set('activeTab', ListClients::TAB_TRASHED)
        ->callAction(TestAction::make('restore')->table($client))
        ->assertHasNoActionErrors();

    $restored = Client::query()->sole();

    expect(Client::withTrashed()->count())->toBe(1)
        ->and($restored->getKey())->toBe($client->getKey())
        ->and($restored->document)->toBe(VALID_CPF)
        ->and($restored->trashed())->toBeFalse()
        ->and($restored->contracts->pluck('id')->all())->toBe([$contract->id]);

    Livewire::test(ListClients::class)->assertCanSeeTableRecords([$restored]);
});

it('asks for confirmation before restoring a client', function () {
    $this->actingAs(makeAdminUser());

    $client = Client::factory()->create();
    $client->delete();

    Livewire::test(ListClients::class)
        ->set('activeTab', ListClients::TAB_TRASHED)
        ->mountAction(TestAction::make('restore')->table($client))
        ->assertActionMounted(
            TestAction::make('restore')
                ->table($client)
                ->arguments([]),
        );

    expect(Client::query()->count())->toBe(0);
});

it('restores several clients at once', function () {
    $this->actingAs(makeAdminUser());

    $first = Client::factory()->create();
    $second = Client::factory()->create();
    $untouched = Client::factory()->create();

    $first->delete();
    $second->delete();
    $untouched->delete();

    Livewire::test(ListClients::class)
        ->set('activeTab', ListClients::TAB_TRASHED)
        ->selectTableRecords([$first->getKey(), $second->getKey()])
        ->callAction(TestAction::make('restore')->table()->bulk())
        ->assertHasNoActionErrors();

    expect(Client::query()->pluck('id')->sort()->values()->all())->toBe([$first->id, $second->id])
        ->and($untouched->refresh()->trashed())->toBeTrue();
});

it('offers the restore actions only on the deleted clients tab and only with the permission', function () {
    $this->actingAs(makeAdminUser());

    $client = Client::factory()->create();
    $client->delete();

    Livewire::test(ListClients::class)
        ->set('activeTab', ListClients::TAB_ACTIVE)
        ->assertActionHidden(TestAction::make('restore')->table()->bulk())
        ->set('activeTab', ListClients::TAB_TRASHED)
        ->assertActionVisible(TestAction::make('restore')->table()->bulk())
        ->assertActionVisible(TestAction::make('restore')->table($client))
        ->assertActionHidden(TestAction::make('delete')->table()->bulk());

    $role = Role::firstOrCreate(['name' => 'clients-reader-only']);
    $role->syncPermissions([Permission::firstOrCreate(['name' => 'clients.view'])]);

    $reader = makeAdminUser();
    $reader->syncRoles([$role]);

    $this->actingAs($reader);

    Livewire::test(ListClients::class)
        ->set('activeTab', ListClients::TAB_TRASHED)
        ->assertCanSeeTableRecords([$client])
        ->assertActionHidden(TestAction::make('restore')->table()->bulk())
        ->assertActionHidden(TestAction::make('restore')->table($client));

    expect(Client::query()->count())->toBe(0);
});

it('restores a client from its view page', function () {
    $this->actingAs(makeAdminUser());

    $client = Client::factory()->create();
    $client->delete();

    Livewire::test(ViewClient::class, ['record' => $client->getRouteKey()])
        ->assertSee('Excluído em')
        ->callAction(TestAction::make('restore'))
        ->assertHasNoActionErrors();

    expect($client->refresh()->trashed())->toBeFalse();
});

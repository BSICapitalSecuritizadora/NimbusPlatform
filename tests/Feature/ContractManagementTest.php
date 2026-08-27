<?php

use App\Enums\ContractStatus;
use App\Filament\Resources\ConstructionUnits\ConstructionUnitResource;
use App\Filament\Resources\Contracts\ContractResource;
use App\Filament\Resources\Contracts\Pages\CreateContract;
use App\Filament\Resources\Contracts\Pages\EditContract;
use App\Filament\Resources\Contracts\Pages\ListContracts;
use App\Filament\Resources\Contracts\Pages\ViewContract;
use App\Models\Client;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\Contract;
use App\Models\ContractInstallment;
use App\Models\Emission;
use Database\Factories\ClientFactory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * Part of the `parity` group: everything here depends on how the database itself
 * behaves -- unique indexes, collation, generated columns, soft deletes against
 * uniqueness, and imports that resolve textual keys. The normal suite runs it on
 * SQLite; `composer test:parity` runs the same tests on MySQL, and both have to
 * agree. See scripts/parity-check.sh.
 */
pest()->group('parity');

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * @return array{0: Emission, 1: Construction, 2: ConstructionUnit, 3: Client}
 */
function contractScenario(): array
{
    [$emission, $construction] = unitEmissionAndConstruction();

    $unit = ConstructionUnit::factory()->forConstruction($construction)->create([
        'block' => '01',
        'unit' => '305',
    ]);

    $client = Client::factory()->create(['name' => 'João da Silva']);

    return [$emission, $construction, $unit, $client];
}

/**
 * @return array<string, mixed>
 */
function fillContractForm(Emission $emission, Construction $construction, ConstructionUnit $unit, Client $client, array $overrides = []): array
{
    return [
        'emission_id' => $emission->id,
        'construction_id' => $construction->id,
        'construction_unit_id' => $unit->id,
        'client_ids' => [$client->id],
        'code' => 'CVC-00123',
        'sale_date' => '2024-03-10',
        'sale_value' => '850.000,00',
        'status' => ContractStatus::Active->value,
        ...$overrides,
    ];
}

it('creates a contract linking the client to the unit', function () {
    $this->actingAs(makeAdminUser());

    [$emission, $construction, $unit, $client] = contractScenario();

    Livewire::test(CreateContract::class)
        ->fillForm(fillContractForm($emission, $construction, $unit, $client))
        ->call('create')
        ->assertHasNoFormErrors();

    $contract = Contract::query()->sole();

    expect($contract->buyerIds())->toBe([$client->id])
        ->and($contract->construction_unit_id)->toBe($unit->id)
        ->and($contract->code)->toBe('CVC-00123')
        ->and($contract->sale_date->toDateString())->toBe('2024-03-10')
        ->and((float) $contract->sale_value)->toBe(850000.00)
        ->and($contract->status)->toBe(ContractStatus::Active)
        ->and($contract->cancellation_date)->toBeNull();
});

it('derives the development from the unit instead of storing it twice', function () {
    [, $construction, $unit, $client] = contractScenario();

    $contract = Contract::factory()->forUnit($unit)->forClient($client)->create();

    expect($contract->construction_id)->toBe($construction->id)
        ->and($contract->construction->development_name)->toBe($construction->development_name)
        ->and($contract->construction->emission->name)->toBe('CRI Conviva');
});

it('relates the contract to its buyers and its unit', function () {
    [, , $unit, $client] = contractScenario();

    $contract = Contract::factory()->forUnit($unit)->forClient($client)->create();

    expect($contract->clients->pluck('id')->all())->toBe([$client->id])
        ->and($contract->constructionUnit->is($unit))->toBeTrue();
});

it('lists every contract of a client', function () {
    [, $construction, $unit, $client] = contractScenario();
    $otherUnit = ConstructionUnit::factory()->forConstruction($construction)->create(['block' => '02', 'unit' => '401']);

    Contract::factory()->forUnit($unit)->forClient($client)->create();
    Contract::factory()->forUnit($otherUnit)->forClient($client)->create();

    expect($client->contracts()->count())->toBe(2);
});

it('keeps the whole commercial history of a unit', function () {
    [, , $unit, $client] = contractScenario();
    $buyer = Client::factory()->create(['name' => 'Maria Oliveira']);

    Contract::factory()->forUnit($unit)->forClient($client)->cancelled()->create([
        'code' => 'CVC-00001',
        'sale_date' => '2024-03-10',
        'cancellation_date' => '2025-08-15',
    ]);
    Contract::factory()->forUnit($unit)->forClient($buyer)->create([
        'code' => 'CVC-00002',
        'sale_date' => '2025-10-20',
    ]);

    expect($unit->contracts()->count())->toBe(2)
        ->and($unit->contracts->pluck('code')->all())->toBe(['CVC-00002', 'CVC-00001'])
        ->and($unit->activeContract->code)->toBe('CVC-00002');
});

it('rejects a unit that does not belong to the selected development', function () {
    $this->actingAs(makeAdminUser());

    [$emission, $construction, , $client] = contractScenario();
    [, $otherConstruction] = unitEmissionAndConstruction('CRI Outro', 'Outro Empreendimento');
    $foreignUnit = ConstructionUnit::factory()->forConstruction($otherConstruction)->create(['block' => '09', 'unit' => '901']);

    Livewire::test(CreateContract::class)
        ->fillForm(fillContractForm($emission, $construction, $foreignUnit, $client))
        ->call('create')
        ->assertHasFormErrors(['construction_unit_id']);

    expect(Contract::query()->count())->toBe(0);
});

it('rejects a development that does not belong to the selected emission', function () {
    $this->actingAs(makeAdminUser());

    [$emission, , $unit, $client] = contractScenario();
    [, $otherConstruction] = unitEmissionAndConstruction('CRI Outro', 'Outro Empreendimento');

    Livewire::test(CreateContract::class)
        ->fillForm(fillContractForm($emission, $otherConstruction, $unit, $client))
        ->call('create')
        ->assertHasFormErrors(['construction_id']);

    expect(Contract::query()->count())->toBe(0);
});

it('rejects a distrato dated before the sale', function () {
    $this->actingAs(makeAdminUser());

    [$emission, $construction, $unit, $client] = contractScenario();

    Livewire::test(CreateContract::class)
        ->fillForm(fillContractForm($emission, $construction, $unit, $client, [
            'status' => ContractStatus::Cancelled->value,
            'sale_date' => '2025-03-10',
            'cancellation_date' => '2025-01-02',
        ]))
        ->call('create')
        ->assertHasFormErrors(['cancellation_date']);

    expect(Contract::query()->count())->toBe(0);
});

it('accepts a distrato on the very day of the sale', function () {
    $this->actingAs(makeAdminUser());

    [$emission, $construction, $unit, $client] = contractScenario();

    Livewire::test(CreateContract::class)
        ->fillForm(fillContractForm($emission, $construction, $unit, $client, [
            'status' => ContractStatus::Cancelled->value,
            'sale_date' => '2025-03-10',
            'cancellation_date' => '2025-03-10',
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Contract::query()->sole()->cancellation_date->toDateString())->toBe('2025-03-10');
});

it('requires the distrato date on a distratado contract', function () {
    $this->actingAs(makeAdminUser());

    [$emission, $construction, $unit, $client] = contractScenario();

    Livewire::test(CreateContract::class)
        ->fillForm(fillContractForm($emission, $construction, $unit, $client, [
            'status' => ContractStatus::Cancelled->value,
            'cancellation_date' => null,
        ]))
        ->call('create')
        ->assertHasFormErrors(['cancellation_date']);

    expect(Contract::query()->count())->toBe(0);
});

it('clears the distrato date when the contract is not distratado', function () {
    [, , $unit, $client] = contractScenario();

    $contract = Contract::factory()->forUnit($unit)->forClient($client)->create([
        'status' => ContractStatus::Active,
        'cancellation_date' => '2025-08-15',
    ]);

    expect($contract->fresh()->cancellation_date)->toBeNull();
});

it('rejects a second live contract for the same unit', function () {
    $this->actingAs(makeAdminUser());

    [$emission, $construction, $unit, $client] = contractScenario();
    Contract::factory()->forUnit($unit)->create(['code' => 'CVC-00001']);

    Livewire::test(CreateContract::class)
        ->fillForm(fillContractForm($emission, $construction, $unit, $client))
        ->call('create')
        ->assertHasFormErrors(['construction_unit_id']);

    expect(Contract::query()->count())->toBe(1);
});

it('rejects a live contract on a unit already settled', function () {
    $this->actingAs(makeAdminUser());

    [$emission, $construction, $unit, $client] = contractScenario();
    Contract::factory()->forUnit($unit)->settled()->create(['code' => 'CVC-00001']);

    Livewire::test(CreateContract::class)
        ->fillForm(fillContractForm($emission, $construction, $unit, $client))
        ->call('create')
        ->assertHasFormErrors(['construction_unit_id']);
});

it('records a permuta without asking for a distrato date', function () {
    $this->actingAs(makeAdminUser());

    [$emission, $construction, $unit, $client] = contractScenario();

    Livewire::test(CreateContract::class)
        ->fillForm(fillContractForm($emission, $construction, $unit, $client, [
            'status' => ContractStatus::Exchanged->value,
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    $contract = Contract::query()->sole();

    expect($contract->status)->toBe(ContractStatus::Exchanged)
        ->and($contract->cancellation_date)->toBeNull();
});

it('rejects a live contract on a unit already permutada', function () {
    $this->actingAs(makeAdminUser());

    [$emission, $construction, $unit, $client] = contractScenario();
    Contract::factory()->forUnit($unit)->exchanged()->create(['code' => 'CVC-00001']);

    Livewire::test(CreateContract::class)
        ->fillForm(fillContractForm($emission, $construction, $unit, $client))
        ->call('create')
        ->assertHasFormErrors(['construction_unit_id']);
});

it('lets the database refuse two live contracts even outside the form', function () {
    [, , $unit] = contractScenario();

    Contract::factory()->forUnit($unit)->create(['code' => 'CVC-00001']);

    expect(fn () => Contract::factory()->forUnit($unit)->create(['code' => 'CVC-00002']))
        ->toThrow(QueryException::class);
});

it('locks the unit in the database for every status the enum treats as live', function () {
    // The generated column spells the statuses out. If the enum grows a new one
    // that holds the unit, the column has to be rewritten by a migration -- this
    // is what says so out loud.
    foreach (ContractStatus::cases() as $status) {
        [, , $unit] = contractScenario();

        Contract::factory()->forUnit($unit)->create([
            'code' => 'CVC-'.$status->value,
            'status' => $status,
            'sale_date' => '2024-03-10',
            'cancellation_date' => $status->requiresCancellationDate() ? '2024-04-10' : null,
        ]);

        $second = fn () => Contract::factory()->forUnit($unit)->create([
            'code' => 'CVC-'.$status->value.'-2',
            'sale_date' => '2025-03-10',
        ]);

        $status->occupiesUnit()
            ? expect($second)->toThrow(QueryException::class)
            : expect($second())->toBeInstanceOf(Contract::class);
    }
});

it('allows a resale once the previous contract is distratado', function () {
    $this->actingAs(makeAdminUser());

    [$emission, $construction, $unit, $client] = contractScenario();
    $buyer = Client::factory()->create(['name' => 'Maria Oliveira']);

    Contract::factory()->forUnit($unit)->forClient($client)->cancelled()->create([
        'code' => 'CVC-00001',
        'sale_date' => '2024-03-10',
        'cancellation_date' => '2025-08-15',
    ]);

    Livewire::test(CreateContract::class)
        ->fillForm(fillContractForm($emission, $construction, $unit, $buyer, [
            'code' => 'CVC-00002',
            'sale_date' => '2025-10-20',
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Contract::query()->count())->toBe(2)
        ->and($unit->activeContract()->first()->buyerIds())->toBe([$buyer->id]);
});

/**
 * `occupied_unit_lock` says a unit is held by at most one contract *now*; it can
 * say nothing about a period that has already closed. The timeline says the same
 * thing about the whole history, and it has to hold on every way in -- a history
 * the monthly reconciliation refuses to write must not be reachable by typing it
 * by hand.
 */
describe('coerência temporal da unidade', function () {
    /**
     * A unit whose contract ran from 10/03/2024 to the distrato on 15/08/2026,
     * plus the next buyer.
     *
     * @return array{0: Emission, 1: Construction, 2: ConstructionUnit, 3: Client, 4: Contract}
     */
    function timelineScenario(): array
    {
        [$emission, $construction, $unit, $client] = contractScenario();
        $buyer = Client::factory()->create(['name' => 'Maria Oliveira']);

        $previous = Contract::factory()->forUnit($unit)->forClient($client)->create([
            'code' => 'CVC-00001',
            'sale_date' => '2024-03-10',
            'status' => ContractStatus::Cancelled,
            'cancellation_date' => '2026-08-15',
        ]);

        return [$emission, $construction, $unit, $buyer, $previous];
    }

    it('accepts a sale opened on the day the previous distrato took effect', function () {
        $this->actingAs(makeAdminUser());

        [$emission, $construction, $unit, $buyer] = timelineScenario();

        Livewire::test(CreateContract::class)
            ->fillForm(fillContractForm($emission, $construction, $unit, $buyer, [
                'code' => 'CVC-00002',
                'sale_date' => '2026-08-15',
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        expect($unit->activeContract()->first()->code)->toBe('CVC-00002');
    });

    it('refuses a sale opened before the previous distrato took effect', function () {
        $this->actingAs(makeAdminUser());

        [$emission, $construction, $unit, $buyer] = timelineScenario();

        Livewire::test(CreateContract::class)
            ->fillForm(fillContractForm($emission, $construction, $unit, $buyer, [
                'code' => 'CVC-00002',
                'sale_date' => '2026-08-05',
            ]))
            ->call('create')
            ->assertHasFormErrors(['sale_date']);

        expect(Contract::query()->count())->toBe(1);
    });

    it('refuses moving a distrato forward over a sale that already followed it', function () {
        $this->actingAs(makeAdminUser());

        [, , $unit, $buyer, $previous] = timelineScenario();

        Contract::factory()->forUnit($unit)->forClient($buyer)->create([
            'code' => 'CVC-00002',
            'sale_date' => '2026-08-20',
        ]);

        // Editing only the distrato date would swallow two days of a contract
        // that is already on record -- the same overlap seen from the other side.
        // The date is deliberately in the past, so only the timeline can refuse it.
        Livewire::test(EditContract::class, ['record' => $previous->getRouteKey()])
            ->fillForm(['cancellation_date' => '2026-08-22'])
            ->call('save')
            ->assertHasFormErrors(['cancellation_date']);

        expect($previous->fresh()->cancellation_date->toDateString())->toBe('2026-08-15');
    });

    it('still accepts moving a distrato within the gap before the next sale', function () {
        $this->actingAs(makeAdminUser());

        [, , $unit, $buyer, $previous] = timelineScenario();

        Contract::factory()->forUnit($unit)->forClient($buyer)->create([
            'code' => 'CVC-00002',
            'sale_date' => '2026-08-20',
        ]);

        Livewire::test(EditContract::class, ['record' => $previous->getRouteKey()])
            ->fillForm(['cancellation_date' => '2026-08-18'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($previous->fresh()->cancellation_date->toDateString())->toBe('2026-08-18');
    });

    it('refuses a distrato dated in the future', function () {
        $this->actingAs(makeAdminUser());

        [$emission, $construction, $unit, $buyer] = contractScenario();

        // Until the distrato happens the contract is ativo and holds its unit.
        // Recording one in advance would leave it released by the status and
        // still occupying by the timeline.
        Livewire::test(CreateContract::class)
            ->fillForm(fillContractForm($emission, $construction, $unit, $buyer, [
                'status' => ContractStatus::Cancelled->value,
                'cancellation_date' => now()->addMonth()->toDateString(),
            ]))
            ->call('create')
            ->assertHasFormErrors(['cancellation_date']);

        expect(Contract::query()->count())->toBe(0);
    });

    it('leaves an untouched pair of historical contracts alone', function () {
        $this->actingAs(makeAdminUser());

        [$emission, $construction, $unit, $buyer] = timelineScenario();

        // Two contracts already on record that overlap each other. Nobody is
        // editing them, so saving a third coherent contract is not the moment to
        // refuse an inconsistency this operation did not create.
        Contract::factory()->forUnit($unit)->create([
            'code' => 'CVC-00000',
            'sale_date' => '2024-06-01',
            'status' => ContractStatus::Cancelled,
            'cancellation_date' => '2025-01-01',
        ]);

        Livewire::test(CreateContract::class)
            ->fillForm(fillContractForm($emission, $construction, $unit, $buyer, [
                'code' => 'CVC-00002',
                'sale_date' => '2026-08-20',
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        expect($unit->activeContract()->first()->code)->toBe('CVC-00002');
    });
});

it('rejects a code already used inside the same development', function () {
    $this->actingAs(makeAdminUser());

    [$emission, $construction, $unit, $client] = contractScenario();
    $otherUnit = ConstructionUnit::factory()->forConstruction($construction)->create(['block' => '02', 'unit' => '402']);

    Contract::factory()->forUnit($otherUnit)->create(['code' => 'A606']);

    Livewire::test(CreateContract::class)
        ->fillForm(fillContractForm($emission, $construction, $unit, $client, ['code' => 'A606']))
        ->call('create')
        ->assertHasFormErrors(['code']);
});

describe('identity of the contract code', function () {
    it('derives the comparison value without touching what is displayed', function () {
        [, , $unit] = contractScenario();

        $contract = Contract::factory()->forUnit($unit)->create(['code' => 'Contrato-A01']);

        expect($contract->code)->toBe('Contrato-A01')
            ->and($contract->fresh()->code)->toBe('Contrato-A01')
            ->and($contract->code_normalized)->toBe('CONTRATO-A01');
    });

    it('normalizes trim, inner spacing and case, and leaves accents alone', function () {
        expect(Contract::normalizeCodeForComparison('  ctr-001  '))->toBe('CTR-001')
            ->and(Contract::normalizeCodeForComparison('Contrato   001'))->toBe('CONTRATO 001')
            ->and(Contract::normalizeCodeForComparison('a606'))->toBe('A606')
            ->and(Contract::normalizeCodeForComparison('1.12.04421394467'))->toBe('1.12.04421394467')
            ->and(Contract::normalizeCodeForComparison('   '))->toBeNull()
            ->and(Contract::normalizeCodeForComparison(null))->toBeNull()
            // Accents are not folded: these stay two different codes.
            ->and(Contract::normalizeCodeForComparison('SÉRIE-1'))
            ->not->toBe(Contract::normalizeCodeForComparison('SERIE-1'));
    });

    it('shares one rule with the installment number', function () {
        foreach (['  a606 ', 'Contrato   001', 'ENTRADA'] as $value) {
            expect(Contract::normalizeCodeForComparison($value))
                ->toBe(ContractInstallment::normalizeNumberForComparison($value));
        }
    });

    it('recalculates the identity when the code is edited', function () {
        $this->actingAs(makeAdminUser());

        [$emission, $construction, $unit, $client] = contractScenario();

        $contract = Contract::factory()->forUnit($unit)->forClient($client)->create(['code' => 'A606']);

        Livewire::test(EditContract::class, ['record' => $contract->getKey()])
            ->fillForm(['code' => ' ctr-001 '])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($contract->fresh()->code)->toBe('ctr-001')
            ->and($contract->fresh()->code_normalized)->toBe('CTR-001')
            ->and($emission->id)->toBeGreaterThan(0)
            ->and($construction->id)->toBeGreaterThan(0);
    });

    /**
     * These go straight through the model, so the unique index is the only thing
     * that can stop them. The results have to be identical on SQLite and MySQL,
     * which is the whole reason identity is decided in PHP and stored binary.
     */
    it('refuses two codes in one development that differ only by case', function () {
        [, $construction, $unit] = contractScenario();

        Contract::factory()->forUnit($unit)->create(['code' => 'A606']);

        $other = ConstructionUnit::factory()->forConstruction($construction)->create(['block' => '01', 'unit' => '402']);

        expect(fn () => Contract::factory()->forUnit($other)->create(['code' => 'a606']))
            ->toThrow(QueryException::class);
    });

    it('refuses two codes in one development that differ only by whitespace', function () {
        [, $construction, $unit] = contractScenario();

        Contract::factory()->forUnit($unit)->create(['code' => 'A606']);

        $other = ConstructionUnit::factory()->forConstruction($construction)->create(['block' => '01', 'unit' => '402']);

        expect(fn () => Contract::factory()->forUnit($other)->create(['code' => ' A606 ']))
            ->toThrow(QueryException::class);
    });

    it('allows the same identity in two different developments', function () {
        [, , $unitA] = contractScenario();

        [, $constructionB] = unitEmissionAndConstruction('CRI Bellevue', 'Alto Bellevue');
        $unitB = ConstructionUnit::factory()->forConstruction($constructionB)->create(['block' => 'A', 'unit' => '12']);

        Contract::factory()->forUnit($unitA)->create(['code' => 'A606']);
        $second = Contract::factory()->forUnit($unitB)->create(['code' => 'a606']);

        expect($second->exists)->toBeTrue()
            ->and(Contract::query()->where('code_normalized', 'A606')->count())->toBe(2);
    });

    /**
     * A contract code is part of the historical identity of a commercial
     * relationship, so a deleted A606 still owns A606 inside its development --
     * including against a differently cased new one.
     */
    it('keeps a soft deleted code reserved against a differently cased one', function () {
        [, $construction, $unit] = contractScenario();

        Contract::factory()->forUnit($unit)->create(['code' => 'A606'])->delete();

        $other = ConstructionUnit::factory()->forConstruction($construction)->create(['block' => '01', 'unit' => '402']);

        expect(fn () => Contract::factory()->forUnit($other)->create(['code' => 'a606']))
            ->toThrow(QueryException::class);
    });

    it('catches the case difference in the form, before the database has to', function () {
        $this->actingAs(makeAdminUser());

        [$emission, $construction, $unit, $client] = contractScenario();

        Contract::factory()->forUnit($unit)->create(['code' => 'A606']);

        $resaleUnit = ConstructionUnit::factory()->forConstruction($construction)->create(['block' => '01', 'unit' => '402']);

        Livewire::test(CreateContract::class)
            ->fillForm(fillContractForm($emission, $construction, $resaleUnit, $client, ['code' => ' a606 ']))
            ->call('create')
            ->assertHasFormErrors(['code']);

        expect(Contract::query()->count())->toBe(1);
    });

    it('lets a contract keep its own code while being edited', function () {
        $this->actingAs(makeAdminUser());

        [, , $unit] = contractScenario();

        $contract = Contract::factory()->forUnit($unit)->create(['code' => 'A606']);

        Livewire::test(EditContract::class, ['record' => $contract->getKey()])
            ->fillForm(['code' => 'a606'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($contract->fresh()->code)->toBe('a606')
            ->and($contract->fresh()->code_normalized)->toBe('A606');
    });
});

it('accepts the same code in a different development', function () {
    $this->actingAs(makeAdminUser());

    [$emission, $construction, $unit, $client] = contractScenario();
    [, $otherConstruction] = unitEmissionAndConstruction('CRI Outro', 'Outro Empreendimento');
    $otherUnit = ConstructionUnit::factory()->forConstruction($otherConstruction)->create(['block' => '01', 'unit' => '101']);

    Contract::factory()->forUnit($otherUnit)->create(['code' => 'A606']);

    Livewire::test(CreateContract::class)
        ->fillForm(fillContractForm($emission, $construction, $unit, $client, ['code' => 'A606']))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Contract::query()->where('code', 'A606')->count())->toBe(2);
});

it('keeps the contract code as text', function () {
    $this->actingAs(makeAdminUser());

    [$emission, $construction, $unit, $client] = contractScenario();

    Livewire::test(CreateContract::class)
        ->fillForm(fillContractForm($emission, $construction, $unit, $client, ['code' => '  A606  ']))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Contract::query()->sole()->code)->toBe('A606');
});

it('edits a contract without touching its history', function () {
    $this->actingAs(makeAdminUser());

    [$emission, $construction, $unit, $client] = contractScenario();
    $contract = Contract::factory()->forUnit($unit)->forClient($client)->create([
        'code' => 'CVC-00123',
        'sale_value' => 850000.00,
    ]);

    Livewire::test(EditContract::class, ['record' => $contract->getRouteKey()])
        ->assertFormSet([
            'emission_id' => $emission->id,
            'construction_id' => $construction->id,
            'construction_unit_id' => $unit->id,
            'client_ids' => [$client->id],
        ])
        ->fillForm(['sale_value' => '900.000,00'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect((float) $contract->fresh()->sale_value)->toBe(900000.00);
});

it('distrata a contract through the edit form', function () {
    $this->actingAs(makeAdminUser());

    [, , $unit, $client] = contractScenario();
    $contract = Contract::factory()->forUnit($unit)->forClient($client)->create(['sale_date' => '2024-03-10']);

    Livewire::test(EditContract::class, ['record' => $contract->getRouteKey()])
        ->fillForm([
            'status' => ContractStatus::Cancelled->value,
            'cancellation_date' => '2025-08-15',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $contract->refresh();

    expect($contract->status)->toBe(ContractStatus::Cancelled)
        ->and($contract->cancellation_date->toDateString())->toBe('2025-08-15')
        ->and($contract->occupiesUnit())->toBeFalse();
});

it('shows the contract without exposing the full document', function () {
    $this->actingAs(makeAdminUser());

    [, , $unit] = contractScenario();
    $client = Client::factory()->create([
        'name' => 'João da Silva',
        'document' => ClientFactory::validCpf(),
    ]);
    $contract = Contract::factory()->forUnit($unit)->forClient($client)->create(['code' => 'CVC-00123']);

    Livewire::test(ViewContract::class, ['record' => $contract->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('CVC-00123')
        ->assertSee('João da Silva')
        ->assertSee(Client::maskDocument($client->document))
        ->assertDontSee(Client::formatDocument($client->document));
});

it('searches contracts by code, client, document, unit and development', function () {
    $this->actingAs(makeAdminUser());

    [, $construction, $unit] = contractScenario();
    $client = Client::factory()->create(['name' => 'João da Silva']);
    Contract::factory()->forUnit($unit)->forClient($client)->create(['code' => 'CVC-00123']);

    $otherUnit = ConstructionUnit::factory()->forConstruction($construction)->create(['block' => '02', 'unit' => '402']);
    $otherClient = Client::factory()->create(['name' => 'Maria Oliveira']);
    Contract::factory()->forUnit($otherUnit)->forClient($otherClient)->create(['code' => 'CVC-00999']);

    foreach (['CVC-00123', 'João', $client->document, '305', 'Conviva Camboinhas', 'CRI Conviva'] as $term) {
        Livewire::test(ListContracts::class)
            ->searchTable($term)
            ->assertCanSeeTableRecords(Contract::query()->where('code', 'CVC-00123')->get());
    }

    Livewire::test(ListContracts::class)
        ->searchTable('Maria')
        ->assertCanNotSeeTableRecords(Contract::query()->where('code', 'CVC-00123')->get());
});

it('filters contracts by emission and status', function () {
    $this->actingAs(makeAdminUser());

    [$emission, , $unit] = contractScenario();
    $wanted = Contract::factory()->forUnit($unit)->create(['code' => 'CVC-00123']);

    [, $otherConstruction] = unitEmissionAndConstruction('CRI Outro', 'Outro Empreendimento');
    $otherUnit = ConstructionUnit::factory()->forConstruction($otherConstruction)->create(['block' => '01', 'unit' => '101']);
    $unwanted = Contract::factory()->forUnit($otherUnit)->cancelled()->create(['code' => 'CVC-00999']);

    Livewire::test(ListContracts::class)
        ->filterTable('emission', $emission->id)
        ->assertCanSeeTableRecords([$wanted])
        ->assertCanNotSeeTableRecords([$unwanted]);

    Livewire::test(ListContracts::class)
        ->filterTable('status', ContractStatus::Cancelled->value)
        ->assertCanSeeTableRecords([$unwanted])
        ->assertCanNotSeeTableRecords([$wanted]);
});

it('soft deletes a contract and frees the unit', function () {
    [, , $unit] = contractScenario();
    $contract = Contract::factory()->forUnit($unit)->create(['code' => 'CVC-00123']);

    $contract->delete();

    expect(Contract::query()->count())->toBe(0)
        ->and(Contract::withTrashed()->count())->toBe(1)
        ->and(Contract::occupyingContract($unit->id))->toBeNull();

    Contract::factory()->forUnit($unit)->create(['code' => 'CVC-00124']);

    expect(Contract::query()->count())->toBe(1);
});

it('never force deletes a contract from the interface', function () {
    [, , $unit] = contractScenario();
    $contract = Contract::factory()->forUnit($unit)->create();

    expect(ContractResource::canForceDelete($contract))->toBeFalse();
});

it('refuses to delete a unit that already carries contracts', function () {
    $this->actingAs(makeAdminUser());

    [, , $unit] = contractScenario();
    Contract::factory()->forUnit($unit)->create();

    expect(ConstructionUnitResource::canDelete($unit))->toBeFalse()
        ->and(fn () => $unit->delete())->toThrow(QueryException::class);
});

it('records contract changes in the activity log without personal data', function () {
    $this->actingAs(makeAdminUser());

    [, , $unit, $client] = contractScenario();
    $contract = Contract::factory()->forUnit($unit)->forClient($client)->create(['sale_date' => '2024-03-10']);

    $contract->update(['status' => ContractStatus::Cancelled, 'cancellation_date' => '2025-08-15']);

    $activity = Activity::query()->where('subject_type', Contract::class)->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->properties['attributes']['status'])->toBe(ContractStatus::Cancelled->value)
        ->and(json_encode($activity->properties))->not->toContain($client->document);
});

it('gates the resource behind the contract permissions', function () {
    $role = Role::firstOrCreate(['name' => 'contracts-reader']);
    $role->syncPermissions([Permission::firstOrCreate(['name' => 'contracts.view'])]);

    $reader = makeAdminUser();
    $reader->syncRoles([$role]);

    $this->actingAs($reader);

    expect(ContractResource::canViewAny())->toBeTrue()
        ->and(ContractResource::canCreate())->toBeFalse()
        ->and(ContractResource::canEdit(new Contract))->toBeFalse()
        ->and(ContractResource::canDelete(new Contract))->toBeFalse()
        ->and(ContractResource::canRestore(new Contract))->toBeFalse();
});

it('hides the contracts listing from a user without the view permission', function () {
    $role = Role::firstOrCreate(['name' => 'no-contracts']);
    $role->syncPermissions([Permission::firstOrCreate(['name' => 'clients.view'])]);

    $user = makeAdminUser();
    $user->syncRoles([$role]);

    $this->actingAs($user);

    expect(ContractResource::canViewAny())->toBeFalse();

    $this->get(ContractResource::getUrl('index'))->assertForbidden();
});

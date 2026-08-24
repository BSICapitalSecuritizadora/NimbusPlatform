<?php

use App\Enums\ContractInstallmentStatus;
use App\Filament\Resources\ContractInstallments\ContractInstallmentResource;
use App\Filament\Resources\ContractInstallments\Pages\CreateContractInstallment;
use App\Filament\Resources\ContractInstallments\Pages\EditContractInstallment;
use App\Filament\Resources\ContractInstallments\Pages\ListContractInstallments;
use App\Filament\Resources\Contracts\Pages\ViewContract;
use App\Filament\Resources\Contracts\RelationManagers\ContractInstallmentsRelationManager;
use App\Models\Client;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\Contract;
use App\Models\ContractInstallment;
use App\Models\Emission;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
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
 * A registered sale, which is the only thing an installment needs to exist.
 *
 * @return array{0: Emission, 1: Construction, 2: Contract}
 */
function installmentScenario(string $code = 'CVC-00123'): array
{
    [$emission, $construction] = unitEmissionAndConstruction();

    $unit = ConstructionUnit::factory()->forConstruction($construction)->create([
        'block' => '01',
        'unit' => '305',
    ]);

    $contract = Contract::factory()
        ->forUnit($unit)
        ->forClient(Client::factory()->create(['name' => 'João da Silva']))
        ->create(['code' => $code, 'sale_value' => 850000.00]);

    return [$emission, $construction, $contract];
}

/**
 * @return array<string, mixed>
 */
function fillInstallmentForm(array $overrides = []): array
{
    return [
        'number' => '001',
        'due_date' => '2026-01-10',
        'expected_value' => '10.000,00',
        'payment_date' => null,
        'paid_value' => null,
        'cancellation_date' => null,
        ...$overrides,
    ];
}

/**
 * Every derived state is asserted against a frozen clock. Reading "today" from
 * the machine would make these pass in August and fail in September.
 */
function freezeInstallmentClock(): void
{
    test()->travelTo('2026-08-21');
}

it('relates the installment to its contract and the contract to its schedule', function () {
    [, , $contract] = installmentScenario();

    $installments = ContractInstallment::factory()->count(3)->forContract($contract)->create();

    expect($contract->installments()->count())->toBe(3)
        ->and($installments->first()->contract->is($contract))->toBeTrue();
});

it('reaches the client and the unit through the contract instead of storing them', function () {
    [$emission, $construction, $contract] = installmentScenario();

    $installment = ContractInstallment::factory()->forContract($contract)->create();

    expect(Schema::hasColumn('contract_installments', 'client_id'))->toBeFalse()
        ->and(Schema::hasColumn('contract_installments', 'construction_unit_id'))->toBeFalse()
        ->and(Schema::hasColumn('contract_installments', 'construction_id'))->toBeFalse()
        ->and(Schema::hasColumn('contract_installments', 'emission_id'))->toBeFalse()
        ->and($installment->contract->client->name)->toBe('João da Silva')
        ->and($installment->contract->constructionUnit->unit)->toBe('305')
        ->and($installment->contract->construction->id)->toBe($construction->id)
        ->and($installment->contract->construction->emission_id)->toBe($emission->id);
});

it('never stores a status column: the state is always computed', function () {
    expect(Schema::hasColumn('contract_installments', 'status'))->toBeFalse()
        ->and(Schema::hasColumn('contract_installments', 'days_overdue'))->toBeFalse()
        ->and(Schema::hasColumn('contract_installments', 'outstanding_value'))->toBeFalse();
});

it('creates an installment from inside the contract without asking for the context again', function () {
    $this->actingAs(makeAdminUser());

    [, , $contract] = installmentScenario();

    Livewire::test(ContractInstallmentsRelationManager::class, [
        'ownerRecord' => $contract,
        'pageClass' => ViewContract::class,
    ])
        ->callAction(TestAction::make('create')->table(), fillInstallmentForm())
        ->assertHasNoActionErrors();

    $installment = ContractInstallment::query()->sole();

    expect($installment->contract_id)->toBe($contract->id)
        ->and($installment->number)->toBe('001')
        ->and($installment->due_date->toDateString())->toBe('2026-01-10')
        ->and((float) $installment->expected_value)->toBe(10000.00)
        ->and($installment->payment_date)->toBeNull()
        ->and($installment->paid_value)->toBeNull();
});

it('creates an installment from the global module by picking the contract', function () {
    $this->actingAs(makeAdminUser());

    [$emission, $construction, $contract] = installmentScenario();

    Livewire::test(CreateContractInstallment::class)
        ->fillForm(fillInstallmentForm([
            'emission_id' => $emission->id,
            'construction_id' => $construction->id,
            'contract_id' => $contract->id,
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(ContractInstallment::query()->sole()->contract_id)->toBe($contract->id);
});

it('keeps the installment number as text, preserving leading zeros and words', function () {
    [, , $contract] = installmentScenario();

    foreach (['001', '002', '12', 'ENTRADA', 'INTERMEDIÁRIA 01', 'CHAVES'] as $index => $number) {
        ContractInstallment::factory()->forContract($contract)->create([
            'number' => $number,
            'due_date' => '2026-0'.($index + 1).'-10',
        ]);
    }

    expect(ContractInstallment::query()->pluck('number')->all())
        ->toContain('001', '002', 'ENTRADA', 'INTERMEDIÁRIA 01', 'CHAVES');
});

it('trims the number without reformatting what the operator wrote', function () {
    [, , $contract] = installmentScenario();

    $installment = ContractInstallment::factory()->forContract($contract)->create(['number' => '  007  ']);

    expect($installment->number)->toBe('007');
});

it('rejects the same number twice inside one contract', function () {
    $this->actingAs(makeAdminUser());

    [, , $contract] = installmentScenario();

    ContractInstallment::factory()->forContract($contract)->create(['number' => '001']);

    Livewire::test(ContractInstallmentsRelationManager::class, [
        'ownerRecord' => $contract,
        'pageClass' => ViewContract::class,
    ])
        ->callAction(TestAction::make('create')->table(), fillInstallmentForm(['number' => '001']))
        ->assertHasActionErrors(['number']);

    expect(ContractInstallment::query()->count())->toBe(1);
});

it('lets the database refuse a repeated number even outside the form', function () {
    [, , $contract] = installmentScenario();

    ContractInstallment::factory()->forContract($contract)->create(['number' => '001']);

    expect(fn () => ContractInstallment::factory()->forContract($contract)->create(['number' => '001']))
        ->toThrow(QueryException::class);
});

describe('identity of the number', function () {
    it('derives the comparison value without touching what is displayed', function () {
        [, , $contract] = installmentScenario();

        $installment = ContractInstallment::factory()->forContract($contract)->create([
            'number' => 'Intermediária 01',
        ]);

        expect($installment->number)->toBe('Intermediária 01')
            ->and($installment->fresh()->number)->toBe('Intermediária 01')
            ->and($installment->number_normalized)->toBe('INTERMEDIÁRIA 01');
    });

    it('normalizes trim, inner spacing and case, and leaves accents alone', function () {
        expect(ContractInstallment::normalizeNumberForComparison('  Intermediária   01  '))->toBe('INTERMEDIÁRIA 01')
            ->and(ContractInstallment::normalizeNumberForComparison('entrada'))->toBe('ENTRADA')
            ->and(ContractInstallment::normalizeNumberForComparison(' ENTRADA '))->toBe('ENTRADA')
            ->and(ContractInstallment::normalizeNumberForComparison("CHAVES\t\n 01"))->toBe('CHAVES 01')
            // Spreadsheets bring non-breaking spaces along; they collapse too.
            ->and(ContractInstallment::normalizeNumberForComparison("ENTRADA\u{00A0}01"))->toBe('ENTRADA 01')
            ->and(ContractInstallment::normalizeNumberForComparison('001'))->toBe('001')
            ->and(ContractInstallment::normalizeNumberForComparison('   '))->toBeNull()
            ->and(ContractInstallment::normalizeNumberForComparison(null))->toBeNull()
            // Accents are not folded: these stay two different identifications.
            ->and(ContractInstallment::normalizeNumberForComparison('INTERMEDIARIA 01'))
            ->not->toBe(ContractInstallment::normalizeNumberForComparison('INTERMEDIÁRIA 01'));
    });

    it('is idempotent, so a value read back from the database keys to itself', function () {
        foreach (['  Intermediária   01  ', 'entrada', '001', "CHAVES\t01"] as $raw) {
            $once = ContractInstallment::normalizeNumberForComparison($raw);

            expect(ContractInstallment::normalizeNumberForComparison($once))->toBe($once);
        }
    });

    /**
     * The database is the barrier, not the form: these go straight through the
     * model so a unique violation is the only thing that can stop them. The
     * results must be identical on SQLite and on MySQL, which is the entire
     * point of deciding identity in PHP and storing it in a binary column.
     */
    it('refuses two numbers that differ only by case', function () {
        [, , $contract] = installmentScenario();

        ContractInstallment::factory()->forContract($contract)->create(['number' => 'ENTRADA']);

        expect(fn () => ContractInstallment::factory()->forContract($contract)->create(['number' => 'entrada']))
            ->toThrow(QueryException::class);
    });

    it('refuses two numbers that differ only by surrounding whitespace', function () {
        [, , $contract] = installmentScenario();

        ContractInstallment::factory()->forContract($contract)->create(['number' => 'Entrada']);

        expect(fn () => ContractInstallment::factory()->forContract($contract)->create(['number' => ' Entrada ']))
            ->toThrow(QueryException::class);
    });

    it('refuses two numbers that differ only by inner spacing', function () {
        [, , $contract] = installmentScenario();

        ContractInstallment::factory()->forContract($contract)->create(['number' => 'Intermediária 01']);

        expect(fn () => ContractInstallment::factory()->forContract($contract)->create(['number' => 'INTERMEDIÁRIA   01']))
            ->toThrow(QueryException::class);
    });

    it('keeps two numbers that differ by an accent as different installments', function () {
        [, , $contract] = installmentScenario();

        ContractInstallment::factory()->forContract($contract)->create(['number' => 'INTERMEDIÁRIA 01', 'due_date' => '2026-01-10']);
        $unaccented = ContractInstallment::factory()->forContract($contract)->create(['number' => 'INTERMEDIARIA 01', 'due_date' => '2026-02-10']);

        expect($unaccented->exists)->toBeTrue()
            ->and(ContractInstallment::query()->where('contract_id', $contract->id)->count())->toBe(2);
    });

    it('allows the same identity in two different contracts', function () {
        [, $construction, $contractA] = installmentScenario('CVC-00123');

        $unitB = ConstructionUnit::factory()->forConstruction($construction)->create(['block' => '01', 'unit' => '402']);
        $contractB = Contract::factory()->forUnit($unitB)->create(['code' => 'CVC-00124']);

        ContractInstallment::factory()->forContract($contractA)->create(['number' => 'Entrada']);
        $second = ContractInstallment::factory()->forContract($contractB)->create(['number' => 'entrada']);

        expect($second->exists)->toBeTrue()
            ->and(ContractInstallment::query()->where('number_normalized', 'ENTRADA')->count())->toBe(2);
    });

    it('catches the case difference in the form, before the database has to', function () {
        test()->actingAs(makeAdminUser());

        [, , $contract] = installmentScenario();

        ContractInstallment::factory()->forContract($contract)->create(['number' => 'ENTRADA']);

        Livewire::test(ContractInstallmentsRelationManager::class, [
            'ownerRecord' => $contract,
            'pageClass' => ViewContract::class,
        ])
            ->callAction(TestAction::make('create')->table(), fillInstallmentForm(['number' => ' entrada ']))
            ->assertHasActionErrors(['number']);

        expect(ContractInstallment::query()->count())->toBe(1);
    });

    it('lets an installment keep its own number while being edited', function () {
        test()->actingAs(makeAdminUser());

        [, , $contract] = installmentScenario();

        $installment = ContractInstallment::factory()->forContract($contract)->create([
            'number' => 'Entrada',
            'due_date' => '2026-01-10',
            'expected_value' => 10000,
        ]);

        Livewire::test(EditContractInstallment::class, ['record' => $installment->getKey()])
            ->fillForm(['number' => 'ENTRADA'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($installment->fresh()->number)->toBe('ENTRADA')
            ->and($installment->fresh()->number_normalized)->toBe('ENTRADA');
    });

    it('frees the identity again once a mistaken installment is deleted', function () {
        [, , $contract] = installmentScenario();

        ContractInstallment::factory()->forContract($contract)->create(['number' => 'Entrada'])->delete();

        $replacement = ContractInstallment::factory()->forContract($contract)->create(['number' => 'ENTRADA']);

        expect($replacement->exists)->toBeTrue();
    });
});

it('accepts the same number in two different contracts', function () {
    [, $construction, $contractA] = installmentScenario('CVC-00123');

    $unitB = ConstructionUnit::factory()->forConstruction($construction)->create(['block' => '01', 'unit' => '402']);
    $contractB = Contract::factory()->forUnit($unitB)->create(['code' => 'CVC-00124']);

    ContractInstallment::factory()->forContract($contractA)->create(['number' => '001']);
    ContractInstallment::factory()->forContract($contractB)->create(['number' => '001']);

    expect(ContractInstallment::query()->where('number', '001')->count())->toBe(2);
});

it('frees the number again once a mistaken installment is deleted', function () {
    [, , $contract] = installmentScenario();

    $mistake = ContractInstallment::factory()->forContract($contract)->create(['number' => '001']);
    $mistake->delete();

    $replacement = ContractInstallment::factory()->forContract($contract)->create(['number' => '001']);

    expect($replacement->exists)->toBeTrue()
        ->and(ContractInstallment::withTrashed()->where('number', '001')->count())->toBe(2)
        ->and(ContractInstallment::query()->where('number', '001')->count())->toBe(1);
});

it('rejects a payment date without a paid value', function () {
    $this->actingAs(makeAdminUser());

    [, , $contract] = installmentScenario();

    Livewire::test(ContractInstallmentsRelationManager::class, [
        'ownerRecord' => $contract,
        'pageClass' => ViewContract::class,
    ])
        ->callAction(TestAction::make('create')->table(), fillInstallmentForm(['payment_date' => '2026-01-10']))
        ->assertHasActionErrors(['paid_value']);

    expect(ContractInstallment::query()->count())->toBe(0);
});

it('rejects a paid value without a payment date', function () {
    $this->actingAs(makeAdminUser());

    [, , $contract] = installmentScenario();

    Livewire::test(ContractInstallmentsRelationManager::class, [
        'ownerRecord' => $contract,
        'pageClass' => ViewContract::class,
    ])
        ->callAction(TestAction::make('create')->table(), fillInstallmentForm(['paid_value' => '10.000,00']))
        ->assertHasActionErrors(['payment_date']);

    expect(ContractInstallment::query()->count())->toBe(0);
});

it('accepts a paid value above the expected one, for juros and multa', function () {
    $this->actingAs(makeAdminUser());

    [, , $contract] = installmentScenario();

    Livewire::test(ContractInstallmentsRelationManager::class, [
        'ownerRecord' => $contract,
        'pageClass' => ViewContract::class,
    ])
        ->callAction(TestAction::make('create')->table(), fillInstallmentForm([
            'payment_date' => '2026-02-15',
            'paid_value' => '10.850,00',
        ]))
        ->assertHasNoActionErrors();

    $installment = ContractInstallment::query()->sole();

    expect((float) $installment->paid_value)->toBe(10850.00)
        ->and($installment->status)->toBe(ContractInstallmentStatus::Paid)
        ->and($installment->outstanding_value)->toBe(0.0);
});

describe('derived status', function () {
    beforeEach(fn () => freezeInstallmentClock());

    it('reads an installment with no receipt and a future due date as a vencer', function () {
        [, , $contract] = installmentScenario();

        $installment = ContractInstallment::factory()->forContract($contract)->create([
            'due_date' => '2026-09-10',
            'expected_value' => 10000,
        ]);

        expect($installment->status)->toBe(ContractInstallmentStatus::Upcoming)
            ->and($installment->outstanding_value)->toBe(10000.00)
            ->and($installment->days_overdue)->toBe(0);
    });

    it('reads a fully received installment as paga', function () {
        [, , $contract] = installmentScenario();

        $installment = ContractInstallment::factory()->forContract($contract)->create([
            'due_date' => '2026-01-10',
            'expected_value' => 10000,
            'payment_date' => '2026-01-10',
            'paid_value' => 10000,
        ]);

        expect($installment->status)->toBe(ContractInstallmentStatus::Paid)
            ->and($installment->outstanding_value)->toBe(0.0)
            ->and($installment->days_overdue)->toBe(0);
    });

    it('reads a partially received installment still in time as parcialmente paga', function () {
        [, , $contract] = installmentScenario();

        $installment = ContractInstallment::factory()->forContract($contract)->create([
            'due_date' => '2026-09-10',
            'expected_value' => 10000,
            'payment_date' => '2026-08-01',
            'paid_value' => 4000,
        ]);

        expect($installment->status)->toBe(ContractInstallmentStatus::PartiallyPaid)
            ->and($installment->outstanding_value)->toBe(6000.00)
            ->and($installment->days_overdue)->toBe(0);
    });

    it('reads a partially received installment past its due date as em atraso', function () {
        [, , $contract] = installmentScenario();

        $installment = ContractInstallment::factory()->forContract($contract)->create([
            'due_date' => '2026-08-10',
            'expected_value' => 10000,
            'payment_date' => '2026-08-05',
            'paid_value' => 4000,
        ]);

        expect($installment->status)->toBe(ContractInstallmentStatus::Overdue)
            ->and($installment->outstanding_value)->toBe(6000.00)
            ->and($installment->days_overdue)->toBe(11);
    });

    it('reads an unpaid installment past its due date as em atraso', function () {
        [, , $contract] = installmentScenario();

        $installment = ContractInstallment::factory()->forContract($contract)->create([
            'due_date' => '2026-08-10',
            'expected_value' => 10000,
        ]);

        expect($installment->status)->toBe(ContractInstallmentStatus::Overdue)
            ->and($installment->days_overdue)->toBe(11);
    });

    it('treats an installment due today as still in time', function () {
        [, , $contract] = installmentScenario();

        $installment = ContractInstallment::factory()->forContract($contract)->create([
            'due_date' => '2026-08-21',
            'expected_value' => 10000,
        ]);

        expect($installment->status)->toBe(ContractInstallmentStatus::Upcoming)
            ->and($installment->days_overdue)->toBe(0);
    });

    it('keeps a cancelled installment out of atraso even when it is long overdue', function () {
        [, , $contract] = installmentScenario();

        $installment = ContractInstallment::factory()->forContract($contract)->create([
            'due_date' => '2025-01-10',
            'expected_value' => 10000,
            'cancellation_date' => '2025-02-01',
        ]);

        expect($installment->status)->toBe(ContractInstallmentStatus::Cancelled)
            ->and($installment->days_overdue)->toBe(0);
    });

    it('lets cancelamento win over a receipt that once landed on the installment', function () {
        [, , $contract] = installmentScenario();

        $installment = ContractInstallment::factory()->forContract($contract)->create([
            'due_date' => '2026-01-10',
            'expected_value' => 10000,
            'payment_date' => '2026-01-10',
            'paid_value' => 10000,
            'cancellation_date' => '2026-03-01',
        ]);

        expect($installment->status)->toBe(ContractInstallmentStatus::Cancelled);
    });

    it('never lets the saldo go negative when the receipt carries juros', function () {
        [, , $contract] = installmentScenario();

        $installment = ContractInstallment::factory()->forContract($contract)->create([
            'due_date' => '2026-08-10',
            'expected_value' => 10000,
            'payment_date' => '2026-08-20',
            'paid_value' => 10850,
        ]);

        expect($installment->outstanding_value)->toBe(0.0)
            ->and($installment->status)->toBe(ContractInstallmentStatus::Paid);
    });

    it('settles an installment paid to the exact cent', function () {
        [, , $contract] = installmentScenario();

        $installment = ContractInstallment::factory()->forContract($contract)->create([
            'due_date' => '2026-08-10',
            'expected_value' => 12500.50,
            'payment_date' => '2026-08-10',
            'paid_value' => 12500.50,
        ]);

        expect($installment->status)->toBe(ContractInstallmentStatus::Paid)
            ->and($installment->outstanding_value)->toBe(0.0);
    });

    it('leaves a saldo of one cent when the receipt is one cent short', function () {
        [, , $contract] = installmentScenario();

        $installment = ContractInstallment::factory()->forContract($contract)->create([
            'due_date' => '2026-09-10',
            'expected_value' => 12500.50,
            'payment_date' => '2026-08-10',
            'paid_value' => 12500.49,
        ]);

        expect($installment->status)->toBe(ContractInstallmentStatus::PartiallyPaid)
            ->and($installment->outstanding_value)->toBe(0.01);
    });

    it('filters by every derived status with the same rule the accessor applies', function () {
        [, , $contract] = installmentScenario();

        $upcoming = ContractInstallment::factory()->forContract($contract)->create(['number' => 'A', 'due_date' => '2026-09-10', 'expected_value' => 10000]);
        $overdue = ContractInstallment::factory()->forContract($contract)->create(['number' => 'B', 'due_date' => '2026-08-10', 'expected_value' => 10000]);
        $partial = ContractInstallment::factory()->forContract($contract)->create(['number' => 'C', 'due_date' => '2026-09-11', 'expected_value' => 10000, 'payment_date' => '2026-08-01', 'paid_value' => 4000]);
        $paid = ContractInstallment::factory()->forContract($contract)->create(['number' => 'D', 'due_date' => '2026-01-10', 'expected_value' => 10000, 'payment_date' => '2026-01-10', 'paid_value' => 10000]);
        $cancelled = ContractInstallment::factory()->forContract($contract)->create(['number' => 'E', 'due_date' => '2026-01-10', 'expected_value' => 10000, 'cancellation_date' => '2026-02-01']);

        $matches = fn (ContractInstallmentStatus $status): array => ContractInstallment::query()
            ->withStatus($status)
            ->pluck('id')
            ->all();

        expect($matches(ContractInstallmentStatus::Upcoming))->toBe([$upcoming->id])
            ->and($matches(ContractInstallmentStatus::Overdue))->toBe([$overdue->id])
            ->and($matches(ContractInstallmentStatus::PartiallyPaid))->toBe([$partial->id])
            ->and($matches(ContractInstallmentStatus::Paid))->toBe([$paid->id])
            ->and($matches(ContractInstallmentStatus::Cancelled))->toBe([$cancelled->id]);
    });

    it('leaves cancelled installments out of the outstanding scope', function () {
        [, , $contract] = installmentScenario();

        ContractInstallment::factory()->forContract($contract)->create(['number' => 'A', 'due_date' => '2026-01-10', 'expected_value' => 10000, 'cancellation_date' => '2026-02-01']);
        $owed = ContractInstallment::factory()->forContract($contract)->create(['number' => 'B', 'due_date' => '2026-08-10', 'expected_value' => 10000]);

        expect(ContractInstallment::query()->outstanding()->pluck('id')->all())->toBe([$owed->id]);
    });
});

it('summarises the schedule of the contract, ignoring the cancelled installments', function () {
    freezeInstallmentClock();

    [, , $contract] = installmentScenario();

    ContractInstallment::factory()->forContract($contract)->create(['number' => '001', 'due_date' => '2026-01-10', 'expected_value' => 500000, 'payment_date' => '2026-01-10', 'paid_value' => 300000]);
    ContractInstallment::factory()->forContract($contract)->create(['number' => '002', 'due_date' => '2026-09-10', 'expected_value' => 350000]);
    ContractInstallment::factory()->forContract($contract)->create(['number' => '003', 'due_date' => '2026-10-10', 'expected_value' => 90000, 'cancellation_date' => '2026-05-01']);

    $summary = $contract->installmentsSummary();

    expect($summary['count'])->toBe(2)
        ->and($summary['expected'])->toBe(850000.00)
        ->and($summary['paid'])->toBe(300000.00)
        ->and($summary['outstanding'])->toBe(550000.00)
        ->and($summary['difference'])->toBe(0.0);
});

it('flags a schedule that does not add up to the sale value without changing anything', function () {
    [, , $contract] = installmentScenario();

    ContractInstallment::factory()->forContract($contract)->create(['number' => '001', 'due_date' => '2026-01-10', 'expected_value' => 845000]);

    $summary = $contract->installmentsSummary();

    expect($summary['difference'])->toBe(-5000.00)
        ->and((float) $contract->fresh()->sale_value)->toBe(850000.00)
        ->and(ContractInstallment::query()->count())->toBe(1);
});

it('shows the schedule and its summary on the contract page', function () {
    freezeInstallmentClock();

    $this->actingAs(makeAdminUser());

    [, , $contract] = installmentScenario();

    ContractInstallment::factory()->forContract($contract)->create(['number' => '001', 'due_date' => '2026-01-10', 'expected_value' => 10000, 'payment_date' => '2026-01-10', 'paid_value' => 10000]);
    ContractInstallment::factory()->forContract($contract)->create(['number' => '003', 'due_date' => '2026-03-10', 'expected_value' => 10000, 'payment_date' => '2026-03-10', 'paid_value' => 4000]);

    Livewire::test(ViewContract::class, ['record' => $contract->getKey()])
        ->assertSuccessful();

    Livewire::test(ContractInstallmentsRelationManager::class, [
        'ownerRecord' => $contract,
        'pageClass' => ViewContract::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords(ContractInstallment::query()->get())
        ->assertSee('001')
        ->assertSee('Em atraso');
});

it('edits an installment and records the change in the activity log', function () {
    $this->actingAs(makeAdminUser());

    [, , $contract] = installmentScenario();

    $installment = ContractInstallment::factory()->forContract($contract)->create([
        'number' => '001',
        'due_date' => '2026-01-10',
        'expected_value' => 10000,
    ]);

    Livewire::test(EditContractInstallment::class, ['record' => $installment->getKey()])
        ->fillForm([
            'due_date' => '2026-02-10',
            'expected_value' => '12.500,50',
            'payment_date' => '2026-02-12',
            'paid_value' => '12.900,00',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $installment->refresh();

    expect($installment->due_date->toDateString())->toBe('2026-02-10')
        ->and((float) $installment->expected_value)->toBe(12500.50)
        ->and((float) $installment->paid_value)->toBe(12900.00);

    $activity = Activity::query()
        ->where('subject_type', ContractInstallment::class)
        ->where('event', 'updated')
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->properties['old']['due_date'])->toStartWith('2026-01-10')
        ->and($activity->properties['attributes']['due_date'])->toStartWith('2026-02-10')
        ->and((float) $activity->properties['old']['expected_value'])->toBe(10000.00)
        ->and((float) $activity->properties['attributes']['expected_value'])->toBe(12500.50)
        ->and($activity->properties['attributes'])->toHaveKeys(['payment_date', 'paid_value'])
        // The buyer lives on the contract; no personal data reaches the log.
        ->and($activity->properties['attributes'])->not->toHaveKeys(['client_id', 'document']);
});

it('records the cancellation of an installment in the activity log', function () {
    [, , $contract] = installmentScenario();

    $installment = ContractInstallment::factory()->forContract($contract)->create(['number' => '001']);
    $installment->update(['cancellation_date' => '2026-05-01']);

    $activity = Activity::query()
        ->where('subject_type', ContractInstallment::class)
        ->where('event', 'updated')
        ->latest('id')
        ->sole();

    expect($activity->properties['attributes']['cancellation_date'])->toStartWith('2026-05-01');
});

it('soft deletes an installment instead of erasing the financial history', function () {
    $this->actingAs(makeAdminUser());

    [, , $contract] = installmentScenario();

    $installment = ContractInstallment::factory()->forContract($contract)->create(['number' => '001']);

    Livewire::test(EditContractInstallment::class, ['record' => $installment->getKey()])
        ->callAction('delete');

    expect(ContractInstallment::query()->count())->toBe(0)
        ->and(ContractInstallment::withTrashed()->count())->toBe(1)
        ->and($installment->fresh()->trashed())->toBeTrue();
});

it('never force deletes an installment from the interface', function () {
    [, , $contract] = installmentScenario();

    $installment = ContractInstallment::factory()->forContract($contract)->create();

    expect(ContractInstallmentResource::canForceDelete($installment))->toBeFalse();
});

it('refuses to delete a contract that already carries installments', function () {
    [, , $contract] = installmentScenario();

    ContractInstallment::factory()->forContract($contract)->create();

    expect(fn () => $contract->forceDelete())->toThrow(QueryException::class);
});

it('filters the global listing by emission, contract and derived status', function () {
    freezeInstallmentClock();

    $this->actingAs(makeAdminUser());

    [$emission, , $contractA] = installmentScenario('CVC-00123');
    [$otherEmission, $otherConstruction] = unitEmissionAndConstruction('CRI Bellevue', 'Alto Bellevue');
    $otherUnit = ConstructionUnit::factory()->forConstruction($otherConstruction)->create(['block' => 'A', 'unit' => '12']);
    $contractB = Contract::factory()->forUnit($otherUnit)->create(['code' => 'ABV-001']);

    $overdue = ContractInstallment::factory()->forContract($contractA)->create(['number' => '001', 'due_date' => '2026-08-10', 'expected_value' => 10000]);
    $upcoming = ContractInstallment::factory()->forContract($contractA)->create(['number' => '002', 'due_date' => '2026-09-10', 'expected_value' => 10000]);
    $elsewhere = ContractInstallment::factory()->forContract($contractB)->create(['number' => '001', 'due_date' => '2026-08-10', 'expected_value' => 10000]);

    Livewire::test(ListContractInstallments::class)
        ->assertCanSeeTableRecords([$overdue, $upcoming, $elsewhere])
        ->filterTable('emission', $emission->id)
        ->assertCanSeeTableRecords([$overdue, $upcoming])
        ->assertCanNotSeeTableRecords([$elsewhere])
        ->filterTable('status', ContractInstallmentStatus::Overdue->value)
        ->assertCanSeeTableRecords([$overdue])
        ->assertCanNotSeeTableRecords([$upcoming, $elsewhere]);

    expect($otherEmission->id)->not->toBe($emission->id);
});

it('searches installments by number and by everything reachable from the contract', function () {
    $this->actingAs(makeAdminUser());

    [, , $contract] = installmentScenario();

    $installment = ContractInstallment::factory()->forContract($contract)->create(['number' => 'ENTRADA']);

    foreach (['ENTRADA', 'CVC-00123', 'João', '305', 'Conviva Camboinhas'] as $term) {
        expect(ContractInstallment::query()->search($term)->pluck('id')->all())
            ->toBe([$installment->id], "busca por \"{$term}\"");
    }
});

it('gates the module behind the installment permissions', function () {
    $limited = User::factory()->withTwoFactor()->create();
    $role = Role::firstOrCreate(['name' => 'parcelas-somente-leitura']);
    $role->syncPermissions([
        Permission::findByName('contracts.view'),
        Permission::findByName('contract-installments.view'),
    ]);
    $limited->assignRole($role);

    [, , $contract] = installmentScenario();
    $installment = ContractInstallment::factory()->forContract($contract)->create();

    $this->actingAs($limited);

    expect(ContractInstallmentResource::canViewAny())->toBeTrue()
        ->and(ContractInstallmentResource::canCreate())->toBeFalse()
        ->and(ContractInstallmentResource::canEdit($installment))->toBeFalse()
        ->and(ContractInstallmentResource::canDelete($installment))->toBeFalse();
});

it('hides the installments listing from a user without the view permission', function () {
    $stranger = User::factory()->withTwoFactor()->create();
    $stranger->assignRole(Role::firstOrCreate(['name' => 'sem-parcelas']));

    $this->actingAs($stranger);

    expect(ContractInstallmentResource::canViewAny())->toBeFalse();

    $this->get(route('admin.contract-installments.template.download'))->assertForbidden();
});

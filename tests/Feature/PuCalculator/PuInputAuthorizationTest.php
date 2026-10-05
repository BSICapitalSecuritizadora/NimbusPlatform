<?php

use App\Domain\PuCalculator\Enums\PuAmortizationType;
use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Domain\PuCalculator\Enums\PuEventType;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\PuEventsRelationManager;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\PuHistoriesRelationManager;
use App\Filament\Resources\Emissions\Pages\EditEmission;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuEvent;
use App\Models\PuHistory;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Spatie\SimpleExcel\SimpleExcelWriter;

/**
 * Fase 2 -- autorização dos insumos do PU (P1-07).
 *
 * Eventos de PU e Histórico de PU só se alteram com `pu.parameters.configure`.
 * Quem só tem as permissões genéricas da Emissão não exclui evento (nem em
 * massa), não importa, lança, edita ou apaga PU -- nem pelo botão, nem por uma
 * requisição forjada ao Livewire. Quem tem a permissão continua podendo.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * Edita a Emissão, mas não tem nenhuma permissão `pu.*`.
 */
function puInputEmissionOperator(): User
{
    $role = Role::firstOrCreate(['name' => 'emission-operator-without-pu']);
    $role->syncPermissions(['emissions.view', 'emissions.update', 'emissions.delete']);
    $user = User::factory()->withTwoFactor()->create(['email' => fake()->unique()->safeEmail()]);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

/**
 * O editor do seeder: tem `pu.parameters.configure`.
 */
function puInputEditor(): User
{
    $user = User::factory()->withTwoFactor()->create(['email' => fake()->unique()->safeEmail()]);
    $user->assignRole('editor');

    return $user;
}

function puInputEmission(): Emission
{
    return Emission::factory()->create(['type' => 'CRI', 'status' => 'active']);
}

function puInputEvent(Emission $emission, int $sequence = 1): EmissionPuEvent
{
    return EmissionPuEvent::query()->create([
        'emission_id' => $emission->id,
        'event_type' => PuEventType::InterestPayment->value,
        'original_date' => sprintf('2026-0%d-10', $sequence + 2),
        'effective_date' => sprintf('2026-0%d-10', $sequence + 2),
        'amortization_type' => PuAmortizationType::None->value,
        'amortization_value' => null,
        'sequence' => $sequence,
    ]);
}

function puInputEventsTable(Emission $emission): Testable
{
    return Livewire::test(PuEventsRelationManager::class, ['ownerRecord' => $emission, 'pageClass' => EditEmission::class]);
}

function puInputHistoriesTable(Emission $emission): Testable
{
    return Livewire::test(PuHistoriesRelationManager::class, ['ownerRecord' => $emission, 'pageClass' => EditEmission::class]);
}

/**
 * Executa uma ação de tabela montada à mão no estado do componente, como faria
 * quem manipula o payload do Livewire para chamar uma ação que não vê.
 *
 * @param  array<string, mixed>  $context
 * @param  array<string, mixed>  $data
 */
function puInputForge(Testable $table, string $action, array $context = [], array $data = []): Testable
{
    return $table
        ->set('mountedActions', [[
            'name' => $action,
            'arguments' => [],
            'context' => ['table' => true, ...$context],
            'data' => $data,
        ]])
        ->call('callMountedAction');
}

// ---------------------------------------------------------------------------
// Eventos de PU
// ---------------------------------------------------------------------------

it('keeps a user without PU permission from deleting PU events, one by one or in bulk', function () {
    $emission = puInputEmission();
    $first = puInputEvent($emission, 1);
    $second = puInputEvent($emission, 2);
    $this->actingAs(puInputEmissionOperator());

    $table = puInputEventsTable($emission)
        ->assertTableBulkActionHidden('delete')
        ->assertActionHidden(TestAction::make('delete')->table($first));

    puInputForge($table->selectTableRecords([$first, $second]), 'delete', ['bulk' => true]);
    $table->call('mountAction', 'delete', [], ['table' => true, 'recordKey' => (string) $first->getKey()]);
    puInputForge($table, 'delete', ['recordKey' => (string) $second->getKey()]);

    expect(EmissionPuEvent::query()->whereBelongsTo($emission)->count())->toBe(2)
        ->and(Gate::forUser(puInputEmissionOperator())->allows('deleteAny', EmissionPuEvent::class))->toBeFalse()
        ->and(Gate::forUser(puInputEmissionOperator())->allows('delete', $first))->toBeFalse();
});

it('keeps a user without PU permission from creating or editing PU events', function () {
    $emission = puInputEmission();
    $event = puInputEvent($emission);
    $this->actingAs(puInputEmissionOperator());

    $table = puInputEventsTable($emission);
    puInputForge($table, 'edit', ['recordKey' => (string) $event->getKey()], ['effective_date' => '2026-03-11']);
    puInputForge($table, 'create', [], [
        'event_type' => PuEventType::InterestPayment->value,
        'original_date' => '2026-06-10',
        'effective_date' => '2026-06-10',
        'amortization_type' => PuAmortizationType::None->value,
    ]);

    expect($event->fresh()->effective_date->toDateString())->toBe('2026-03-10')
        ->and(EmissionPuEvent::query()->whereBelongsTo($emission)->count())->toBe(1);
});

it('still lets a user with the PU permission delete PU events, one by one or in bulk', function () {
    $emission = puInputEmission();
    $first = puInputEvent($emission, 1);
    $second = puInputEvent($emission, 2);
    $third = puInputEvent($emission, 3);
    $this->actingAs(puInputEditor());

    puInputEventsTable($emission)
        ->assertTableBulkActionVisible('delete')
        ->callTableBulkAction('delete', [$first, $second]);
    puInputEventsTable($emission)
        ->callAction(TestAction::make('delete')->table($third));

    expect(EmissionPuEvent::query()->whereBelongsTo($emission)->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Histórico de PU
// ---------------------------------------------------------------------------

it('keeps a user without PU permission from importing, entering, editing or deleting PU history', function () {
    Storage::fake('local');
    $emission = puInputEmission();
    $history = PuHistory::query()->create(['emission_id' => $emission->id, 'date' => '2026-03-10', 'unit_value' => '1000.000000']);
    $other = PuHistory::query()->create(['emission_id' => $emission->id, 'date' => '2026-03-11', 'unit_value' => '1000.100000']);
    Storage::disk('local')->put('imports/forged.csv', "Data,PU\n12/03/2026,\"1,00\"\n");
    $this->actingAs(puInputEmissionOperator());

    $table = puInputHistoriesTable($emission)
        ->assertActionHidden(TestAction::make('import')->table())
        ->assertActionHidden(TestAction::make('create')->table())
        ->assertActionHidden(TestAction::make('edit')->table($history))
        ->assertActionHidden(TestAction::make('delete')->table($history))
        ->assertTableBulkActionHidden('delete');

    puInputForge($table, 'import', [], ['file' => 'imports/forged.csv']);
    puInputForge($table, 'create', [], ['date' => '2026-03-13', 'unit_value' => '1.00']);
    puInputForge($table, 'edit', ['recordKey' => (string) $history->getKey()], ['date' => '2026-03-10', 'unit_value' => '1.00']);
    puInputForge($table, 'delete', ['recordKey' => (string) $history->getKey()]);
    puInputForge($table->selectTableRecords([$history, $other]), 'delete', ['bulk' => true]);

    expect(PuHistory::query()->whereBelongsTo($emission)->count())->toBe(2)
        ->and(bccomp((string) $history->fresh()->unit_value, '1000', 6))->toBe(0)
        ->and(Gate::forUser(puInputEmissionOperator())->allows('import', PuHistory::class))->toBeFalse();
});

it('still lets a user with the PU permission manage PU history, recording where each value came from', function () {
    Storage::fake('local');
    $emission = puInputEmission();
    $history = PuHistory::query()->create(['emission_id' => $emission->id, 'date' => '2026-03-10', 'unit_value' => '1000.000000']);
    $doomed = PuHistory::query()->create(['emission_id' => $emission->id, 'date' => '2026-03-09', 'unit_value' => '999.000000']);
    $path = temporaryTestFilePath('pu-historico-autorizacao');
    SimpleExcelWriter::create($path)->noHeaderRow()->addRows([['Data', 'PU'], ['12/03/2026', '1001,50']])->close();
    $upload = spreadsheetUpload(discardTemplateFileAfterTest($path), 'pu.xlsx');
    $this->actingAs(puInputEditor());

    puInputHistoriesTable($emission)
        ->assertActionVisible(TestAction::make('import')->table())
        ->callAction(TestAction::make('import')->table(), ['file' => $upload])
        ->assertHasNoActionErrors();
    puInputHistoriesTable($emission)
        ->callAction(TestAction::make('create')->table(), ['date' => '2026-03-13', 'unit_value' => '1002.00'])
        ->assertHasNoActionErrors();
    puInputHistoriesTable($emission)
        ->callAction(TestAction::make('edit')->table($history), ['date' => '2026-03-10', 'unit_value' => '1000.25'])
        ->assertHasNoActionErrors();
    puInputHistoriesTable($emission)
        ->callAction(TestAction::make('delete')->table($doomed));

    expect(PuHistory::query()->whereBelongsTo($emission)->whereDate('date', '2026-03-12')->sole()->source)->toBe(PuHistory::SOURCE_IMPORT)
        ->and(PuHistory::query()->whereBelongsTo($emission)->whereDate('date', '2026-03-13')->sole()->source)->toBe(PuHistory::SOURCE_MANUAL)
        ->and($history->fresh()->source)->toBe(PuHistory::SOURCE_MANUAL)
        ->and(bccomp((string) $history->fresh()->unit_value, '1000.25', 6))->toBe(0)
        ->and($doomed->fresh())->toBeNull();
});

// ---------------------------------------------------------------------------
// Governança da curva
// ---------------------------------------------------------------------------

it('refuses a forged homologation or invalidation from a user without the governance permissions', function () {
    $emission = puInputEmission();
    $generated = EmissionPuCurveVersion::factory()->create([
        'emission_id' => $emission->id,
        'calculation_version' => 'v1',
        'status' => PuCurveStatus::Generated->value,
        'generated_by' => User::factory()->create()->id,
    ]);
    $this->actingAs(puInputEditor());

    Livewire::test(EditEmission::class, ['record' => $emission->getRouteKey()])
        ->assertActionHidden('homologatePuCurve')
        ->assertActionHidden('invalidatePuCurve')
        ->set('mountedActions', [['name' => 'homologatePuCurve', 'arguments' => [], 'context' => [], 'data' => ['calculation_version' => 'v1', 'justification' => 'Forjada.']]])
        ->call('callMountedAction')
        ->set('mountedActions', [['name' => 'invalidatePuCurve', 'arguments' => [], 'context' => [], 'data' => ['calculation_version' => 'v1']]])
        ->call('callMountedAction');

    expect($generated->fresh()->status)->toBe(PuCurveStatus::Generated)
        ->and(Activity::query()->whereIn('description', ['pu_curve_homologated', 'pu_curve_invalidated'])->exists())->toBeFalse();
});

<?php

use App\Enums\MeasurementResponsibility;
use App\Exceptions\DelegationHistoryException;
use App\Filament\Resources\Operations\Pages\ListOperations;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Operation;
use App\Models\ResponsibilityDelegation;
use App\Models\User;
use App\Services\ResponsibilityDelegationService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * `responsibility_delegations` é trilha auditável, e nem `users` nem
 * `operations` têm SoftDeletes: hard delete é o único delete dos dois e está na
 * interface. Enquanto as chaves cascateavam, apagar um usuário levava embora
 * toda delegação em que ele aparecesse -- sem aviso, e deixando o `activity_log`
 * apontando para linhas que não existiam mais.
 *
 * A proteção tem três camadas, e cada uma é medida aqui separadamente: a chave
 * estrangeira RESTRICT (que o `DB::table()` alcança porque não passa por evento
 * de modelo), o evento `deleting` do modelo (que alcança qualquer caminho de
 * código) e a recusa da interface (que transforma as duas anteriores em
 * mensagem, e aborta o lote inteiro antes da primeira exclusão).
 *
 * Grupo `parity`: a regra é do schema, e o schema tem de dizer a mesma coisa nos
 * dois bancos -- foi por diferença de engine que ela passou despercebida.
 */
pest()->group('parity');

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
    CarbonImmutable::setTestNow('2026-09-01 12:00:00');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function delegationHistoryParticipant(): User
{
    $user = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $user->givePermissionTo([
        'delegations.create', 'delegations.revoke', 'measurements.review',
        'measurements.pay', 'measurements.receipts', 'measurements.finalize',
    ]);

    return $user;
}

function delegationHistoryOperationOf(User $holder): Operation
{
    return Operation::factory()->create(array_fill_keys(
        array_map(
            fn (MeasurementResponsibility $responsibility): string => $responsibility->operationColumn(),
            MeasurementResponsibility::cases(),
        ),
        $holder->getKey(),
    ));
}

/**
 * Uma delegação de escopo de operação, que exercita as três chaves de uma vez:
 * delegante, delegado e operação.
 *
 * @return array{delegator: User, delegate: User, operation: Operation, delegation: ResponsibilityDelegation}
 */
function delegationHistoryScenario(): array
{
    $delegator = delegationHistoryParticipant();
    $delegate = delegationHistoryParticipant();
    $operation = delegationHistoryOperationOf($delegator);

    $delegation = app(ResponsibilityDelegationService::class)->createDelegation([
        'delegator_user_id' => $delegator->getKey(),
        'delegate_user_id' => $delegate->getKey(),
        'scope_type' => ResponsibilityDelegation::SCOPE_OPERATION,
        'scope_operation_id' => $operation->getKey(),
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addDays(5),
        'reason' => 'Cobertura de férias',
    ], $delegator);

    return compact('delegator', 'delegate', 'operation', 'delegation');
}

function revokeDelegationHistory(ResponsibilityDelegation $delegation, User $actor): void
{
    app(ResponsibilityDelegationService::class)->revokeDelegation($delegation, $actor, 'Retorno antecipado');
}

/** Exclusão que ignora os eventos do modelo, para medir só a chave estrangeira. */
function deleteRowDirectly(string $table, int $id): void
{
    DB::table($table)->where('id', $id)->delete();
}

// ── Chave estrangeira ─────────────────────────────────────────────────────────

it('keeps the three delegation keys restricting deletes', function () {
    $foreignKeys = collect(Schema::getForeignKeys('responsibility_delegations'))
        ->mapWithKeys(fn (array $key): array => [$key['columns'][0] => strtolower((string) $key['on_delete'])]);

    expect($foreignKeys['delegator_user_id'])->toBe('restrict')
        ->and($foreignKeys['delegate_user_id'])->toBe('restrict')
        ->and($foreignKeys['scope_operation_id'])->toBe('restrict')
        // Autoria, não vínculo: uma delegação sem quem a criou continua legível.
        ->and($foreignKeys['revoked_by'])->toBe('set null')
        ->and($foreignKeys['created_by'])->toBe('set null');
});

it('refuses at the database to erase a user who delegated', function () {
    $scenario = delegationHistoryScenario();

    expect(fn () => deleteRowDirectly('users', $scenario['delegator']->getKey()))
        ->toThrow(QueryException::class)
        ->and(ResponsibilityDelegation::query()->count())->toBe(1)
        ->and(User::query()->whereKey($scenario['delegator']->getKey())->exists())->toBeTrue();
});

it('refuses at the database to erase a user who received a delegation', function () {
    $scenario = delegationHistoryScenario();

    expect(fn () => deleteRowDirectly('users', $scenario['delegate']->getKey()))
        ->toThrow(QueryException::class)
        ->and(ResponsibilityDelegation::query()->count())->toBe(1);
});

it('refuses at the database to erase an operation a delegation is scoped to', function () {
    $scenario = delegationHistoryScenario();

    expect(fn () => deleteRowDirectly('operations', $scenario['operation']->getKey()))
        ->toThrow(QueryException::class)
        ->and(ResponsibilityDelegation::query()->count())->toBe(1)
        ->and(Operation::query()->whereKey($scenario['operation']->getKey())->exists())->toBeTrue();
});

it('keeps refusing after the delegation is revoked', function () {
    $scenario = delegationHistoryScenario();
    revokeDelegationHistory($scenario['delegation'], $scenario['delegator']);

    // Revogar encerra a autoridade e não apaga o registro de que ela existiu.
    expect($scenario['delegation']->fresh()->revoked_at)->not->toBeNull()
        ->and(fn () => deleteRowDirectly('users', $scenario['delegator']->getKey()))
        ->toThrow(QueryException::class)
        ->and(fn () => deleteRowDirectly('users', $scenario['delegate']->getKey()))
        ->toThrow(QueryException::class)
        ->and(fn () => deleteRowDirectly('operations', $scenario['operation']->getKey()))
        ->toThrow(QueryException::class)
        ->and(ResponsibilityDelegation::query()->count())->toBe(1);
});

// ── Evento do modelo ──────────────────────────────────────────────────────────

it('refuses in the model to erase a user with delegation history', function () {
    $scenario = delegationHistoryScenario();

    expect(fn () => $scenario['delegator']->delete())
        ->toThrow(DelegationHistoryException::class, 'Este usuário possui histórico de delegações de responsabilidade e não pode ser excluído.')
        ->and(fn () => $scenario['delegate']->delete())
        ->toThrow(DelegationHistoryException::class)
        ->and(ResponsibilityDelegation::query()->count())->toBe(1);
});

it('refuses in the model to erase an operation with delegation history', function () {
    $scenario = delegationHistoryScenario();

    expect(fn () => $scenario['operation']->delete())
        ->toThrow(DelegationHistoryException::class, 'Esta operação possui histórico de delegações de responsabilidade e não pode ser excluída.')
        ->and(ResponsibilityDelegation::query()->count())->toBe(1);
});

it('erases a user and an operation that never appeared in a delegation', function () {
    $scenario = delegationHistoryScenario();
    $stranger = delegationHistoryParticipant();
    $untouchedOperation = delegationHistoryOperationOf($stranger);

    $stranger->delete();

    expect(User::query()->whereKey($stranger->getKey())->exists())->toBeFalse();

    $untouchedOperation->delete();

    expect(Operation::query()->whereKey($untouchedOperation->getKey())->exists())->toBeFalse()
        // A delegação do outro cenário continua intacta: nada de cascade lateral.
        ->and(ResponsibilityDelegation::query()->count())->toBe(1)
        ->and($scenario['delegation']->fresh())->not->toBeNull();
});

it('logs no deletion when the deletion was refused', function () {
    $scenario = delegationHistoryScenario();
    $activitiesBefore = Activity::query()->count();

    expect(fn () => $scenario['delegator']->delete())->toThrow(DelegationHistoryException::class);

    expect(Activity::query()->count())->toBe($activitiesBefore)
        ->and(Activity::query()->where('description', 'deleted')->count())->toBe(0)
        // O que já estava registrado continua registrado, e o sujeito existe.
        ->and(Activity::query()->where('log_name', 'delegations')->where('description', 'delegation_created')->count())->toBe(1);
});

// ── Interface ─────────────────────────────────────────────────────────────────

function actAsUserEraser(): User
{
    $eraser = User::factory()->withTwoFactor()->create(['email' => fake()->unique()->safeEmail()]);
    $eraser->assignRole('super-admin');
    test()->actingAs($eraser);

    return $eraser;
}

function actAsOperationEraser(Operation $operation): User
{
    $eraser = makeAdminUser();
    $eraser->givePermissionTo('operations.delete');
    $operation->forceFill(['assigned_user_id' => $eraser->getKey()])->save();
    test()->actingAs($eraser);

    return $eraser;
}

it('explains the refusal instead of erasing a user from the edit page', function () {
    $scenario = delegationHistoryScenario();
    actAsUserEraser();

    Livewire::test(EditUser::class, ['record' => $scenario['delegator']->getKey()])
        ->callAction(TestAction::make('delete'))
        ->assertNotified('Usuário não excluído.');

    expect(User::query()->whereKey($scenario['delegator']->getKey())->exists())->toBeTrue()
        ->and(ResponsibilityDelegation::query()->count())->toBe(1);
});

it('refuses the whole user batch when one of them has delegation history', function () {
    $scenario = delegationHistoryScenario();
    actAsUserEraser();
    $erasable = User::factory()->create();

    Livewire::test(ListUsers::class)
        ->selectTableRecords([$erasable->getKey(), $scenario['delegate']->getKey()])
        ->callAction(TestAction::make('delete')->table()->bulk())
        ->assertNotified('Nenhum usuário excluído.');

    // Recusa atômica: o excluível também sobrevive, para que ninguém precise
    // adivinhar o que o lote apagou antes de parar.
    expect(User::query()->whereKey($erasable->getKey())->exists())->toBeTrue()
        ->and(User::query()->whereKey($scenario['delegate']->getKey())->exists())->toBeTrue()
        ->and(ResponsibilityDelegation::query()->count())->toBe(1);
});

it('erases a user batch that has no delegation history', function () {
    delegationHistoryScenario();
    actAsUserEraser();
    $first = User::factory()->create();
    $second = User::factory()->create();

    Livewire::test(ListUsers::class)
        ->selectTableRecords([$first->getKey(), $second->getKey()])
        ->callAction(TestAction::make('delete')->table()->bulk())
        ->assertHasNoActionErrors();

    expect(User::query()->whereKey([$first->getKey(), $second->getKey()])->count())->toBe(0)
        ->and(ResponsibilityDelegation::query()->count())->toBe(1);
});

it('explains the refusal instead of erasing an operation from the listing', function () {
    $scenario = delegationHistoryScenario();
    actAsOperationEraser($scenario['operation']);

    Livewire::test(ListOperations::class)
        ->callAction(TestAction::make('delete')->table($scenario['operation']))
        ->assertNotified('Operação não excluída.');

    expect(Operation::query()->whereKey($scenario['operation']->getKey())->exists())->toBeTrue()
        ->and(ResponsibilityDelegation::query()->count())->toBe(1);
});

it('refuses the whole operation batch when one of them has delegation history', function () {
    $scenario = delegationHistoryScenario();
    $eraser = actAsOperationEraser($scenario['operation']);
    $erasable = Operation::factory()->create(['assigned_user_id' => $eraser->getKey()]);

    Livewire::test(ListOperations::class)
        ->selectTableRecords([$erasable->getKey(), $scenario['operation']->getKey()])
        ->callAction(TestAction::make('delete')->table()->bulk())
        ->assertNotified('Nenhuma operação excluída.');

    expect(Operation::query()->whereKey($erasable->getKey())->exists())->toBeTrue()
        ->and(Operation::query()->whereKey($scenario['operation']->getKey())->exists())->toBeTrue()
        ->and(ResponsibilityDelegation::query()->count())->toBe(1);
});

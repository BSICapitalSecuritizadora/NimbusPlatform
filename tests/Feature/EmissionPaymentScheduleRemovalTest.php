<?php

use App\Actions\Emissions\ImportPaymentsFromSpreadsheet;
use App\Actions\Emissions\RemovePaymentFromSchedule;
use App\Enums\PaymentRemovalOutcome;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\Emissions\Pages\EditEmission;
use App\Filament\Resources\Emissions\Pages\ViewEmission;
use App\Models\Emission;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\DeleteAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * @param  array<string, float|int>  $values
 */
function scheduleDateForRemoval(Emission $emission, string $paymentDate, array $values = []): Payment
{
    return $emission->payments()->create([
        'payment_date' => $paymentDate,
        'premium_value' => 0,
        'interest_value' => 0,
        'amortization_value' => 0,
        'extra_amortization_value' => 0,
        ...$values,
    ]);
}

function paymentScheduleOn(Emission $emission, string $pageClass = ViewEmission::class): Testable
{
    return Livewire::test(PaymentsRelationManager::class, [
        'ownerRecord' => $emission,
        'pageClass' => $pageClass,
    ]);
}

function paymentScheduleEditorUser(): User
{
    $user = User::factory()->withTwoFactor()->create();
    $user->assignRole('editor');

    return $user;
}

/**
 * Títulos das notificações da última requisição, lidos sem consumir a sessão
 * (o `assertNotified()` a esvazia).
 *
 * @return list<string>
 */
function paymentScheduleNotificationTitles(): array
{
    $notifications = session()->get('filament.claimed_notifications') ?? session()->get('filament.notifications') ?? [];

    return array_values(array_map(
        fn (array $notification): string => (string) ($notification['title'] ?? ''),
        $notifications,
    ));
}

it('shows a discreet trash icon and a confirmation with the values of the date', function () {
    $emission = Emission::factory()->create();
    $payment = scheduleDateForRemoval($emission, '2026-11-09', [
        'premium_value' => 1250.5,
        'interest_value' => 59557,
        'amortization_value' => 326085.3,
        'extra_amortization_value' => 10.9,
    ]);

    $this->actingAs(makeAdminUser());

    paymentScheduleOn($emission)
        ->assertTableActionVisible('delete', $payment)
        ->assertTableActionExists('delete', fn (DeleteAction $action): bool => $action->isIconButton()
            && ($action->getIcon() === 'heroicon-o-trash')
            && ($action->getTooltip() === 'Remover data')
            && ($action->getColor() === 'danger')
            && ($action->getKeyBindings() === null), $payment)
        ->mountTableAction('delete', $payment)
        ->assertMountedActionModalSee([
            'Remover data do cronograma',
            'Deseja realmente remover a data 09/11/2026 do cronograma de pagamentos?',
            'Prêmio',
            '1.250,50',
            'Juros',
            '59.557,00',
            'Amortização',
            '326.085,30',
            'Amortização Extra',
            '10,90',
            'Esta ação removerá este registro do cronograma de pagamentos.',
            'Cancelar',
            'Remover',
        ]);
});

it('keeps the date when the confirmation is cancelled', function () {
    $emission = Emission::factory()->create();
    $payment = scheduleDateForRemoval($emission, '2026-11-08');

    $this->actingAs(makeAdminUser());

    paymentScheduleOn($emission)
        ->mountTableAction('delete', $payment)
        ->unmountTableAction()
        ->assertCanSeeTableRecords([$payment]);

    $this->assertModelExists($payment);
});

it('removes only the confirmed date and leaves the rest of the schedule intact', function () {
    $emission = Emission::factory()->create();
    $october = scheduleDateForRemoval($emission, '2026-10-08', ['interest_value' => 65000.1, 'amortization_value' => 1200]);
    $november = scheduleDateForRemoval($emission, '2026-11-08', ['interest_value' => 64000.2]);

    $this->actingAs(makeAdminUser());

    paymentScheduleOn($emission)
        ->assertCountTableRecords(2)
        ->callTableAction('delete', $november)
        ->assertNotified('Data removida com sucesso.')
        ->assertCanSeeTableRecords([$october])
        ->assertCanNotSeeTableRecords([$november])
        ->assertCountTableRecords(1);

    $this->assertModelMissing($november);
    $this->assertModelExists($october);

    $october->refresh();

    expect($october->payment_date->toDateString())->toBe('2026-10-08')
        ->and($october->interest_value)->toBe('65000.10')
        ->and($october->amortization_value)->toBe('1200.00');
});

it('removes the date only from the emission it belongs to', function () {
    $seriesA = Emission::factory()->create();
    $seriesB = Emission::factory()->create();
    $paymentA = scheduleDateForRemoval($seriesA, '2026-11-08', ['interest_value' => 100]);
    $paymentB = scheduleDateForRemoval($seriesB, '2026-11-08', ['interest_value' => 100]);

    $this->actingAs(makeAdminUser());

    paymentScheduleOn($seriesA)
        ->callTableAction('delete', $paymentA)
        ->assertNotified('Data removida com sucesso.');

    $this->assertModelMissing($paymentA);
    $this->assertModelExists($paymentB);

    expect($paymentB->fresh()->interest_value)->toBe('100.00');
});

it('refuses to remove a date of another emission through a forged record key', function () {
    $seriesA = Emission::factory()->create();
    $paymentB = scheduleDateForRemoval(Emission::factory()->create(), '2026-11-08');

    $this->actingAs(makeAdminUser());

    paymentScheduleOn($seriesA)
        ->call('mountAction', 'delete', [], ['table' => true, 'recordKey' => (string) $paymentB->getKey()])
        ->call('callMountedAction')
        ->assertNotified('Este registro não está mais disponível.');

    $this->assertModelExists($paymentB);
});

it('hides the removal from users who cannot delete emission data', function () {
    $emission = Emission::factory()->create();
    $payment = scheduleDateForRemoval($emission, '2026-11-08');

    $this->actingAs(paymentScheduleEditorUser());

    paymentScheduleOn($emission)
        ->assertTableActionHidden('delete', $payment);

    paymentScheduleOn($emission, EditEmission::class)
        ->assertTableActionHidden('delete', $payment)
        ->assertTableBulkActionHidden('delete');
});

it('refuses forged removal requests from users who cannot delete emission data', function () {
    $emission = Emission::factory()->create();
    $payment = scheduleDateForRemoval($emission, '2026-11-08');

    $this->actingAs(paymentScheduleEditorUser());

    paymentScheduleOn($emission)
        ->call('mountAction', 'delete', [], ['table' => true, 'recordKey' => (string) $payment->getKey()])
        ->call('callMountedAction');

    paymentScheduleOn($emission, EditEmission::class)
        ->set('selectedTableRecords', [(string) $payment->getKey()])
        ->call('mountAction', 'delete', [], ['table' => true, 'bulk' => true])
        ->call('callMountedAction');

    $this->assertModelExists($payment);

    expect(paymentScheduleNotificationTitles())->not->toContain('Data removida com sucesso.');
});

it('keeps the rest of the schedule as it was on the view page', function () {
    $emission = Emission::factory()->create();
    $payment = scheduleDateForRemoval($emission, '2026-11-08');

    $this->actingAs(makeAdminUser());

    paymentScheduleOn($emission)
        ->assertTableActionVisible('import')
        ->assertTableActionHidden('create')
        ->assertTableActionHidden('edit', $payment)
        ->assertTableBulkActionHidden('delete');

    paymentScheduleOn($emission, EditEmission::class)
        ->assertTableActionVisible('delete', $payment)
        ->assertTableBulkActionVisible('delete');
});

it('warns once and does not fail when the date was removed while the confirmation was open', function () {
    $emission = Emission::factory()->create();
    $keptPayment = scheduleDateForRemoval($emission, '2026-10-08');
    $payment = scheduleDateForRemoval($emission, '2026-11-08');

    $this->actingAs(makeAdminUser());

    $component = paymentScheduleOn($emission)
        ->mountTableAction('delete', $payment);

    $payment->delete();

    $component->call('callMountedAction');

    expect(paymentScheduleNotificationTitles())->toBe(['Este registro não está mais disponível.']);

    $component
        ->assertCanSeeTableRecords([$keptPayment])
        ->assertCountTableRecords(1);

    $this->assertModelExists($keptPayment);
});

it('warns once and does not fail when a stale row is clicked after the date was removed', function () {
    $emission = Emission::factory()->create();
    $payment = scheduleDateForRemoval($emission, '2026-11-08');

    $this->actingAs(makeAdminUser());

    $component = paymentScheduleOn($emission);

    $payment->delete();

    $component->call('mountAction', 'delete', [], ['table' => true, 'recordKey' => (string) $payment->getKey()]);

    expect(paymentScheduleNotificationTitles())->toBe(['Este registro não está mais disponível.'])
        ->and($component->instance()->mountedActions)->toBe([]);

    $component->assertCountTableRecords(0);
});

it('keeps the date when it changed while the confirmation was open', function () {
    $emission = Emission::factory()->create();
    $payment = scheduleDateForRemoval($emission, '2026-11-08', ['interest_value' => 59557]);

    $this->actingAs(makeAdminUser());

    $component = paymentScheduleOn($emission)
        ->mountTableAction('delete', $payment);

    $payment->update(['interest_value' => 60000]);

    $component
        ->callMountedTableAction()
        ->assertNotified('Esta data foi alterada enquanto a confirmação estava aberta.');

    $this->assertModelExists($payment);

    expect($payment->fresh()->interest_value)->toBe('60000.00');
});

it('reports a missing date, or one of another emission, without removing anything', function () {
    $emission = Emission::factory()->create();
    $removedPayment = scheduleDateForRemoval($emission, '2026-11-08');
    $removedSnapshot = RemovePaymentFromSchedule::snapshot($removedPayment);
    $removedPayment->delete();
    $otherEmissionPayment = scheduleDateForRemoval(Emission::factory()->create(), '2026-11-08');

    $removePayment = app(RemovePaymentFromSchedule::class);

    expect($removePayment->handle($emission, $removedPayment->getKey(), $removedSnapshot))
        ->toBe(PaymentRemovalOutcome::Unavailable)
        ->and($removePayment->handle($emission, $otherEmissionPayment->getKey(), RemovePaymentFromSchedule::snapshot($otherEmissionPayment)))
        ->toBe(PaymentRemovalOutcome::Unavailable);

    $this->assertModelExists($otherEmissionPayment);
});

it('records the removal in the activity log with the user and the previous values', function () {
    $emission = Emission::factory()->create();
    $payment = scheduleDateForRemoval($emission, '2026-11-09', [
        'premium_value' => 10.5,
        'interest_value' => 59557.25,
        'amortization_value' => 1000,
        'extra_amortization_value' => 2.75,
    ]);
    $admin = makeAdminUser();

    $this->actingAs($admin);

    paymentScheduleOn($emission)->callTableAction('delete', $payment);

    $activity = Activity::query()
        ->where('subject_type', $payment->getMorphClass())
        ->where('subject_id', $payment->getKey())
        ->where('event', 'deleted')
        ->sole();

    expect($activity->causer?->is($admin))->toBeTrue()
        ->and($activity->created_at)->not->toBeNull()
        ->and($activity->properties['old'])->toMatchArray([
            'emission_id' => $emission->id,
            'premium_value' => '10.50',
            'interest_value' => '59557.25',
            'amortization_value' => '1000.00',
            'extra_amortization_value' => '2.75',
        ])
        ->and($activity->properties['old']['payment_date'])->toStartWith('2026-11-09');
});

it('keeps the date when its audit entry cannot be written', function () {
    Exceptions::fake();

    $emission = Emission::factory()->create();
    $payment = scheduleDateForRemoval($emission, '2026-11-08');

    $this->actingAs(makeAdminUser());

    $component = paymentScheduleOn($emission);

    Activity::creating(function (): never {
        throw new RuntimeException('activity_log indisponível');
    });

    $component
        ->callTableAction('delete', $payment)
        ->assertNotified('Não foi possível remover a data do cronograma.');

    Exceptions::assertReported(RuntimeException::class);

    $this->assertModelExists($payment);
});

it('moves back to the last existing page when the only date of the last page is removed', function () {
    $emission = Emission::factory()->create();
    $payments = collect(range(0, 10))->map(fn (int $month): Payment => scheduleDateForRemoval(
        $emission,
        CarbonImmutable::create(2026, 1, 8)->addMonths($month)->toDateString(),
    ));
    $onlyDateOfLastPage = $payments->first();

    $this->actingAs(makeAdminUser());

    $component = paymentScheduleOn($emission)
        ->set('tableRecordsPerPage', 10)
        ->call('setPage', 2)
        ->assertCanSeeTableRecords([$onlyDateOfLastPage])
        ->callTableAction('delete', $onlyDateOfLastPage)
        ->assertNotified('Data removida com sucesso.');

    expect((int) $component->instance()->getTablePage())->toBe(1);

    $component
        ->assertCountTableRecords(10)
        ->assertCanSeeTableRecords($payments->slice(1)->all());
});

it('stays on the same page and keeps the search when the page still has dates', function () {
    $emission = Emission::factory()->create();
    $payments = collect(range(0, 11))->map(fn (int $month): Payment => scheduleDateForRemoval(
        $emission,
        CarbonImmutable::create(2026, 1, 8)->addMonths($month)->toDateString(),
    ));

    $this->actingAs(makeAdminUser());

    $component = paymentScheduleOn($emission)
        ->set('tableRecordsPerPage', 10)
        ->set('tableSearch', '2026')
        ->call('setPage', 2)
        ->callTableAction('delete', $payments[1])
        ->assertNotified('Data removida com sucesso.')
        ->assertSet('tableSearch', '2026')
        ->assertCanSeeTableRecords([$payments[0]])
        ->assertCountTableRecords(11);

    expect((int) $component->instance()->getTablePage())->toBe(2);
});

it('shows a friendly message and keeps the technical details in the log when the removal fails', function () {
    Exceptions::fake();

    $emission = Emission::factory()->create();
    $payment = scheduleDateForRemoval($emission, '2026-11-08');

    $this->mock(RemovePaymentFromSchedule::class)
        ->shouldReceive('handle')
        ->andThrow(new RuntimeException('SQLSTATE[HY000]: General error (SQL: delete from `payments` where `id` = 1)'));

    $this->actingAs(makeAdminUser());

    $component = paymentScheduleOn($emission)
        ->callTableAction('delete', $payment);

    expect(json_encode(session()->get('filament.claimed_notifications') ?? session()->get('filament.notifications')))
        ->not->toContain('SQLSTATE')
        ->not->toContain('delete from');

    $component->assertNotified('Não foi possível remover a data do cronograma.');

    Exceptions::assertReported(RuntimeException::class);

    $this->assertModelExists($payment);
});

it('recreates a removed date when a later import brings it again', function () {
    Storage::fake('local');

    $emission = Emission::factory()->create();
    $payment = scheduleDateForRemoval($emission, '2026-11-09', ['interest_value' => 59557]);

    $this->actingAs(makeAdminUser());

    paymentScheduleOn($emission)->callTableAction('delete', $payment);

    $this->assertModelMissing($payment);

    Storage::disk('local')->put('imports/cronograma.csv', "Data,Juros\n09/11/2026,59557\n");

    app(ImportPaymentsFromSpreadsheet::class)->handle(Storage::disk('local')->path('imports/cronograma.csv'), $emission);

    $recreatedPayment = $emission->payments()->sole();

    expect($recreatedPayment->getKey())->not->toBe($payment->getKey())
        ->and($recreatedPayment->payment_date->toDateString())->toBe('2026-11-09')
        ->and($recreatedPayment->interest_value)->toBe('59557.00');
});

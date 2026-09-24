<?php

use App\Actions\Expenses\BuildExpenseCalendar;
use App\Enums\ExpensePaymentStatus;
use App\Filament\Resources\Expenses\Pages\EditExpense;
use App\Filament\Resources\Expenses\Pages\ExpenseCalendar;
use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Filament\Resources\Expenses\RelationManagers\HistoriesRelationManager;
use App\Filament\Resources\Expenses\RelationManagers\MonthlyConsolidatedRelationManager;
use App\Jobs\SyncContaAzulExpensesJob;
use App\Models\ContaAzulToken;
use App\Models\Emission;
use App\Models\Expense;
use App\Models\ExpenseHistory;
use App\Models\ExpenseServiceProvider;
use App\Models\Fund;
use App\Models\User;
use App\Services\ContaAzulClient;
use App\Services\Reports\EmissionMonthlyReportService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('shows the calendar action on the expenses list page', function () {
    $this->actingAs(makeExpenseCalendarAdminUser());

    Livewire::test(ListExpenses::class)
        ->assertActionExists('calendar')
        ->assertActionHasLabel('calendar', 'Calendário');
});

it('builds recurring payment events for the selected month', function () {
    $emission = Emission::factory()->create([
        'name' => 'CRI Conviva',
    ]);
    $serviceProvider = ExpenseServiceProvider::factory()->create([
        'name' => 'BSI Capital',
    ]);

    Expense::factory()->create([
        'emission_id' => $emission->id,
        'expense_service_provider_id' => $serviceProvider->id,
        'category' => 'Custódia da CCI',
        'amount' => 750,
        'period' => Expense::PERIOD_MONTHLY,
        'start_date' => '2026-03-04',
        'end_date' => '2026-05-04',
    ]);

    $calendar = app(BuildExpenseCalendar::class)->handle('2026-04');

    expect($calendar['summary']['event_count'])->toBe(1)
        ->and($calendar['summary']['total_amount'])->toBe('R$ 750,00')
        ->and(
            collect($calendar['weeks'])
                ->flatten(1)
                ->flatMap(fn (array $day): array => $day['events'])
                ->contains(fn (array $event): bool => $event['date'] === '2026-04-04'
                    && $event['operation'] === 'CRI Conviva'
                    && $event['category'] === 'Custódia da CCI'
                    && $event['amount_label'] === 'R$ 750,00')
        )->toBeTrue();
});

it('filters calendar events by operation and category', function () {
    $selectedEmission = Emission::factory()->create([
        'name' => 'CRI Conviva',
    ]);
    $otherEmission = Emission::factory()->create([
        'name' => 'CRI Atlas',
    ]);
    $serviceProvider = ExpenseServiceProvider::factory()->create();

    Expense::factory()->create([
        'emission_id' => $selectedEmission->id,
        'expense_service_provider_id' => $serviceProvider->id,
        'category' => 'Engenharia',
        'amount' => 5000,
        'period' => Expense::PERIOD_SINGLE,
        'start_date' => '2026-04-15',
    ]);
    Expense::factory()->create([
        'emission_id' => $otherEmission->id,
        'expense_service_provider_id' => $serviceProvider->id,
        'category' => 'Cartório',
        'amount' => 750,
        'period' => Expense::PERIOD_SINGLE,
        'start_date' => '2026-04-20',
    ]);

    $calendar = app(BuildExpenseCalendar::class)->handle('2026-04', [
        'emission_id' => $selectedEmission->id,
        'category' => 'Engenharia',
    ]);

    expect($calendar['summary']['event_count'])->toBe(1)
        ->and($calendar['summary']['total_amount'])->toBe('R$ 5.000,00')
        ->and(
            collect($calendar['weeks'])
                ->flatten(1)
                ->flatMap(fn (array $day): array => $day['events'])
                ->every(fn (array $event): bool => $event['operation'] === 'CRI Conviva' && $event['category'] === 'Engenharia')
        )->toBeTrue();
});

it('builds quarterly payment events only for matching months', function () {
    $emission = Emission::factory()->create([
        'name' => 'CRI Trimestral',
    ]);
    $serviceProvider = ExpenseServiceProvider::factory()->create([
        'name' => 'BSI Capital',
    ]);

    Expense::factory()->create([
        'emission_id' => $emission->id,
        'expense_service_provider_id' => $serviceProvider->id,
        'category' => 'Engenharia',
        'amount' => 1200,
        'period' => Expense::PERIOD_QUARTERLY,
        'start_date' => '2026-02-10',
        'end_date' => '2026-11-10',
    ]);

    $aprilCalendar = app(BuildExpenseCalendar::class)->handle('2026-04');
    $mayCalendar = app(BuildExpenseCalendar::class)->handle('2026-05');

    expect($aprilCalendar['summary']['event_count'])->toBe(0)
        ->and($mayCalendar['summary']['event_count'])->toBe(1)
        ->and($mayCalendar['summary']['total_amount'])->toBe('R$ 1.200,00')
        ->and(
            collect($mayCalendar['weeks'])
                ->flatten(1)
                ->flatMap(fn (array $day): array => $day['events'])
                ->contains(fn (array $event): bool => $event['date'] === '2026-05-10'
                    && $event['operation'] === 'CRI Trimestral'
                    && $event['category'] === 'Engenharia'
                    && $event['amount_label'] === 'R$ 1.200,00')
        )->toBeTrue();
});

it('renders scheduled payment events on the expense calendar page', function () {
    $this->actingAs(makeExpenseCalendarAdminUser());

    $emission = Emission::factory()->create([
        'name' => 'CRI Conviva',
    ]);
    $serviceProvider = ExpenseServiceProvider::factory()->create([
        'name' => 'BSI Capital',
    ]);

    Expense::factory()->create([
        'emission_id' => $emission->id,
        'expense_service_provider_id' => $serviceProvider->id,
        'category' => 'Engenharia',
        'amount' => 5000,
        'period' => Expense::PERIOD_SINGLE,
        'start_date' => '2026-04-15',
        'end_date' => null,
    ]);

    Livewire::test(ExpenseCalendar::class)
        ->set('visibleMonth', '2026-04')
        ->assertSee('hidden overflow-hidden rounded-3xl', false)
        ->assertSee('lg:block', false)
        ->assertSee('lg:hidden', false)
        ->assertSee('Todas as operações')
        ->assertSee('Todas as categorias')
        ->assertSee('Calendário de pagamentos')
        ->assertSee('CRI Conviva')
        ->assertSee('Engenharia')
        ->assertSee('R$ 5.000,00');
});

it('accepts list filters as calendar context through query string', function () {
    $this->actingAs(makeExpenseCalendarAdminUser());

    $emission = Emission::factory()->create();

    Livewire::withQueryParams(['category' => 'Cartório', 'emission_id' => (string) $emission->id])
        ->test(ExpenseCalendar::class)
        ->assertSet('selectedCategory', 'Cartório')
        ->assertSet('selectedEmissionId', (string) $emission->id);
});

it('ignores invalid calendar context from the query string', function () {
    $this->actingAs(makeExpenseCalendarAdminUser());

    Livewire::withQueryParams(['category' => 'Categoria Inexistente', 'emission_id' => '99999'])
        ->test(ExpenseCalendar::class)
        ->assertSet('selectedCategory', null)
        ->assertSet('selectedEmissionId', null);
});

it('marks calendar events with temporal status relative to today', function () {
    $this->travelTo(Carbon::parse('2026-08-10'));

    $emission = Emission::factory()->create();
    $serviceProvider = ExpenseServiceProvider::factory()->create();

    Expense::factory()->create([
        'emission_id' => $emission->id,
        'expense_service_provider_id' => $serviceProvider->id,
        'category' => 'Cartório',
        'amount' => 100,
        'period' => Expense::PERIOD_SINGLE,
        'start_date' => '2026-08-05',
    ]);
    Expense::factory()->create([
        'emission_id' => $emission->id,
        'expense_service_provider_id' => $serviceProvider->id,
        'category' => 'Engenharia',
        'amount' => 200,
        'period' => Expense::PERIOD_SINGLE,
        'start_date' => '2026-08-12',
    ]);
    Expense::factory()->create([
        'emission_id' => $emission->id,
        'expense_service_provider_id' => $serviceProvider->id,
        'category' => 'Auditoria',
        'amount' => 300,
        'period' => Expense::PERIOD_SINGLE,
        'start_date' => '2026-08-25',
    ]);

    $events = collect(app(BuildExpenseCalendar::class)->handle('2026-08')['weeks'])
        ->flatten(1)
        ->flatMap(fn (array $day): array => $day['events'])
        ->keyBy('date');

    expect($events->get('2026-08-05')['is_overdue'])->toBeTrue()
        ->and($events->get('2026-08-05')['is_due_soon'])->toBeFalse()
        ->and($events->get('2026-08-12')['is_overdue'])->toBeFalse()
        ->and($events->get('2026-08-12')['is_due_soon'])->toBeTrue()
        ->and($events->get('2026-08-25')['is_overdue'])->toBeFalse()
        ->and($events->get('2026-08-25')['is_due_soon'])->toBeFalse();
});

it('shows a single notice instead of per-day empty states when the month has no events', function () {
    $this->actingAs(makeExpenseCalendarAdminUser());

    Livewire::test(ExpenseCalendar::class)
        ->set('visibleMonth', '2026-04')
        ->assertSee('Nenhum pagamento previsto')
        ->assertDontSee('Sem pagamentos previstos');
});

it('collapses busy days behind an overflow link and lists every event in the day modal', function () {
    $this->actingAs(makeExpenseCalendarAdminUser());

    $serviceProvider = ExpenseServiceProvider::factory()->create();

    foreach (['CRI Alpha', 'CRI Beta', 'CRI Gamma'] as $emissionName) {
        Expense::factory()->create([
            'emission_id' => Emission::factory()->create(['name' => $emissionName])->id,
            'expense_service_provider_id' => $serviceProvider->id,
            'category' => 'Cartório',
            'amount' => 500,
            'period' => Expense::PERIOD_SINGLE,
            'start_date' => '2026-04-15',
        ]);
    }

    Livewire::test(ExpenseCalendar::class)
        ->set('visibleMonth', '2026-04')
        ->assertSee('+1 pagamento')
        ->call('openDay', '2026-04-15')
        ->assertSet('selectedDate', '2026-04-15')
        ->assertSee('CRI Alpha')
        ->assertSee('CRI Beta')
        ->assertSee('CRI Gamma')
        ->assertSee('3 pagamentos previstos')
        ->call('closeDay')
        ->assertSet('selectedDate', null);
});

it('uses expense payment history as the source of truth for paid occurrences', function () {
    $emission = Emission::factory()->create(['name' => 'CRI Conviva']);
    $serviceProvider = ExpenseServiceProvider::factory()->create(['name' => 'BSI Capital']);

    $expense = Expense::factory()->create([
        'emission_id' => $emission->id,
        'expense_service_provider_id' => $serviceProvider->id,
        'category' => 'Custódia da CCI',
        'amount' => 5900.55,
        'period' => Expense::PERIOD_MONTHLY,
        'start_date' => '2026-05-04',
        'end_date' => '2026-09-04',
    ]);

    $expense->histories()->create([
        'due_date' => '2026-05-04',
        'payment_date' => '2026-05-05',
        'amount' => 5900.55,
    ]);

    $calendar = app(BuildExpenseCalendar::class)->handle('2026-05');
    $event = collect($calendar['weeks'])
        ->flatten(1)
        ->flatMap(fn (array $day): array => $day['events'])
        ->firstWhere('date', '2026-05-04');

    expect($event)->not->toBeNull()
        ->and($event['has_payment'])->toBeTrue()
        ->and($event['status_value'])->toBe(ExpensePaymentStatus::Paid->value)
        ->and($event['status_label'])->toBe('Pago')
        ->and($event['due_date_label'])->toBe('04/05/2026')
        ->and($event['payment_date_label'])->toBe('05/05/2026')
        ->and($event['paid_amount'])->toBe(5900.55)
        ->and($event['paid_amount_label'])->toBe('R$ 5.900,55')
        ->and($event['expected_amount_label'])->toBe('R$ 5.900,55');
});

it('correctly isolates occurrences across competencies in recurring expenses', function () {
    $this->travelTo(Carbon::parse('2026-08-15'));

    $emission = Emission::factory()->create(['name' => 'CRI Conviva']);
    $serviceProvider = ExpenseServiceProvider::factory()->create(['name' => 'Custodiante Master']);

    $expense = Expense::factory()->create([
        'emission_id' => $emission->id,
        'expense_service_provider_id' => $serviceProvider->id,
        'category' => 'Custódia da CCI',
        'amount' => 5900.55,
        'period' => Expense::PERIOD_MONTHLY,
        'start_date' => '2026-05-04',
        'end_date' => '2026-09-04',
    ]);

    // May: paid on 05/05/2026
    $expense->histories()->create([
        'due_date' => '2026-05-04',
        'payment_date' => '2026-05-05',
        'amount' => 5900.55,
    ]);

    // June: paid on 04/06/2026
    $expense->histories()->create([
        'due_date' => '2026-06-04',
        'payment_date' => '2026-06-04',
        'amount' => 5900.55,
    ]);

    // July: paid on 07/07/2026
    $expense->histories()->create([
        'due_date' => '2026-07-04',
        'payment_date' => '2026-07-07',
        'amount' => 5900.55,
    ]);

    // August: no payment (vencimento 04/08/2026 < today 15/08/2026) -> VENCIDO
    // September: no payment (vencimento 04/09/2026 >= today 15/08/2026) -> PENDENTE

    $action = app(BuildExpenseCalendar::class);

    $mayEvent = collect($action->handle('2026-05')['weeks'])->flatten(1)->flatMap(fn ($d) => $d['events'])->firstWhere('date', '2026-05-04');
    $juneEvent = collect($action->handle('2026-06')['weeks'])->flatten(1)->flatMap(fn ($d) => $d['events'])->firstWhere('date', '2026-06-04');
    $julyEvent = collect($action->handle('2026-07')['weeks'])->flatten(1)->flatMap(fn ($d) => $d['events'])->firstWhere('date', '2026-07-04');
    $augustEvent = collect($action->handle('2026-08')['weeks'])->flatten(1)->flatMap(fn ($d) => $d['events'])->firstWhere('date', '2026-08-04');
    $septemberEvent = collect($action->handle('2026-09')['weeks'])->flatten(1)->flatMap(fn ($d) => $d['events'])->firstWhere('date', '2026-09-04');

    expect($mayEvent['status_label'])->toBe('Pago')
        ->and($mayEvent['payment_date_label'])->toBe('05/05/2026')
        ->and($juneEvent['status_label'])->toBe('Pago')
        ->and($juneEvent['payment_date_label'])->toBe('04/06/2026')
        ->and($julyEvent['status_label'])->toBe('Pago')
        ->and($julyEvent['payment_date_label'])->toBe('07/07/2026')
        ->and($augustEvent['status_label'])->toBe('Vencido')
        ->and($augustEvent['payment_date_label'])->toBe('—')
        ->and($augustEvent['paid_amount_label'])->toBe('—')
        ->and($augustEvent['is_overdue'])->toBeTrue()
        ->and($septemberEvent['status_label'])->toBe('Pendente')
        ->and($septemberEvent['payment_date_label'])->toBe('—')
        ->and($septemberEvent['paid_amount_label'])->toBe('—')
        ->and($septemberEvent['is_overdue'])->toBeFalse();
});

it('keeps paid occurrences visible on the calendar and reflects them in KPI totals', function () {
    $emission = Emission::factory()->create();
    $serviceProvider = ExpenseServiceProvider::factory()->create();

    $expense = Expense::factory()->create([
        'emission_id' => $emission->id,
        'expense_service_provider_id' => $serviceProvider->id,
        'category' => 'Auditoria',
        'amount' => 4500,
        'period' => Expense::PERIOD_SINGLE,
        'start_date' => '2026-05-15',
    ]);

    $expense->histories()->create([
        'due_date' => '2026-05-15',
        'amount' => 4500,
        'payment_date' => '2026-05-15',
        'status' => 'paid',
    ]);

    $calendar = app(BuildExpenseCalendar::class)->handle('2026-05');

    expect($calendar['summary']['event_count'])->toBe(1)
        ->and($calendar['summary']['total_amount'])->toBe('R$ 4.500,00')
        ->and($calendar['summary']['operation_count'])->toBe(1);

    $events = collect($calendar['weeks'])->flatten(1)->flatMap(fn ($d) => $d['events']);
    expect($events)->toHaveCount(1)
        ->and($events->first()['status_label'])->toBe('Pago');
});

it('opens event details modal with full information when an event is clicked', function () {
    $this->actingAs(makeExpenseCalendarAdminUser());

    $emission = Emission::factory()->create(['name' => 'CRI Conviva']);
    $serviceProvider = ExpenseServiceProvider::factory()->create(['name' => 'BSI Capital']);

    $expense = Expense::factory()->create([
        'emission_id' => $emission->id,
        'expense_service_provider_id' => $serviceProvider->id,
        'category' => 'Custódia da CCI',
        'amount' => 5900.55,
        'period' => Expense::PERIOD_SINGLE,
        'start_date' => '2026-05-04',
    ]);

    $expense->histories()->create([
        'due_date' => '2026-05-04',
        'payment_date' => '2026-05-05',
        'amount' => 5900.55,
    ]);

    $eventId = "expense-{$expense->id}-20260504";

    Livewire::test(ExpenseCalendar::class)
        ->set('visibleMonth', '2026-05')
        ->assertSee('Custódia da CCI')
        ->assertSee('R$ 5.900,55')
        ->assertSee('Pago')
        ->call('openEvent', $eventId)
        ->assertSet('selectedEventId', $eventId)
        ->assertSee('Detalhes da ocorrência')
        // Os cards de KPI também exibem "Valor previsto"/"Valor pago"; a marcação abaixo é só a do modal.
        ->assertSeeHtml('tracking-wider text-slate-400">Valor previsto</span>')
        ->assertSeeHtml('tracking-wider text-slate-400">Valor pago</span>')
        ->assertSee('04/05/2026')
        ->assertSee('05/05/2026')
        ->call('closeEvent')
        ->assertSet('selectedEventId', null);
});

it('displays payment date and status on the expense histories relation manager', function () {
    $this->actingAs(makeExpenseCalendarAdminUser());

    $expense = Expense::factory()->create();
    $expense->histories()->create([
        'due_date' => '2026-05-04',
        'payment_date' => '2026-05-05',
        'amount' => 5900.55,
        'conta_azul_bill_id' => 'bill-123',
    ]);

    Livewire::test(HistoriesRelationManager::class, [
        'ownerRecord' => $expense,
        'pageClass' => EditExpense::class,
    ])
        ->assertCanSeeTableRecords($expense->histories)
        ->assertSee('04/05/2026')
        ->assertSee('05/05/2026')
        ->assertSee('Pago')
        ->assertSee('5.900,55');
});

it('allows creating a manual payment history with due_date, amount, and payment_date', function () {
    $this->actingAs(makeExpenseCalendarAdminUser());

    $expense = Expense::factory()->create();

    Livewire::test(HistoriesRelationManager::class, [
        'ownerRecord' => $expense,
        'pageClass' => EditExpense::class,
    ])
        ->callAction(TestAction::make('create')->table(), [
            'due_date' => '2026-07-10',
            'amount' => '4200.50',
            'payment_date' => '2026-07-12',
        ])
        ->assertHasNoActionErrors();

    $history = $expense->histories()->first();
    expect($history)->not->toBeNull()
        ->and($history->due_date->toDateString())->toBe('2026-07-10')
        ->and((float) $history->amount)->toEqual(4200.50)
        ->and($history->payment_date?->toDateString())->toBe('2026-07-12')
        ->and($history->status)->toBe('paid')
        ->and($history->isPaid())->toBeTrue();
});

it('audits Test 1: payment with due_date and payment_date shows exact dates and paid status', function () {
    Carbon::setTestNow('2026-08-15');

    $expense = Expense::factory()->create([
        'period' => Expense::PERIOD_MONTHLY,
        'start_date' => '2026-08-04',
        'amount' => 5000,
    ]);

    $expense->histories()->create([
        'due_date' => '2026-08-04',
        'payment_date' => '2026-08-11',
        'amount' => 5000,
    ]);

    $calendar = app(BuildExpenseCalendar::class)->handle('2026-08');
    $event = collect($calendar['weeks'])->flatten(1)->flatMap(fn ($d) => $d['events'])->firstWhere('date', '2026-08-04');

    expect($event)->not->toBeNull()
        ->and($event['due_date_label'])->toBe('04/08/2026')
        ->and($event['payment_date_label'])->toBe('11/08/2026')
        ->and($event['status_label'])->toBe('Pago')
        ->and($event['paid_amount_label'])->toBe('R$ 5.000,00');
});

it('audits Test 2: legacy paid record without payment_date shows dash and never invents date using due_date', function () {
    Carbon::setTestNow('2026-08-15');

    $expense = Expense::factory()->create([
        'period' => Expense::PERIOD_MONTHLY,
        'start_date' => '2026-08-04',
        'amount' => 5000,
    ]);

    $expense->histories()->create([
        'due_date' => '2026-08-04',
        'payment_date' => null,
        'status' => 'paid',
        'amount' => 5000,
    ]);

    $calendar = app(BuildExpenseCalendar::class)->handle('2026-08');
    $event = collect($calendar['weeks'])->flatten(1)->flatMap(fn ($d) => $d['events'])->firstWhere('date', '2026-08-04');

    expect($event)->not->toBeNull()
        ->and($event['status_label'])->toBe('Pago')
        ->and($event['payment_date_label'])->toBe('—')
        ->and($event['paid_amount_label'])->toBe('R$ 5.000,00');
});

it('audits Test 3: unpaid bill shows pending or overdue and never paid just because an imported record exists', function () {
    Carbon::setTestNow('2026-08-15');

    $expense = Expense::factory()->create([
        'period' => Expense::PERIOD_MONTHLY,
        'start_date' => '2026-08-20',
        'amount' => 3000,
    ]);

    // Pending record in the future (due 20/08/2026 > today 15/08/2026)
    $expense->histories()->create([
        'due_date' => '2026-08-20',
        'payment_date' => null,
        'status' => 'pending',
        'amount' => 3000,
    ]);

    // Overdue record in the past (due 10/08/2026 < today 15/08/2026)
    $expensePast = Expense::factory()->create([
        'period' => Expense::PERIOD_MONTHLY,
        'start_date' => '2026-08-10',
        'amount' => 2000,
    ]);
    $expensePast->histories()->create([
        'due_date' => '2026-08-10',
        'payment_date' => null,
        'status' => 'pending',
        'amount' => 2000,
    ]);

    $calendar = app(BuildExpenseCalendar::class)->handle('2026-08');
    $events = collect($calendar['weeks'])->flatten(1)->flatMap(fn ($d) => $d['events']);

    $futureEvent = $events->firstWhere('date', '2026-08-20');
    $pastEvent = $events->firstWhere('date', '2026-08-10');

    expect($futureEvent['status_label'])->toBe('Pendente')
        ->and($futureEvent['payment_date_label'])->toBe('—')
        ->and($futureEvent['paid_amount_label'])->toBe('—')
        ->and($pastEvent['status_label'])->toBe('Vencido')
        ->and($pastEvent['payment_date_label'])->toBe('—')
        ->and($pastEvent['paid_amount_label'])->toBe('—');
});

it('audits Test 4: payment of one month cannot settle occurrence of another month', function () {
    Carbon::setTestNow('2026-06-15');

    $expense = Expense::factory()->create([
        'period' => Expense::PERIOD_MONTHLY,
        'start_date' => '2026-05-10',
        'end_date' => '2026-07-10',
        'amount' => 4000,
    ]);

    // Only May is paid
    $expense->histories()->create([
        'due_date' => '2026-05-10',
        'payment_date' => '2026-05-10',
        'status' => 'paid',
        'amount' => 4000,
    ]);

    $action = app(BuildExpenseCalendar::class);
    $juneEvents = collect($action->handle('2026-06')['weeks'])->flatten(1)->flatMap(fn ($d) => $d['events']);
    $juneEvent = $juneEvents->firstWhere('date', '2026-06-10');

    expect($juneEvent)->not->toBeNull()
        ->and($juneEvent['status_label'])->toBe('Vencido') // 10/06 < 15/06
        ->and($juneEvent['payment_date_label'])->toBe('—')
        ->and($juneEvent['paid_amount_label'])->toBe('—');
});

it('audits Test 5: two histories in same month do not silently pick wrong record', function () {
    Carbon::setTestNow('2026-05-25');

    $expense = Expense::factory()->create([
        'period' => Expense::PERIOD_MONTHLY,
        'start_date' => '2026-05-04',
        'amount' => 1000,
    ]);

    // Scheduled occurrence is on 04/05
    $expense->histories()->create([
        'due_date' => '2026-05-04',
        'payment_date' => '2026-05-04',
        'status' => 'paid',
        'amount' => 1000,
    ]);

    // Additional history in the same month on day 20
    $expense->histories()->create([
        'due_date' => '2026-05-20',
        'payment_date' => '2026-05-21',
        'status' => 'paid',
        'amount' => 500,
    ]);

    $calendar = app(BuildExpenseCalendar::class)->handle('2026-05');
    $events = collect($calendar['weeks'])->flatten(1)->flatMap(fn ($d) => $d['events']);

    $eventDay4 = $events->firstWhere('date', '2026-05-04');
    $eventDay20 = $events->firstWhere('date', '2026-05-20');

    expect($eventDay4)->not->toBeNull()
        ->and($eventDay4['paid_amount_label'])->toBe('R$ 1.000,00')
        ->and($eventDay4['payment_date_label'])->toBe('04/05/2026')
        ->and($eventDay20)->not->toBeNull()
        ->and($eventDay20['paid_amount_label'])->toBe('R$ 500,00')
        ->and($eventDay20['payment_date_label'])->toBe('21/05/2026');
});

it('audits Test 6: month-end occurrence with due date shifted to next month associates correctly without contaminating february', function () {
    Carbon::setTestNow('2026-02-15');

    $expense = Expense::factory()->create([
        'period' => Expense::PERIOD_MONTHLY,
        'start_date' => '2026-01-31',
        'amount' => 7000,
    ]);

    // Bank due date shifted from Saturday 31/01/2026 to Monday 02/02/2026
    $expense->histories()->create([
        'due_date' => '2026-02-02',
        'payment_date' => '2026-02-02',
        'status' => 'paid',
        'amount' => 7000,
    ]);

    $action = app(BuildExpenseCalendar::class);

    // In January: the 31/01 occurrence matches the shifted payment
    $janEvents = collect($action->handle('2026-01')['weeks'])->flatten(1)->flatMap(fn ($d) => $d['events']);
    $janEvent = $janEvents->firstWhere('date', '2026-01-31');

    expect($janEvent)->not->toBeNull()
        ->and($janEvent['status_label'])->toBe('Pago')
        ->and($janEvent['payment_date_label'])->toBe('02/02/2026')
        ->and($janEvent['paid_amount_label'])->toBe('R$ 7.000,00');

    // In February: the 28/02 occurrence does NOT associate with 02/02
    $febEvents = collect($action->handle('2026-02')['weeks'])->flatten(1)->flatMap(fn ($d) => $d['events']);
    $febEvent = $febEvents->firstWhere('date', '2026-02-28');

    expect($febEvent)->not->toBeNull()
        ->and($febEvent['status_label'])->toBe('Pendente') // 28/02 > 15/02
        ->and($febEvent['payment_date_label'])->toBe('—')
        ->and($febEvent['paid_amount_label'])->toBe('—');
});

it('audits Test 7: Conta Azul sync populates payment_date and status when available', function () {
    config()->set('conta-azul.category_map', [
        'Taxa de Gestão' => 'Gestão',
    ]);

    $emission = Emission::factory()->create();
    $fund = Fund::factory()->create([
        'emission_id' => $emission->id,
        'conta_azul_account_id' => 'acc-123',
    ]);

    $job = new SyncContaAzulExpensesJob;

    $bill = [
        'id' => 'bill-ca-777',
        'total' => 1250.00,
        'pago' => 1250.00,
        'nao_pago' => 0.00,
        'status' => 'RECEBIDO',
        'status_traduzido' => 'QUITADO',
        'data_vencimento' => '2026-05-10',
        'data_pagamento' => '2026-05-12',
        'categorias' => [
            ['nome' => 'Taxa de Gestão'],
        ],
    ];

    $job->upsertExpense($fund, $bill);

    $history = ExpenseHistory::where('conta_azul_bill_id', 'bill-ca-777')->first();

    expect($history)->not->toBeNull()
        ->and($history->amount)->toEqual('1250.00')
        ->and($history->due_date->toDateString())->toBe('2026-05-10')
        ->and($history->payment_date?->toDateString())->toBe('2026-05-12')
        ->and($history->status)->toBe('paid')
        ->and($history->isPaid())->toBeTrue();
});

it('audits Test 8: subsequent sync updates pending bill to paid without duplicate history', function () {
    Carbon::setTestNow('2026-06-01');

    config()->set('conta-azul.category_map', [
        'Taxa de Gestão' => 'Gestão',
    ]);

    $emission = Emission::factory()->create();
    $fund = Fund::factory()->create([
        'emission_id' => $emission->id,
        'conta_azul_account_id' => 'acc-123',
    ]);

    $job = new SyncContaAzulExpensesJob;

    // 1st sync: pending
    $pendingBill = [
        'id' => 'bill-ca-888',
        'total' => 3000.00,
        'pago' => 0.00,
        'nao_pago' => 3000.00,
        'status' => 'OPEN',
        'status_traduzido' => 'EM_ABERTO',
        'data_vencimento' => '2026-06-20',
        'categorias' => [
            ['nome' => 'Taxa de Gestão'],
        ],
    ];

    $job->upsertExpense($fund, $pendingBill);

    expect(ExpenseHistory::where('conta_azul_bill_id', 'bill-ca-888')->count())->toBe(1);
    $historyBefore = ExpenseHistory::where('conta_azul_bill_id', 'bill-ca-888')->first();
    expect($historyBefore->status)->toBe('pending')
        ->and($historyBefore->payment_date)->toBeNull()
        ->and($historyBefore->isPaid())->toBeFalse();

    // 2nd sync: paid
    $paidBill = [
        'id' => 'bill-ca-888',
        'total' => 3000.00,
        'pago' => 3000.00,
        'nao_pago' => 0.00,
        'status' => 'PAID',
        'status_traduzido' => 'QUITADO',
        'data_vencimento' => '2026-06-20',
        'data_pagamento' => '2026-06-19',
        'categorias' => [
            ['nome' => 'Taxa de Gestão'],
        ],
    ];

    $job->upsertExpense($fund, $paidBill);

    // Must still have exactly 1 record (no duplication!)
    expect(ExpenseHistory::where('conta_azul_bill_id', 'bill-ca-888')->count())->toBe(1);

    $historyAfter = ExpenseHistory::where('conta_azul_bill_id', 'bill-ca-888')->first();
    expect($historyAfter->status)->toBe('paid')
        ->and($historyAfter->payment_date?->toDateString())->toBe('2026-06-19')
        ->and($historyAfter->isPaid())->toBeTrue();
});

it('audits Test A: legacy Conta Azul record with null status and null payment_date with external bill open is NOT paid', function () {
    Carbon::setTestNow('2026-08-15');

    $expense = Expense::factory()->create([
        'period' => Expense::PERIOD_MONTHLY,
        'start_date' => '2026-08-04',
        'amount' => 5000,
    ]);

    // Legacy record imported by old sync, status = null, payment_date = null
    $expense->histories()->create([
        'due_date' => '2026-08-04',
        'payment_date' => null,
        'status' => null,
        'amount' => 5000,
        'conta_azul_bill_id' => 'legacy-open-bill-1',
    ]);

    $calendar = app(BuildExpenseCalendar::class)->handle('2026-08');
    $event = collect($calendar['weeks'])->flatten(1)->flatMap(fn ($d) => $d['events'])->firstWhere('date', '2026-08-04');

    expect($event)->not->toBeNull()
        ->and($event['status_label'])->not->toBe('Pago')
        ->and($event['status_label'])->toBe('Vencido') // 04/08 < 15/08 e não quitada
        ->and($event['payment_date_label'])->toBe('—')
        ->and($event['paid_amount_label'])->toBe('—');
});

it('audits Test B: legacy Conta Azul record with null status is reconciled to paid when external bill is quitada', function () {
    Carbon::setTestNow('2026-08-15');

    config()->set('conta-azul.category_map', [
        'Taxa de Gestão' => 'Gestão',
    ]);

    $emission = Emission::factory()->create();
    $fund = Fund::factory()->create([
        'emission_id' => $emission->id,
        'conta_azul_account_id' => 'acc-123',
    ]);

    $expense = Expense::factory()->create([
        'emission_id' => $emission->id,
        'category' => 'Gestão',
        'period' => Expense::PERIOD_MONTHLY,
        'start_date' => '2026-08-04',
        'amount' => 5000,
    ]);

    // Legacy record in database before reconciliation
    $expense->histories()->create([
        'due_date' => '2026-08-04',
        'payment_date' => null,
        'status' => null,
        'amount' => 5000,
        'conta_azul_bill_id' => 'legacy-bill-paid-in-ca',
    ]);

    // Before reconciliation: NOT paid
    $calendarBefore = app(BuildExpenseCalendar::class)->handle('2026-08');
    $eventBefore = collect($calendarBefore['weeks'])->flatten(1)->flatMap(fn ($d) => $d['events'])->firstWhere('date', '2026-08-04');
    expect($eventBefore['status_label'])->toBe('Vencido');

    // Run reconciliation via sync
    $job = new SyncContaAzulExpensesJob;
    $bill = [
        'id' => 'legacy-bill-paid-in-ca',
        'total' => 5000.00,
        'pago' => 5000.00,
        'nao_pago' => 0.00,
        'status' => 'PAID',
        'status_traduzido' => 'QUITADO',
        'data_vencimento' => '2026-08-04',
        'data_pagamento' => '2026-08-04',
        'categorias' => [
            ['nome' => 'Taxa de Gestão'],
        ],
    ];
    $job->upsertExpense($fund, $bill);

    // After reconciliation: Paid, no duplicate history
    expect(ExpenseHistory::where('conta_azul_bill_id', 'legacy-bill-paid-in-ca')->count())->toBe(1);

    $calendarAfter = app(BuildExpenseCalendar::class)->handle('2026-08');
    $eventAfter = collect($calendarAfter['weeks'])->flatten(1)->flatMap(fn ($d) => $d['events'])->firstWhere('date', '2026-08-04');
    expect($eventAfter['status_label'])->toBe('Pago')
        ->and($eventAfter['payment_date_label'])->toBe('04/08/2026')
        ->and($eventAfter['paid_amount_label'])->toBe('R$ 5.000,00');
});

it('audits Test C: partially paid bill (total 5000, pago 2000, nao_pago 3000) is not considered fully paid', function () {
    Carbon::setTestNow('2026-08-15');

    config()->set('conta-azul.category_map', [
        'Taxa de Gestão' => 'Gestão',
    ]);

    $emission = Emission::factory()->create();
    $fund = Fund::factory()->create([
        'emission_id' => $emission->id,
        'conta_azul_account_id' => 'acc-123',
    ]);

    $job = new SyncContaAzulExpensesJob;
    $partialBill = [
        'id' => 'bill-partial-123',
        'total' => 5000.00,
        'pago' => 2000.00,
        'nao_pago' => 3000.00,
        'status' => 'PARTIALLY_PAID',
        'status_traduzido' => 'RECEBIDO_PARCIAL',
        'data_vencimento' => '2026-08-20',
        'data_pagamento' => '2026-08-12',
        'categorias' => [
            ['nome' => 'Taxa de Gestão'],
        ],
    ];

    $job->upsertExpense($fund, $partialBill);

    $history = ExpenseHistory::where('conta_azul_bill_id', 'bill-partial-123')->first();
    expect($history)->not->toBeNull()
        ->and((float) $history->amount)->toEqual(5000.00) // Nominal amount preserved
        ->and((float) $history->paid_amount)->toEqual(2000.00) // Paid amount stored separately
        ->and($history->status)->toBe('partially_paid')
        ->and($history->isFullyPaid())->toBeFalse()
        ->and($history->isPartiallyPaid())->toBeTrue();

    $calendar = app(BuildExpenseCalendar::class)->handle('2026-08');
    $event = collect($calendar['weeks'])->flatten(1)->flatMap(fn ($d) => $d['events'])->firstWhere('date', '2026-08-20');

    expect($event)->not->toBeNull()
        ->and($event['status_label'])->toBe('Parcialmente pago')
        ->and($event['status_label'])->not->toBe('Pago')
        ->and($event['expected_amount_label'])->toBe('R$ 5.000,00')
        ->and($event['paid_amount_label'])->toBe('R$ 2.000,00')
        ->and($event['paid_amount_label'])->not->toBe('R$ 5.000,00')
        ->and($event['remaining_amount_label'])->toBe('R$ 3.000,00')
        ->and($event['payment_date_label'])->toBe('12/08/2026');
});

it('audits Test D: old pending bill does not become Paid just because an ExpenseHistory exists', function () {
    Carbon::setTestNow('2026-08-15');

    $expense = Expense::factory()->create([
        'period' => Expense::PERIOD_MONTHLY,
        'start_date' => '2026-08-10',
        'amount' => 4500,
    ]);

    // Old pending record
    $expense->histories()->create([
        'due_date' => '2026-08-10',
        'payment_date' => null,
        'status' => 'pending',
        'amount' => 4500,
    ]);

    $calendar = app(BuildExpenseCalendar::class)->handle('2026-08');
    $event = collect($calendar['weeks'])->flatten(1)->flatMap(fn ($d) => $d['events'])->firstWhere('date', '2026-08-10');

    expect($event)->not->toBeNull()
        ->and($event['status_label'])->toBe('Vencido') // 10/08 < 15/08
        ->and($event['status_label'])->not->toBe('Pago')
        ->and($event['payment_date_label'])->toBe('—')
        ->and($event['paid_amount_label'])->toBe('—');
});

it('audits Test E: amount continues representing nominal amount and preserves totals in MonthlyConsolidatedRelationManager and EmissionMonthlyReportService', function () {
    $emission = Emission::factory()->create();
    $expense = Expense::factory()->for($emission)->create(['amount' => 5000]);

    // History with partial payment: nominal amount = 5000, paid_amount = 2000
    $expense->histories()->create([
        'due_date' => '2026-05-15',
        'amount' => 5000,
        'paid_amount' => 2000,
        'status' => 'partially_paid',
        'payment_date' => '2026-05-10',
    ]);

    // History with full payment: nominal amount = 6000, paid_amount = 6000
    $expense->histories()->create([
        'due_date' => '2026-05-20',
        'amount' => 6000,
        'paid_amount' => 6000,
        'status' => 'paid',
        'payment_date' => '2026-05-20',
    ]);

    // History in previous competence
    $expense->histories()->create([
        'due_date' => '2026-04-15',
        'amount' => 4000,
        'paid_amount' => 4000,
        'status' => 'paid',
        'payment_date' => '2026-04-15',
    ]);

    // 1. EmissionMonthlyReportService: sums nominal amount (5000 + 6000 = 11000 for 05/2026)
    $data = app(EmissionMonthlyReportService::class)
        ->build($emission, CarbonImmutable::parse('2026-05-01'));

    expect($data['expenses_history']['has_data'])->toBeTrue();
    $mayRow = collect($data['expenses_history']['rows'])->firstWhere('competencia', '05/2026');
    expect($mayRow['total'])->toBe('R$ 11.000,00'); // Preserves exact nominal sum 5.000 + 6.000!

    // 2. MonthlyConsolidatedRelationManager: sums nominal amount (11000 for 05/2026)
    $admin = makeExpenseCalendarAdminUser();
    Livewire::actingAs($admin)
        ->test(MonthlyConsolidatedRelationManager::class, [
            'ownerRecord' => $expense,
            'pageClass' => EditExpense::class,
        ])
        ->assertSee('11.000,00');
});

it('audits Test F: DeleteAction is removed and CreateAction requires proper authorization', function () {
    $admin = makeExpenseCalendarAdminUser();
    $expense = Expense::factory()->create();
    $expense->histories()->create([
        'due_date' => '2026-05-15',
        'amount' => 5000,
        'payment_date' => '2026-05-15',
        'status' => 'paid',
    ]);

    // Verify DeleteAction does not exist on table
    Livewire::actingAs($admin)
        ->test(HistoriesRelationManager::class, [
            'ownerRecord' => $expense,
            'pageClass' => EditExpense::class,
        ])
        ->assertActionDoesNotExist('delete');

    // Verify user without expenses.create permission cannot create
    $viewerUser = User::factory()->withTwoFactor()->create();
    $viewerUser->givePermissionTo('expenses.view');

    Livewire::actingAs($viewerUser)
        ->test(HistoriesRelationManager::class, [
            'ownerRecord' => $expense,
            'pageClass' => EditExpense::class,
        ])
        ->assertActionHidden(TestAction::make('create')->table());
});

it('audits Test Realistic: persists real payment date from Conta Azul parcelas baixas when payment_date != due_date', function () {
    Carbon::setTestNow('2026-06-20');

    ContaAzulToken::create([
        'access_token' => 'fake-access-token',
        'refresh_token' => 'fake-refresh-token',
        'expires_at' => now()->addHours(2),
    ]);

    config()->set('conta-azul.category_map', [
        'Taxa de Gestão' => 'Gestão',
    ]);

    $emission = Emission::factory()->create();
    $fund = Fund::factory()->create([
        'emission_id' => $emission->id,
        'conta_azul_account_id' => 'acc-ca-real-test',
    ]);

    $billId = 'ca-bill-uuid-8103';

    // Mock HTTP response for Conta Azul parcelas endpoint
    Http::fake([
        'https://api-v2.contaazul.com/v1/financeiro/eventos-financeiros/parcelas/'.$billId => Http::response([
            'id' => $billId,
            'status' => 'QUITADO',
            'data_vencimento' => '2026-06-10',
            'data_pagamento_previsto' => '2026-06-10',
            'baixas' => [
                [
                    'id' => 'baixa-uuid-1',
                    'data_pagamento' => '2026-06-15', // Paid on 15/06/2026 (due_date was 10/06/2026)
                    'valor_composicao' => [
                        'valor_liquido' => 8103.55,
                    ],
                ],
            ],
        ], 200),
    ]);

    $client = app(ContaAzulClient::class);
    $job = new SyncContaAzulExpensesJob;

    // Payload exactly matching what /v1/financeiro/eventos-financeiros/contas-a-pagar/buscar returns
    $billFromSearch = [
        'id' => $billId,
        'status' => 'ACQUITTED',
        'status_traduzido' => 'RECEBIDO',
        'total' => 8103.55,
        'pago' => 8103.55,
        'nao_pago' => 0.0,
        'data_vencimento' => '2026-06-10',
        'data_competencia' => '2026-06-10',
        'categorias' => [
            ['nome' => 'Taxa de Gestão'],
        ],
    ];

    $job->upsertExpense($fund, $billFromSearch, $client);

    $history = ExpenseHistory::where('conta_azul_bill_id', $billId)->first();

    expect($history)->not->toBeNull()
        ->and((float) $history->amount)->toEqual(8103.55)
        ->and((float) $history->paid_amount)->toEqual(8103.55)
        ->and($history->status)->toBe('paid')
        ->and($history->due_date->toDateString())->toBe('2026-06-10')
        ->and($history->payment_date->toDateString())->toBe('2026-06-15')
        ->and($history->due_date->toDateString())->not->toBe($history->payment_date->toDateString())
        ->and($history->isPaid())->toBeTrue();

    // Verify calendar presentation
    $calendar = app(BuildExpenseCalendar::class)->handle('2026-06');
    $event = collect($calendar['weeks'])->flatten(1)->flatMap(fn ($d) => $d['events'])->firstWhere('date', '2026-06-10');

    expect($event)->not->toBeNull()
        ->and($event['due_date_label'])->toBe('10/06/2026')
        ->and($event['payment_date_label'])->toBe('15/06/2026')
        ->and($event['status_label'])->toBe('Pago')
        ->and($event['expected_amount_label'])->toBe('R$ 8.103,55')
        ->and($event['paid_amount_label'])->toBe('R$ 8.103,55');
});

it('keeps Valor previsto numerically identical to the legacy float sum after the rename', function () {
    $this->travelTo(Carbon::parse('2026-08-15'));

    $emission = Emission::factory()->create();

    foreach (['0.10', '0.20', '0.70', '1234.56', '5900.55', '16935.12'] as $index => $amount) {
        Expense::factory()->create([
            'emission_id' => $emission->id,
            'amount' => $amount,
            'period' => Expense::PERIOD_SINGLE,
            'start_date' => sprintf('2026-08-%02d', $index + 3),
        ]);
    }

    $calendar = app(BuildExpenseCalendar::class)->handle('2026-08');
    $events = collect($calendar['weeks'])->flatten(1)->flatMap(fn (array $day): array => $day['events']);

    // Fórmula anterior do "Valor do mês": soma em float das ocorrências + number_format.
    $legacyTotal = 'R$ '.number_format((float) $events->sum('amount'), 2, ',', '.');

    expect($calendar['summary']['total_amount'])->toBe($legacyTotal)
        ->and($calendar['summary']['total_amount'])->toBe('R$ 24.071,23')
        ->and($calendar['summary']['event_count'])->toBe(6);
});

it('sums the actual paid amount of the scheduled occurrence', function (array $history, string $paidAmount, string $paidPercentage, string $settlementLabel) {
    $this->travelTo(Carbon::parse('2026-08-15'));

    $expense = Expense::factory()->create([
        'amount' => 1000,
        'period' => Expense::PERIOD_SINGLE,
        'start_date' => '2026-08-10',
    ]);

    if ($history !== []) {
        $expense->histories()->create(['due_date' => '2026-08-10', 'amount' => 1000, ...$history]);
    }

    $summary = app(BuildExpenseCalendar::class)->handle('2026-08')['summary'];

    expect($summary['event_count'])->toBe(1)
        ->and($summary['total_amount'])->toBe('R$ 1.000,00')
        ->and($summary['paid_amount'])->toBe($paidAmount)
        ->and($summary['paid_percentage'])->toBe($paidPercentage)
        ->and($summary['settlement_label'])->toBe($settlementLabel);
})->with([
    'fully paid' => [
        ['status' => 'paid', 'paid_amount' => 1000, 'payment_date' => '2026-08-10'],
        'R$ 1.000,00', '100,00%', 'Integralmente pago',
    ],
    'partially paid' => [
        ['status' => 'partially_paid', 'paid_amount' => 600, 'payment_date' => '2026-08-10'],
        'R$ 600,00', '60,00%', 'R$ 400,00 em aberto',
    ],
    // Regra já existente no resolvedor: quitado sem `paid_amount` vale o valor nominal do lançamento.
    'legacy paid record without paid_amount' => [
        ['status' => 'paid', 'paid_amount' => null, 'payment_date' => null],
        'R$ 1.000,00', '100,00%', 'Integralmente pago',
    ],
    'unpaid history (paid_amount null)' => [
        ['status' => 'pending', 'paid_amount' => null, 'payment_date' => null],
        'R$ 0,00', '0,00%', 'R$ 1.000,00 em aberto',
    ],
    'unpaid without any history' => [
        [],
        'R$ 0,00', '0,00%', 'R$ 1.000,00 em aberto',
    ],
]);

it('aggregates expected and paid totals across multiple occurrences', function () {
    $this->travelTo(Carbon::parse('2026-08-15'));

    $emission = Emission::factory()->create();

    $paid = Expense::factory()->create(['emission_id' => $emission->id, 'amount' => 1000, 'start_date' => '2026-08-03']);
    $paid->histories()->create(['due_date' => '2026-08-03', 'amount' => 1000, 'paid_amount' => 1000, 'status' => 'paid', 'payment_date' => '2026-08-03']);

    $partial = Expense::factory()->create(['emission_id' => $emission->id, 'amount' => 2500, 'start_date' => '2026-08-12']);
    $partial->histories()->create(['due_date' => '2026-08-12', 'amount' => 2500, 'paid_amount' => 1000, 'status' => 'partially_paid', 'payment_date' => '2026-08-12']);

    Expense::factory()->create(['emission_id' => $emission->id, 'amount' => 750, 'start_date' => '2026-08-25']);

    $recurring = Expense::factory()->create([
        'emission_id' => $emission->id,
        'amount' => 5900.55,
        'period' => Expense::PERIOD_MONTHLY,
        'start_date' => '2026-06-04',
    ]);
    $recurring->histories()->create(['due_date' => '2026-07-04', 'amount' => 5900.55, 'paid_amount' => 5900.55, 'status' => 'paid', 'payment_date' => '2026-07-04']);
    $recurring->histories()->create(['due_date' => '2026-08-04', 'amount' => 5900.55, 'paid_amount' => 5900.55, 'status' => 'paid', 'payment_date' => '2026-08-05']);

    $summary = app(BuildExpenseCalendar::class)->handle('2026-08')['summary'];

    // Previsto: 1.000 + 2.500 + 750 + 5.900,55 | Pago: 1.000 + 1.000 + 0 + 5.900,55 (julho fica fora)
    expect($summary['event_count'])->toBe(4)
        ->and($summary['total_amount'])->toBe('R$ 10.150,55')
        ->and($summary['paid_amount'])->toBe('R$ 7.900,55')
        ->and($summary['paid_percentage'])->toBe('77,83%')
        ->and($summary['outstanding_amount'])->toBe('R$ 2.250,00')
        ->and($summary['settlement_label'])->toBe('R$ 2.250,00 em aberto')
        ->and($summary['operation_count'])->toBe(1);
});

it('applies operation and category filters to expected and paid totals alike', function () {
    $this->travelTo(Carbon::parse('2026-08-15'));

    $bellevue = Emission::factory()->create(['name' => 'Alto Bellevue']);
    $conviva = Emission::factory()->create(['name' => 'CRI Conviva']);

    $scenarios = [
        [$bellevue, 'Assessor Jurídico', 1000, 1000],
        [$bellevue, 'Auditoria', 2000, 500],
        [$conviva, 'Assessor Jurídico', 4000, null],
        [$conviva, 'Auditoria', 8000, 8000],
    ];

    foreach ($scenarios as $day => [$emission, $category, $amount, $paidAmount]) {
        $date = sprintf('2026-08-%02d', $day + 5);
        $expense = Expense::factory()->create([
            'emission_id' => $emission->id,
            'category' => $category,
            'amount' => $amount,
            'start_date' => $date,
        ]);
        $expense->histories()->create([
            'due_date' => $date,
            'amount' => $amount,
            'paid_amount' => $paidAmount,
            'status' => $paidAmount === null ? 'pending' : ($paidAmount < $amount ? 'partially_paid' : 'paid'),
            'payment_date' => $paidAmount === null ? null : $date,
        ]);
    }

    $action = app(BuildExpenseCalendar::class);

    $all = $action->handle('2026-08')['summary'];
    $byOperation = $action->handle('2026-08', ['emission_id' => $bellevue->id])['summary'];
    $byCategory = $action->handle('2026-08', ['category' => 'Assessor Jurídico'])['summary'];
    $byBoth = $action->handle('2026-08', ['emission_id' => $bellevue->id, 'category' => 'Assessor Jurídico'])['summary'];

    expect([$all['event_count'], $all['total_amount'], $all['paid_amount'], $all['operation_count']])
        ->toBe([4, 'R$ 15.000,00', 'R$ 9.500,00', 2])
        ->and([$byOperation['event_count'], $byOperation['total_amount'], $byOperation['paid_amount'], $byOperation['paid_percentage'], $byOperation['operation_count']])
        ->toBe([2, 'R$ 3.000,00', 'R$ 1.500,00', '50,00%', 1])
        ->and([$byCategory['event_count'], $byCategory['total_amount'], $byCategory['paid_amount'], $byCategory['paid_percentage'], $byCategory['operation_count']])
        ->toBe([2, 'R$ 5.000,00', 'R$ 1.000,00', '20,00%', 2])
        ->and([$byBoth['event_count'], $byBoth['total_amount'], $byBoth['paid_amount'], $byBoth['paid_percentage'], $byBoth['operation_count']])
        ->toBe([1, 'R$ 1.000,00', 'R$ 1.000,00', '100,00%', 1]);

    $this->actingAs(makeExpenseCalendarAdminUser());

    $component = Livewire::test(ExpenseCalendar::class)
        ->set('visibleMonth', '2026-08')
        ->set('selectedEmissionId', (string) $bellevue->id)
        ->set('selectedCategory', 'Assessor Jurídico')
        ->assertSee('Indicadores refletem os filtros aplicados.');

    expect($component->instance()->getCalendarData()['summary'])->toMatchArray($byBoth);

    $component->call('clearFilters');

    expect($component->instance()->getCalendarData()['summary'])->toMatchArray($all);
});

it('recalculates expected and paid KPIs when the displayed month changes', function () {
    $this->travelTo(Carbon::parse('2026-08-15'));
    $this->actingAs(makeExpenseCalendarAdminUser());

    $august = Expense::factory()->create(['amount' => 1000, 'start_date' => '2026-08-10']);
    $august->histories()->create(['due_date' => '2026-08-10', 'amount' => 1000, 'paid_amount' => 600, 'status' => 'partially_paid', 'payment_date' => '2026-08-10']);

    $september = Expense::factory()->create(['amount' => 2000, 'start_date' => '2026-09-10']);
    $september->histories()->create(['due_date' => '2026-09-10', 'amount' => 2000, 'paid_amount' => 2000, 'status' => 'paid', 'payment_date' => '2026-09-08']);

    $summaryOf = fn ($component): array => $component->instance()->getCalendarData()['summary'];

    $component = Livewire::test(ExpenseCalendar::class)
        ->set('visibleMonth', '2026-08')
        ->assertSee('Valor previsto')
        ->assertSee('Valor pago')
        ->assertDontSee('Valor do mês')
        ->assertSee('60,00%')
        ->assertSee('R$ 400,00 em aberto');

    expect($summaryOf($component))->toMatchArray(['total_amount' => 'R$ 1.000,00', 'paid_amount' => 'R$ 600,00', 'paid_percentage' => '60,00%']);

    $component->call('nextMonth')
        ->assertSee('100,00%')
        ->assertSee('Integralmente pago')
        ->assertDontSee('60,00%');

    expect($summaryOf($component))->toMatchArray(['total_amount' => 'R$ 2.000,00', 'paid_amount' => 'R$ 2.000,00', 'paid_percentage' => '100,00%']);

    $component->set('visibleMonth', '2026-10')
        ->assertSee('Sem valor previsto no período.');

    expect($summaryOf($component))->toMatchArray(['event_count' => 0, 'total_amount' => 'R$ 0,00', 'paid_amount' => 'R$ 0,00', 'paid_percentage' => null]);

    $component->call('previousMonth');

    expect($summaryOf($component))->toMatchArray(['total_amount' => 'R$ 2.000,00', 'paid_amount' => 'R$ 2.000,00']);

    $component->call('currentMonth')
        ->assertSet('visibleMonth', '2026-08')
        ->assertSee('60,00%');

    expect($summaryOf($component))->toMatchArray(['total_amount' => 'R$ 1.000,00', 'paid_amount' => 'R$ 600,00']);
});

it('counts a payment in the month of its due date, not in the month it was paid', function () {
    $this->travelTo(Carbon::parse('2026-09-15'));

    $expense = Expense::factory()->create(['amount' => 1000, 'start_date' => '2026-08-20']);
    $expense->histories()->create([
        'due_date' => '2026-08-20',
        'payment_date' => '2026-09-03',
        'amount' => 1000,
        'paid_amount' => 1000,
        'status' => 'paid',
    ]);

    $action = app(BuildExpenseCalendar::class);
    $august = $action->handle('2026-08')['summary'];
    $september = $action->handle('2026-09')['summary'];

    // Regra adotada: o calendário é por vencimento (competência da ocorrência). "Valor pago"
    // soma o que foi pago contra as ocorrências vencendo no mês exibido, qualquer que seja a
    // data do pagamento; não é fluxo de caixa por payment_date. Pago em setembro, conta em agosto.
    expect($august['total_amount'])->toBe('R$ 1.000,00')
        ->and($august['paid_amount'])->toBe('R$ 1.000,00')
        ->and($august['paid_percentage'])->toBe('100,00%')
        ->and($september['event_count'])->toBe(0)
        ->and($september['paid_amount'])->toBe('R$ 0,00')
        ->and($september['paid_percentage'])->toBeNull();
});

it('never divides by zero when there is no positive expected amount', function () {
    $this->travelTo(Carbon::parse('2026-08-15'));
    $this->actingAs(makeExpenseCalendarAdminUser());

    $empty = app(BuildExpenseCalendar::class)->handle('2026-04')['summary'];

    expect($empty)->toBe([
        'event_count' => 0,
        'total_amount' => 'R$ 0,00',
        'paid_amount' => 'R$ 0,00',
        'paid_percentage' => null,
        'outstanding_amount' => 'R$ 0,00',
        'settlement_label' => null,
        'operation_count' => 0,
    ]);

    // Despesa legada com valor nulo: a ocorrência existe, mas o previsto é zero.
    Expense::factory()->create(['amount' => null, 'start_date' => '2026-08-20']);

    $zeroExpected = app(BuildExpenseCalendar::class)->handle('2026-08')['summary'];

    expect($zeroExpected['event_count'])->toBe(1)
        ->and($zeroExpected['total_amount'])->toBe('R$ 0,00')
        ->and($zeroExpected['paid_percentage'])->toBeNull()
        ->and($zeroExpected['settlement_label'])->toBeNull();

    Livewire::test(ExpenseCalendar::class)
        ->set('visibleMonth', '2026-08')
        ->assertSee('Sem valor previsto no período.')
        ->assertDontSee('NaN')
        ->assertDontSee('INF')
        ->assertDontSee('Infinity');
});

it('has no cancellation state: a reversed or stale payment never counts as paid', function () {
    $this->travelTo(Carbon::parse('2026-08-15'));

    config()->set('conta-azul.category_map', ['Taxa de Gestão' => 'Gestão']);

    $emission = Emission::factory()->create();
    $fund = Fund::factory()->create(['emission_id' => $emission->id, 'conta_azul_account_id' => 'acc-123']);
    $job = new SyncContaAzulExpensesJob;
    $bill = [
        'id' => 'bill-reversed-1',
        'total' => 1000.00,
        'status_traduzido' => 'QUITADO',
        'status' => 'PAID',
        'pago' => 1000.00,
        'nao_pago' => 0.00,
        'data_vencimento' => '2026-08-10',
        'data_pagamento' => '2026-08-10',
        'categorias' => [['nome' => 'Taxa de Gestão']],
    ];

    $job->upsertExpense($fund, $bill);

    expect(app(BuildExpenseCalendar::class)->handle('2026-08')['summary']['paid_amount'])->toBe('R$ 1.000,00');

    // A baixa é estornada no Conta Azul: a sincronização reclassifica a conta como em aberto
    // e limpa o paid_amount. Não existe estado "cancelado" no domínio de despesas.
    $job->upsertExpense($fund, [...$bill, 'status' => 'CANCELLED', 'status_traduzido' => 'CANCELADO', 'pago' => 0.00, 'nao_pago' => 1000.00]);

    $history = ExpenseHistory::query()->where('conta_azul_bill_id', 'bill-reversed-1')->sole();

    expect($history->status)->toBe(ExpensePaymentStatus::Overdue->value)
        ->and($history->paid_amount)->toBeNull();

    // Lançamento com paid_amount residual, mas status que não é de pagamento.
    $stale = Expense::factory()->create(['emission_id' => $emission->id, 'category' => 'Auditoria', 'amount' => 500, 'start_date' => '2026-08-20']);
    $stale->histories()->create(['due_date' => '2026-08-20', 'amount' => 500, 'paid_amount' => 500, 'status' => 'pending']);

    $summary = app(BuildExpenseCalendar::class)->handle('2026-08')['summary'];

    // As duas ocorrências continuam no previsto (o calendário não exclui nada por cancelamento),
    // mas nenhuma entra no pago.
    expect($summary['event_count'])->toBe(2)
        ->and($summary['total_amount'])->toBe('R$ 1.500,00')
        ->and($summary['paid_amount'])->toBe('R$ 0,00')
        ->and($summary['paid_percentage'])->toBe('0,00%');
});

it('preserves the real value and percentage when the paid amount exceeds the expected amount', function () {
    $this->travelTo(Carbon::parse('2026-08-15'));
    $this->actingAs(makeExpenseCalendarAdminUser());

    $expense = Expense::factory()->create(['amount' => 1000, 'start_date' => '2026-08-10']);
    $expense->histories()->create([
        'due_date' => '2026-08-10',
        'amount' => 1000,
        'paid_amount' => 1054.20,
        'status' => 'paid',
        'payment_date' => '2026-08-12',
    ]);

    $summary = app(BuildExpenseCalendar::class)->handle('2026-08')['summary'];

    expect($summary['total_amount'])->toBe('R$ 1.000,00')
        ->and($summary['paid_amount'])->toBe('R$ 1.054,20')
        ->and($summary['paid_percentage'])->toBe('105,42%')
        ->and($summary['outstanding_amount'])->toBe('R$ -54,20')
        ->and($summary['settlement_label'])->toBe('R$ 54,20 acima do previsto');

    Livewire::test(ExpenseCalendar::class)
        ->set('visibleMonth', '2026-08')
        ->assertSee('105,42%')
        ->assertSee('R$ 54,20 acima do previsto');
});

function makeExpenseCalendarAdminUser(): User
{
    $user = User::factory()->withTwoFactor()->create([
        'email' => fake()->unique()->safeEmail(),
    ]);
    $user->assignRole('admin');

    return $user;
}

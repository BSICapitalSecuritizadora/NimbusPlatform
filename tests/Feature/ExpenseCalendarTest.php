<?php

use App\Actions\Expenses\BuildExpenseCalendar;
use App\Filament\Resources\Expenses\Pages\ExpenseCalendar;
use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Models\Emission;
use App\Models\Expense;
use App\Models\ExpenseServiceProvider;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
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

function makeExpenseCalendarAdminUser(): User
{
    $user = User::factory()->withTwoFactor()->create([
        'email' => fake()->unique()->safeEmail(),
    ]);
    $user->assignRole('admin');

    return $user;
}

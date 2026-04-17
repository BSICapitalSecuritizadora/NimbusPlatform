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
        ->assertSee('Todas as operações')
        ->assertSee('Todas as categorias')
        ->assertSee('Calendário de pagamentos')
        ->assertSee('CRI Conviva')
        ->assertSee('Engenharia')
        ->assertSee('R$ 5.000,00');
});

function makeExpenseCalendarAdminUser(): User
{
    $user = User::factory()->withTwoFactor()->create([
        'email' => fake()->unique()->safeEmail(),
    ]);
    $user->assignRole('admin');

    return $user;
}

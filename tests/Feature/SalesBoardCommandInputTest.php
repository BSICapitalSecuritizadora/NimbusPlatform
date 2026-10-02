<?php

use App\Enums\SalesBoardCycleStatus;
use App\Filament\Resources\SalesBoardCycles\Pages\ListSalesBoardCycles;
use App\Models\SalesBoardCycle;
use App\Support\SalesBoards\BusinessDateInput;
use App\Support\SalesBoards\ReferenceMonthInput;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SalesBoards\CycleFixture;

/**
 * O que uma pessoa digita como competência ou como data de negócio não se
 * adivinha.
 *
 * A linha de comando aceita só `mm/aaaa` e `aaaa-mm` para a competência, e só
 * `aaaa-mm-dd` para a data de negócio forçada: `07/25`, `now` e `last month`
 * eram aceitos pelo `Carbon::parse()`, e um ciclo gerado por engano só sai por
 * cancelamento. A tela tem leitor próprio, porque o DatePicker entrega a data
 * completa com hora.
 */
uses(RefreshDatabase::class);

it('reads a competence only as mm/aaaa or aaaa-mm', function (string $raw, ?string $expected) {
    expect(ReferenceMonthInput::parse($raw)?->toDateString())->toBe($expected);
})->with([
    'mm/aaaa' => ['07/2025', '2025-07-01'],
    'aaaa-mm' => ['2025-07', '2025-07-01'],
    'padded mm/aaaa' => [' 12/2026 ', '2026-12-01'],
    'two digit year' => ['07/25', null],
    'one digit month' => ['7/2026', null],
    'now' => ['now', null],
    'last month' => ['last month', null],
    'full date' => ['2025-07-31', null],
    'month thirteen' => ['13/2026', null],
    'iso month thirteen' => ['2026-13', null],
    'month name' => ['julho/2026', null],
]);

it('reads the date picker state of the freeze action', function (string $raw, ?string $expected) {
    expect(ReferenceMonthInput::fromDateState($raw)?->toDateString())->toBe($expected);
})->with([
    'picker state with time' => ['2026-08-01 00:00:00', '2026-08-01'],
    'date in the middle of the month' => ['2026-08-15', '2026-08-01'],
    'now' => ['now', null],
    'impossible day' => ['2026-02-30', null],
    'month only' => ['2026-08', null],
]);

it('reads a date picker state given as a date instance', function () {
    expect(ReferenceMonthInput::fromDateState(CarbonImmutable::parse('2026-08-20 15:30:00'))?->toDateString())->toBe('2026-08-01')
        ->and(ReferenceMonthInput::fromDateState(null))->toBeNull();
});

it('reads a business date only as aaaa-mm-dd', function (string $raw, ?string $expected) {
    expect(BusinessDateInput::parse($raw)?->format('Y-m-d H:i:s'))->toBe($expected);
})->with([
    'iso date' => ['2026-09-13', '2026-09-13 00:00:00'],
    'brazilian date' => ['13/09/2026', null],
    'now' => ['now', null],
    'one digit month' => ['2026-9-13', null],
    'impossible day' => ['2026-02-30', null],
]);

it('refuses an ambiguous competence on generate-cycle and derive without generating anything', function (string $command) {
    [$construction] = CycleFixture::readyConstruction(1);

    $this->artisan($command, [
        '--construction' => $construction->id,
        '--reference-month' => '07/25',
    ])
        ->expectsOutputToContain('Competência inválida. Use mm/aaaa ou aaaa-mm.')
        ->assertExitCode(1);

    expect(SalesBoardCycle::query()->count())->toBe(0);
})->with(['sales-boards:generate-cycle', 'sales-boards:derive']);

it('still freezes through the screen with the date picker state', function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(makeAdminUser());

    [$construction] = CycleFixture::readyConstruction(2);

    Livewire::test(ListSalesBoardCycles::class)
        ->callAction(TestAction::make('generateCycle'), data: [
            'construction_id' => $construction->id,
            'reference_month' => '2026-07-01 00:00:00',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('Ciclo gerado');

    $cycle = SalesBoardCycle::query()->sole();

    expect($cycle->status)->toBe(SalesBoardCycleStatus::Generated)
        ->and($cycle->reference_month->toDateString())->toBe('2026-07-01');
});

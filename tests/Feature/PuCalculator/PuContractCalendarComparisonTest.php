<?php

use App\Domain\PuCalculator\DTOs\PuDailyCurveRowData;
use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Domain\PuCalculator\Services\PuContractCalendarComparisonService;
use App\Domain\PuCalculator\Services\PuSimulationService;
use App\Domain\PuCalculator\Support\BusinessCalendarRegistry;
use App\Filament\Resources\Emissions\Pages\EditEmission;
use App\Filament\Resources\Emissions\Pages\PuCalculatorSimulator;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuDailyCurve;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Action;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Pu\PuSimulationFixture;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Http::preventStrayRequests();
});

/**
 * Emissão com o Termo em feriados nacionais e uma curva oficial vigente no
 * calendário de mercado, gravada a partir da engine oficial.
 */
function emissionWithOfficialMarketCurve(): Emission
{
    $scenario = PuSimulationFixture::calculableScenario();
    $emission = $scenario['emission'];
    PuSimulationFixture::persistParameter($emission, [
        'curve_end_date' => PuSimulationFixture::windowEndDate()->toDateString(),
    ]);
    $rows = app(PuSimulationService::class)->simulate($emission->fresh(), $scenario['input'])->rows;
    $version = EmissionPuCurveVersion::factory()->create([
        'emission_id' => $emission->id,
        'calculation_version' => 'v1',
        'status' => PuCurveStatus::Generated->value,
    ]);
    $timestamp = now();

    EmissionPuDailyCurve::query()->insert(array_map(fn (PuDailyCurveRowData $row): array => [
        ...$row->toPersistenceArray($emission->id, 'v1'),
        'curve_version_id' => $version->id,
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ], $rows));

    return $emission->fresh();
}

function contractCalendarComparison(Emission $emission): array
{
    return app(PuContractCalendarComparisonService::class)->compare($emission->fresh());
}

it('compares the official market curve with the same curve counted in the Termo calendar', function () {
    $emission = emissionWithOfficialMarketCurve();
    $rowsBefore = EmissionPuDailyCurve::query()->count();

    $comparison = contractCalendarComparison($emission);
    $rows = collect($comparison['rows'])->keyBy('date');

    expect($comparison['available'])->toBeTrue()
        ->and($comparison['official_calendar'])->toBe(BusinessCalendarRegistry::MARKET_CALENDAR)
        ->and($comparison['contract_calendar'])->toBe(BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS)
        ->and($comparison['days_compared'])->toBe($rowsBefore)
        // Até a véspera de Corpus Christi os dois calendários contam os mesmos dias úteis.
        ->and($rows['2026-06-03']['difference'])->toBe('0.00000000')
        // Corpus Christi é útil só no Termo: a curva do contrato acumula um dia a mais.
        ->and($comparison['first_divergent_date'])->toBe('2026-06-04')
        ->and($rows['2026-06-04']['official_business_day'])->toBeFalse()
        ->and($rows['2026-06-04']['contract_business_day'])->toBeTrue()
        ->and(bccomp($rows['2026-06-04']['difference'], '0', 8))->toBe(1)
        // O cupom de 08/06 carrega esse dia a mais de juros.
        ->and(bccomp($rows['2026-06-08']['contract_payment'], $rows['2026-06-08']['official_payment'], 8))->toBe(1)
        ->and($comparison['divergent_days'])->toBeGreaterThan(0)
        // Nada é gravado: a curva do contrato só existe na comparação.
        ->and(EmissionPuDailyCurve::query()->count())->toBe($rowsBefore)
        ->and(EmissionPuCurveVersion::query()->count())->toBe(1);

    $html = view('filament.emissions.pu-contract-calendar-comparison', ['comparison' => $comparison])->render();

    expect($html)->toContain('04/06/2026')
        ->and($html)->toContain(BusinessCalendarRegistry::label(BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS))
        ->and($html)->toContain('Não / Sim');
});

it('explains why the comparison is unavailable', function (Closure $prepare, string $reason) {
    $comparison = contractCalendarComparison($prepare());

    expect($comparison['available'])->toBeFalse()
        ->and($comparison['reason'])->toContain($reason)
        ->and($comparison['rows'])->toBe([]);
})->with([
    'sem curva oficial' => [fn (): Emission => PuSimulationFixture::calculableScenario()['emission'], 'Gere a curva oficial'],
    'curva oficial já no calendário do Termo' => [function (): Emission {
        $emission = emissionWithOfficialMarketCurve();
        $emission->puParameter->update(['calendar_code' => BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS]);

        return $emission;
    }, 'já usa o calendário do Termo'],
]);

it('offers the comparison from the emission screen to anyone who can see the curve', function () {
    $emission = emissionWithOfficialMarketCurve();
    $this->actingAs(makeAdminUser());

    Livewire::test(EditEmission::class, ['record' => $emission->getRouteKey()])
        ->assertActionVisible('comparePuContractCalendar')
        ->assertActionExists('comparePuContractCalendar', fn (Action $action): bool => $action->getModalHeading() === 'Curva oficial × calendário do Termo')
        ->mountAction('comparePuContractCalendar')
        ->assertHasNoErrors();
});

it('offers the Termo calendar as the accrual hypothesis in the simulator', function () {
    $this->actingAs(makeAdminUser());
    $scenario = PuSimulationFixture::calculableScenario();

    $options = Livewire::test(PuCalculatorSimulator::class, ['record' => $scenario['emission']->getRouteKey()])
        ->instance()
        ->accrualCalendarOptions();

    expect($options)->toHaveKey(BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS)
        ->and($options[BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS])
        ->toBe('Calendário do Termo — '.BusinessCalendarRegistry::label(BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS))
        ->and($options)->not->toHaveKey(BusinessCalendarRegistry::MARKET_CALENDAR);
});

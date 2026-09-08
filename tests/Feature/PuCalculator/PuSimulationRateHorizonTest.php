<?php

use App\Domain\PuCalculator\DTOs\PuSimulationInput;
use App\Domain\PuCalculator\DTOs\PuSimulationResult;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use App\Domain\PuCalculator\Services\PuSimulationService;
use App\Domain\PuCalculator\Support\BusinessCalendarRegistry;
use App\Filament\Resources\Emissions\Pages\PuCalculatorSimulator;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
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

/** Vencimento contratual do Alto sintético. Nunca é recorte de simulação. */
const HORIZON_CONTRACTUAL_MATURITY = '2031-05-08';

function simulateWindow(string $endDate, ?string $indexRateCalendarCode = null): PuSimulationResult
{
    $scenario = PuSimulationFixture::calculableScenario();

    return app(PuSimulationService::class)->simulate($scenario['emission'], new PuSimulationInput(
        firstIntegralizationDate: PuSimulationFixture::integralizationDate(),
        simulationEndDate: CarbonImmutable::parse($endDate),
        indexRateCalendarCode: $indexRateCalendarCode,
    ));
}

// ---------------------------------------------------------------------------
// A regressão observada na tela: o recorte nascia no vencimento.
// Não existe janela implícita -- a data final é input explícito do usuário.
// ---------------------------------------------------------------------------

it('proposes no simulation window at all when the calculator opens', function () {
    $this->actingAs(makeAdminUser());
    $scenario = PuSimulationFixture::calculableScenario();

    $component = Livewire::test(PuCalculatorSimulator::class, ['record' => $scenario['emission']->getRouteKey()])
        ->assertOk();

    $page = $component->instance();

    // Nem o vencimento nem qualquer outra data ocupam o campo: ele nasce vazio.
    expect($page->simulationEndDate)->toBeNull()
        ->and($page->simulationInput()->simulationEndDate)->toBeNull()
        // E o vencimento contratual continua resolvido, como vencimento.
        ->and($page->parameterResolution()['values']['contractual_curve_end_date'])
        ->toBe(HORIZON_CONTRACTUAL_MATURITY)
        ->and($page->contractualMaturityLabel())->toBe('08/05/2031');
});

it('derives no window from the first integralization date', function () {
    $this->actingAs(makeAdminUser());
    $scenario = PuSimulationFixture::calculableScenario();

    $component = Livewire::test(PuCalculatorSimulator::class, ['record' => $scenario['emission']->getRouteKey()])
        ->set('firstIntegralizationDate', PuSimulationFixture::integralizationDate()->toDateString());

    // Informar o início não inventa um fim: não existe janela implícita.
    expect($component->instance()->simulationEndDate)->toBeNull()
        ->and($component->instance()->simulationInput()->simulationEndDate)->toBeNull();
});

it('refuses to calculate until the end date is informed', function () {
    $this->actingAs(makeAdminUser());
    $scenario = PuSimulationFixture::calculableScenario();

    $component = Livewire::test(PuCalculatorSimulator::class, ['record' => $scenario['emission']->getRouteKey()])
        ->set('firstIntegralizationDate', PuSimulationFixture::integralizationDate()->toDateString())
        ->call('calculate')
        ->assertNotified('Informe a data final da simulação.');

    // Nada foi simulado: nenhuma janela de anos correu em silêncio.
    expect($component->instance()->result())->toBeNull()
        ->and($component->instance()->hasCalculated)->toBeFalse();
});

it('calculates as soon as the user informs the end date', function () {
    $this->actingAs(makeAdminUser());
    $scenario = PuSimulationFixture::calculableScenario();

    $component = Livewire::test(PuCalculatorSimulator::class, ['record' => $scenario['emission']->getRouteKey()])
        ->set('firstIntegralizationDate', PuSimulationFixture::integralizationDate()->toDateString())
        ->set('simulationEndDate', PuSimulationFixture::windowEndDate()->toDateString())
        ->call('calculate');

    $result = $component->instance()->result();

    // O bloqueio é do campo vazio, não do fluxo.
    expect($result)->not->toBeNull()
        ->and($result->parameters['curve_end_date'])
        ->toBe(PuSimulationFixture::windowEndDate()->toDateString())
        ->and($result->parameters['contractual_curve_end_date'])->toBe(HORIZON_CONTRACTUAL_MATURITY);
});

// ---------------------------------------------------------------------------
// O horizonte de taxas obedece ao recorte, e é derivado dele
// ---------------------------------------------------------------------------

it('bounds the required rate dates by the simulation cutoff, not by maturity', function () {
    $result = simulateWindow(PuSimulationFixture::windowEndDate()->toDateString());
    $required = $result->requiredRateDates;
    $cutoff = PuSimulationFixture::windowEndDate();

    expect($required)->not->toBeEmpty();

    // Nenhuma exigência além do recorte, e nada perto do vencimento.
    $max = CarbonImmutable::parse($required[array_key_last($required)]);

    expect($max->lessThanOrEqualTo($cutoff))->toBeTrue()
        ->and($max->year)->toBe($cutoff->year)
        ->and(collect($required)->contains(fn (string $d): bool => CarbonImmutable::parse($d)->gt($cutoff)))
        ->toBeFalse();
});

it('derives the last required rate date from the last accrual through the official lag', function () {
    $result = simulateWindow(PuSimulationFixture::windowEndDate()->toDateString());
    $lastRow = $result->lastRow();

    expect($result->parameters['index_rate_lookup_mode'])
        ->toBe(PuIndexRateLookupMode::BusinessDayLagExact->value)
        ->and((int) $result->parameters['index_rate_lag_business_days'])->toBe(-5);

    // A maior data exigida é exatamente a observação da última linha da janela.
    $observed = collect($result->rows)
        ->map(fn ($row): ?string => $row->indexRateDate?->toDateString())
        ->filter()
        ->max();

    expect($lastRow->date->toDateString())->toBe(PuSimulationFixture::windowEndDate()->toDateString())
        ->and($result->requiredRateDates[array_key_last($result->requiredRateDates)])->toBe($observed);
});

it('preserves the premium tail before the first integralization', function () {
    $result = simulateWindow(PuSimulationFixture::windowEndDate()->toDateString());
    $first = CarbonImmutable::parse($result->requiredRateDates[0]);

    // A cauda do prêmio pré-integralização exige taxas ANTERIORES ao início da
    // curva; limitar o horizonte não pode tê-la eliminado.
    expect($result->premium['enabled'])->toBeTrue()
        ->and($first->lessThan(PuSimulationFixture::integralizationDate()))->toBeTrue();
});

it('asks for more rates as the cutoff moves further out', function () {
    $short = simulateWindow(PuSimulationFixture::windowEndDate()->toDateString());
    $longer = simulateWindow('2026-08-31');

    // O plano responde ao recorte real: janela maior, mais datas exigidas.
    expect(count($longer->requiredRateDates))->toBeGreaterThan(count($short->requiredRateDates))
        ->and(CarbonImmutable::parse($longer->requiredRateDates[array_key_last($longer->requiredRateDates)])
            ->lessThanOrEqualTo(CarbonImmutable::parse('2026-08-31')))->toBeTrue()
        // E a cauda anterior é a mesma nos dois: ela depende da integralização,
        // não do recorte.
        ->and($longer->requiredRateDates[0])->toBe($short->requiredRateDates[0]);
});

it('keeps the contractual maturity constant across every cutoff', function () {
    $short = simulateWindow(PuSimulationFixture::windowEndDate()->toDateString());
    $longer = simulateWindow('2026-08-31');

    expect($short->parameters['contractual_curve_end_date'])->toBe(HORIZON_CONTRACTUAL_MATURITY)
        ->and($longer->parameters['contractual_curve_end_date'])->toBe(HORIZON_CONTRACTUAL_MATURITY)
        // O recorte entregue à engine é o da simulação, nunca o vencimento.
        ->and($short->parameters['curve_end_date'])
        ->toBe(PuSimulationFixture::windowEndDate()->toDateString())
        ->and($longer->parameters['curve_end_date'])->toBe('2026-08-31');
});

it('respects the cutoff with and without the observation calendar hypothesis', function () {
    $contractual = simulateWindow(PuSimulationFixture::windowEndDate()->toDateString());
    $banking = simulateWindow(
        PuSimulationFixture::windowEndDate()->toDateString(),
        BusinessCalendarRegistry::BR_BANKING_ANBIMA,
    );
    $cutoff = PuSimulationFixture::windowEndDate();

    // A separação de calendário não pode reabrir o horizonte até o vencimento.
    foreach ([$contractual, $banking] as $result) {
        $max = CarbonImmutable::parse($result->requiredRateDates[array_key_last($result->requiredRateDates)]);

        expect($result->requiredRateDates)->not->toBeEmpty()
            ->and($max->lessThanOrEqualTo($cutoff))->toBeTrue();
    }
});

it('writes nothing while planning the rate horizon', function () {
    $scenario = PuSimulationFixture::calculableScenario();
    $before = PuSimulationFixture::counts();

    app(PuSimulationService::class)->simulate($scenario['emission'], $scenario['input']);

    expect(PuSimulationFixture::counts())->toBe($before);
});

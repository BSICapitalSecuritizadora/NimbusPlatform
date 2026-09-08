<?php

use App\Domain\PuCalculator\Enums\PuSimulationState;
use App\Filament\Resources\Emissions\EmissionResource;
use App\Filament\Resources\Emissions\Pages\PuCalculatorSimulator;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuParameter;
use App\Models\IndexRate;
use App\Models\User;
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

// ---------------------------------------------------------------------------
// Autorização
// ---------------------------------------------------------------------------

it('opens the calculator for a user with pu.curve.view', function () {
    $this->actingAs(makeAdminUser());
    $emission = PuSimulationFixture::contractualEmission();

    expect(PuCalculatorSimulator::canAccess(['record' => $emission]))->toBeTrue();

    Livewire::test(PuCalculatorSimulator::class, ['record' => $emission->getRouteKey()])
        ->assertOk();
});

it('blocks the calculator for a user without pu.curve.view', function () {
    $user = User::factory()->withTwoFactor()->create(['email' => fake()->unique()->safeEmail()]);
    $user->assignRole('commercial-representative');
    $this->actingAs($user);
    $emission = PuSimulationFixture::contractualEmission();

    expect(PuCalculatorSimulator::canAccess(['record' => $emission]))->toBeFalse();

    $this->get(EmissionResource::getUrl('pu-calculator', ['record' => $emission]))
        ->assertForbidden();
});

it('does not require the promotion permission to simulate', function () {
    $this->actingAs(makeAdminUser());
    $emission = PuSimulationFixture::contractualEmission();

    // A calculadora é ferramenta de leitura/simulação: a autoridade de promoção
    // operacional é irrelevante aqui.
    expect(PuCalculatorSimulator::canAccess(['record' => $emission]))->toBeTrue();
});

// ---------------------------------------------------------------------------
// Conteúdo da tela
// ---------------------------------------------------------------------------

it('shows the non operational simulation banner', function () {
    $this->actingAs(makeAdminUser());
    $emission = PuSimulationFixture::contractualEmission();

    Livewire::test(PuCalculatorSimulator::class, ['record' => $emission->getRouteKey()])
        ->assertOk()
        ->assertSee('SIMULAÇÃO — NÃO OPERACIONAL')
        ->assertSee('não criam uma curva oficial');
});

it('shows the contractual parameters resolved from the confirmed baseline', function () {
    $this->actingAs(makeAdminUser());
    $emission = PuSimulationFixture::contractualEmission();

    Livewire::test(PuCalculatorSimulator::class, ['record' => $emission->getRouteKey()])
        ->assertOk()
        ->assertSee('Indexador')
        ->assertSee('CDI')
        ->assertSee(PuSimulationFixture::CALENDAR_CODE)
        ->assertSee('Contratual')
        ->assertSee('Base de dias úteis');
});

it('presents the first integralization date as an explicit simulation parameter', function () {
    $this->actingAs(makeAdminUser());
    $emission = PuSimulationFixture::contractualEmission();

    Livewire::test(PuCalculatorSimulator::class, ['record' => $emission->getRouteKey()])
        ->assertOk()
        ->assertSee('Data da primeira integralização')
        ->assertSee('PARÂMETRO DE SIMULAÇÃO')
        ->assertSee('não satisfaz o Gate C');
});

it('marks an informed value as a simulation override', function () {
    $this->actingAs(makeAdminUser());
    $emission = PuSimulationFixture::contractualEmission();

    Livewire::test(PuCalculatorSimulator::class, ['record' => $emission->getRouteKey()])
        ->set('firstIntegralizationDate', PuSimulationFixture::integralizationDate()->toDateString())
        ->assertOk()
        ->assertSee('Override de simulação');
});

it('reports the missing first integralization date instead of inventing one', function () {
    $this->actingAs(makeAdminUser());
    $emission = PuSimulationFixture::contractualEmission();

    Livewire::test(PuCalculatorSimulator::class, ['record' => $emission->getRouteKey()])
        ->assertOk()
        ->assertSee('Não definido')
        ->set('firstIntegralizationDate', null)
        ->call('calculate')
        ->assertSee('Informe para simulação');
});

it('does not disable the calculation when Gate C is pending', function () {
    $this->actingAs(makeAdminUser());
    $scenario = PuSimulationFixture::calculableScenario();

    // Nenhuma evidência de primeira integralização, nenhum parâmetro, nenhuma
    // curva: readiness operacional bloqueada, simulação disponível.
    expect(EmissionPuParameter::query()->count())->toBe(0)
        ->and(EmissionPuCurveVersion::query()->count())->toBe(0);

    Livewire::test(PuCalculatorSimulator::class, ['record' => $scenario['emission']->getRouteKey()])
        ->set('firstIntegralizationDate', PuSimulationFixture::integralizationDate()->toDateString())
        ->set('simulationEndDate', PuSimulationFixture::windowEndDate()->toDateString())
        ->call('calculate')
        ->assertOk()
        ->assertSee('Simulação calculada')
        ->assertSee('Gate C operacional');
});

// ---------------------------------------------------------------------------
// Cálculo, curva e memória
// ---------------------------------------------------------------------------

it('calculates and renders the curve rows and the calculation memory', function () {
    $this->actingAs(makeAdminUser());
    $scenario = PuSimulationFixture::calculableScenario();

    $component = Livewire::test(PuCalculatorSimulator::class, ['record' => $scenario['emission']->getRouteKey()])
        ->set('firstIntegralizationDate', PuSimulationFixture::integralizationDate()->toDateString())
        ->set('simulationEndDate', PuSimulationFixture::windowEndDate()->toDateString())
        ->call('calculate')
        ->assertOk()
        ->assertSee('PU na data selecionada')
        ->assertSee('Curva diária')
        ->assertSee('Memória de cálculo')
        ->assertSee('Fator CDI diário')
        ->assertSee('Fator spread + CDI')
        ->assertSee('DUP (juros)')
        ->assertSee('DUT (juros)')
        ->assertSee('Data da taxa CDI utilizada');

    expect($component->instance()->result()?->state)->toBe(PuSimulationState::Calculated)
        ->and($component->instance()->result()?->rowCount())->toBeGreaterThan(0);
});

it('opens the memory of a selected curve date', function () {
    $this->actingAs(makeAdminUser());
    $scenario = PuSimulationFixture::calculableScenario();

    $component = Livewire::test(PuCalculatorSimulator::class, ['record' => $scenario['emission']->getRouteKey()])
        ->set('firstIntegralizationDate', PuSimulationFixture::integralizationDate()->toDateString())
        ->set('simulationEndDate', PuSimulationFixture::windowEndDate()->toDateString())
        ->call('calculate');

    $rows = $component->instance()->result()->rows;
    $targetDate = $rows[1]->date->toDateString();

    $component->call('selectCurveDate', $targetDate)->assertOk();

    expect($component->instance()->selectedRow()?->date->toDateString())->toBe($targetDate);
});

it('filters the curve to payments only without touching the engine result', function () {
    $this->actingAs(makeAdminUser());
    $scenario = PuSimulationFixture::calculableScenario();

    $component = Livewire::test(PuCalculatorSimulator::class, ['record' => $scenario['emission']->getRouteKey()])
        ->set('firstIntegralizationDate', PuSimulationFixture::integralizationDate()->toDateString())
        ->set('simulationEndDate', PuSimulationFixture::windowEndDate()->toDateString())
        ->call('calculate');

    $total = $component->instance()->result()->rowCount();

    $component->set('paymentsOnly', true);

    expect(count($component->instance()->visibleRows()))->toBeLessThan($total)
        ->and($component->instance()->result()->rowCount())->toBe($total);
});

it('shows the first coupon premium summary when enabled', function () {
    $this->actingAs(makeAdminUser());
    $scenario = PuSimulationFixture::calculableScenario();

    Livewire::test(PuCalculatorSimulator::class, ['record' => $scenario['emission']->getRouteKey()])
        ->set('firstIntegralizationDate', PuSimulationFixture::integralizationDate()->toDateString())
        ->set('simulationEndDate', PuSimulationFixture::windowEndDate()->toDateString())
        ->call('calculate')
        ->assertSee('Prêmio do primeiro cupom')
        ->assertSee('DU anteriores à primeira integralização');
});

it('shows the exact missing rate dates when a required rate is absent', function () {
    $this->actingAs(makeAdminUser());
    $scenario = PuSimulationFixture::calculableScenario();
    IndexRate::query()->delete();

    Livewire::test(PuCalculatorSimulator::class, ['record' => $scenario['emission']->getRouteKey()])
        ->set('firstIntegralizationDate', PuSimulationFixture::integralizationDate()->toDateString())
        ->set('simulationEndDate', PuSimulationFixture::windowEndDate()->toDateString())
        ->call('calculate')
        ->assertOk()
        ->assertSee('Faltam taxas para')
        ->assertSee('Nenhuma taxa anterior, próxima, interpolada ou mais recente');
});

it('keeps the empty state before the first calculation', function () {
    $this->actingAs(makeAdminUser());
    $emission = PuSimulationFixture::contractualEmission();

    Livewire::test(PuCalculatorSimulator::class, ['record' => $emission->getRouteKey()])
        ->assertOk()
        ->assertSee('para executar a engine')
        ->assertDontSee('Memória de cálculo');
});

// ---------------------------------------------------------------------------
// Zero efeito operacional pela tela
// ---------------------------------------------------------------------------

it('writes nothing when the page calculates', function () {
    $this->actingAs(makeAdminUser());
    $scenario = PuSimulationFixture::calculableScenario();
    $before = PuSimulationFixture::counts();

    Livewire::test(PuCalculatorSimulator::class, ['record' => $scenario['emission']->getRouteKey()])
        ->set('firstIntegralizationDate', PuSimulationFixture::integralizationDate()->toDateString())
        ->set('simulationEndDate', PuSimulationFixture::windowEndDate()->toDateString())
        ->call('calculate')
        ->assertOk();

    expect(PuSimulationFixture::counts())->toBe($before);
});

it('never presents the result as candidate, validated or operational', function () {
    $this->actingAs(makeAdminUser());
    $scenario = PuSimulationFixture::calculableScenario();

    Livewire::test(PuCalculatorSimulator::class, ['record' => $scenario['emission']->getRouteKey()])
        ->set('firstIntegralizationDate', PuSimulationFixture::integralizationDate()->toDateString())
        ->set('simulationEndDate', PuSimulationFixture::windowEndDate()->toDateString())
        ->call('calculate')
        ->assertOk()
        ->assertSee('Simulação calculada')
        ->assertDontSee('Curva candidata')
        ->assertDontSee('Validação externa independente')
        ->assertDontSee('Promoção operacional');
});

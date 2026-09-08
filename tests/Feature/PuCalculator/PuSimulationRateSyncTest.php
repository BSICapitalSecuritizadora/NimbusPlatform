<?php

use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuSimulationState;
use App\Domain\PuCalculator\Services\IndexRateSyncService;
use App\Filament\Resources\Emissions\Pages\PuCalculatorSimulator;
use App\Models\IndexRate;
use App\Models\User;
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
});

/**
 * Payload SGS mínimo e válido para as datas pedidas. Nenhuma chamada real ao
 * Banco Central acontece: o cliente oficial é exercitado contra um fake.
 *
 * @param  list<string>  $dates
 */
function fakeSgsSeries(array $dates, string $value = '14,90'): void
{
    Http::preventStrayRequests();
    Http::fake([
        '*api.bcb.gov.br*' => Http::response(
            array_map(
                fn (string $date): array => [
                    'data' => CarbonImmutable::parse($date)->format('d/m/Y'),
                    'valor' => $value,
                ],
                $dates,
            ),
            200,
        ),
    ]);
}

// ---------------------------------------------------------------------------
// A ação é separada do cálculo
// ---------------------------------------------------------------------------

it('never fetches the central bank while calculating', function () {
    $this->actingAs(makeAdminUser());
    $scenario = PuSimulationFixture::calculableScenario();
    // Qualquer request HTTP durante o cálculo estoura o teste.
    Http::preventStrayRequests();

    Livewire::test(PuCalculatorSimulator::class, ['record' => $scenario['emission']->getRouteKey()])
        ->set('firstIntegralizationDate', PuSimulationFixture::integralizationDate()->toDateString())
        ->set('simulationEndDate', PuSimulationFixture::windowEndDate()->toDateString())
        ->call('calculate')
        ->assertOk();
});

it('only offers the sync action while rates are missing', function () {
    $this->actingAs(makeAdminUser());
    $scenario = PuSimulationFixture::calculableScenario();
    Http::preventStrayRequests();

    $calculated = Livewire::test(PuCalculatorSimulator::class, ['record' => $scenario['emission']->getRouteKey()])
        ->set('firstIntegralizationDate', PuSimulationFixture::integralizationDate()->toDateString())
        ->set('simulationEndDate', PuSimulationFixture::windowEndDate()->toDateString())
        ->call('calculate');

    $calculated->assertActionHidden('syncRequiredRates');

    expect($calculated->instance()->result()?->state)->toBe(PuSimulationState::Calculated);

    IndexRate::query()->delete();

    // Componente novo: o estado de taxas mudou no banco e a simulação anterior
    // não deve ser reaproveitada.
    $blocked = Livewire::test(PuCalculatorSimulator::class, ['record' => $scenario['emission']->getRouteKey()])
        ->set('firstIntegralizationDate', PuSimulationFixture::integralizationDate()->toDateString())
        ->set('simulationEndDate', PuSimulationFixture::windowEndDate()->toDateString())
        ->call('calculate');

    $blocked->assertActionVisible('syncRequiredRates');

    expect($blocked->instance()->result()?->state)->toBe(PuSimulationState::RatesMissing);
});

it('hides the sync action from a user without pu.index.sync', function () {
    // A calculadora é uma página do RESOURCE de emissões, então o Filament
    // exige `emissions.view` para montá-la (`CanAuthorizeResourceAccess`), além
    // de `pu.curve.view` para a própria página. Sem `emissions.view` o mount
    // aborta com 403 e não existe snapshot -- o que fazia o harness do Livewire
    // falhar com "Invalid Livewire snapshot structure" em vez de avaliar a
    // visibilidade da ação. O usuário deste cenário é um leitor legítimo de
    // emissões que NÃO possui `pu.index.sync`.
    $viewer = User::factory()->withTwoFactor()->create(['email' => fake()->unique()->safeEmail()]);
    $viewer->givePermissionTo(['emissions.view', 'pu.curve.view']);
    $this->actingAs($viewer);
    $scenario = PuSimulationFixture::calculableScenario();
    IndexRate::query()->delete();
    Http::preventStrayRequests();

    expect($viewer->can('pu.curve.view'))->toBeTrue()
        ->and($viewer->can('pu.index.sync'))->toBeFalse();

    $component = Livewire::test(PuCalculatorSimulator::class, ['record' => $scenario['emission']->getRouteKey()])
        ->assertOk()
        ->set('firstIntegralizationDate', PuSimulationFixture::integralizationDate()->toDateString())
        ->set('simulationEndDate', PuSimulationFixture::windowEndDate()->toDateString())
        ->call('calculate');

    $component->assertActionHidden('syncRequiredRates');

    expect($component->instance()->result()?->state)->toBe(PuSimulationState::RatesMissing);
});

// ---------------------------------------------------------------------------
// Fronteira de escrita: apenas index_rates
// ---------------------------------------------------------------------------

it('writes only index rates when the explicit sync action runs', function () {
    $this->actingAs(makeAdminUser());
    $scenario = PuSimulationFixture::calculableScenario();
    IndexRate::query()->delete();
    Http::preventStrayRequests();

    $component = Livewire::test(PuCalculatorSimulator::class, ['record' => $scenario['emission']->getRouteKey()])
        ->set('firstIntegralizationDate', PuSimulationFixture::integralizationDate()->toDateString())
        ->set('simulationEndDate', PuSimulationFixture::windowEndDate()->toDateString())
        ->call('calculate');

    $missing = $component->instance()->result()->missingRateDates;
    $before = PuSimulationFixture::counts();

    fakeSgsSeries($missing);

    $component->callAction('syncRequiredRates');

    $after = PuSimulationFixture::counts();

    expect($after['index_rates'])->toBeGreaterThan($before['index_rates']);

    foreach (array_keys($before) as $table) {
        if ($table === 'index_rates') {
            continue;
        }

        expect($after[$table])->toBe($before[$table]);
    }
});

it('uses the homologated bcb_sgs 4389 provenance for the synced rates', function () {
    $this->actingAs(makeAdminUser());
    $scenario = PuSimulationFixture::calculableScenario();
    IndexRate::query()->delete();
    Http::preventStrayRequests();

    $component = Livewire::test(PuCalculatorSimulator::class, ['record' => $scenario['emission']->getRouteKey()])
        ->set('firstIntegralizationDate', PuSimulationFixture::integralizationDate()->toDateString())
        ->set('simulationEndDate', PuSimulationFixture::windowEndDate()->toDateString())
        ->call('calculate');

    fakeSgsSeries($component->instance()->result()->missingRateDates);
    $component->callAction('syncRequiredRates');

    $rate = IndexRate::query()->latest('id')->first();

    expect($rate?->source)->toBe('bcb_sgs')
        ->and((string) $rate?->external_series_code)->toBe('4389')
        ->and($rate?->isProjectedRate())->toBeFalse();
});

it('does not overwrite a locally stored rate that diverges', function () {
    $this->actingAs(makeAdminUser());
    $scenario = PuSimulationFixture::calculableScenario();
    $component = Livewire::test(PuCalculatorSimulator::class, ['record' => $scenario['emission']->getRouteKey()])
        ->set('firstIntegralizationDate', PuSimulationFixture::integralizationDate()->toDateString())
        ->set('simulationEndDate', PuSimulationFixture::windowEndDate()->toDateString())
        ->call('calculate');
    $required = $component->instance()->result()->requiredRateDates;
    $target = $required[0];
    IndexRate::query()->whereDate('rate_date', $target)->update(['rate_value' => '9.99000000']);

    fakeSgsSeries([$target], '14,90');
    // A política de escrita é `skip_existing`: um valor local divergente é
    // preservado para decisão humana, nunca sobrescrito silenciosamente.
    app(IndexRateSyncService::class)->sync(
        indexer: PuIndexer::Cdi,
        from: CarbonImmutable::parse($target),
        to: CarbonImmutable::parse($target),
        dryRun: false,
        userId: null,
        overwritePolicy: IndexRateSyncService::POLICY_SKIP,
    );

    expect((string) IndexRate::query()->whereDate('rate_date', $target)->first()?->rate_value)
        ->toBe('9.99000000');
});

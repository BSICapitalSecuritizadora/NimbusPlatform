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
use Livewire\Features\SupportTesting\Testable;
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

// ---------------------------------------------------------------------------
// A data exigida pela simulação precisa chegar EXATAMENTE ao Banco Central
// ---------------------------------------------------------------------------

/**
 * 04/06/2026 é Corpus Christi. O calendário `BR_NATIONAL_HOLIDAYS` materializa
 * somente feriados FIXOS de lei federal, então esse dia é dia útil aqui e a
 * simulação exige o CDI dele -- mas o Banco Central não divulga taxa em dia sem
 * expediente bancário. É exatamente o caso observado na tela do Alto Bellevue.
 */
const CORPUS_CHRISTI_2026 = '2026-06-04';

/**
 * Cenário calculável com uma única taxa ausente: a de Corpus Christi.
 *
 * @return array{component:Testable, missing:list<string>}
 */
function scenarioMissingOnlyCorpusChristi(): array
{
    $scenario = PuSimulationFixture::calculableScenario();
    Http::preventStrayRequests();

    $component = Livewire::test(PuCalculatorSimulator::class, ['record' => $scenario['emission']->getRouteKey()])
        ->set('firstIntegralizationDate', PuSimulationFixture::integralizationDate()->toDateString())
        ->set('simulationEndDate', PuSimulationFixture::windowEndDate()->toDateString())
        ->call('calculate');

    // A premissa do cenário é explícita: se esta data deixar de ser exigida, o
    // teste falha aqui, e não com um sintoma distante.
    expect($component->instance()->result()->requiredRateDates)->toContain(CORPUS_CHRISTI_2026);

    IndexRate::query()
        ->where('indexer', PuIndexer::Cdi->value)
        ->whereDate('rate_date', CORPUS_CHRISTI_2026)
        ->delete();

    $component->call('calculate');
    $result = $component->instance()->result();

    expect($result->state)->toBe(PuSimulationState::RatesMissing)
        ->and($result->missingRateDates)->toBe([CORPUS_CHRISTI_2026]);

    return ['component' => $component, 'missing' => $result->missingRateDates];
}

/** A janela consultada no SGS foi exatamente `$from`..`$to`, e nada além disso. */
function assertSgsWindowRequested(string $from, string $to): void
{
    Http::assertSent(function ($request) use ($from, $to): bool {
        $url = urldecode($request->url());

        return str_contains($url, 'bcdata.sgs.4389/dados')
            && str_contains($url, 'dataInicial='.CarbonImmutable::parse($from)->format('d/m/Y'))
            && str_contains($url, 'dataFinal='.CarbonImmutable::parse($to)->format('d/m/Y'));
    });
}

it('asks the central bank for exactly the missing date the simulation reported', function () {
    $this->actingAs(makeAdminUser());
    ['component' => $component] = scenarioMissingOnlyCorpusChristi();

    fakeSgsSeries([CORPUS_CHRISTI_2026]);

    $component->callAction('syncRequiredRates');

    // Uma única consulta, e a janela é a data exigida -- nunca a emissão toda.
    Http::assertSentCount(1);
    assertSgsWindowRequested(CORPUS_CHRISTI_2026, CORPUS_CHRISTI_2026);
});

it('persists the synced rate with the homologated provenance and recalculates to calculated', function () {
    $this->actingAs(makeAdminUser());
    ['component' => $component] = scenarioMissingOnlyCorpusChristi();

    fakeSgsSeries([CORPUS_CHRISTI_2026]);

    $component->callAction('syncRequiredRates')
        ->assertNotified('Sincronização concluída.');

    $rate = IndexRate::query()
        ->where('indexer', PuIndexer::Cdi->value)
        ->whereDate('rate_date', CORPUS_CHRISTI_2026)
        ->first();

    expect($rate)->not->toBeNull()
        ->and($rate->source)->toBe('bcb_sgs')
        ->and($rate->source_reference)->toBe('bcb_sgs:4389')
        ->and((string) $rate->external_series_code)->toBe('4389');

    $recalculated = $component->instance()->result();

    expect($recalculated->missingRateDates)->not->toContain(CORPUS_CHRISTI_2026)
        ->and($recalculated->missingRateDates)->toBe([])
        ->and($recalculated->state)->toBe(PuSimulationState::Calculated);
});

it('reports the still missing date instead of announcing a successful sync', function () {
    $this->actingAs(makeAdminUser());
    ['component' => $component] = scenarioMissingOnlyCorpusChristi();

    // O Banco Central responde SEM dados, como num dia sem divulgação.
    Http::preventStrayRequests();
    Http::fake(['*api.bcb.gov.br*' => Http::response([], 200)]);

    $component->callAction('syncRequiredRates')
        ->assertNotified('Não foi possível obter todas as taxas necessárias.');

    // A consulta ocorreu de fato: este caso é diferente de "nenhum request".
    Http::assertSentCount(1);
    assertSgsWindowRequested(CORPUS_CHRISTI_2026, CORPUS_CHRISTI_2026);

    expect(IndexRate::query()
        ->where('indexer', PuIndexer::Cdi->value)
        ->whereDate('rate_date', CORPUS_CHRISTI_2026)
        ->exists())->toBeFalse()
        ->and($component->instance()->result()->missingRateDates)->toBe([CORPUS_CHRISTI_2026]);
});

it('never reports a successful sync while a requested date is still missing', function () {
    $this->actingAs(makeAdminUser());
    ['component' => $component] = scenarioMissingOnlyCorpusChristi();

    Http::preventStrayRequests();
    Http::fake(['*api.bcb.gov.br*' => Http::response([], 200)]);

    // A regressão exata da tela: `0 consultada(s)` apresentado como sucesso,
    // com a data continuando ausente logo abaixo.
    $component->callAction('syncRequiredRates')
        ->assertNotNotified('Sincronização concluída.');
});

it('stops offering the sync once the rate exists and does not consult again', function () {
    $this->actingAs(makeAdminUser());
    ['component' => $component] = scenarioMissingOnlyCorpusChristi();

    fakeSgsSeries([CORPUS_CHRISTI_2026]);
    $component->callAction('syncRequiredRates');

    $created = IndexRate::query()
        ->where('indexer', PuIndexer::Cdi->value)
        ->whereDate('rate_date', CORPUS_CHRISTI_2026)
        ->count();

    // Nenhuma taxa pendente: a ação sai de cena e nenhuma consulta adicional
    // é feita ao Banco Central.
    $component->assertActionHidden('syncRequiredRates');

    expect($created)->toBe(1)
        ->and($component->instance()->result()->state)->toBe(PuSimulationState::Calculated);

    Http::assertSentCount(1);
});

<?php

use App\Domain\PuCalculator\DTOs\PuSimulationInput;
use App\Domain\PuCalculator\Enums\IpcaProjectionPolicy;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuSimulationState;
use App\Domain\PuCalculator\Exceptions\PuRateDomainException;
use App\Domain\PuCalculator\Services\PuCurveGeneratorService;
use App\Domain\PuCalculator\Services\PuSimulationService;
use App\Models\Emission;
use App\Models\IndexRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Pu\PuSimulationFixture;

/**
 * Domínio financeiro das taxas que alimentam a engine do PU.
 *
 * Toda capitalização multiplica por uma base positiva: 1 + taxa/100 no CDI, no
 * spread, no prefixado e no cupom do IPCA; NI_ref/NI_ant na correção do IPCA. Base
 * zero ou negativa é recusada ANTES da raiz, com `PuRateDomainException` -- mesmo
 * quando a raiz existe na matemática (zero tem raiz zero; base negativa tem raiz real
 * de grau ímpar). Taxa negativa com base positiva continua válida.
 *
 * Os caminhos daqui não passam pelos pré-requisitos operacionais (que já exigem
 * prefixado e cupom IPCA maiores que zero): é a engine que precisa se defender,
 * porque a simulação e a homologação a chamam diretamente.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Http::preventStrayRequests();
});

function rateDomainPrefixedEmission(string $annualRate): Emission
{
    $emission = Emission::factory()->active()->create(['type' => 'CRI']);
    $emission->puParameter()->create([
        'curve_start_date' => '2026-03-02',
        'curve_end_date' => '2026-03-04',
        'initial_unit_value' => '1000.0000000000000000',
        'spread_rate' => '0.00000000',
        'annual_rate' => $annualRate,
        'indexer' => PuIndexer::Prefixed->value,
        'business_day_basis' => 252,
        'calendar_code' => 'B3',
        'legacy_projection_enabled' => false,
    ]);

    return $emission->fresh();
}

/**
 * Curva IPCA de 01/01/2024 a 03/01/2024, aniversário no dia 1 e defasagem de 2
 * meses: a linha de 02/01 corrige por NI(nov/23) / NI(out/23).
 *
 * @param  array<string, string>  $indexNumbers  mês (Y-m-d) => número-índice
 */
function rateDomainIpcaEmission(string $annualRate, array $indexNumbers = []): Emission
{
    $emission = Emission::factory()->active()->create(['type' => 'CRI']);
    $emission->puParameter()->create([
        'curve_start_date' => '2024-01-01',
        'curve_end_date' => '2024-01-03',
        'initial_unit_value' => '1000.0000000000000000',
        'spread_rate' => '0.00000000',
        'annual_rate' => $annualRate,
        'indexer' => PuIndexer::Ipca->value,
        'base_index_date' => '2024-01-01',
        'index_lag_months' => 2,
        'correction_frequency' => 'monthly',
        'index_projection_policy' => IpcaProjectionPolicy::PublishedOnly->value,
        'business_day_basis' => 252,
        'calendar_code' => 'B3',
        'legacy_projection_enabled' => false,
    ]);

    $indexNumbers = [
        '2023-09-01' => '6500.00000000',
        '2023-10-01' => '6550.00000000',
        '2023-11-01' => '6600.00000000',
        ...$indexNumbers,
    ];

    foreach ($indexNumbers as $month => $value) {
        IndexRate::query()->create([
            'indexer' => PuIndexer::Ipca->value,
            'rate_date' => $month,
            'rate_value' => $value,
            'source' => 'manual_import',
            'is_projected' => false,
        ]);
    }

    return $emission->fresh();
}

// ---------------------------------------------------------------------------
// Prefixado
// ---------------------------------------------------------------------------

it('refuses a fixed rate that zeroes or inverts the compounding base', function (string $annualRate) {
    $emission = rateDomainPrefixedEmission($annualRate);

    expect(fn () => app(PuCurveGeneratorService::class)->handle($emission))
        ->toThrow(PuRateDomainException::class, 'acima de -100% a.a.');
})->with([
    'exatamente -100% a.a.' => ['-100.00000000'],
    'abaixo de -100% a.a.' => ['-150.00000000'],
]);

it('keeps calculating a negative fixed rate whose compounding base stays positive', function () {
    $rows = app(PuCurveGeneratorService::class)->handle(rateDomainPrefixedEmission('-5.00000000'))->rows;
    $lastRow = $rows[array_key_last($rows)];

    expect($rows)->toHaveCount(3)
        ->and(bccomp($lastRow->updatedUnitValue, '1000', 16))->toBe(-1)
        ->and(bccomp($lastRow->updatedUnitValue, '0', 16))->toBe(1);
});

// ---------------------------------------------------------------------------
// IPCA: cupom e número-índice
// ---------------------------------------------------------------------------

it('refuses an IPCA coupon that zeroes or inverts the compounding base', function (string $annualRate) {
    $emission = rateDomainIpcaEmission($annualRate);

    expect(fn () => app(PuCurveGeneratorService::class)->handle($emission))
        ->toThrow(PuRateDomainException::class, 'acima de -100% a.a.');
})->with([
    // Antes: cupom diário 0, juros = -corrigido e PU atualizado 0 em silêncio.
    'exatamente -100% a.a.' => ['-100.00000000'],
    'abaixo de -100% a.a.' => ['-150.00000000'],
]);

it('keeps calculating a negative IPCA coupon whose compounding base stays positive', function () {
    $rows = app(PuCurveGeneratorService::class)->handle(rateDomainIpcaEmission('-5.00000000'))->rows;

    expect($rows)->toHaveCount(3)
        ->and(bccomp($rows[2]->updatedUnitValue, '0', 16))->toBe(1);
});

it('refuses a non-positive IPCA index number instead of flooring the correction', function (string $month, string $indexNumber) {
    $emission = rateDomainIpcaEmission('5.00000000', [$month => $indexNumber]);

    expect(fn () => app(PuCurveGeneratorService::class)->handle($emission))
        ->toThrow(PuRateDomainException::class, 'número-índice positivo');
})->with([
    // Antes: NI(nov)/NI(out) negativo caía no piso de deflação e a correção virava 1 em silêncio.
    'NI de referência negativo' => ['2023-11-01', '-6600.00000000'],
    'NI anterior negativo' => ['2023-10-01', '-6550.00000000'],
    // Antes: DivisionByZeroError cru no meio da curva.
    'NI anterior zero' => ['2023-10-01', '0.00000000'],
]);

// ---------------------------------------------------------------------------
// Simulação: nenhuma tela ou validação de formulário no caminho
// ---------------------------------------------------------------------------

it('reports a simulated spread outside the financial domain as a failed simulation', function (array $overrides) {
    $emission = PuSimulationFixture::bareEmission();

    $result = app(PuSimulationService::class)->simulate($emission, new PuSimulationInput(
        firstIntegralizationDate: PuSimulationFixture::integralizationDate(),
        simulationEndDate: PuSimulationFixture::windowEndDate(),
        overrides: [...PuSimulationFixture::manualOverrides(), ...$overrides],
    ));

    expect($result->state)->toBe(PuSimulationState::CalculationFailed)
        ->and($result->rows)->toBe([])
        ->and($result->selectedRow)->toBeNull()
        ->and($result->reason)->toContain('acima de -100% a.a.');
})->with([
    'spread de -100% a.a.' => [['spread_rate' => '-100']],
    // Antes: base ímpar dava raiz real negativa e a simulação saía "calculada" com
    // Fator Spread de sinal alternado.
    'spread de -150% a.a. em base 21' => [['spread_rate' => '-150', 'business_day_basis' => '21']],
]);

it('keeps simulating a negative spread whose compounding base stays positive', function () {
    $emission = PuSimulationFixture::bareEmission();

    $result = app(PuSimulationService::class)->simulate($emission, new PuSimulationInput(
        firstIntegralizationDate: PuSimulationFixture::integralizationDate(),
        simulationEndDate: PuSimulationFixture::windowEndDate(),
        overrides: [...PuSimulationFixture::manualOverrides(), 'spread_rate' => '-20'],
    ));

    expect($result->state)->toBe(PuSimulationState::Calculated)
        ->and(bccomp($result->lastRow()->factorSpread, '1', 16))->toBe(-1)
        ->and(bccomp($result->lastRow()->factorSpread, '0', 16))->toBe(1);
});

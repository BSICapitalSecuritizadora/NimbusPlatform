<?php

use App\Actions\Emissions\GeneratePuDailyCurve;
use App\Domain\PuCalculator\Enums\PuValidationMode;
use App\Domain\PuCalculator\Services\PuReferenceWorkbookScenarioService;
use App\Domain\PuCalculator\Services\PuValidationService;
use App\Domain\PuCalculator\Services\PuValidationSpreadsheetLocatorService;
use App\Models\Emission;
use App\Models\EmissionPuDailyCurve;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Pu\PuValidationTolerance;

uses(RefreshDatabase::class);

/**
 * Regressão REAL da engine CDI + spread: roda PuCurveGenerationService (via GeneratePuDailyCurve)
 * e compara a curva GERADA contra o gabarito, linha-a-linha. Diferente de PuValidationTest (que
 * espelha o próprio gabarito) e de PuOperationalReadinessTest (que só confere a contagem de linhas),
 * este teste exige que os VALORES da engine batam com o gabarito dentro de tolerância documentada.
 *
 * Tolerâncias (medidas empiricamente, sem drift ao longo de ~5 anos):
 *  - PU atualizado/residual: EXATO em 6 casas decimais (display-scale) em todas as linhas.
 *  - Valor total (PU x quantidade): <= R$ 0,01 (a engine carrega o PU em escala 16; o gabarito
 *    arredonda o PU em ~6 casas antes de multiplicar pela quantidade — diferença puramente de
 *    precisão de exibição, não de fórmula).
 *  - Em raw-scale (16 casas) restam diferenças de ordem ~1e-7 por unidade, originadas da política
 *    de arredondamento POR COLUNA da engine externa (Fator Spread arred. 9, Fator Indicador arred. 8
 *    etc.). O próprio gabarito é internamente inconsistente nessa ordem de grandeza (coluna Juros !=
 *    base x (Fator-1)), logo reprodução bit-exata é impossível; a engine Nimbus é internamente
 *    consistente.
 */
function generateCdiCurveFromGabarito(string $keyword): array
{
    $emission = Emission::factory()->create([
        'name' => $keyword.' Regression',
        'type' => $keyword === 'AMANI' ? 'CRI' : 'CR',
        'status' => 'active',
        'issued_quantity' => 20000,
    ]);

    $path = app(PuValidationSpreadsheetLocatorService::class)->findByKeyword($keyword);
    app(PuReferenceWorkbookScenarioService::class)->sync($emission, $path);
    $result = app(GeneratePuDailyCurve::class)->handle($emission, syncLegacyProjections: false);

    return [$emission, $path, $result->calculationVersion];
}

dataset('cdi_gabaritos', [
    'AMANI' => ['AMANI', 1810],
    'TROUPE' => ['TROUPE', 696],
]);

it('reproduces the CDI gabarito PU within 6 decimals across the whole curve', function (string $keyword, int $expectedRows) {
    [$emission, $path, $version] = generateCdiCurveFromGabarito($keyword);

    $report = app(PuValidationService::class)->handle($emission, $path, $version, PuValidationMode::DisplayScale);

    expect($report->totalRowsCompared)->toBe($expectedRows)
        // PU atualizado: exato em 6 casas em TODAS as linhas (sem drift).
        ->and(bccomp($report->largestPuDifference, PuValidationTolerance::PU_DISPLAY, PuValidationTolerance::DISPLAY_SCALE))->toBeLessThanOrEqual(0)
        // Valor total na carteira: divergência sub-centavo (apenas precisão de exibição do PU).
        ->and(bccomp($report->largestTotalValueDifference, PuValidationTolerance::TOTAL_VALUE, PuValidationTolerance::DISPLAY_SCALE))->toBeLessThanOrEqual(0)
        ->and(bccomp($report->largestPaymentDifference, PuValidationTolerance::TOTAL_VALUE, PuValidationTolerance::DISPLAY_SCALE))->toBeLessThanOrEqual(0);
})->with('cdi_gabaritos');

it('keeps the raw-scale CDI divergence bounded to per-column rounding noise (~1e-7)', function (string $keyword, int $expectedRows) {
    [$emission, $path, $version] = generateCdiCurveFromGabarito($keyword);

    $report = app(PuValidationService::class)->handle($emission, $path, $version, PuValidationMode::RawScale);

    // Mesmo em escala 16, a maior diferença por unidade de PU fica em ruído de arredondamento.
    expect(bccomp($report->largestPuDifference, PuValidationTolerance::RAW_UNIT, PuValidationTolerance::RAW_SCALE))->toBeLessThanOrEqual(0)
        ->and(bccomp($report->largestTotalValueDifference, PuValidationTolerance::TOTAL_VALUE, PuValidationTolerance::RAW_SCALE))->toBeLessThanOrEqual(0);
})->with('cdi_gabaritos');

it('produces a different curve when the spread parameter changes (values are not fixed)', function () {
    [$emission, $path, $version] = generateCdiCurveFromGabarito('AMANI');

    $sampleDate = '2027-02-26';
    $puAtSpread65 = EmissionPuDailyCurve::query()
        ->where('emission_id', $emission->id)
        ->where('calculation_version', $version)
        ->whereDate('curve_date', $sampleDate)
        ->value('updated_unit_value');

    expect($puAtSpread65)->not->toBeNull();

    // Dobra o spread e regenera: o PU acumulado precisa mudar materialmente.
    $emission->puParameter->update(['spread_rate' => '13.00000000']);
    $newVersion = app(GeneratePuDailyCurve::class)->handle($emission->fresh(), syncLegacyProjections: false)->calculationVersion;

    $puAtSpread13 = EmissionPuDailyCurve::query()
        ->where('emission_id', $emission->id)
        ->where('calculation_version', $newVersion)
        ->whereDate('curve_date', $sampleDate)
        ->value('updated_unit_value');

    expect($puAtSpread13)->not->toBeNull()
        ->and(bccomp($puAtSpread13, $puAtSpread65, 8))->toBe(1)
        // Diferença economicamente relevante (não é ruído de arredondamento).
        ->and(bccomp(bcsub($puAtSpread13, $puAtSpread65, 8), '1.00000000', 8))->toBe(1);
});

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
 *  - Valor total (PU x quantidade): <= R$ 0,001 (pior caso medido 1e-4).
 *  - Em raw-scale (16 casas) a engine reproduz o gabarito: nos modos "Exact" o fator Spread×DI é
 *    arredondado em 9 casas ANTES do cálculo dos juros, exatamente como a engine externa (comprovado
 *    em AMANI 2026-03-02 e TROUPE 2025-06-05: juros do gabarito = base × (round9(fator) − 1)).
 *    O ruído residual (~1e-8 por unidade) vem do armazenamento em double (IEEE 754) das células do
 *    próprio .xlsx, logo bit-exatidão absoluta não é alcançável nem exigida.
 */
function generateCdiCurveFromGabarito(string $keyword): array
{
    $emission = Emission::factory()->create([
        'name' => $keyword.' Regression',
        'type' => $keyword === 'TROUPE' ? 'CR' : 'CRI',
        'status' => 'active',
        'issued_quantity' => $keyword === 'CONVIVA' ? 50000 : 20000,
    ]);

    $path = app(PuValidationSpreadsheetLocatorService::class)->findByKeyword($keyword);
    app(PuReferenceWorkbookScenarioService::class)->sync($emission, $path);
    $result = app(GeneratePuDailyCurve::class)->handle($emission, syncLegacyProjections: false);

    return [$emission, $path, $result->calculationVersion];
}

dataset('cdi_gabaritos', [
    'AMANI' => ['AMANI', 1810],
    'TROUPE' => ['TROUPE', 696],
    'CONVIVA' => ['CONVIVA', 1079],
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

it('keeps the raw-scale CDI divergence bounded to the gabarito float-storage noise (~1e-8)', function (string $keyword, int $expectedRows) {
    [$emission, $path, $version] = generateCdiCurveFromGabarito($keyword);

    $report = app(PuValidationService::class)->handle($emission, $path, $version, PuValidationMode::RawScale);

    // Mesmo em escala 16, a maior diferença por unidade de PU fica no ruído de double do .xlsx.
    expect(bccomp($report->largestPuDifference, PuValidationTolerance::RAW_UNIT, PuValidationTolerance::RAW_SCALE))->toBeLessThanOrEqual(0)
        ->and(bccomp($report->largestTotalValueDifference, PuValidationTolerance::TOTAL_VALUE, PuValidationTolerance::RAW_SCALE))->toBeLessThanOrEqual(0);
})->with('cdi_gabaritos');

it('rounds the combined Spread x DI factor to 9 decimals before interest, matching the external engine on the first raw-divergence line', function () {
    [$emission, , $version] = generateCdiCurveFromGabarito('AMANI');

    // Primeira linha historicamente divergente em raw-scale (2026-03-02): fator combinado
    // 1.0008013787894596 -> round9 = 1.000801379 -> juros = 1000 x 0.000801379 = 0.801379 EXATO.
    // Sem o arredondamento em 9 casas, os juros seriam 0.8013787894596... (falha em raw-scale).
    $row = EmissionPuDailyCurve::query()
        ->where('emission_id', $emission->id)
        ->where('calculation_version', $version)
        ->whereDate('curve_date', '2026-03-02')
        ->sole();

    expect(bccomp((string) $row->interest_real_unit_value, '0.801379', PuValidationTolerance::RAW_SCALE))->toBe(0)
        ->and(bccomp((string) $row->updated_unit_value, '1000.801379', PuValidationTolerance::RAW_SCALE))->toBe(0)
        // O fator persistido permanece SEM o arredondamento de uso (como exibido no gabarito).
        ->and(bccomp((string) $row->factor_spread_di, '1.000801379', PuValidationTolerance::RAW_SCALE))->not->toBe(0);
});

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

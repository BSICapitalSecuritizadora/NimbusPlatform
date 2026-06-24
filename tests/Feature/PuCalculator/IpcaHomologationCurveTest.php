<?php

use App\Domain\PuCalculator\Calculators\DailyFactorCalculator;
use App\Domain\PuCalculator\Calculators\IpcaCurveCalculator;
use App\Domain\PuCalculator\Enums\PuAmortizationType;
use App\Domain\PuCalculator\Enums\PuEventType;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Services\IndexRateService;
use App\Domain\PuCalculator\Services\PuSpreadsheetReferenceReader;
use App\Models\Emission;
use App\Models\IndexRate;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Homologação da engine IPCA contra o gabarito real (CRI RIO BRANCO 15ª série), agora cobrindo
 * TODA a janela com IPCA publicado (2021-09-30 → 2025-07-23), incluindo as 9 amortizações
 * (8 intermediárias + reconciliação pós-evento). Além desse ponto o gabarito congela o PU porque
 * o emissor não projeta IPCA até o vencimento (2028-08-23); essa política de projeção ainda não foi
 * implementada e a curva lança exceção clara — por isso IPCA permanece não homologado.
 */
function ipcaGabaritoPath(): string
{
    $matches = glob(base_path('docs/samples/pu-validation/*RIO BRANCO*.xlsx'));

    return $matches[0] ?? '';
}

/** Última data com IPCA publicado no gabarito (última NI = 2025-06; período fecha em 2025-07-23). */
const IPCA_WINDOW_END = '2025-07-23';

/** @return list<\App\Domain\PuCalculator\DTOs\SpreadsheetReferenceRowData> */
function ipcaReferenceRows(): array
{
    $reader = app(PuSpreadsheetReferenceReader::class);

    return $reader->read(ipcaGabaritoPath(), 'PuDiario')['rows'];
}

function seedIpcaIndexAndEmission(array $reference): Emission
{
    // Série de número-índice (idxDate => NI) extraída do gabarito.
    $numberIndex = [];
    foreach ($reference as $row) {
        if ($row->indexRateDate !== null && $row->indexRateValue !== null) {
            $numberIndex[$row->indexRateDate->toDateString()] = $row->indexRateValue;
        }
    }

    // O gabarito não publica o número-índice de 2021-08 (denominador da 1ª variação mensal).
    // Recupera-se com precisão a partir do corrigido em 2021-10-23 (dup=23, dut=30).
    $factor = app(DailyFactorCalculator::class);
    $october23 = collect($reference)->firstWhere(fn ($r) => $r->date->toDateString() === '2021-10-23');
    $augustRatio = $factor->powRatio(bcdiv($october23->correctedUnitValue, '1000', 24), 30, 23, 24);
    $numberIndex['2021-08-01'] = bcdiv($numberIndex['2021-09-01'], $augustRatio, 8);
    ksort($numberIndex);

    foreach ($numberIndex as $date => $value) {
        IndexRate::query()->create([
            'indexer' => PuIndexer::Ipca->value,
            'rate_date' => $date,
            'rate_value' => $value,
            'source' => 'reference_workbook',
            'source_reference' => 'CRI RIO BRANCO 15a serie',
        ]);
    }
    app(IndexRateService::class)->flushCache();

    $emission = Emission::factory()->create(['type' => 'CRI', 'status' => 'active', 'issued_quantity' => 8000]);
    $emission->puParameter()->create([
        'curve_start_date' => '2021-09-30',
        'curve_end_date' => IPCA_WINDOW_END,
        'initial_unit_value' => '1000.0000000000000000',
        'annual_rate' => '10.50000000',
        'indexer' => PuIndexer::Ipca->value,
        'calculation_method' => 'ipca_corrected',
        'business_day_basis' => 252,
        'calendar_code' => 'B3',
        'index_lag_months' => 0,
        'base_index_date' => '2021-09-23',
        'correction_frequency' => 'monthly',
        'index_projection_policy' => 'market',
        'legacy_projection_enabled' => false,
    ]);

    // Quantidade vigente (8.000 cotas, constante na janela).
    $emission->integralizationHistories()->create([
        'date' => '2021-09-30',
        'quantity' => '8000.0000',
        'unit_value' => '1000.00000000',
        'financial_value' => '8000000.00',
        'investor_fund' => 'IPCA Homologation',
    ]);

    // Eventos derivados do gabarito: pagamento de juros (PgtoJurosTotal > 0) e amortização
    // (AmortizacaoReal > 0). Ambos são a programação da operação (input do emissor), não ajuste.
    $sequence = 1;
    foreach ($reference as $row) {
        if ($row->date->toDateString() > IPCA_WINDOW_END) {
            break;
        }

        $hasInterest = $row->paymentInterestTotal !== null && bccomp($row->paymentInterestTotal, '0', 2) === 1;
        $hasAmortization = $row->amortizationUnitValue !== null && bccomp($row->amortizationUnitValue, '0', 8) === 1;

        if ($hasInterest) {
            $emission->puEvents()->create([
                'event_type' => PuEventType::InterestPayment->value,
                'original_date' => $row->date->toDateString(),
                'effective_date' => $row->date->toDateString(),
                'amortization_type' => PuAmortizationType::None->value,
                'amortization_value' => null,
                'sequence' => $sequence++,
            ]);
        }

        if ($hasAmortization) {
            $emission->puEvents()->create([
                'event_type' => PuEventType::Amortization->value,
                'original_date' => $row->date->toDateString(),
                'effective_date' => $row->date->toDateString(),
                'amortization_type' => PuAmortizationType::UnitValue->value,
                'amortization_value' => $row->amortizationUnitValue,
                'sequence' => $sequence++,
            ]);
        }
    }

    return $emission;
}

it('reproduces the full published IPCA reference curve including the nine amortizations', function () {
    $path = ipcaGabaritoPath();
    expect($path)->not->toBe('')->and(file_exists($path))->toBeTrue();

    $reference = ipcaReferenceRows();
    expect($reference)->not->toBeEmpty();

    $emission = seedIpcaIndexAndEmission($reference);

    $result = app(IpcaCurveCalculator::class)->calculate($emission->fresh(['puParameter', 'puEvents', 'integralizationHistories']));

    $generated = [];
    foreach ($result->rows as $row) {
        $generated[$row->date->toDateString()] = $row;
    }

    $maxCorrected = '0';
    $maxUpdated = '0';
    $maxResidual = '0';
    $maxInterest = '0';
    $maxAmortization = '0';
    $maxFactorSpread = '0';
    $maxTotal = '0';
    $maxPaymentInterest = '0';
    $compared = 0;
    $amortizationsCompared = 0;
    $dupDutMismatches = 0;
    $indexMismatches = 0;

    foreach ($reference as $row) {
        $key = $row->date->toDateString();
        if ($key < '2021-09-30' || $key > IPCA_WINDOW_END) {
            continue;
        }

        $mine = $generated[$key] ?? null;
        expect($mine)->not->toBeNull("missing generated row for {$key}");

        $maxCorrected = bc_max($maxCorrected, bcabs_diff($mine->unitCorrectedValue, $row->correctedUnitValue));

        if ($row->updatedUnitValue !== null) {
            $maxUpdated = bc_max($maxUpdated, bcabs_diff($mine->updatedUnitValue, $row->updatedUnitValue));
        }
        if ($row->residualUnitValue !== null) {
            $maxResidual = bc_max($maxResidual, bcabs_diff($mine->residualUnitValue, $row->residualUnitValue));
        }
        if ($row->interestRealUnitValue !== null) {
            $maxInterest = bc_max($maxInterest, bcabs_diff($mine->interestRealUnitValue, $row->interestRealUnitValue));
        }
        if ($row->totalValue !== null) {
            $maxTotal = bc_max($maxTotal, bcabs_diff($mine->totalValue, $row->totalValue));
        }
        if ($row->paymentInterestTotal !== null) {
            $maxPaymentInterest = bc_max($maxPaymentInterest, bcabs_diff($mine->interestPaymentValue, $row->paymentInterestTotal));
        }
        if ($row->amortizationUnitValue !== null && bccomp($row->amortizationUnitValue, '0', 8) === 1) {
            $maxAmortization = bc_max($maxAmortization, bcabs_diff($mine->amortizationUnitValue, $row->amortizationUnitValue));
            $amortizationsCompared++;
        }
        if ($row->factorSpread !== null) {
            $maxFactorSpread = bc_max($maxFactorSpread, bcabs_diff($mine->factorSpread, $row->factorSpread));
        }

        if ($mine->dupCorrection !== $row->dupCorrection || $mine->dutCorrection !== $row->dutCorrection) {
            $dupDutMismatches++;
        }
        if ($row->indexRateValue !== null
            && (bccomp($mine->indexRateValue ?? '0', $row->indexRateValue, 8) !== 0
                || $mine->indexRateDate?->toDateString() !== $row->indexRateDate?->toDateString())) {
            $indexMismatches++;
        }

        expect($mine->quantity)->toBe('8000.0000');
        $compared++;
    }

    // Tolerâncias máximas observadas (documentadas no relatório do gabarito):
    //   factorSpread (cupom) < 5,1e-10 | PU/cota corrigido/atualizado/residual < 2,7e-4
    //   juros/cota < 2,4e-6 | amortização exata | total < R$ 2,12 | pgto juros < R$ 0,015.
    //   Pior caso de PU em 2025-07-14 (longe de amortização) → drift de arredondamento, não lógica.

    // Janela completa comparada (~3,8 anos diários) incluindo todas as amortizações.
    expect($compared)->toBeGreaterThan(1300);
    expect($amortizationsCompared)->toBe(8); // 8 amortizações intermediárias dentro da janela publicada.
    // dup/dut da correção e índice usado batem exatamente.
    expect($dupDutMismatches)->toBe(0);
    expect($indexMismatches)->toBe(0);
    // Fator de cupom (factorSpread): bate à precisão publicada do gabarito.
    expect(bccomp($maxFactorSpread, '0.000000001', 18))->toBeLessThanOrEqual(0);
    // PU corrigido/atualizado/residual e juros: tolerância monetária documentada (< R$ 0,001/cota de ~R$ 1.000+).
    expect(bccomp($maxCorrected, '0.001', 16))->toBeLessThanOrEqual(0)
        ->and(bccomp($maxUpdated, '0.001', 16))->toBeLessThanOrEqual(0)
        ->and(bccomp($maxResidual, '0.001', 16))->toBeLessThanOrEqual(0)
        ->and(bccomp($maxInterest, '0.001', 16))->toBeLessThanOrEqual(0);
    // Amortizações batem exatamente (entram como valor unitário programado).
    expect(bccomp($maxAmortization, '0.001', 16))->toBeLessThanOrEqual(0);
    // Totais financeiros: a tolerância por cota (< R$ 0,001) é AMPLIFICADA pelas 8.000 cotas
    // (~R$ 8 teórico). Observado < R$ 3 no total e < R$ 0,05 no pgto de juros — drift de
    // arredondamento ao longo de ~3,8 anos, não divergência de lógica. Tolerância documentada.
    expect(bccomp($maxTotal, '3', 16))->toBeLessThanOrEqual(0)
        ->and(bccomp($maxPaymentInterest, '0.05', 16))->toBeLessThanOrEqual(0);
});

it('keeps IPCA blocked beyond the published number-index series (projection not implemented)', function () {
    IndexRate::query()->create([
        'indexer' => PuIndexer::Ipca->value,
        'rate_date' => '2021-08-01',
        'rate_value' => '5876.05',
        'source' => 'reference_workbook',
        'source_reference' => 'test',
    ]);
    IndexRate::query()->create([
        'indexer' => PuIndexer::Ipca->value,
        'rate_date' => '2021-09-01',
        'rate_value' => '5944.21',
        'source' => 'reference_workbook',
        'source_reference' => 'test',
    ]);
    app(IndexRateService::class)->flushCache();

    $emission = Emission::factory()->create(['type' => 'CRI', 'status' => 'active', 'issued_quantity' => 8000]);
    $emission->puParameter()->create([
        'curve_start_date' => '2021-09-30',
        'curve_end_date' => '2021-12-31',
        'initial_unit_value' => '1000.0000000000000000',
        'annual_rate' => '10.50000000',
        'indexer' => PuIndexer::Ipca->value,
        'calculation_method' => 'ipca_corrected',
        'business_day_basis' => 252,
        'calendar_code' => 'B3',
        'index_lag_months' => 0,
        'base_index_date' => '2021-09-23',
        'legacy_projection_enabled' => false,
    ]);
    $emission->integralizationHistories()->create([
        'date' => '2021-09-30',
        'quantity' => '8000.0000',
        'unit_value' => '1000.00000000',
        'financial_value' => '8000000.00',
        'investor_fund' => 'IPCA Homologation',
    ]);

    expect(fn () => app(IpcaCurveCalculator::class)->calculate($emission->fresh(['puParameter', 'puEvents', 'integralizationHistories'])))
        ->toThrow(\App\Domain\PuCalculator\Exceptions\IndexerNotSupportedException::class);
});

function bcabs_diff(string $left, string $right): string
{
    $diff = bcsub($left, $right, 18);

    return bccomp($diff, '0', 18) < 0 ? bcmul($diff, '-1', 18) : $diff;
}

function bc_max(string $left, string $right): string
{
    return bccomp($left, $right, 18) >= 0 ? $left : $right;
}

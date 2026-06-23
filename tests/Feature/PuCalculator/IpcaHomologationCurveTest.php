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
 * Homologação da engine IPCA contra o gabarito real (CRI RIO BRANCO 15ª série) na janela
 * pré-amortização (2021-09-30 → 2024-09-16). A interação das amortizações intermediárias
 * ainda não está homologada (ver IpcaCurveCalculator) e fica fora desta janela.
 */
function ipcaGabaritoPath(): string
{
    $matches = glob(base_path('docs/samples/pu-validation/*RIO BRANCO*.xlsx'));

    return $matches[0] ?? '';
}

/** Última data validável: véspera da primeira amortização intermediária. */
const IPCA_WINDOW_END = '2024-09-16';

it('reproduces the IPCA reference curve in the pre-amortization window to documented precision', function () {
    $path = ipcaGabaritoPath();
    expect($path)->not->toBe('')->and(file_exists($path))->toBeTrue();

    $reader = app(PuSpreadsheetReferenceReader::class);
    $reference = $reader->read($path, 'PuDiario')['rows'];
    expect($reference)->not->toBeEmpty();

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
        if ($date > '2024-10-01') {
            continue;
        }
        IndexRate::query()->create([
            'indexer' => PuIndexer::Ipca->value,
            'rate_date' => $date,
            'rate_value' => $value,
            'source' => 'reference_workbook',
            'source_reference' => 'CRI RIO BRANCO 15a serie',
        ]);
    }
    app(IndexRateService::class)->flushCache();

    $emission = Emission::factory()->create(['type' => 'CRI', 'status' => 'active']);
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

    // Eventos de juros mensais (pagamento no aniversário, dia 23) derivados do gabarito.
    $sequence = 1;
    foreach ($reference as $row) {
        if ($row->date->toDateString() > IPCA_WINDOW_END) {
            break;
        }
        if ($row->paymentInterestTotal === null || bccomp($row->paymentInterestTotal, '0', 2) !== 1) {
            continue;
        }
        $emission->puEvents()->create([
            'event_type' => PuEventType::InterestPayment->value,
            'original_date' => $row->date->toDateString(),
            'effective_date' => $row->date->toDateString(),
            'amortization_type' => PuAmortizationType::None->value,
            'amortization_value' => null,
            'sequence' => $sequence++,
        ]);
    }

    $result = app(IpcaCurveCalculator::class)->calculate($emission->fresh(['puParameter', 'puEvents', 'integralizationHistories']));

    $generated = [];
    foreach ($result->rows as $row) {
        $generated[$row->date->toDateString()] = $row;
    }

    $maxCorrected = '0';
    $maxUpdated = '0';
    $maxFactorSpread = '0';
    $compared = 0;
    $dupDutMismatches = 0;
    $indexMismatches = 0;
    $worstCorrectedAt = '';

    foreach ($reference as $row) {
        $key = $row->date->toDateString();
        if ($key < '2021-09-30' || $key > IPCA_WINDOW_END) {
            continue;
        }

        $mine = $generated[$key] ?? null;
        expect($mine)->not->toBeNull("missing generated row for {$key}");

        $correctedDiff = bcabs_diff($mine->unitCorrectedValue, $row->correctedUnitValue);
        if (bccomp($correctedDiff, $maxCorrected, 16) === 1) {
            $maxCorrected = $correctedDiff;
            $worstCorrectedAt = $key;
        }

        if ($row->updatedUnitValue !== null) {
            $updatedDiff = bcabs_diff($mine->updatedUnitValue, $row->updatedUnitValue);
            if (bccomp($updatedDiff, $maxUpdated, 16) === 1) {
                $maxUpdated = $updatedDiff;
            }
        }

        if ($row->factorSpread !== null) {
            $factorDiff = bcabs_diff($mine->factorSpread, $row->factorSpread);
            if (bccomp($factorDiff, $maxFactorSpread, 18) === 1) {
                $maxFactorSpread = $factorDiff;
            }
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

    // Janela completa comparada (~3 anos diários).
    expect($compared)->toBeGreaterThan(1000);
    // dup/dut e índice usado batem exatamente.
    expect($dupDutMismatches)->toBe(0);
    expect($indexMismatches)->toBe(0);
    // Fator de cupom (factorSpread): bate à precisão publicada do gabarito (~10 casas).
    expect(bccomp($maxFactorSpread, '0.000000001', 18))->toBeLessThanOrEqual(0);
    // PU corrigido/atualizado: tolerância monetária documentada (< R$ 0,001 por cota de ~R$ 1.000+).
    expect(bccomp($maxCorrected, '0.001', 16))->toBeLessThanOrEqual(0)
        ->and(bccomp($maxUpdated, '0.001', 16))->toBeLessThanOrEqual(0);

    // Sanidade: o último número-índice usado na janela é o de Ago/2024.
    expect($generated[IPCA_WINDOW_END]->indexRateValue)->not->toBeNull();
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

    $emission = Emission::factory()->create(['type' => 'CRI', 'status' => 'active']);
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

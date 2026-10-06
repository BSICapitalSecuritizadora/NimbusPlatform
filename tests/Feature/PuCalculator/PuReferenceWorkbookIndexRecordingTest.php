<?php

use App\Domain\PuCalculator\DTOs\IndexRateRecordOutcome;
use App\Domain\PuCalculator\DTOs\SpreadsheetReferenceRowData;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Exceptions\IndexRateObservationRefusedException;
use App\Domain\PuCalculator\Services\IndexRateObservationRecorder;
use App\Domain\PuCalculator\Services\PuReferenceWorkbookScenarioService;
use App\Domain\PuCalculator\Services\PuSpreadsheetReferenceReader;
use App\Models\Emission;
use App\Models\IndexRate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Fase 3.1 -- a planilha de referência não é um segundo jeito de gravar CDI.
 *
 * Ela apagava e regravava por `upsert` as taxas que declara ter usado e
 * preenchia o futuro com a última taxa como `forward_projection`. Agora cada taxa
 * passa pelo {@see IndexRateObservationRecorder},
 * com as regras da produção: reimportar é idempotente, valor diferente numa data
 * registrada é correção (caminho próprio), data com projeção não é tomada, valor
 * fora do domínio é recusado -- e qualquer recusa desfaz o cenário inteiro. Nada
 * é projetado.
 *
 * O leitor da planilha é substituído: o que importa aqui é o caminho de gravação.
 */
uses(RefreshDatabase::class);

const P31R_WORKBOOK = '/tmp/pu-referencia/PU_REFERENCIA_TESTE.xlsx';

/**
 * Planilha diária de 02 a 13/03/2026, com o CDI do dia útil anterior em cada dia
 * útil -- a última taxa declarada é a de 12/03.
 *
 * @param  array<string, string>  $rateOverrides  valor declarado por data da taxa
 * @return list<SpreadsheetReferenceRowData>
 */
function p31rRows(array $rateOverrides = []): array
{
    $rows = [];
    $dupInterest = 0;
    $previousBusinessDay = CarbonImmutable::parse('2026-02-27');

    for ($date = CarbonImmutable::parse('2026-03-02'); $date->lte(CarbonImmutable::parse('2026-03-13')); $date = $date->addDay()) {
        $isBusinessDay = ! $date->isWeekend();
        $first = $date->toDateString() === '2026-03-02';
        $rateDate = $isBusinessDay && ! $first ? $previousBusinessDay : null;

        if ($isBusinessDay && ! $first) {
            $dupInterest++;
        }

        $rows[] = new SpreadsheetReferenceRowData(
            date: $date,
            unitBaseValue: '1000.0000000000000000',
            correctedUnitValue: null,
            factorDi: null,
            factorDiAccumulated: null,
            factorSpread: null,
            factorSpreadDi: null,
            updatedUnitValue: null,
            residualUnitValue: null,
            interestRealUnitValue: null,
            amortizationUnitValue: null,
            quantity: '100.0000',
            totalValue: null,
            paymentInterestTotal: null,
            paymentAmortizationPrincipalTotal: null,
            paymentAmortizationCorrectionTotal: null,
            paymentTotalValue: null,
            eventOriginalDate: null,
            eventDueDate: null,
            indexRateDate: $rateDate,
            indexRateValue: $rateDate !== null ? ($rateOverrides[$rateDate->toDateString()] ?? '14.90000000') : null,
            dupCorrection: null,
            dutCorrection: null,
            dupInterest: $dupInterest,
            dutInterest: 252,
        );

        if ($isBusinessDay) {
            $previousBusinessDay = $date;
        }
    }

    return $rows;
}

/**
 * @param  list<SpreadsheetReferenceRowData>  $rows
 */
function p31rSync(Emission $emission, array $rows): array
{
    test()->mock(PuSpreadsheetReferenceReader::class)
        ->shouldReceive('read')
        ->andReturn(['sheet_name' => 'PuDiario', 'rows' => $rows]);

    return app(PuReferenceWorkbookScenarioService::class)->sync($emission, P31R_WORKBOOK);
}

function p31rEmission(): Emission
{
    return Emission::factory()->create(['name' => 'CR Referência', 'type' => 'CR', 'status' => 'active']);
}

/**
 * @return array<string, array{value: string, source: string, source_reference: ?string, projected: bool}>
 */
function p31rStoredCdi(): array
{
    return IndexRate::query()
        ->forIndexer(PuIndexer::Cdi)
        ->orderBy('rate_date')
        ->get()
        ->mapWithKeys(fn (IndexRate $rate): array => [$rate->rate_date->toDateString() => [
            'value' => (string) $rate->rate_value,
            'source' => (string) $rate->source,
            'source_reference' => $rate->source_reference,
            'projected' => $rate->isProjectedRate(),
        ]])
        ->all();
}

it('records the workbook CDI through the governed recorder, with provenance and without any projected tail', function () {
    $emission = p31rEmission();

    $summary = p31rSync($emission, p31rRows());
    $stored = p31rStoredCdi();
    $parameter = $emission->fresh()->puParameter;

    expect(array_keys($stored))->toBe(['2026-03-02', '2026-03-03', '2026-03-04', '2026-03-05', '2026-03-06', '2026-03-09', '2026-03-10', '2026-03-11', '2026-03-12'])
        ->and(collect($stored)->pluck('source')->unique()->all())->toBe([PuReferenceWorkbookScenarioService::INDEX_RATE_SOURCE])
        ->and(collect($stored)->pluck('source_reference')->unique()->all())->toBe(['PU_REFERENCIA_TESTE.xlsx'])
        ->and(collect($stored)->pluck('projected')->unique()->all())->toBe([false])
        // A cauda futura não é preenchida com a última taxa: 13/03 não tem CDI.
        ->and(IndexRate::query()->where('source_reference', 'forward_projection')->exists())->toBeFalse()
        ->and(IndexRate::query()->where('is_projected', true)->exists())->toBeFalse()
        ->and($summary['index_rate_rows'])->toBe(9)
        ->and($summary['index_rates_created'])->toBe(9)
        ->and($summary['index_rates_unchanged'])->toBe(0)
        ->and($summary['index_rate_source_conflicts'])->toBe([])
        // A planilha diz em que dias houve CDI: o calendário dela é o de divulgação do cenário.
        ->and($parameter->index_rate_calendar_code)->toBe($parameter->calendar_code)
        ->and($parameter->calendar_code)->toStartWith('HML_PU_');
});

it('is idempotent when the same workbook is synchronized again', function () {
    $emission = p31rEmission();
    p31rSync($emission, p31rRows());
    $before = p31rStoredCdi();
    $ids = IndexRate::query()->orderBy('id')->pluck('id')->all();

    $summary = p31rSync($emission, p31rRows());

    expect(p31rStoredCdi())->toBe($before)
        ->and(IndexRate::query()->orderBy('id')->pluck('id')->all())->toBe($ids)
        ->and($summary['index_rates_created'])->toBe(0)
        ->and($summary['index_rates_unchanged'])->toBe(9);
});

it('never overwrites a realized CDI with a different workbook value, and writes nothing at all', function () {
    $emission = p31rEmission();
    IndexRate::factory()->create([
        'indexer' => PuIndexer::Cdi->value,
        'rate_date' => '2026-03-05',
        'rate_value' => '14.80000000',
        'source' => 'bcb_sgs',
        'source_reference' => 'bcb_sgs:4389',
    ]);

    try {
        p31rSync($emission, p31rRows());
        $this->fail('A planilha sobrescreveu o CDI de 05/03.');
    } catch (IndexRateObservationRefusedException $refusal) {
        expect($refusal->outcomes)->toHaveCount(1)
            ->and($refusal->outcomes[0]->status)->toBe(IndexRateRecordOutcome::VALUE_CONFLICT)
            ->and($refusal->getMessage())->toContain('2026-03-05')
            ->and($refusal->getMessage())->toContain('pu:index-rates:correct');
    }

    expect(p31rStoredCdi())->toBe(['2026-03-05' => [
        'value' => '14.80000000',
        'source' => 'bcb_sgs',
        'source_reference' => 'bcb_sgs:4389',
        'projected' => false,
    ]])
        // A transação desfez o cenário inteiro, inclusive os parâmetros.
        ->and($emission->fresh()->puParameter)->toBeNull();
});

it('keeps the existing provenance when the workbook declares the same value from another source', function () {
    $emission = p31rEmission();
    IndexRate::factory()->create([
        'indexer' => PuIndexer::Cdi->value,
        'rate_date' => '2026-03-05',
        'rate_value' => '14.90000000',
        'source' => 'bcb_sgs',
        'source_reference' => 'bcb_sgs:4389',
    ]);

    $summary = p31rSync($emission, p31rRows());

    expect($summary['index_rate_source_conflicts'])->toBe(['2026-03-05'])
        ->and($summary['index_rates_created'])->toBe(8)
        ->and(p31rStoredCdi()['2026-03-05']['source'])->toBe('bcb_sgs');
});

it('does not take a date occupied by a projection', function () {
    $emission = p31rEmission();
    IndexRate::factory()->create([
        'indexer' => PuIndexer::Cdi->value,
        'rate_date' => '2026-03-10',
        'rate_value' => '15.25000000',
        'source' => 'scenario',
        'source_reference' => 'scenario:flat',
        'is_projected' => true,
    ]);

    expect(fn () => p31rSync($emission, p31rRows()))
        ->toThrow(IndexRateObservationRefusedException::class, IndexRateRecordOutcome::NATURE_CONFLICT);

    expect(p31rStoredCdi())->toBe(['2026-03-10' => [
        'value' => '15.25000000',
        'source' => 'scenario',
        'source_reference' => 'scenario:flat',
        'projected' => true,
    ]]);
});

it('refuses a workbook rate outside the financial domain with the production rule', function () {
    $emission = p31rEmission();

    expect(fn () => p31rSync($emission, p31rRows(['2026-03-04' => '-100.00000000'])))
        ->toThrow(IndexRateObservationRefusedException::class, IndexRateRecordOutcome::REJECTED);

    expect(IndexRate::query()->count())->toBe(0);
});

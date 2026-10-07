<?php

use App\Domain\PuCalculator\DTOs\IndexRateRecordOutcome;
use App\Domain\PuCalculator\DTOs\IndexRateSyncResult;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Services\IndexRateImportService;
use App\Domain\PuCalculator\Services\IndexRateObservationRecorder;
use App\Domain\PuCalculator\Services\IndexRateSyncService;
use App\Models\IndexRate;
use App\Models\IndexRateCorrection;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Activitylog\Models\Activity;

/**
 * Fase 3 (P1-08) -- uma observação já registrada nunca é sobrescrita em silêncio.
 *
 * Antes, a importação por planilha fazia `updateOrCreate` e trocava valor e
 * origem de qualquer data -- inclusive o CDI publicado pelo Banco Central. Agora:
 * data nova entra; a mesma data com o mesmo valor e a mesma origem é idempotente;
 * valor diferente é conflito (correção só pelo caminho próprio, com motivo);
 * mesmo valor com outra origem mantém a procedência existente.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00:00', 'America/Sao_Paulo'));
});

function p3iCsv(string $contents): string
{
    $path = temporaryTestFilePath('phase3-index-'.getmypid(), 'csv');
    file_put_contents($path, $contents);

    return $path;
}

function p3iImport(string $csv, ?string $source = 'manual_import', bool $confirmOutliers = false): array
{
    return app(IndexRateImportService::class)->importPublished(PuIndexer::Cdi, p3iCsv($csv), $source, null, $confirmOutliers);
}

function p3iBcbRow(string $date, string $value): IndexRate
{
    return IndexRate::query()->create([
        'indexer' => PuIndexer::Cdi->value,
        'rate_date' => CarbonImmutable::parse($date)->startOfDay(),
        'rate_value' => $value,
        'source' => 'bcb_sgs',
        'source_reference' => 'bcb_sgs:4389',
        'external_series_code' => '4389',
        'is_projected' => false,
    ]);
}

function p3iSync(array $payload, ?string $policy = null): IndexRateSyncResult
{
    Http::preventStrayRequests();
    Http::fake(['api.bcb.gov.br/dados/serie/bcdata.sgs.4389/*' => Http::response($payload, 200)]);

    return app(IndexRateSyncService::class)->sync(
        PuIndexer::Cdi,
        CarbonImmutable::parse('2026-09-01'),
        CarbonImmutable::parse('2026-09-30'),
        false,
        null,
        $policy,
    );
}

function p3iValue(string $date): ?string
{
    $value = IndexRate::query()->forIndexer(PuIndexer::Cdi)->whereDate('rate_date', $date)->value('rate_value');

    return $value !== null ? (string) $value : null;
}

it('creates a new observation and treats an identical re-import as a no-op', function () {
    $first = p3iImport("rate_date,rate_value\n2026-09-01,14.90\n2026-09-02,\"14,91\"\n");
    $row = IndexRate::query()->whereDate('rate_date', '2026-09-01')->sole();
    $stamp = [$row->source_reference, (string) $row->getRawOriginal('updated_at')];

    $this->travel(5)->minutes();
    $second = p3iImport("rate_date,rate_value\n2026-09-01,14.90\n2026-09-02,14.91\n");
    $row->refresh();

    expect($first['imported'])->toBe(2)
        ->and(p3iValue('2026-09-02'))->toBe('14.91000000')
        ->and($second['imported'])->toBe(0)
        ->and($second['unchanged'])->toBe(2)
        ->and($second['conflicts'])->toBe([])
        ->and($second['errors'])->toBe([])
        ->and([$row->source_reference, (string) $row->getRawOriginal('updated_at')])->toBe($stamp)
        ->and(IndexRate::query()->count())->toBe(2)
        ->and(IndexRateCorrection::query()->count())->toBe(0);
});

it('refuses to overwrite an existing date with a different value from a spreadsheet', function () {
    p3iBcbRow('2026-09-01', '14.90000000');

    $result = p3iImport("rate_date,rate_value\n2026-09-01,15.90\n");

    expect($result['imported'])->toBe(0)
        ->and($result['conflicts'])->toHaveCount(1)
        ->and($result['conflicts'][0]['status'])->toBe(IndexRateRecordOutcome::VALUE_CONFLICT)
        ->and($result['errors'][0])->toContain('pu:index-rates:correct')
        ->and(p3iValue('2026-09-01'))->toBe('14.90000000')
        ->and(IndexRate::query()->whereDate('rate_date', '2026-09-01')->value('source'))->toBe('bcb_sgs')
        ->and(IndexRateCorrection::query()->count())->toBe(0);
});

it('keeps the official provider provenance when a generic import brings the same value', function () {
    p3iBcbRow('2026-09-01', '14.90000000');

    $result = p3iImport("rate_date,rate_value\n2026-09-01,14.90\n", source: 'planilha B3');

    expect($result['conflicts'][0]['status'])->toBe(IndexRateRecordOutcome::SOURCE_CONFLICT)
        ->and(IndexRate::query()->whereDate('rate_date', '2026-09-01')->sole()->only(['source', 'source_reference']))
        ->toBe(['source' => 'bcb_sgs', 'source_reference' => 'bcb_sgs:4389']);
});

dataset('p3i_rejected_values', [
    'base de capitalizacao nula' => ['-100'],
    'base de capitalizacao negativa' => ['"-150,5"'],
    'separadores ambiguos' => ['"1.234,56"'],
    'texto' => ['quatorze'],
    'mais de oito casas' => ['14.123456789'],
]);

it('rejects values outside the financial domain or with an ambiguous format', function (string $value) {
    $result = p3iImport("rate_date,rate_value\n2026-09-01,{$value}\n");

    expect($result['imported'])->toBe(0)
        ->and($result['errors'])->toHaveCount(1)
        ->and(IndexRate::query()->count())->toBe(0);
})->with('p3i_rejected_values');

it('preserves a legitimate negative CDI above the compounding boundary', function () {
    $result = p3iImport("rate_date,rate_value\n2026-09-01,-0.50\n2026-09-02,-99.5\n", confirmOutliers: true);

    expect($result['imported'])->toBe(2)
        ->and(p3iValue('2026-09-01'))->toBe('-0.50000000')
        ->and(p3iValue('2026-09-02'))->toBe('-99.50000000');
});

dataset('p3i_rejected_dates', [
    'data futura como realizada' => ['2026-10-07', 'futura'],
    'data inexistente' => ['2026-02-30', 'inexistente'],
    'mes sem dia para CDI diario' => ['2026-09', 'exige YYYY-MM-DD'],
]);

it('rejects dates that cannot be a realized CDI observation', function (string $date, string $message) {
    $result = p3iImport("rate_date,rate_value\n2026-09-01,14.90\n{$date},14.90\n");

    expect($result['imported'])->toBe(1)
        ->and($result['errors'])->toHaveCount(1)
        ->and($result['errors'][0])->toContain($message)
        ->and(IndexRate::query()->count())->toBe(1);
})->with('p3i_rejected_dates');

it('rejects a file that repeats a date with an ambiguous meaning', function () {
    $result = p3iImport("rate_date,rate_value\n2026-09-01,14.90\n2026-09-01,14.95\n");

    expect($result['imported'])->toBe(1)
        ->and($result['errors'][0])->toContain('aparece de novo')
        ->and(p3iValue('2026-09-01'))->toBe('14.90000000');
});

it('holds a value ten times off the previous observation until it is explicitly confirmed', function () {
    p3iBcbRow('2026-09-01', '14.90000000');

    $held = p3iImport("rate_date,rate_value\n2026-09-02,0.149\n");
    $confirmed = p3iImport("rate_date,rate_value\n2026-09-02,0.149\n", confirmOutliers: true);

    expect($held['imported'])->toBe(0)
        ->and($held['needs_confirmation'])->toHaveCount(1)
        ->and($held['errors'][0])->toContain('possível erro de unidade')
        ->and($confirmed['imported'])->toBe(1)
        ->and(p3iValue('2026-09-02'))->toBe('0.14900000');
});

it('records who imported and what happened to each date', function () {
    $user = User::factory()->create();
    p3iBcbRow('2026-09-01', '14.90000000');

    app(IndexRateImportService::class)->importPublished(
        PuIndexer::Cdi,
        p3iCsv("rate_date,rate_value\n2026-09-01,15.00\n2026-09-02,14.90\n"),
        'manual_import',
        $user->id,
    );
    $activity = Activity::query()->where('description', 'pu_index_rates_imported')->sole();

    expect($activity->causer_id)->toBe($user->id)
        ->and($activity->properties['imported'])->toBe(1)
        ->and($activity->properties['conflicts'][0]['date'])->toBe('2026-09-01');
});

it('refuses a CDI projection import and never lets an IPCA projection replace a published index', function () {
    expect(fn () => app(IndexRateImportService::class)->importProjectedSeries(
        PuIndexer::Cdi,
        p3iCsv("rate_date,rate_value\n2026-12-01,15.00\n"),
        ['name' => 'CDI futuro', 'projection_source' => 'mercado'],
    ))->toThrow(InvalidArgumentException::class, 'Não existe modelo de projeção aprovado');

    IndexRate::query()->create([
        'indexer' => PuIndexer::Ipca->value,
        'rate_date' => CarbonImmutable::parse('2026-08-01'),
        'rate_value' => '7100.00000000',
        'source' => 'IBGE',
        'is_projected' => false,
    ]);

    $result = app(IndexRateImportService::class)->importProjectedSeries(
        PuIndexer::Ipca,
        p3iCsv("rate_date,rate_value\n2026-08,7200.00\n2026-09,7250.00\n"),
        ['name' => 'IPCA mercado', 'projection_source' => 'ANBIMA', 'projection_policy' => 'market'],
    );

    expect($result['imported'])->toBe(1)
        ->and($result['errors'][0])->toContain('projeção não substitui publicado')
        ->and(IndexRate::query()->forIndexer(PuIndexer::Ipca)->whereDate('rate_date', '2026-08-01')->sole()->only(['rate_value', 'is_projected']))
        ->toBe(['rate_value' => '7100.00000000', 'is_projected' => false]);
});

it('does not let a realized observation silently replace a projected row', function () {
    IndexRate::query()->create([
        'indexer' => PuIndexer::Cdi->value,
        'rate_date' => CarbonImmutable::parse('2026-09-01'),
        'rate_value' => '15.00000000',
        'source' => 'reference_workbook',
        'source_reference' => 'forward_projection',
    ]);

    $outcome = app(IndexRateObservationRecorder::class)->recordRealized(
        PuIndexer::Cdi,
        CarbonImmutable::parse('2026-09-01'),
        '14.90',
        ['source' => 'bcb_sgs'],
    );

    expect($outcome->status)->toBe(IndexRateRecordOutcome::NATURE_CONFLICT)
        ->and(p3iValue('2026-09-01'))->toBe('15.00000000');
});

it('keeps a repeated provider sync idempotent without rewriting provenance or opening corrections', function () {
    $payload = [['data' => '01/09/2026', 'valor' => '14.90'], ['data' => '02/09/2026', 'valor' => '14.90']];

    $first = p3iSync($payload);
    $row = IndexRate::query()->whereDate('rate_date', '2026-09-01')->sole();
    $fetchedAt = (string) $row->getRawOriginal('fetched_at');
    $this->travel(1)->days();
    $second = p3iSync($payload);
    $overwrite = p3iSync($payload, IndexRateSyncService::POLICY_OVERWRITE);

    expect($first->created)->toBe(2)
        ->and($second->created)->toBe(0)
        ->and($second->skipped)->toBe(2)
        ->and($second->conflicts)->toBe([])
        ->and($second->errors)->toBe([])
        ->and($overwrite->updated)->toBe(0)
        ->and((string) $row->fresh()->getRawOriginal('fetched_at'))->toBe($fetchedAt)
        ->and(IndexRate::query()->count())->toBe(2)
        ->and(IndexRateCorrection::query()->count())->toBe(0);
});

it('reports a provider revision as a conflict under the default policy and changes nothing', function () {
    p3iBcbRow('2026-09-01', '14.90000000');

    $result = p3iSync([['data' => '01/09/2026', 'valor' => '14.95']]);

    expect($result->updated)->toBe(0)
        ->and($result->conflicts)->toHaveCount(1)
        ->and($result->conflicts[0]['status'])->toBe('value_conflict')
        ->and(p3iValue('2026-09-01'))->toBe('14.90000000')
        ->and(IndexRateCorrection::query()->count())->toBe(0);
});

it('applies a provider revision only through the audited correction path when the operator opts in', function () {
    p3iBcbRow('2026-09-01', '14.90000000');

    $result = p3iSync([['data' => '01/09/2026', 'valor' => '14.95']], IndexRateSyncService::POLICY_UPDATE);
    $correction = IndexRateCorrection::query()->sole();

    expect($result->updated)->toBe(1)
        ->and(p3iValue('2026-09-01'))->toBe('14.95000000')
        ->and($correction->origin)->toBe(IndexRateCorrection::ORIGIN_PROVIDER_REVISION)
        ->and((string) $correction->previous_rate_value)->toBe('14.90000000')
        ->and((string) $correction->new_rate_value)->toBe('14.95000000')
        ->and($correction->reason)->toContain('bcb_sgs');
});

it('rejects a provider value outside the financial domain without writing it', function () {
    $result = p3iSync([['data' => '01/09/2026', 'valor' => '-100.00'], ['data' => '02/09/2026', 'valor' => '14.90']]);

    expect($result->created)->toBe(1)
        ->and($result->errors[0])->toContain('-100')
        ->and(p3iValue('2026-09-01'))->toBeNull();
});

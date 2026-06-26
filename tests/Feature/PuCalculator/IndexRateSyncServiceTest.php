<?php

use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Services\IndexRateSyncService;
use App\Models\IndexRate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function fakeCdi(array $rows): void
{
    Http::fake(['api.bcb.gov.br/*' => Http::response($rows, 200)]);
}

function syncCdi(string $from = '2024-01-01', string $to = '2024-01-31', ?string $policy = null): \App\Domain\PuCalculator\DTOs\IndexRateSyncResult
{
    return app(IndexRateSyncService::class)->sync(
        PuIndexer::Cdi,
        CarbonImmutable::parse($from),
        CarbonImmutable::parse($to),
        false,
        null,
        $policy,
    );
}

it('creates published CDI rates from BCB with origin metadata', function () {
    fakeCdi([
        ['data' => '02/01/2024', 'valor' => '11.65'],
        ['data' => '03/01/2024', 'valor' => '11.65'],
    ]);

    $result = syncCdi();

    expect($result->created)->toBe(2)
        ->and($result->fetched)->toBe(2)
        ->and(IndexRate::query()->where('source', 'bcb_sgs')->count())->toBe(2);

    $row = IndexRate::query()->where('indexer', 'CDI')->whereDate('rate_date', '2024-01-02')->first();
    expect($row->rate_value)->toEqual('11.65000000')
        ->and($row->external_series_code)->toBe('4389')
        ->and($row->is_projected)->toBeFalse()
        ->and($row->fetched_at)->not->toBeNull();
});

it('is idempotent and respects the Y-m-d H:i:s date cast (no duplicates)', function () {
    fakeCdi([['data' => '02/01/2024', 'valor' => '11.65']]);

    syncCdi();
    $second = syncCdi();

    expect($second->created)->toBe(0)
        ->and($second->skipped)->toBe(1)
        ->and(IndexRate::query()->where('indexer', 'CDI')->count())->toBe(1);
});

it('defaults to insert-only (does not overwrite an existing date)', function () {
    Http::fakeSequence('api.bcb.gov.br/*')
        ->push([['data' => '02/01/2024', 'valor' => '11.65']])
        ->push([['data' => '02/01/2024', 'valor' => '11.90']]);

    syncCdi();
    $result = syncCdi(); // política default (skip_existing): não sobrescreve

    expect($result->created)->toBe(0)
        ->and($result->updated)->toBe(0)
        ->and($result->skipped)->toBe(1)
        ->and(IndexRate::query()->whereDate('rate_date', '2024-01-02')->value('rate_value'))->toEqual('11.65000000');
});

it('updates a bcb row only when the value changed (update_if_changed)', function () {
    Http::fakeSequence('api.bcb.gov.br/*')
        ->push([['data' => '02/01/2024', 'valor' => '11.65']])
        ->push([['data' => '02/01/2024', 'valor' => '11.90']]);

    syncCdi();
    $result = syncCdi(policy: IndexRateSyncService::POLICY_UPDATE);

    expect($result->updated)->toBe(1)
        ->and(IndexRate::query()->whereDate('rate_date', '2024-01-02')->value('rate_value'))->toEqual('11.90000000');
});

it('skips existing rows under skip_existing policy', function () {
    Http::fakeSequence('api.bcb.gov.br/*')
        ->push([['data' => '02/01/2024', 'valor' => '11.65']])
        ->push([['data' => '02/01/2024', 'valor' => '11.90']]);

    syncCdi();
    $result = syncCdi(policy: IndexRateSyncService::POLICY_SKIP);

    expect($result->skipped)->toBe(1)
        ->and($result->updated)->toBe(0)
        ->and(IndexRate::query()->whereDate('rate_date', '2024-01-02')->value('rate_value'))->toEqual('11.65000000');
});

it('never overwrites manually imported rows', function () {
    IndexRate::query()->create([
        'indexer' => 'CDI',
        'rate_date' => CarbonImmutable::parse('2024-01-02')->startOfDay(),
        'rate_value' => '99.99000000',
        'source' => 'manual_import',
        'is_projected' => false,
    ]);

    fakeCdi([['data' => '02/01/2024', 'valor' => '11.65']]);
    $result = syncCdi(policy: IndexRateSyncService::POLICY_OVERWRITE);

    expect($result->skipped)->toBe(1)
        ->and(IndexRate::query()->whereDate('rate_date', '2024-01-02')->value('rate_value'))->toEqual('99.99000000');
});

it('does not persist anything on dry-run', function () {
    fakeCdi([['data' => '02/01/2024', 'valor' => '11.65']]);

    $result = app(IndexRateSyncService::class)->sync(
        PuIndexer::Cdi,
        CarbonImmutable::parse('2024-01-01'),
        CarbonImmutable::parse('2024-01-31'),
        true,
    );

    expect($result->created)->toBe(1)
        ->and($result->dryRun)->toBeTrue()
        ->and(IndexRate::query()->count())->toBe(0);
});

it('transforms IPCA monthly variation into a chained number-index from an anchor', function () {
    IndexRate::query()->create([
        'indexer' => 'IPCA',
        'rate_date' => CarbonImmutable::parse('2023-12-01')->startOfDay(),
        'rate_value' => '6000.00000000',
        'source' => 'manual_import',
        'is_projected' => false,
    ]);

    Http::fake(['api.bcb.gov.br/*' => Http::response([
        ['data' => '01/01/2024', 'valor' => '0.50'],
        ['data' => '01/02/2024', 'valor' => '1.00'],
    ], 200)]);

    $result = app(IndexRateSyncService::class)->sync(
        PuIndexer::Ipca,
        CarbonImmutable::parse('2024-01-01'),
        CarbonImmutable::parse('2024-02-29'),
    );

    expect($result->created)->toBe(2)
        ->and(IndexRate::query()->where('indexer', 'IPCA')->whereDate('rate_date', '2024-01-01')->value('rate_value'))->toEqual('6030.00000000')
        ->and(IndexRate::query()->where('indexer', 'IPCA')->whereDate('rate_date', '2024-02-01')->value('rate_value'))->toEqual('6090.30000000');

    $row = IndexRate::query()->where('indexer', 'IPCA')->whereDate('rate_date', '2024-01-01')->first();
    expect($row->is_projected)->toBeFalse()
        ->and($row->source)->toBe('bcb_sgs');
});

it('auto-seeds a base number-index and syncs when there is no IPCA anchor (no blocking)', function () {
    Http::fake(['api.bcb.gov.br/*' => Http::response([
        ['data' => '01/01/2024', 'valor' => '0.50'],
        ['data' => '01/02/2024', 'valor' => '1.00'],
    ], 200)]);

    $result = app(IndexRateSyncService::class)->sync(
        PuIndexer::Ipca,
        CarbonImmutable::parse('2024-01-01'),
        CarbonImmutable::parse('2024-02-29'),
    );

    // Não bloqueia: cria as 2 competências consultadas (base âncora não conta como "criado").
    expect($result->created)->toBe(2)
        ->and($result->fetched)->toBe(2)
        ->and($result->hasErrors())->toBeFalse()
        ->and($result->hasNotices())->toBeTrue()
        ->and($result->notices[0])->toContain('criado automaticamente');

    // Base 100 no mês anterior (2023-12) + encadeamento 100*(1,005)=100,5 e 100,5*(1,01)=101,505.
    expect(IndexRate::query()->where('indexer', 'IPCA')->count())->toBe(3)
        ->and(IndexRate::query()->where('indexer', 'IPCA')->whereDate('rate_date', '2023-12-01')->value('rate_value'))->toEqual('100.00000000')
        ->and(IndexRate::query()->where('indexer', 'IPCA')->whereDate('rate_date', '2024-01-01')->value('rate_value'))->toEqual('100.50000000')
        ->and(IndexRate::query()->where('indexer', 'IPCA')->whereDate('rate_date', '2024-02-01')->value('rate_value'))->toEqual('101.50500000');

    // A base âncora fica identificável e marcada como publicada (não projetada).
    $base = IndexRate::query()->where('indexer', 'IPCA')->whereDate('rate_date', '2023-12-01')->first();
    expect($base->is_projected)->toBeFalse()
        ->and($base->source)->toBe('bcb_sgs')
        ->and($base->external_series_code)->toBe('433');
});

it('does not re-seed the base anchor on a repeated IPCA sync (idempotent)', function () {
    Http::fake(['api.bcb.gov.br/*' => Http::response([
        ['data' => '01/01/2024', 'valor' => '0.50'],
    ], 200)]);

    $first = app(IndexRateSyncService::class)->sync(PuIndexer::Ipca, CarbonImmutable::parse('2024-01-01'), CarbonImmutable::parse('2024-01-31'));
    $second = app(IndexRateSyncService::class)->sync(PuIndexer::Ipca, CarbonImmutable::parse('2024-01-01'), CarbonImmutable::parse('2024-01-31'));

    expect($first->created)->toBe(1)
        ->and($second->created)->toBe(0)
        ->and($second->skipped)->toBe(1)
        ->and($second->hasNotices())->toBeFalse()
        ->and(IndexRate::query()->where('indexer', 'IPCA')->count())->toBe(2);
});

it('records a completed last-sync status even when every IPCA record already exists', function () {
    Http::fake(['api.bcb.gov.br/*' => Http::response([
        ['data' => '01/01/2024', 'valor' => '0.50'],
    ], 200)]);

    app(IndexRateSyncService::class)->sync(PuIndexer::Ipca, CarbonImmutable::parse('2024-01-01'), CarbonImmutable::parse('2024-01-31'));
    \Illuminate\Support\Facades\Cache::flush();
    app(IndexRateSyncService::class)->sync(PuIndexer::Ipca, CarbonImmutable::parse('2024-01-01'), CarbonImmutable::parse('2024-01-31'));

    $status = \Illuminate\Support\Facades\Cache::get('pu_index_sync_ipca_status');

    expect($status)->toMatchArray(['status' => 'completed'])
        ->and($status['synced_at'] ?? null)->not->toBeNull();
});

it('consolidates yearly blocks into a single sync run without duplicates', function () {
    config(['pu_indexes.bcb.chunk_months' => 12, 'pu_indexes.bcb.retries' => 1, 'pu_indexes.bcb.retry_sleep_ms' => 0]);

    Http::fakeSequence('api.bcb.gov.br/*')
        ->push([['data' => '03/01/2022', 'valor' => '9.15']])
        ->push([['data' => '02/01/2023', 'valor' => '13.65']])
        ->push([['data' => '02/01/2024', 'valor' => '11.65']]);

    $result = syncCdi('2022-01-01', '2024-01-31');

    expect($result->created)->toBe(3)
        ->and($result->fetched)->toBe(3)
        ->and($result->blocksTotal)->toBe(3)
        ->and($result->blocksSucceeded())->toBe(3)
        ->and($result->hasBlockFailures())->toBeFalse()
        ->and(IndexRate::query()->where('indexer', 'CDI')->count())->toBe(3);
});

it('does not duplicate rows across chunked blocks on a repeated sync (idempotent)', function () {
    config(['pu_indexes.bcb.chunk_months' => 12, 'pu_indexes.bcb.retries' => 1, 'pu_indexes.bcb.retry_sleep_ms' => 0]);

    // Cada bloco recebe o mesmo ponto único; a dedupe por data deixa apenas 1 registro.
    Http::fake(['api.bcb.gov.br/*' => Http::response([['data' => '02/01/2024', 'valor' => '11.65']], 200)]);

    $first = syncCdi('2022-01-01', '2024-01-31');
    expect($first->created)->toBe(1)
        ->and(IndexRate::query()->where('indexer', 'CDI')->count())->toBe(1);

    $second = syncCdi('2022-01-01', '2024-01-31');
    expect($second->created)->toBe(0)
        ->and($second->skipped)->toBe(1)
        ->and(IndexRate::query()->where('indexer', 'CDI')->count())->toBe(1);
});

it('persists the blocks that succeed and reports the failed block (partial sync)', function () {
    config(['pu_indexes.bcb.chunk_months' => 12, 'pu_indexes.bcb.retries' => 1, 'pu_indexes.bcb.retry_sleep_ms' => 0]);

    Http::fakeSequence('api.bcb.gov.br/*')
        ->push('error', 500)
        ->push([['data' => '02/01/2023', 'valor' => '13.65']])
        ->push([['data' => '02/01/2024', 'valor' => '11.65']]);

    $result = syncCdi('2022-01-01', '2024-01-31');

    expect($result->created)->toBe(2)
        ->and($result->blocksTotal)->toBe(3)
        ->and($result->blocksFailed())->toBe(1)
        ->and($result->hasBlockFailures())->toBeTrue()
        ->and($result->hasErrors())->toBeTrue()
        ->and($result->errors[0])->toContain('Sincronização parcial')
        ->and(IndexRate::query()->where('indexer', 'CDI')->count())->toBe(2);
});

it('reports a clean "no new records" run when the API responds without data', function () {
    Http::fake(['api.bcb.gov.br/*' => Http::response([], 200)]);

    $result = syncCdi();

    expect($result->fetched)->toBe(0)
        ->and($result->created)->toBe(0)
        ->and($result->hasBlockFailures())->toBeFalse()
        ->and($result->errors[0])->toContain('respondeu sem dados');
});

it('uses the configured IPCA series code so it can target IPCA geral (433) or IPCA Serviços (10844)', function () {
    config(['pu_indexes.bcb.series.ipca.code' => 10844]);

    IndexRate::query()->create([
        'indexer' => 'IPCA',
        'rate_date' => CarbonImmutable::parse('2023-12-01')->startOfDay(),
        'rate_value' => '100.00000000',
        'source' => 'manual_import',
        'is_projected' => false,
    ]);

    Http::fake(['api.bcb.gov.br/*' => Http::response([
        ['data' => '01/01/2024', 'valor' => '0.50'],
    ], 200)]);

    $result = app(IndexRateSyncService::class)->sync(
        PuIndexer::Ipca,
        CarbonImmutable::parse('2024-01-01'),
        CarbonImmutable::parse('2024-01-31'),
    );

    expect($result->externalSeriesCode)->toBe(10844)
        ->and($result->created)->toBe(1);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'bcdata.sgs.10844'));

    $row = IndexRate::query()->where('indexer', 'IPCA')->whereDate('rate_date', '2024-01-01')->first();
    expect($row->external_series_code)->toBe('10844')
        ->and($row->rate_value)->toEqual('100.50000000');
});

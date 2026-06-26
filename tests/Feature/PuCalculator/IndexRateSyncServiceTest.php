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

it('blocks IPCA sync when there is no anchor number-index', function () {
    Http::fake(['api.bcb.gov.br/*' => Http::response([
        ['data' => '01/01/2024', 'valor' => '0.50'],
    ], 200)]);

    $result = app(IndexRateSyncService::class)->sync(
        PuIndexer::Ipca,
        CarbonImmutable::parse('2024-01-01'),
        CarbonImmutable::parse('2024-01-31'),
    );

    expect($result->created)->toBe(0)
        ->and($result->hasErrors())->toBeTrue()
        ->and(IndexRate::query()->where('indexer', 'IPCA')->count())->toBe(0);
});

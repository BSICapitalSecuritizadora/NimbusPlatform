<?php

use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Services\IndexRateSyncService;
use App\Models\IndexRate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('creates an IPCA anchor number-index for the given competence', function () {
    $this->artisan('pu:index-rates:seed-ipca-anchor', ['--month' => '2016-11', '--value' => '100'])
        ->assertSuccessful();

    $row = IndexRate::query()->where('indexer', 'IPCA')->whereDate('rate_date', '2016-11-01')->first();

    expect($row)->not->toBeNull()
        ->and($row->rate_value)->toEqual('100.00000000')
        ->and($row->source)->toBe('manual_import')
        ->and($row->is_projected)->toBeFalse();
});

it('enables an IPCA sync that was previously blocked by a missing anchor', function () {
    $this->artisan('pu:index-rates:seed-ipca-anchor', ['--month' => '2023-12'])->assertSuccessful();

    Http::fake(['api.bcb.gov.br/*' => Http::response([
        ['data' => '01/01/2024', 'valor' => '0.50'],
    ], 200)]);

    $result = app(IndexRateSyncService::class)->sync(
        PuIndexer::Ipca,
        CarbonImmutable::parse('2024-01-01'),
        CarbonImmutable::parse('2024-01-31'),
    );

    expect($result->created)->toBe(1)
        ->and($result->hasErrors())->toBeFalse()
        ->and(IndexRate::query()->where('indexer', 'IPCA')->whereDate('rate_date', '2024-01-01')->value('rate_value'))->toEqual('100.50000000');
});

it('does not overwrite an existing IPCA row for the same competence', function () {
    IndexRate::query()->create([
        'indexer' => 'IPCA',
        'rate_date' => CarbonImmutable::parse('2016-11-01')->startOfDay(),
        'rate_value' => '5000.00000000',
        'source' => 'manual_import',
        'is_projected' => false,
    ]);

    $this->artisan('pu:index-rates:seed-ipca-anchor', ['--month' => '2016-11'])
        ->expectsOutputToContain('Já existe um IPCA')
        ->assertSuccessful();

    expect(IndexRate::query()->where('indexer', 'IPCA')->whereDate('rate_date', '2016-11-01')->value('rate_value'))
        ->toEqual('5000.00000000');
});

it('rejects an invalid competence', function () {
    $this->artisan('pu:index-rates:seed-ipca-anchor', ['--month' => 'invalid'])
        ->assertFailed();
});

it('requires the month option', function () {
    $this->artisan('pu:index-rates:seed-ipca-anchor')
        ->assertFailed();
});

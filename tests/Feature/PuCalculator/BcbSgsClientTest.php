<?php

use App\Domain\PuCalculator\DTOs\BcbSgsFetchResult;
use App\Domain\PuCalculator\Exceptions\BcbSgsException;
use App\Domain\PuCalculator\Services\BcbSgsClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // Backoff sem espera real e uma tentativa por bloco (determinístico) por padrão nos testes.
    config(['pu_indexes.bcb.retry_sleep_ms' => 0, 'pu_indexes.bcb.retries' => 1]);
});

it('parses a SGS JSON response into DTOs with decimal-string values', function () {
    Http::fake(['api.bcb.gov.br/*' => Http::response([
        ['data' => '02/01/2024', 'valor' => '11.65'],
        ['data' => '03/01/2024', 'valor' => '11,70'],
    ], 200)]);

    $result = app(BcbSgsClient::class)->fetchSeries(4389, CarbonImmutable::parse('2024-01-01'), CarbonImmutable::parse('2024-01-31'));

    expect($result)->toBeInstanceOf(BcbSgsFetchResult::class)
        ->and($result->rates)->toHaveCount(2)
        ->and($result->rates[0]->referenceDate->toDateString())->toBe('2024-01-02')
        ->and($result->rates[0]->value)->toBe('11.65')
        ->and($result->rates[1]->value)->toBe('11.70')
        ->and($result->rates[0]->seriesCode)->toBe(4389)
        ->and($result->blocksTotal)->toBe(1)
        ->and($result->hasBlockFailures())->toBeFalse();
});

it('returns an empty rates list when the API returns no data', function () {
    Http::fake(['api.bcb.gov.br/*' => Http::response([], 200)]);

    $result = app(BcbSgsClient::class)->fetchSeries(433, CarbonImmutable::parse('2024-01-01'), CarbonImmutable::parse('2024-01-31'));

    expect($result->rates)->toBe([])
        ->and($result->blocksFailed())->toBe(0);
});

it('throws on HTTP error from the Banco Central when every block fails', function () {
    Http::fake(['api.bcb.gov.br/*' => Http::response('error', 500)]);

    expect(fn () => app(BcbSgsClient::class)->fetchSeries(4389, CarbonImmutable::parse('2024-01-01'), CarbonImmutable::parse('2024-01-31')))
        ->toThrow(BcbSgsException::class);
});

it('throws on connection timeout when every block fails', function () {
    Http::fake(fn () => throw new ConnectionException('Connection timed out'));

    expect(fn () => app(BcbSgsClient::class)->fetchSeries(4389, CarbonImmutable::parse('2024-01-01'), CarbonImmutable::parse('2024-01-31')))
        ->toThrow(BcbSgsException::class);
});

it('skips malformed items without keys', function () {
    Http::fake(['api.bcb.gov.br/*' => Http::response([
        ['data' => '02/01/2024', 'valor' => '11.65'],
        ['foo' => 'bar'],
        ['data' => 'invalid', 'valor' => 'x'],
    ], 200)]);

    $result = app(BcbSgsClient::class)->fetchSeries(4389, CarbonImmutable::parse('2024-01-01'), CarbonImmutable::parse('2024-01-31'));

    expect($result->rates)->toHaveCount(1)
        ->and($result->rates[0]->value)->toBe('11.65');
});

it('splits a long window into yearly blocks and consolidates the results', function () {
    config(['pu_indexes.bcb.chunk_months' => 12]);

    Http::fakeSequence('api.bcb.gov.br/*')
        ->push([['data' => '03/01/2022', 'valor' => '9.15']])
        ->push([['data' => '02/01/2023', 'valor' => '13.65']])
        ->push([['data' => '02/01/2024', 'valor' => '11.65']]);

    $result = app(BcbSgsClient::class)->fetchSeries(
        4389,
        CarbonImmutable::parse('2022-01-01'),
        CarbonImmutable::parse('2024-01-31'),
    );

    expect($result->blocksTotal)->toBe(3)
        ->and($result->blocksSucceeded())->toBe(3)
        ->and($result->rates)->toHaveCount(3);

    Http::assertSentCount(3);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'dataInicial=01%2F01%2F2022'));
    Http::assertSent(fn ($request) => str_contains($request->url(), 'dataInicial=01%2F01%2F2024'));
});

it('deduplicates points returned in more than one block by date', function () {
    config(['pu_indexes.bcb.chunk_months' => 12]);

    Http::fakeSequence('api.bcb.gov.br/*')
        ->push([['data' => '31/12/2022', 'valor' => '13.00']])
        ->push([['data' => '31/12/2022', 'valor' => '99.99'], ['data' => '02/01/2023', 'valor' => '13.65']]);

    $result = app(BcbSgsClient::class)->fetchSeries(
        4389,
        CarbonImmutable::parse('2022-06-01'),
        CarbonImmutable::parse('2023-06-30'),
    );

    $dates = array_map(fn ($rate) => $rate->referenceDate->toDateString(), $result->rates);

    expect($result->rates)->toHaveCount(2)
        ->and($dates)->toContain('2022-12-31', '2023-01-02');
});

it('records a partial failure when one block fails but others succeed', function () {
    config(['pu_indexes.bcb.chunk_months' => 12]);

    Http::fakeSequence('api.bcb.gov.br/*')
        ->push('error', 500)
        ->push([['data' => '02/01/2023', 'valor' => '13.65']])
        ->push([['data' => '02/01/2024', 'valor' => '11.65']]);

    $result = app(BcbSgsClient::class)->fetchSeries(
        4389,
        CarbonImmutable::parse('2022-01-01'),
        CarbonImmutable::parse('2024-01-31'),
    );

    expect($result->blocksTotal)->toBe(3)
        ->and($result->blocksFailed())->toBe(1)
        ->and($result->hasBlockFailures())->toBeTrue()
        ->and($result->rates)->toHaveCount(2)
        ->and($result->blockFailures[0]->from->toDateString())->toBe('2022-01-01');
});

it('retries a failing block with backoff before succeeding', function () {
    config(['pu_indexes.bcb.retries' => 3]);

    Http::fakeSequence('api.bcb.gov.br/*')
        ->push('flaky', 500)
        ->push([['data' => '02/01/2024', 'valor' => '11.65']]);

    $result = app(BcbSgsClient::class)->fetchSeries(
        4389,
        CarbonImmutable::parse('2024-01-01'),
        CarbonImmutable::parse('2024-01-31'),
    );

    expect($result->rates)->toHaveCount(1)
        ->and($result->hasBlockFailures())->toBeFalse();

    Http::assertSentCount(2);
});

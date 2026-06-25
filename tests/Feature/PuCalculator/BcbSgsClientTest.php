<?php

use App\Domain\PuCalculator\Exceptions\BcbSgsException;
use App\Domain\PuCalculator\Services\BcbSgsClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

it('parses a SGS JSON response into DTOs with decimal-string values', function () {
    Http::fake(['api.bcb.gov.br/*' => Http::response([
        ['data' => '02/01/2024', 'valor' => '11.65'],
        ['data' => '03/01/2024', 'valor' => '11,70'],
    ], 200)]);

    $rates = app(BcbSgsClient::class)->fetchSeries(4389, CarbonImmutable::parse('2024-01-01'), CarbonImmutable::parse('2024-01-31'));

    expect($rates)->toHaveCount(2)
        ->and($rates[0]->referenceDate->toDateString())->toBe('2024-01-02')
        ->and($rates[0]->value)->toBe('11.65')
        ->and($rates[1]->value)->toBe('11.70')
        ->and($rates[0]->seriesCode)->toBe(4389);
});

it('returns an empty array when the API returns no data', function () {
    Http::fake(['api.bcb.gov.br/*' => Http::response([], 200)]);

    expect(app(BcbSgsClient::class)->fetchSeries(433, CarbonImmutable::parse('2024-01-01'), CarbonImmutable::parse('2024-01-31')))
        ->toBe([]);
});

it('throws on HTTP error from the Banco Central', function () {
    Http::fake(['api.bcb.gov.br/*' => Http::response('error', 500)]);

    expect(fn () => app(BcbSgsClient::class)->fetchSeries(4389, CarbonImmutable::parse('2024-01-01'), CarbonImmutable::parse('2024-01-31')))
        ->toThrow(BcbSgsException::class);
});

it('throws on connection timeout', function () {
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

    $rates = app(BcbSgsClient::class)->fetchSeries(4389, CarbonImmutable::parse('2024-01-01'), CarbonImmutable::parse('2024-01-31'));

    expect($rates)->toHaveCount(1)
        ->and($rates[0]->value)->toBe('11.65');
});

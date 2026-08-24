<?php

use App\Actions\Expenses\LookupExpenseServiceProviderCnpj;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    Http::preventStrayRequests();
});

it('reports the rate limit instead of a not-found error', function () {
    Http::fake([
        'https://publica.cnpj.ws/cnpj/*' => Http::response([
            'status' => 429,
            'titulo' => 'Muitas requisições',
            'detalhes' => 'Excedido o limite máximo de 3 consultas por minuto.',
        ], 429),
    ]);

    $result = app(LookupExpenseServiceProviderCnpj::class)->handle('19.131.243/0001-97');

    expect($result['status'])->toBe(429)
        ->and($result['payload']['error'])->toContain('Limite de consultas públicas de CNPJ excedido')
        ->and($result['payload']['error'])->not->toContain('Não foi possível localizar dados');
});

it('still reports a genuinely unknown cnpj as not found', function () {
    Http::fake([
        'https://publica.cnpj.ws/cnpj/*' => Http::response(status: 404),
    ]);

    $result = app(LookupExpenseServiceProviderCnpj::class)->handle('19.131.243/0001-97');

    expect($result['status'])->toBe(422)
        ->and($result['payload']['error'])->toBe('Não foi possível localizar dados para este CNPJ.');
});

it('queries the registry only once per cnpj', function () {
    Http::fake([
        'https://publica.cnpj.ws/cnpj/*' => Http::response([
            'razao_social' => 'Open Knowledge Brasil',
            'estabelecimento' => ['nome_fantasia' => 'OKBR'],
        ]),
    ]);

    $action = app(LookupExpenseServiceProviderCnpj::class);

    $first = $action->handle('19131243000197');
    $second = $action->handle('19.131.243/0001-97');

    expect($first)->toBe($second)
        ->and($first['payload']['data']['name'])->toBe('OKBR')
        ->and($first['payload']['data']['official_name'])->toBe('Open Knowledge Brasil');

    Http::assertSentCount(1);
});

it('does not cache failed lookups', function () {
    Http::fake([
        'https://publica.cnpj.ws/cnpj/*' => Http::sequence()
            ->push(status: 429)
            ->push(['razao_social' => 'Open Knowledge Brasil', 'estabelecimento' => ['nome_fantasia' => 'OKBR']]),
    ]);

    $action = app(LookupExpenseServiceProviderCnpj::class);

    expect($action->handle('19131243000197')['status'])->toBe(429)
        ->and($action->handle('19131243000197')['status'])->toBe(200);

    Http::assertSentCount(2);
});

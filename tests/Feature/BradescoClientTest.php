<?php

use App\Exceptions\Bradesco\BradescoAuthenticationException;
use App\Exceptions\Bradesco\BradescoBusinessDataException;
use App\Exceptions\Bradesco\BradescoConfigurationException;
use App\Exceptions\Bradesco\BradescoIncompleteResponseException;
use App\Exceptions\Bradesco\BradescoNetworkException;
use App\Exceptions\Bradesco\BradescoValidationException;
use App\Models\BankAccount;
use App\Services\Bradesco\BradescoClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->account = BankAccount::factory()->create([
        'agency_number' => '0123',
        'agency_digit' => '4',
        'account_number' => '0012345',
        'account_digit' => '6',
    ]);

    config([
        'bradesco.enabled' => true,
        'bradesco.live_enabled' => true,
        'bradesco.environment' => 'sandbox',
        'bradesco.auth.scheme' => 'test-scheme',
        'bradesco.timeouts.connect_seconds' => 5,
        'bradesco.timeouts.request_seconds' => 30,
    ]);

    Http::preventStrayRequests();
});

function bradescoClient(): BradescoClient
{
    return app(BradescoClient::class);
}

function liveOptions(array $overrides = []): array
{
    return array_merge(['live' => true, 'source_note' => 'test authorization'], $overrides);
}

function saldoPayloadFor(string $numeroConta = '0012345', string $digitoConta = '6'): array
{
    return [
        'saldoCC' => [
            'codigoRetorno' => '00',
            'mensagem' => 'OK',
            'numeroConta' => $numeroConta,
            'digitoConta' => $digitoConta,
            'quantidadeLancamentos' => '1',
            'lstLancamentosSaldos' => [
                [
                    'nomeProduto' => 'DISPONIVEL:',
                    'nomeProdutoResumido' => 'DISPONIVEL:',
                    'codigoProduto' => 999,
                    'identificadorSaldo' => '0',
                    'valorLancamento' => '10,00',
                    'sinalSaldo' => '+',
                ],
            ],
        ],
        'saldoAplicacaoCC' => [
            'codigoRetorno' => '00',
            'mensagem' => 'OK',
            'quantidadeLancamentos' => '1',
            'lstLancamentosSaldos' => [
                [
                    'nomeProduto' => 'INVEST FACIL BRADESCO',
                    'nomeProdutoResumido' => 'INVEST FACIL BR',
                    'codigoProduto' => 19,
                    'identificadorSaldo' => '1',
                    'valorLancamento' => '250,00',
                    'sinalSaldo' => '+',
                ],
            ],
        ],
    ];
}

it('refuses live calls while any gate is closed', function () {
    config(['bradesco.enabled' => false]);
    expect(fn () => bradescoClient()->fetchBalances(test()->account, liveOptions()))
        ->toThrow(BradescoConfigurationException::class, 'desabilitada');

    config(['bradesco.enabled' => true, 'bradesco.live_enabled' => false]);
    expect(fn () => bradescoClient()->fetchBalances(test()->account, liveOptions()))
        ->toThrow(BradescoConfigurationException::class, 'desabilitadas');

    config(['bradesco.live_enabled' => true, 'bradesco.auth.scheme' => null]);
    expect(fn () => bradescoClient()->fetchBalances(test()->account, liveOptions()))
        ->toThrow(BradescoConfigurationException::class, 'autenticação');

    config(['bradesco.auth.scheme' => 'test-scheme']);
    expect(fn () => bradescoClient()->fetchBalances(test()->account, ['live' => true]))
        ->toThrow(BradescoConfigurationException::class, 'confirmação');

    Http::assertNothingSent();
});

it('sends canonical identity with leading zeros and no check digits', function () {
    Http::fake([
        'openapisandbox.prebanco.com.br/*' => Http::response(saldoPayloadFor(), 200),
    ]);

    bradescoClient()->fetchBalances(test()->account, liveOptions(['tipo_operacao' => 2]));

    Http::assertSent(function ($request) {
        $query = $request->data();

        return parse_url($request->url(), PHP_URL_PATH) === '/v1/fornecimento-saldos-contas/saldos'
            && ($query['agencia'] ?? null) === '0123'
            && ($query['conta'] ?? null) === '0012345'
            && (string) ($query['tipoOperacao'] ?? '') === '2'
            && $request->method() === 'GET';
    });
});

it('maps HTTP failures to distinct error classes without retrying', function (int $status, string $exception) {
    Http::fake(['openapisandbox.prebanco.com.br/*' => Http::response(['erro' => true], $status)]);

    expect(fn () => bradescoClient()->fetchBalances(test()->account, liveOptions()))
        ->toThrow($exception);

    Http::assertSentCount(1);
})->with([
    'unauthorized is authentication' => [401, BradescoAuthenticationException::class],
    'forbidden is authentication' => [403, BradescoAuthenticationException::class],
    'bad request is validation' => [400, BradescoValidationException::class],
    'not found is validation' => [404, BradescoValidationException::class],
    'wrong method is validation' => [405, BradescoValidationException::class],
    'server error is network' => [500, BradescoNetworkException::class],
    'unavailable is network' => [503, BradescoNetworkException::class],
]);

it('reads refused identities inside HTTP 400 bodies', function () {
    Http::fake(['openapisandbox.prebanco.com.br/*' => Http::response([
        'saldoAplicacaoCC' => [
            'codigoRetorno' => '04',
            'mensagem' => 'SEM AUTORIZACAO',
        ],
    ], 400)]);

    expect(fn () => bradescoClient()->fetchBalances(test()->account, liveOptions()))
        ->toThrow(BradescoAuthenticationException::class, 'autorização');
});

it('reads business failures inside HTTP 400 bodies', function () {
    Http::fake(['openapisandbox.prebanco.com.br/*' => Http::response([
        'saldoCC' => [
            'codigoRetorno' => '08',
            'mensagem' => 'CONTA INVALIDA',
        ],
    ], 400)]);

    expect(fn () => bradescoClient()->fetchBalances(test()->account, liveOptions()))
        ->toThrow(BradescoBusinessDataException::class, 'negócio');
});

it('keeps unexplained HTTP 400 bodies as validation errors', function () {
    Http::fake(['openapisandbox.prebanco.com.br/*' => Http::response('not json', 400)]);

    expect(fn () => bradescoClient()->fetchBalances(test()->account, liveOptions()))
        ->toThrow(BradescoValidationException::class, 'rejeitou');
});

it('surfaces connection failures and timeouts as network errors', function () {
    Http::fake(['openapisandbox.prebanco.com.br/*' => Http::failedConnection()]);

    expect(fn () => bradescoClient()->fetchBalances(test()->account, liveOptions()))
        ->toThrow(BradescoNetworkException::class, 'Falha de comunicação');

    Http::assertSentCount(1);
});

it('rejects non-JSON and off-contract payloads', function () {
    Http::fake(['openapisandbox.prebanco.com.br/*' => Http::response('not json', 200)]);

    expect(fn () => bradescoClient()->fetchBalances(test()->account, liveOptions()))
        ->toThrow(BradescoBusinessDataException::class, 'fora do contrato');
});

it('refuses partial live responses instead of using them', function () {
    $payload = saldoPayloadFor();
    $payload['saldoAplicacaoCC']['codigoRetorno'] = '01';

    Http::fake(['openapisandbox.prebanco.com.br/*' => Http::response($payload, 200)]);

    expect(fn () => bradescoClient()->fetchBalances(test()->account, liveOptions()))
        ->toThrow(BradescoIncompleteResponseException::class, 'parcial');
});

it('rejects live answers echoed for another account', function () {
    Http::fake(['openapisandbox.prebanco.com.br/*' => Http::response(saldoPayloadFor('99999', '6'), 200)]);

    expect(fn () => bradescoClient()->fetchBalances(test()->account, liveOptions()))
        ->toThrow(BradescoBusinessDataException::class, 'outra conta');
});

it('validates statement scope before any HTTP traffic', function () {
    expect(fn () => bradescoClient()->fetchStatement(test()->account, liveOptions([
        'tipo' => 'xx',
        'data_inicio' => '01012025',
        'data_fim' => '31122025',
    ])))->toThrow(BradescoValidationException::class, 'somente contas correntes');

    expect(fn () => bradescoClient()->fetchStatement(test()->account, liveOptions([
        'tipo' => 'cc',
        'data_inicio' => '2025-01-01',
        'data_fim' => '31122025',
    ])))->toThrow(BradescoValidationException::class, 'ddmmaaaa');

    Http::assertNothingSent();
});

it('fetches statements with the documented params', function () {
    Http::fake([
        'openapisandbox.prebanco.com.br/*' => Http::response([
            'extratoPorPeriodo' => [
                'codigoRetorno' => '00',
                'mensagem' => 'OK',
                'numeroConta' => '0012345',
                'digitoConta' => '6',
                'quantidadeLancamentos' => '0',
                'lstLancamentoMensal' => [],
            ],
        ], 200),
    ]);

    $report = bradescoClient()->fetchStatement(test()->account, liveOptions([
        'tipo' => 'cc',
        'data_inicio' => '01012025',
        'data_fim' => '31122025',
    ]));

    expect($report->complete)->toBeTrue()
        ->and($report->totalMovements)->toBe(0);

    Http::assertSent(function ($request) {
        $query = $request->data();

        return parse_url($request->url(), PHP_URL_PATH) === '/v1/fornecimento-extratos-contas/extratos'
            && ($query['dataInicio'] ?? null) === '01012025'
            && ($query['dataFim'] ?? null) === '31122025'
            && ($query['tipo'] ?? null) === 'cc';
    });
});

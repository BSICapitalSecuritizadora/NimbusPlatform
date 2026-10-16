<?php

namespace App\Services\Bradesco;

use App\DTOs\Bradesco\BradescoBalanceReport;
use App\DTOs\Bradesco\BradescoStatementReport;
use App\Exceptions\Bradesco\BradescoAuthenticationException;
use App\Exceptions\Bradesco\BradescoBusinessDataException;
use App\Exceptions\Bradesco\BradescoConfigurationException;
use App\Exceptions\Bradesco\BradescoIncompleteResponseException;
use App\Exceptions\Bradesco\BradescoNetworkException;
use App\Exceptions\Bradesco\BradescoValidationException;
use App\Models\BankAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Read-only Bradesco API client (manual v2.2.2).
 *
 * GET-only, explicit timeouts, no retries: a failed or partial read
 * surfaces as a typed error instead of hiding behind another attempt.
 * Error classes stay distinct — configuration, authentication,
 * network, validation, business data — so callers react to the cause,
 * not to a generic failure.
 *
 * Live traffic is fenced by four independent gates: the integration
 * switch, the live switch, a documented authentication scheme (still
 * UNRESOLVED in the manual, so live is blocked), and an explicit
 * operator confirmation with an authorization note. Fixture-based
 * diagnostics never touch this client. Tests drive it through
 * `Http::fake()` with the switches overridden in config.
 *
 * Nothing here logs URLs, query strings, tokens or payloads.
 */
final class BradescoClient
{
    public function __construct(
        private readonly BradescoAccountIdentity $identity,
        private readonly BradescoBalanceInterpreter $interpreter,
        private readonly BradescoStatementInspector $inspector,
    ) {}

    /**
     * @param  array{live?: bool, source_note?: string, tipo_operacao?: ?int}  $options
     */
    public function fetchBalances(BankAccount $bankAccount, array $options = []): BradescoBalanceReport
    {
        $this->ensureLiveAllowed($options);

        $tipoOperacao = $this->resolveTipoOperacao($options['tipo_operacao'] ?? null);
        $params = $this->identity->requestParams($bankAccount);

        $query = ['agencia' => $params['agencia'], 'conta' => $params['conta']];

        if ($tipoOperacao !== null) {
            $query['tipoOperacao'] = $tipoOperacao;
        }

        $requestedAt = $this->nowIso();
        $payload = $this->get((string) config('bradesco.endpoints.balances'), $query);
        $capturedAt = $this->nowIso();

        $report = $this->interpreter->interpret($payload, $bankAccount, $tipoOperacao, $requestedAt, $capturedAt);

        $this->refuseIncomplete($report->complete, $report->completenessIssues);

        return $report;
    }

    /**
     * @param  array{live?: bool, source_note?: string, tipo?: string, data_inicio?: string, data_fim?: string, tipo_operacao?: ?int}  $options
     */
    public function fetchStatement(BankAccount $bankAccount, array $options = []): BradescoStatementReport
    {
        $this->ensureLiveAllowed($options);

        $tipo = (string) ($options['tipo'] ?? '');
        $this->identity->assertSupportedStatementType($tipo);

        $dataInicio = $this->statementDate($options['data_inicio'] ?? null, 'data_inicio');
        $dataFim = $this->statementDate($options['data_fim'] ?? null, 'data_fim');
        $tipoOperacao = $this->resolveTipoOperacao($options['tipo_operacao'] ?? null);
        $params = $this->identity->requestParams($bankAccount);

        $query = [
            'agencia' => $params['agencia'],
            'conta' => $params['conta'],
            'dataInicio' => $dataInicio,
            'dataFim' => $dataFim,
            'tipo' => strtolower($tipo),
        ];

        if ($tipoOperacao !== null) {
            $query['tipoOperacao'] = $tipoOperacao;
        }

        $requestedAt = $this->nowIso();
        $payload = $this->get((string) config('bradesco.endpoints.statements'), $query);
        $capturedAt = $this->nowIso();

        $report = $this->inspector->inspect($payload, $bankAccount, $requestedAt, $capturedAt);

        $this->refuseIncomplete($report->complete, $report->completenessIssues);

        return $report;
    }

    public function baseUrl(): string
    {
        $environment = (string) config('bradesco.environment', 'sandbox');
        $urls = config('bradesco.base_urls', []);

        return (string) ($urls[$environment] ?? $urls['sandbox'] ?? '');
    }

    /**
     * @param  array{live?: bool, source_note?: string}  $options
     */
    private function ensureLiveAllowed(array $options): void
    {
        if (config('bradesco.enabled') !== true) {
            throw BradescoConfigurationException::integrationDisabled();
        }

        if (config('bradesco.live_enabled') !== true) {
            throw BradescoConfigurationException::liveDisabled();
        }

        if (! is_string(config('bradesco.auth.scheme')) || trim((string) config('bradesco.auth.scheme')) === '') {
            throw BradescoConfigurationException::authUnresolved();
        }

        $confirmed = ($options['live'] ?? false) === true;
        $note = trim((string) ($options['source_note'] ?? ''));

        if (! $confirmed || $note === '') {
            throw BradescoConfigurationException::missingConfirmation();
        }
    }

    private function resolveTipoOperacao(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            $configured = config('bradesco.default_tipo_operacao');

            if ($configured === null || $configured === '') {
                return null;
            }

            $value = $configured;
        }

        if ($value === 1 || $value === '1') {
            return 1;
        }

        if ($value === 2 || $value === '2') {
            return 2;
        }

        throw BradescoValidationException::invalidIdentity('tipoOperacao fora do documentado (1 ou 2)');
    }

    private function statementDate(mixed $value, string $field): string
    {
        if (! is_string($value) || preg_match('/^\d{8}$/', $value) !== 1) {
            throw BradescoValidationException::invalidIdentity("{$field} fora do formato documentado (ddmmaaaa)");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(string $endpoint, array $query): array
    {
        $url = rtrim($this->baseUrl(), '/').$endpoint;
        $connect = (int) config('bradesco.timeouts.connect_seconds', 5);
        $timeout = (int) config('bradesco.timeouts.request_seconds', 30);

        try {
            $response = Http::connectTimeout($connect)
                ->timeout($timeout)
                ->acceptJson()
                ->get($url, $query);
        } catch (ConnectionException) {
            throw BradescoNetworkException::connection('tempo esgotado ou host inalcançável');
        }

        $this->assertHttpOk($response, $endpoint);

        $payload = $response->json();

        if (! is_array($payload)) {
            throw BradescoBusinessDataException::invalidPayload('corpo fora do formato JSON objeto');
        }

        return $payload;
    }

    private function assertHttpOk(Response $response, string $endpoint): void
    {
        $status = $response->status();

        if ($status >= 200 && $status < 300) {
            return;
        }

        if ($status === 401 || $status === 403) {
            throw BradescoAuthenticationException::http($status);
        }

        if ($status === 400) {
            $this->throwForBadRequest($response);
        }

        if (in_array($status, [404, 405], true)) {
            throw BradescoValidationException::http($status);
        }

        if ($status === 500 || $status === 503) {
            throw BradescoNetworkException::serverError($status);
        }

        throw BradescoBusinessDataException::withContext(
            "Resposta do Bradesco fora do contrato documentado: HTTP {$status}.",
            ['endpoint' => $endpoint]
        );
    }

    /**
     * Map an HTTP 400 through the manual's codigoRetorno table when the
     * bank explains itself in the body (04 = refused identity, 08 =
     * business error, both documented over HTTP 400); anything
     * unexplained keeps today's validation reading.
     */
    private function throwForBadRequest(Response $response): never
    {
        $payload = $response->json();

        if (is_array($payload)) {
            /** @var list<array{string, string}> $codes */
            $codes = [];

            foreach (['saldoCC', 'saldoCP', 'saldoAplicacaoCC', 'extratoUltimosLancamentos', 'extratoLancamentosFuturos', 'extratoPorPeriodo'] as $section) {
                $meta = $payload[$section] ?? null;

                if (is_array($meta) && array_key_exists('codigoRetorno', $meta)) {
                    $codes[] = [trim((string) $meta['codigoRetorno']), trim((string) ($meta['mensagem'] ?? ''))];
                }
            }

            foreach ($codes as [$code, $message]) {
                if ($code === '04') {
                    throw BradescoAuthenticationException::backend($message);
                }
            }

            foreach ($codes as [$code, $message]) {
                if ($code === '08') {
                    throw BradescoBusinessDataException::backend($message);
                }
            }
        }

        throw BradescoValidationException::http(400);
    }

    /**
     * @param  list<string>  $issues
     */
    private function refuseIncomplete(bool $complete, array $issues): void
    {
        if ($complete) {
            return;
        }

        throw BradescoIncompleteResponseException::withContext(
            'Resposta parcial ou inconsistente do Bradesco; nada foi aproveitado. Consulte o diagnóstico com fixture para ver a evidência classificada.',
            ['issues' => $issues]
        );
    }

    private function nowIso(): string
    {
        return Carbon::now('UTC')->toIso8601String();
    }
}

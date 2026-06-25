<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\BcbSgsRateData;
use App\Domain\PuCalculator\Exceptions\BcbSgsException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Cliente HTTP da API pública SGS do Banco Central.
 *
 * Endpoint: {base}/bcdata.sgs.{codigo}/dados?formato=json&dataInicial=dd/MM/aaaa&dataFinal=dd/MM/aaaa
 * Resposta: lista de {"data":"dd/MM/aaaa","valor":"0.67"}.
 *
 * Mantém o valor como STRING (sem float). Trata timeout, erro HTTP e resposta vazia/ inválida lançando
 * {@see BcbSgsException} — nunca é chamado dentro do cálculo da curva.
 */
class BcbSgsClient
{
    /**
     * @return list<BcbSgsRateData>
     */
    public function fetchSeries(int $seriesCode, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $config = (array) config('pu_indexes.bcb');

        try {
            $response = Http::baseUrl((string) ($config['base_url'] ?? 'https://api.bcb.gov.br/dados/serie'))
                ->timeout((int) ($config['timeout'] ?? 15))
                ->retry((int) ($config['retries'] ?? 2), (int) ($config['retry_sleep_ms'] ?? 500), throw: false)
                ->acceptJson()
                ->get(sprintf('/bcdata.sgs.%d/dados', $seriesCode), [
                    'formato' => 'json',
                    'dataInicial' => $from->format('d/m/Y'),
                    'dataFinal' => $to->format('d/m/Y'),
                ]);
        } catch (ConnectionException $exception) {
            throw new BcbSgsException(
                sprintf('Falha de conexão/timeout ao consultar a série %d no Banco Central: %s', $seriesCode, $exception->getMessage()),
                previous: $exception,
            );
        }

        if ($response->failed()) {
            throw new BcbSgsException(sprintf(
                'A API do Banco Central retornou erro HTTP %d ao consultar a série %d.',
                $response->status(),
                $seriesCode,
            ));
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new BcbSgsException(sprintf('Resposta inválida (não-JSON) da série %d do Banco Central.', $seriesCode));
        }

        $rates = [];

        foreach ($payload as $item) {
            if (! is_array($item) || ! isset($item['data'], $item['valor'])) {
                continue;
            }

            $date = $this->parseDate((string) $item['data']);
            $value = $this->normalizeValue((string) $item['valor']);

            if ($date === null || $value === null) {
                continue;
            }

            $rates[] = new BcbSgsRateData(referenceDate: $date, value: $value, seriesCode: $seriesCode);
        }

        return $rates;
    }

    private function parseDate(string $value): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::createFromFormat('d/m/Y', trim($value))?->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    private function normalizeValue(string $value): ?string
    {
        $normalized = str_replace([' ', ','], ['', '.'], trim($value));

        if ($normalized === '' || ! is_numeric($normalized)) {
            return null;
        }

        return $normalized;
    }
}

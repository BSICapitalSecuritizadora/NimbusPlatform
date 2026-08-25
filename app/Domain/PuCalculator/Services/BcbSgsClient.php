<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\BcbSgsBlockFailure;
use App\Domain\PuCalculator\DTOs\BcbSgsFetchResult;
use App\Domain\PuCalculator\DTOs\BcbSgsRateData;
use App\Domain\PuCalculator\DTOs\BcbSgsRawPayload;
use App\Domain\PuCalculator\Exceptions\BcbSgsException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cliente HTTP da API pública SGS do Banco Central.
 *
 * Endpoint: {base}/bcdata.sgs.{codigo}/dados?formato=json&dataInicial=dd/MM/aaaa&dataFinal=dd/MM/aaaa
 * Resposta: lista de {"data":"dd/MM/aaaa","valor":"0.67"}.
 *
 * A janela total é dividida em BLOCOS contíguos (config `chunk_months`) consultados em sequência —
 * séries diárias de 10 anos numa única chamada estouram o timeout do SGS. Cada bloco tem retry com
 * backoff exponencial; uma falha de bloco é registrada (sem derrubar os demais) e só vira
 * {@see BcbSgsException} quando TODOS os blocos falham. Os pontos são deduplicados por data.
 *
 * Mantém o valor como STRING (sem float). Nunca é chamado dentro do cálculo da curva.
 */
class BcbSgsClient
{
    public function fetchSeries(int $seriesCode, CarbonImmutable $from, CarbonImmutable $to): BcbSgsFetchResult
    {
        $config = (array) config('pu_indexes.bcb');
        $chunkMonths = max(1, (int) ($config['chunk_months'] ?? 12));
        $blocks = $this->buildBlocks($from, $to, $chunkMonths);

        /** @var list<BcbSgsRateData> $rates */
        $rates = [];
        /** @var list<BcbSgsBlockFailure> $failures */
        $failures = [];
        /** @var list<BcbSgsRawPayload> $rawPayloads */
        $rawPayloads = [];
        /** @var list<array<string, mixed>> $invalidEntries */
        $invalidEntries = [];
        /** @var list<array<string, mixed>> $duplicateDates */
        $duplicateDates = [];
        $seenDates = [];

        foreach ($blocks as [$blockFrom, $blockTo]) {
            try {
                $block = $this->fetchBlock($seriesCode, $blockFrom, $blockTo, $config);
            } catch (BcbSgsException $exception) {
                $failures[] = new BcbSgsBlockFailure($blockFrom, $blockTo, $exception->getMessage());

                Log::warning('Falha ao consultar bloco da série SGS do Banco Central.', [
                    'series_code' => $seriesCode,
                    'from' => $blockFrom->toDateString(),
                    'to' => $blockTo->toDateString(),
                    'error' => $exception->getMessage(),
                ]);

                continue;
            }

            $rawPayloads[] = $block['payload'];
            $invalidEntries = [...$invalidEntries, ...$block['invalid_entries']];

            foreach ($block['rates'] as $rate) {
                $key = $rate->referenceDate->toDateString();

                if (isset($seenDates[$key])) {
                    $duplicateDates[] = [
                        'issue' => 'duplicate_date',
                        'reference_date' => $key,
                        'kept_value' => $seenDates[$key],
                        'discarded_value' => $rate->rawValue ?? $rate->value,
                    ];

                    continue;
                }

                $seenDates[$key] = $rate->rawValue ?? $rate->value;
                $rates[] = $rate;
            }
        }

        // Falha total: todos os blocos falharam → erro de conexão/API real (não derruba parcialmente).
        if ($failures !== [] && count($failures) === count($blocks)) {
            throw new BcbSgsException(sprintf(
                'Falha em todos os %d bloco(s) ao consultar a série %d no Banco Central. Último erro: %s',
                count($blocks),
                $seriesCode,
                $failures[count($failures) - 1]->message,
            ));
        }

        return new BcbSgsFetchResult(
            rates: $rates,
            blockFailures: $failures,
            blocksTotal: count($blocks),
            rawPayloads: $rawPayloads,
            invalidEntries: $invalidEntries,
            duplicateDates: $duplicateDates,
        );
    }

    /**
     * Divide [from, to] em blocos contíguos e NÃO sobrepostos de até `chunkMonths` meses.
     *
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function buildBlocks(CarbonImmutable $from, CarbonImmutable $to, int $chunkMonths): array
    {
        if ($from->greaterThan($to)) {
            return [];
        }

        $blocks = [];
        $cursor = $from;

        while ($cursor->lessThanOrEqualTo($to)) {
            $blockEnd = $cursor->addMonths($chunkMonths)->subDay();

            if ($blockEnd->greaterThan($to)) {
                $blockEnd = $to;
            }

            $blocks[] = [$cursor, $blockEnd];
            $cursor = $blockEnd->addDay();
        }

        return $blocks;
    }

    /**
     * Consulta um único bloco com retry/backoff exponencial. Lança {@see BcbSgsException} ao falhar.
     *
     * @param  array<string, mixed>  $config
     * @return array{rates:list<BcbSgsRateData>,payload:BcbSgsRawPayload,invalid_entries:list<array<string, mixed>>}
     */
    private function fetchBlock(int $seriesCode, CarbonImmutable $from, CarbonImmutable $to, array $config): array
    {
        $baseSleepMs = max(0, (int) ($config['retry_sleep_ms'] ?? 500));

        try {
            $response = Http::baseUrl((string) ($config['base_url'] ?? 'https://api.bcb.gov.br/dados/serie'))
                ->connectTimeout((int) ($config['connect_timeout'] ?? 10))
                ->timeout((int) ($config['timeout'] ?? 30))
                ->retry(
                    max(1, (int) ($config['retries'] ?? 3)),
                    fn (int $attempt): int => $baseSleepMs * (2 ** ($attempt - 1)),
                    throw: false,
                )
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
        $invalidEntries = [];

        foreach ($payload as $position => $item) {
            if (! is_array($item) || ! isset($item['data'], $item['valor'])) {
                $invalidEntries[] = [
                    'issue' => 'invalid_entry_shape',
                    'position' => $position,
                    'entry' => $item,
                ];

                continue;
            }

            $date = $this->parseDate((string) $item['data']);
            $value = $this->normalizeValue((string) $item['valor']);

            if ($date === null || $value === null) {
                $invalidEntries[] = [
                    'issue' => $date === null ? 'invalid_reference_date' : 'invalid_decimal_format',
                    'position' => $position,
                    'entry' => $item,
                ];

                continue;
            }

            $rates[] = new BcbSgsRateData(
                referenceDate: $date,
                value: $value,
                seriesCode: $seriesCode,
                rawValue: (string) $item['valor'],
            );
        }

        $body = $response->body();

        return [
            'rates' => $rates,
            'payload' => new BcbSgsRawPayload(
                from: $from,
                to: $to,
                url: (string) $response->effectiveUri(),
                body: $body,
                sha256: hash('sha256', $body),
                capturedAt: CarbonImmutable::now(),
            ),
            'invalid_entries' => $invalidEntries,
        ];
    }

    private function parseDate(string $value): ?CarbonImmutable
    {
        try {
            $date = CarbonImmutable::createFromFormat('!d/m/Y', trim($value));

            return $date !== null && $date->format('d/m/Y') === trim($value)
                ? $date
                : null;
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

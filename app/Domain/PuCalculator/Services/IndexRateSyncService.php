<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\BcbSgsRateData;
use App\Domain\PuCalculator\DTOs\IndexRateSyncResult;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Models\IndexRate;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Sincroniza índices PUBLICADOS do Banco Central (SGS) para `index_rates`, de forma idempotente.
 *
 * Semântica de `rate_value` preservada:
 *  - CDI (série 4389): valor já é a TAXA ANUAL base 252 (% a.a.) → persistido direto.
 *  - IPCA (série 433): valor é a VARIAÇÃO MENSAL (%) → transformado em NÚMERO-ÍNDICE encadeando sobre o
 *    último NI persistido (âncora), em precisão decimal (bcmath). Nunca persiste variação como NI.
 *
 * Idempotência: a chave é (indexer, rate_date). Como o cast `date` persiste `Y-m-d H:i:s`, a busca usa
 * `whereDate`. Linhas de outra origem (manual/projetada) NÃO são sobrescritas — a sync só gerencia as
 * linhas que ela mesma criou (source = bcb_sgs).
 */
class IndexRateSyncService
{
    public const POLICY_SKIP = 'skip_existing';

    public const POLICY_UPDATE = 'update_if_changed';

    public const POLICY_OVERWRITE = 'overwrite';

    private const VALUE_SCALE = 8;

    public function __construct(
        private readonly BcbSgsClient $client,
        private readonly IndexRateLookupService $lookupService,
        private readonly PuAuditLogService $auditLogService,
    ) {}

    public function sync(
        PuIndexer $indexer,
        CarbonImmutable $from,
        CarbonImmutable $to,
        bool $dryRun = false,
        ?int $userId = null,
        ?string $overwritePolicy = null,
    ): IndexRateSyncResult {
        $config = $this->seriesConfig($indexer);
        $policy = $this->resolvePolicy($overwritePolicy);
        $fetchedAt = CarbonImmutable::now();

        $result = new IndexRateSyncResult(
            indexer: $indexer,
            source: (string) $config['source'],
            externalSeriesCode: (int) $config['code'],
            from: $from,
            to: $to,
            fetchedAt: $fetchedAt,
            dryRun: $dryRun,
        );

        $rates = $this->client->fetchSeries((int) $config['code'], $from, $to);
        $result->fetched = count($rates);

        if ($rates === []) {
            $result->addError(sprintf(
                'A série %d (%s) não retornou dados no período %s a %s.',
                (int) $config['code'],
                $indexer->value,
                $from->toDateString(),
                $to->toDateString(),
            ));

            return $result;
        }

        usort($rates, fn (BcbSgsRateData $a, BcbSgsRateData $b): int => $a->referenceDate <=> $b->referenceDate);

        match ((string) $config['value_type']) {
            'annual_rate' => $this->syncAnnualRate($rates, $config, $policy, $dryRun, $fetchedAt, $result),
            'monthly_variation' => $this->syncMonthlyVariation($rates, $config, $policy, $dryRun, $fetchedAt, $result),
            default => throw new InvalidArgumentException(sprintf('value_type desconhecido para %s.', $indexer->value)),
        };

        if (! $dryRun) {
            $this->lookupService->flushCache();
            $this->auditLogService->logIndexSync($result, $userId);
        }

        return $result;
    }

    /**
     * CDI: o valor já é taxa anual base 252; persiste por dia exato.
     *
     * @param  list<BcbSgsRateData>  $rates
     * @param  array<string, mixed>  $config
     */
    private function syncAnnualRate(array $rates, array $config, string $policy, bool $dryRun, CarbonImmutable $fetchedAt, IndexRateSyncResult $result): void
    {
        foreach ($rates as $rate) {
            $this->applyRow($result->indexer, $rate->referenceDate->startOfDay(), $rate->value, $config, $policy, $dryRun, $fetchedAt, $result);
        }
    }

    /**
     * IPCA: transforma variação mensal em número-índice encadeando sobre o último NI persistido (âncora).
     *
     * @param  list<BcbSgsRateData>  $rates
     * @param  array<string, mixed>  $config
     */
    private function syncMonthlyVariation(array $rates, array $config, string $policy, bool $dryRun, CarbonImmutable $fetchedAt, IndexRateSyncResult $result): void
    {
        $firstMonth = $rates[0]->referenceDate->startOfMonth();
        $anchor = $this->latestIpcaIndexBefore($firstMonth);

        if ($anchor === null) {
            $result->addError(sprintf(
                'Não há número-índice IPCA âncora anterior a %s. Importe/cadastre um NI base de IPCA antes de sincronizar por variação (o SGS publica variação mensal, não número-índice).',
                $firstMonth->toDateString(),
            ));

            return;
        }

        $runningNi = (string) $anchor;

        foreach ($rates as $rate) {
            $month = $rate->referenceDate->startOfMonth();
            $factor = bcadd('1', bcdiv($rate->value, '100', 16), 16);
            $derivedNi = $this->round8(bcmul($runningNi, $factor, 16));

            $runningNi = $this->applyRow($result->indexer, $month, $derivedNi, $config, $policy, $dryRun, $fetchedAt, $result);
        }
    }

    /**
     * Cria/atualiza uma linha respeitando idempotência e proteção a dados de outra origem.
     * Retorna o valor efetivo persistido naquela data (para encadeamento do IPCA).
     *
     * @param  array<string, mixed>  $config
     */
    private function applyRow(
        PuIndexer $indexer,
        CarbonImmutable $date,
        string $value,
        array $config,
        string $policy,
        bool $dryRun,
        CarbonImmutable $fetchedAt,
        IndexRateSyncResult $result,
    ): string {
        $existing = IndexRate::query()
            ->where('indexer', $indexer->value)
            ->whereDate('rate_date', $date->toDateString())
            ->first();

        if ($existing === null) {
            if (! $dryRun) {
                IndexRate::query()->create([
                    'indexer' => $indexer->value,
                    'rate_date' => $date->startOfDay(),
                    'rate_value' => $value,
                    'source' => (string) $config['source'],
                    'source_reference' => sprintf('%s:%d', $config['source'], $config['code']),
                    'external_series_code' => (string) $config['code'],
                    'fetched_at' => $fetchedAt,
                    'is_projected' => false,
                    'projection_source' => null,
                    'projection_reference_date' => null,
                    'projection_policy' => null,
                    'index_projection_series_id' => null,
                ]);
            }

            $result->created++;

            return $value;
        }

        // Não sobrescreve dado de outra origem (manual/publicado/projetado).
        if ((string) $existing->source !== (string) $config['source']) {
            $result->skipped++;

            return (string) $existing->rate_value;
        }

        $changed = bccomp((string) $existing->rate_value, $value, self::VALUE_SCALE) !== 0;

        if ($policy === self::POLICY_SKIP || ($policy === self::POLICY_UPDATE && ! $changed)) {
            $result->skipped++;

            return (string) $existing->rate_value;
        }

        if (! $dryRun) {
            $existing->update([
                'rate_value' => $value,
                'external_series_code' => (string) $config['code'],
                'fetched_at' => $fetchedAt,
            ]);
        }

        $result->updated++;

        return $value;
    }

    private function latestIpcaIndexBefore(CarbonImmutable $month): ?string
    {
        $anchor = IndexRate::query()
            ->where('indexer', PuIndexer::Ipca->value)
            ->whereDate('rate_date', '<', $month->toDateString())
            ->orderByDesc('rate_date')
            ->first();

        return $anchor !== null ? (string) $anchor->rate_value : null;
    }

    /**
     * @return array{code: int, value_type: string, source: string}
     */
    private function seriesConfig(PuIndexer $indexer): array
    {
        $key = match ($indexer) {
            PuIndexer::Cdi => 'cdi',
            PuIndexer::Ipca => 'ipca',
            default => throw new InvalidArgumentException(sprintf('A sincronização do Banco Central não suporta o indexador %s.', $indexer->value)),
        };

        $config = (array) config("pu_indexes.bcb.series.{$key}");

        return [
            'code' => (int) ($config['code'] ?? 0),
            'value_type' => (string) ($config['value_type'] ?? ''),
            'source' => (string) ($config['source'] ?? 'bcb_sgs'),
        ];
    }

    private function resolvePolicy(?string $overwritePolicy): string
    {
        $policy = $overwritePolicy ?? (string) config('pu_indexes.bcb.overwrite_policy', self::POLICY_UPDATE);

        return in_array($policy, [self::POLICY_SKIP, self::POLICY_UPDATE, self::POLICY_OVERWRITE], true)
            ? $policy
            : self::POLICY_UPDATE;
    }

    private function round8(string $value): string
    {
        $increment = str_starts_with($value, '-') ? '-0.000000005' : '0.000000005';

        return bcadd($value, $increment, self::VALUE_SCALE);
    }
}

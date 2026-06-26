<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\BcbSgsRateData;
use App\Domain\PuCalculator\DTOs\IndexRateSyncResult;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Models\IndexRate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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

        $fetch = $this->client->fetchSeries((int) $config['code'], $from, $to);
        $rates = $fetch->rates;
        $result->fetched = count($rates);
        $result->blocksTotal = $fetch->blocksTotal;

        foreach ($fetch->blockFailures as $blockFailure) {
            $result->addBlockFailure($blockFailure->describe());
        }

        if ($result->hasBlockFailures()) {
            $result->addError(sprintf(
                'Sincronização parcial: %d de %d bloco(s) falharam ao consultar o Banco Central (os demais foram processados).',
                $result->blocksFailed(),
                $result->blocksTotal,
            ));
        }

        if ($rates === []) {
            $result->addError($result->hasBlockFailures()
                ? sprintf('Nenhum dado pôde ser consultado na série %d (%s) — todos os blocos retornados falharam.', (int) $config['code'], $indexer->value)
                : sprintf(
                    'A API do Banco Central respondeu sem dados para a série %d (%s) no período %s a %s (nenhuma divulgação no intervalo).',
                    (int) $config['code'],
                    $indexer->value,
                    $from->toDateString(),
                    $to->toDateString(),
                ));

            $this->recordLastSyncStatus($result, $dryRun);

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

        $this->recordLastSyncStatus($result, $dryRun);

        Log::log(
            $result->hasErrors() ? 'warning' : 'info',
            sprintf(
                'Sincronização de índices %s (%s): período %s a %s | blocos %d/%d ok | retornados %d | inseridos %d | atualizados %d | ignorados %d%s',
                $result->indexer->value,
                $result->source,
                $result->from->toDateString(),
                $result->to->toDateString(),
                $result->blocksSucceeded(),
                $result->blocksTotal,
                $result->fetched,
                $result->created,
                $result->updated,
                $result->skipped,
                $result->dryRun ? ' | DRY-RUN' : '',
            ),
            $result->toArray(),
        );

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
     * A engine de curva IPCA usa apenas RAZÕES (NI_ref / NI_anterior), então a sincronização NUNCA
     * bloqueia por ausência de âncora: quando não há nenhum número-índice anterior, uma base arbitrária
     * (config `anchor_base`, default 100) é criada automaticamente no mês anterior à primeira competência
     * e o encadeamento prossegue. Isso não afeta a correção monetária (razões idênticas).
     *
     * @param  list<BcbSgsRateData>  $rates
     * @param  array<string, mixed>  $config
     */
    private function syncMonthlyVariation(array $rates, array $config, string $policy, bool $dryRun, CarbonImmutable $fetchedAt, IndexRateSyncResult $result): void
    {
        $firstMonth = $rates[0]->referenceDate->startOfMonth();
        $anchor = $this->latestIpcaIndexBefore($firstMonth);

        if ($anchor === null) {
            $baseMonth = $firstMonth->subMonthNoOverflow();
            $anchor = $this->round8((string) config('pu_indexes.bcb.series.ipca.anchor_base', '100'));

            $this->seedAnchorBase($baseMonth, $anchor, $config, $dryRun, $fetchedAt);

            $result->addNotice(sprintf(
                'Número-índice base de IPCA criado automaticamente (%s em %s) para permitir o encadeamento da variação mensal — a sincronização não foi bloqueada. A base é arbitrária e não afeta a correção monetária (a curva usa apenas razões NI_ref/NI_anterior).',
                $anchor,
                $baseMonth->toDateString(),
            ));
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
     * Cria a linha de número-índice base (âncora) que destrava o encadeamento do IPCA. Não entra na
     * contagem de criados/ignorados (é plumbing interno, não uma competência consultada na API).
     *
     * @param  array<string, mixed>  $config
     */
    private function seedAnchorBase(CarbonImmutable $month, string $value, array $config, bool $dryRun, CarbonImmutable $fetchedAt): void
    {
        if ($dryRun) {
            return;
        }

        IndexRate::query()->create([
            'indexer' => PuIndexer::Ipca->value,
            'rate_date' => $month->startOfDay(),
            'rate_value' => $value,
            'source' => (string) $config['source'],
            'source_reference' => sprintf('%s:%d:base', $config['source'], $config['code']),
            'external_series_code' => (string) $config['code'],
            'fetched_at' => $fetchedAt,
            'is_projected' => false,
            'projection_source' => null,
            'projection_reference_date' => null,
            'projection_policy' => null,
            'index_projection_series_id' => null,
        ]);
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

    /**
     * Registra o status da última sincronização (cache) para a UI mostrar "sincronizado em ...",
     * mesmo quando a API respondeu corretamente porém todos os registros já existiam. Só marca
     * "completed" quando a consulta à API foi integral (sem falha de bloco) e não é dry-run.
     */
    private function recordLastSyncStatus(IndexRateSyncResult $result, bool $dryRun): void
    {
        if ($dryRun || $result->hasBlockFailures()) {
            return;
        }

        Cache::put(
            sprintf('pu_index_sync_%s_status', strtolower($result->indexer->value)),
            ['status' => 'completed'] + $result->toArray(),
            86400,
        );
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

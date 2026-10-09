<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\BcbSgsFetchResult;
use App\Domain\PuCalculator\DTOs\BcbSgsRateData;
use App\Domain\PuCalculator\DTOs\IndexRateSyncResult;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Models\IndexRate;
use App\Models\IndexRateCorrection;
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
 *
 * Data nova entra pelo {@see IndexRateObservationRecorder} (domínio financeiro, data não futura, valor
 * fora do padrão retido). Data já registrada com o MESMO valor é idempotente: nada é regravado, nem a
 * procedência. Valor DIFERENTE numa data já registrada é revisão de histórico:
 *  - na política padrão (`skip_existing`) vira conflito explícito no resultado e nada muda;
 *  - com `update_if_changed`/`overwrite` (escolha explícita do operador) passa pelo
 *    {@see IndexRateCorrectionService} como revisão da fonte: valor anterior no livro de correções,
 *    trilha, e as curvas governadas que o usavam ficam marcadas para reprocessamento -- nunca reescritas.
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
        private readonly IndexRateObservationRecorder $recorder,
        private readonly IndexRateCorrectionService $corrections,
        private readonly PuIndexSyncAttemptRecorder $attempts,
    ) {}

    /**
     * Read-only fetch boundary for controlled workflows that must validate the
     * complete SGS payload before deciding whether any exact snapshot may be
     * persisted.
     */
    public function fetchPublishedRates(
        PuIndexer $indexer,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): BcbSgsFetchResult {
        $config = $this->seriesConfig($indexer);

        return $this->client->fetchSeries((int) $config['code'], $from, $to);
    }

    /** @return array{code:int,value_type:string,source:string} */
    public function publishedSource(PuIndexer $indexer): array
    {
        return $this->seriesConfig($indexer);
    }

    /**
     * Fase 6: toda sincronização que grava (não a simulação) deixa uma tentativa
     * registrada, com o resultado classificado ou a falha higienizada.
     */
    public function sync(
        PuIndexer $indexer,
        CarbonImmutable $from,
        CarbonImmutable $to,
        bool $dryRun = false,
        ?int $userId = null,
        ?string $overwritePolicy = null,
    ): IndexRateSyncResult {
        $attempt = $dryRun ? null : $this->attempts->start($indexer, (string) $this->seriesConfig($indexer)['source'], $from, $to, $userId);
        $invalidEntries = 0;

        try {
            $result = $this->performSync($indexer, $from, $to, $dryRun, $userId, $overwritePolicy, $invalidEntries);
        } catch (\Throwable $exception) {
            $this->attempts->fail($attempt, $exception);

            throw $exception;
        }

        $this->attempts->finish($attempt, $result, $invalidEntries);

        return $result;
    }

    private function performSync(
        PuIndexer $indexer,
        CarbonImmutable $from,
        CarbonImmutable $to,
        bool $dryRun,
        ?int $userId,
        ?string $overwritePolicy,
        int &$invalidEntries,
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
        $invalidEntries = count($fetch->invalidEntries);
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
            'annual_rate' => $this->syncAnnualRate($rates, $config, $policy, $dryRun, $fetchedAt, $result, $userId),
            'monthly_variation' => $this->syncMonthlyVariation($rates, $config, $policy, $dryRun, $fetchedAt, $result, $userId),
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
    private function syncAnnualRate(array $rates, array $config, string $policy, bool $dryRun, CarbonImmutable $fetchedAt, IndexRateSyncResult $result, ?int $userId): void
    {
        foreach ($rates as $rate) {
            $this->applyRow($result->indexer, $rate->referenceDate->startOfDay(), $rate->value, $config, $policy, $dryRun, $fetchedAt, $result, $userId);
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
    private function syncMonthlyVariation(array $rates, array $config, string $policy, bool $dryRun, CarbonImmutable $fetchedAt, IndexRateSyncResult $result, ?int $userId): void
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

            $runningNi = $this->applyRow($result->indexer, $month, $derivedNi, $config, $policy, $dryRun, $fetchedAt, $result, $userId);
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
     * Cria uma linha nova ou classifica a data já registrada, respeitando idempotência e a proteção a
     * dados de outra origem. Retorna o valor efetivo naquela data (para encadeamento do IPCA).
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
        ?int $userId,
    ): string {
        $existing = IndexRate::query()
            ->where('indexer', $indexer->value)
            ->whereDate('rate_date', $date->toDateString())
            ->first();

        if ($existing === null) {
            $outcome = $this->recorder->recordRealized(
                $indexer,
                $date,
                $value,
                [
                    'source' => (string) $config['source'],
                    'source_reference' => sprintf('%s:%d', $config['source'], $config['code']),
                    'external_series_code' => (string) $config['code'],
                    'fetched_at' => $fetchedAt,
                ],
                dryRun: $dryRun,
            );

            if ($outcome->wasCreated()) {
                $result->created++;

                return (string) $outcome->value;
            }

            // Recusa (domínio, data futura), valor retido para confirmação ou a mesma data gravada por outro
            // processo entre a leitura e a gravação: nada novo entra, e o motivo fica no resultado.
            $result->skipped++;

            if ($outcome->isConflict()) {
                $result->addConflict($outcome->toArray());
            }

            if ($outcome->isConflict() || $outcome->isRejected()) {
                $result->addError(sprintf('%s: %s', $date->toDateString(), $outcome->reason));
            }

            return $outcome->existing !== null ? (string) $outcome->existing->rate_value : $value;
        }

        $changed = bccomp((string) $existing->rate_value, $value, self::VALUE_SCALE) !== 0;

        // Não sobrescreve dado de outra origem (manual/publicado/projetado). Valor diferente fica explícito.
        if ((string) $existing->source !== (string) $config['source']) {
            $result->skipped++;

            if ($changed) {
                $result->addConflict([
                    'status' => 'source_conflict',
                    'date' => $date->toDateString(),
                    'value' => $value,
                    'existing_value' => (string) $existing->rate_value,
                    'existing_source' => $existing->source,
                    'reason' => 'Data registrada por outra origem com valor diferente; mantida.',
                ]);
            }

            return (string) $existing->rate_value;
        }

        // Mesmo valor: idempotente. Nem o valor nem a procedência são regravados.
        if (! $changed) {
            $result->skipped++;

            return (string) $existing->rate_value;
        }

        if ($policy === self::POLICY_SKIP) {
            $result->skipped++;
            $result->addConflict([
                'status' => 'value_conflict',
                'date' => $date->toDateString(),
                'value' => $value,
                'existing_value' => (string) $existing->rate_value,
                'existing_source' => $existing->source,
                'reason' => 'A fonte informa valor diferente do registrado; revisão de histórico não é aplicada na política padrão.',
            ]);
            $result->addError(sprintf(
                '%s: a fonte informa %s, mas o registrado é %s; nada foi alterado (corrija pelo caminho de correção, com motivo).',
                $date->toDateString(),
                $value,
                (string) $existing->rate_value,
            ));

            return (string) $existing->rate_value;
        }

        if (! $dryRun) {
            try {
                $this->corrections->correct(
                    indexer: $indexer,
                    rateDate: $date->toDateString(),
                    newValue: $value,
                    reason: sprintf(
                        'Revisão divulgada pela fonte %s (série %d), aplicada pela sincronização com a política %s.',
                        (string) $config['source'],
                        (int) $config['code'],
                        $policy,
                    ),
                    correctedByUserId: $userId,
                    origin: IndexRateCorrection::ORIGIN_PROVIDER_REVISION,
                );
            } catch (InvalidArgumentException $exception) {
                $result->skipped++;
                $result->addError(sprintf('%s: %s', $date->toDateString(), $exception->getMessage()));

                return (string) $existing->rate_value;
            }

            $existing->refresh()->forceFill([
                'external_series_code' => (string) $config['code'],
                'fetched_at' => $fetchedAt,
            ])->save();
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

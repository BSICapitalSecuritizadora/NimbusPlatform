<?php

declare(strict_types=1);

namespace App\Support\SalesBoards;

use App\Enums\SalesBoardCycleStatus;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardPublication;
use App\Services\SalesBoards\SalesDiscountPolicyRegistrar;
use App\Support\Dates\InclusiveDateBound;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * Até onde vai a posição publicada de um empreendimento: a guarda única de
 * "esta data não alcança competência publicada".
 *
 * A posição publicada é imutável. A permuta (registro, encerramento e
 * substituição) e a baixa de unidade perguntam aqui se a data que querem gravar
 * ainda cai depois dela, e a reabertura de competência pergunta se alguma
 * posterior já foi publicada; a política de desconto deve perguntar também, em
 * vez de manter uma regra própria. A retificação de competência publicada é o
 * único caminho que mexe no que ficou para trás.
 *
 * Competência publicada é a que tem {@see SalesBoardPublication}, em qualquer
 * sequência -- nunca o status Aprovado do ciclo. Os dois coincidem enquanto não
 * existe retificação; com ela o ciclo publicado volta a Gerado, e a regra por
 * status passaria a aceitar datas dentro de uma posição que continua publicada.
 * Nenhum código novo deve usar o status Aprovado como sinônimo de publicada:
 * quem precisar da pergunta usa esta classe.
 *
 * A leitura comum é consistente com a transação; com `$lockingRead` ela é feita
 * com `sharedLock()` -- no ciclo e na publicação --, para ler a versão
 * commitada mais recente. É a forma de quem acabou de esperar o lock de um ciclo
 * em aprovação: a publicação que a aprovação gravou precisa aparecer aqui.
 *
 * Quem grava um fato datado trava antes os ciclos em aberto que a data alcança
 * ({@see self::lockOpenCyclesReachedBy()}), na ordem de locks do Quadro, e só
 * então lê a fronteira: a aprovação trava o ciclo antes de derivar a fonte, e
 * sem essa espera um fato commitado no meio da aprovação ficaria dentro da
 * competência que ela publica. A política de desconto é a exceção: ela se
 * encontra com a aprovação na obra, que a aprovação trava em modo
 * compartilhado logo depois do ciclo e o registro trava em modo exclusivo
 * antes de ler a fronteira ({@see SalesDiscountPolicyRegistrar}).
 */
final class PublishedCompetenceBoundary
{
    /**
     * A competência publicada mais recente da obra, no primeiro dia do mês.
     */
    public static function lastPublishedMonth(int $constructionId, bool $lockingRead = false): ?CarbonImmutable
    {
        $month = self::publishedCycles($constructionId, $lockingRead)->max('reference_month');

        return $month === null ? null : self::monthOf($month);
    }

    /**
     * A competência publicada mais recente de cada obra, numa consulta -- só as
     * obras que têm alguma.
     *
     * @param  list<int>  $constructionIds
     * @return array<int, CarbonImmutable> `construction_id` => primeiro dia do mês
     */
    public static function lastPublishedMonths(array $constructionIds): array
    {
        if ($constructionIds === []) {
            return [];
        }

        return self::publishedCyclesOf($constructionIds)
            ->groupBy('construction_id')
            ->selectRaw('construction_id, max(reference_month) as last_published_month')
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->construction_id => self::monthOf($row->last_published_month)])
            ->all();
    }

    /**
     * O primeiro dia que nenhuma competência publicada alcança: o dia seguinte
     * ao fim da última publicada, ou `null` quando a obra não tem nenhuma.
     */
    public static function firstOpenDay(int $constructionId): ?CarbonImmutable
    {
        return self::lastPublishedMonth($constructionId)?->endOfMonth()->addDay()->startOfDay();
    }

    /**
     * As competências publicadas da obra entre dois meses, inclusive, em ordem
     * crescente e no primeiro dia de cada mês.
     *
     * @return list<CarbonImmutable>
     */
    public static function publishedMonthsBetween(int $constructionId, CarbonImmutable $from, CarbonImmutable $through): array
    {
        /** @var Collection<int, mixed> $months */
        $months = self::publishedCycles($constructionId)
            ->where('reference_month', '>=', $from->startOfMonth()->toDateString())
            ->where('reference_month', '<=', $through->endOfMonth()->format('Y-m-d H:i:s'))
            ->orderBy('reference_month')
            ->pluck('reference_month');

        return $months
            ->map(fn (mixed $month): CarbonImmutable => self::monthOf($month))
            ->unique(fn (CarbonImmutable $month): string => $month->toDateString())
            ->values()
            ->all();
    }

    /**
     * Trava os ciclos em aberto da obra -- fora de Aprovado e de Cancelado -- com
     * competência a partir do mês da data, na ordem de locks do Quadro: do mês
     * mais recente para o mais antigo (`reference_month` e `id` decrescentes),
     * antes da unidade, da permuta e do contrato. A aprovação de M trava M e
     * depois M-1, e as duas ordens não se cruzam.
     *
     * Só faz sentido dentro de uma transação.
     */
    public static function lockOpenCyclesReachedBy(int $constructionId, CarbonImmutable $date): void
    {
        SalesBoardCycle::query()
            ->where('construction_id', $constructionId)
            ->whereNotIn('status', [SalesBoardCycleStatus::Approved->value, SalesBoardCycleStatus::Cancelled->value])
            ->where('reference_month', '>=', $date->startOfMonth()->toDateString())
            ->orderByDesc('reference_month')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->get(['id']);
    }

    /**
     * Trava em modo compartilhado os ciclos da obra com competência posterior
     * ao mês, de qualquer situação, do mais recente para o mais antigo -- a
     * ordem de locks do Quadro.
     *
     * É o que quem muda a cadeia de uma competência (a reabertura) faz **antes**
     * de travar o próprio ciclo. A aprovação de uma competência posterior trava
     * o ciclo dela primeiro, com FOR UPDATE pela chave primária, e é no
     * registro da chave primária que as duas precisam se encontrar: ou a
     * reabertura espera a aprovação terminar e encontra a posterior publicada,
     * ou a aprovação espera a reabertura. Vale também com mês sem ciclo entre
     * as duas, quando o portão de ordem da aprovação para nesse mês e nem lê a
     * competência reaberta -- o ciclo da posterior é o único lock em comum.
     *
     * Por isso são duas leituras travadas, e nenhuma comum (o instantâneo do
     * `REPEATABLE READ` só nasce depois das esperas):
     *
     * - os ids, pelo índice único (`construction_id`, `reference_month`), do mês
     *   mais recente para o mais antigo. A consulta é coberta pelo índice, e no
     *   MySQL o lock compartilhado dela fica só nos registros do índice
     *   secundário -- não conflita com o FOR UPDATE da aprovação. Ela dá a
     *   ordem e segura as lacunas: uma competência posterior gerada durante a
     *   reabertura espera;
     * - cada ciclo pela chave primária, um por vez e na mesma ordem. É este o
     *   lock que encontra o da aprovação. Uma consulta só pelos ids travaria na
     *   ordem da chave primária, e não na do Quadro.
     *
     * Só faz sentido dentro de uma transação.
     */
    public static function lockCyclesAfter(int $constructionId, CarbonInterface $referenceMonth): void
    {
        $month = CarbonImmutable::parse($referenceMonth->toDateString())->startOfMonth();

        $cycleIds = SalesBoardCycle::query()
            ->where('construction_id', $constructionId)
            ->where('reference_month', '>', InclusiveDateBound::upperBound($month->endOfMonth()))
            ->orderByDesc('reference_month')
            ->orderByDesc('id')
            ->sharedLock()
            ->pluck('id');

        foreach ($cycleIds as $cycleId) {
            SalesBoardCycle::query()->whereKey($cycleId)->sharedLock()->value('id');
        }
    }

    /**
     * @return Builder<SalesBoardCycle>
     */
    private static function publishedCycles(int $constructionId, bool $lockingRead = false): Builder
    {
        return self::withPublication(SalesBoardCycle::query()->where('construction_id', $constructionId), $lockingRead);
    }

    /**
     * @param  list<int>  $constructionIds
     * @return Builder<SalesBoardCycle>
     */
    private static function publishedCyclesOf(array $constructionIds): Builder
    {
        return self::withPublication(SalesBoardCycle::query()->whereIn('construction_id', $constructionIds));
    }

    /**
     * @param  Builder<SalesBoardCycle>  $query
     * @return Builder<SalesBoardCycle>
     */
    private static function withPublication(Builder $query, bool $lockingRead = false): Builder
    {
        $cycles = (new SalesBoardCycle)->getTable();
        $publications = (new SalesBoardPublication)->getTable();

        return $query
            ->whereExists(function (QueryBuilder $query) use ($cycles, $publications, $lockingRead): void {
                $query->selectRaw('1')
                    ->from($publications)
                    ->whereColumn("{$publications}.sales_board_cycle_id", "{$cycles}.id");

                if ($lockingRead) {
                    $query->sharedLock();
                }
            })
            ->when($lockingRead, fn (Builder $query): Builder => $query->sharedLock());
    }

    private static function monthOf(mixed $month): CarbonImmutable
    {
        return CarbonImmutable::parse(substr((string) $month, 0, 10))->startOfMonth();
    }
}

<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\Models\SalesBoard;
use App\Models\SalesBoardCycle;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Competências com posição já registrada no Quadro de Vendas, por
 * empreendimento, para a conferência das importações e para os formulários da
 * fonte.
 *
 * A posição registrada é uma foto: corrigir depois um contrato, uma parcela ou
 * um valor de unidade não muda o número que as garantias e o relatório mensal
 * já leem. O que a entrada pode fazer é avisar, antes de gravar, quais fatos
 * alcançam competências já registradas -- e dizer o caminho que o fato vai
 * seguir, que depende de como a competência foi registrada:
 *
 * - **publicada pelo ciclo mensal**: a posição publicada não muda; o fato entra
 *   como movimento extemporâneo na próxima competência a ser publicada, passa
 *   pela validação da construtora e pela Gestão, e a última competência
 *   publicada pode ser corrigida pela retificação;
 * - **em retificação**: o fato entra na versão retificada se ela for recalculada
 *   antes da aprovação. Vale para todo fato que a última competência publicada
 *   alcança -- inclusive o datado num mês anterior a ela, que a versão
 *   retificada recebe como extemporâneo --, e não só para o datado no próprio
 *   mês em retificação (só a última publicada pode estar em retificação);
 * - **registrada à mão**: o quadro dessa competência é revisto em "Nova
 *   Atualização", com motivo.
 *
 * A posição é acumulada: um fato datado em março pesa em março e em todas as
 * competências seguintes. Por isso uma data alcança toda competência registrada
 * cujo mês não seja anterior ao dela, e não só a do próprio mês.
 *
 * Só lê, e só avisa: nada aqui bloqueia. Duas consultas para o arquivo inteiro
 * (ou para o formulário) -- os quadros registrados e os ciclos com publicação
 * --, qualquer que seja o número de linhas; ou, na leitura em fluxo das
 * parcelas, duas por empreendimento, na primeira linha que o alcança
 * ({@see self::onDemand()}). {@see self::noticeFor()} é pura.
 */
final class RegisteredCompetenceIndex
{
    /**
     * Os assuntos que {@see self::noticeFor()} conhece, cada um com a forma de
     * dizer o fato. Outro texto qualquer vira "A data …".
     */
    public const SUBJECT_SALE = 'venda';

    public const SUBJECT_SALE_VALUE = 'valor_venda';

    public const SUBJECT_CANCELLATION = 'distrato';

    public const SUBJECT_PAYMENT = 'pagamento';

    public const SUBJECT_INSTALLMENT_CANCELLATION = 'cancelamento_parcela';

    public const SUBJECT_UNIT_VALUE = 'valor_unidade';

    public const SUBJECT_FACT = 'fato';

    /**
     * @param  array<int, array{months: list<string>, published: array<string, true>, rectifying: array<string, true>}>  $byConstruction
     * @param  bool  $loadsOnDemand  carrega o empreendimento na primeira pergunta sobre ele
     */
    private function __construct(
        private array $byConstruction,
        private readonly bool $loadsOnDemand = false,
    ) {}

    /**
     * @param  iterable<int|string|null>  $constructionIds
     */
    public static function forConstructions(iterable $constructionIds): self
    {
        $ids = collect($constructionIds)
            ->filter()
            ->map(fn (int|string $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        return new self(self::load($ids));
    }

    /**
     * Índice que começa vazio e lê cada empreendimento na primeira vez em que é
     * consultado.
     *
     * Serve à leitura em fluxo: a planilha de parcelas não é retida em memória,
     * então os empreendimentos que ela alcança só são conhecidos linha a linha.
     * Duas consultas por empreendimento, nunca por linha.
     */
    public static function onDemand(): self
    {
        return new self([], loadsOnDemand: true);
    }

    /**
     * Competências registradas do empreendimento que um fato datado em alguma das
     * datas alcança. Vale a mais antiga das datas: é dela em diante que a posição
     * muda.
     *
     * @return list<string> competências (`Y-m`) em ordem crescente
     */
    public function reachedBy(?int $constructionId, ?string ...$dates): array
    {
        $earliest = self::earliest($dates);

        if (($constructionId === null) || ($earliest === null)) {
            return [];
        }

        $earliestMonth = substr($earliest, 0, 7);

        return array_values(array_filter(
            $this->monthsOf($constructionId),
            fn (string $month): bool => $month >= $earliestMonth,
        ));
    }

    /**
     * Todas as competências registradas do empreendimento.
     *
     * @return list<string>
     */
    public function allOf(?int $constructionId): array
    {
        return $constructionId === null ? [] : $this->monthsOf($constructionId);
    }

    /**
     * A competência publicada pelo ciclo mais recente do empreendimento (`Y-m`),
     * ou `null`.
     */
    public function lastPublishedMonth(?int $constructionId): ?string
    {
        if ($constructionId === null) {
            return null;
        }

        $published = array_keys($this->entryOf($constructionId)['published']);

        return $published === [] ? null : max($published);
    }

    /**
     * O aviso de entrada para um fato datado: o que acontece com ele por ter
     * data em competência já registrada -- ou `null` quando nenhuma é alcançada.
     *
     * Pura: lê só o que o índice já carregou.
     */
    public function noticeFor(?int $constructionId, string $subject, ?string ...$dates): ?string
    {
        $earliest = self::earliest($dates);

        if (($constructionId === null) || ($earliest === null)) {
            return null;
        }

        $reached = $this->reachedBy($constructionId, $earliest);

        if ($reached === []) {
            return null;
        }

        $entry = $this->entryOf($constructionId);
        $published = array_values(array_filter($reached, fn (string $month): bool => isset($entry['published'][$month])));

        if ($published === []) {
            return self::manualNotice($reached);
        }

        $factMonth = substr($earliest, 0, 7);
        $lastPublished = (string) $this->lastPublishedMonth($constructionId);
        $next = self::label(CarbonImmutable::createFromFormat('!Y-m', $lastPublished)->addMonthNoOverflow()->format('Y-m'));

        /**
         * Só a última competência publicada pode estar em retificação, e todo
         * fato que ela alcança -- do próprio mês ou de antes dele -- entra na
         * versão retificada se ela for recalculada antes da aprovação.
         */
        if (isset($entry['rectifying'][$lastPublished]) && in_array($lastPublished, $reached, true)) {
            return $lastPublished === $factMonth
                ? sprintf(
                    'A competência %s está em retificação: o fato entra na versão retificada se ela for recalculada antes da aprovação; depois disso, entra como extemporâneo em %s.',
                    self::label($factMonth),
                    $next,
                )
                : sprintf(
                    '%s alcança a competência %s, que está em retificação: o fato entra na versão retificada de %s se ela for recalculada antes da aprovação; depois disso, entra como extemporâneo em %s.',
                    self::subjectPhrase($subject, $earliest),
                    self::label($lastPublished),
                    self::label($lastPublished),
                    $next,
                );
        }

        $where = isset($entry['published'][$factMonth])
            ? sprintf('cai em competência já publicada no Quadro de Vendas (%s)', self::label($factMonth))
            : sprintf('alcança competência já publicada no Quadro de Vendas (%s)', self::range($published));

        $consequence = match ($subject) {
            self::SUBJECT_SALE_VALUE => sprintf('A posição publicada não muda: a venda entra como revisão de venda publicada em %s e passa pela validação da construtora e pela Gestão.', $next),
            self::SUBJECT_UNIT_VALUE => sprintf('A posição publicada não muda: o valor novo entra na próxima competência a ser publicada (%s).', $next),
            default => sprintf('A posição publicada não muda: o fato entra como movimento extemporâneo em %s e passa pela validação da construtora e pela Gestão.', $next),
        };

        $rectification = ($lastPublished !== '') && in_array($lastPublished, $reached, true) && ! isset($entry['rectifying'][$lastPublished])
            ? sprintf(' Para corrigir a posição publicada de %s, a Gestão pode usar “Retificar competência”.', self::label($lastPublished))
            : '';

        return sprintf('%s %s. %s%s', self::subjectPhrase($subject, $earliest), $where, $consequence, $rectification);
    }

    /**
     * @return list<string>
     */
    private function monthsOf(int $constructionId): array
    {
        return $this->entryOf($constructionId)['months'];
    }

    /**
     * @return array{months: list<string>, published: array<string, true>, rectifying: array<string, true>}
     */
    private function entryOf(int $constructionId): array
    {
        if ($this->loadsOnDemand && ! array_key_exists($constructionId, $this->byConstruction)) {
            $this->byConstruction[$constructionId] = self::load([$constructionId])[$constructionId] ?? self::emptyEntry();
        }

        return $this->byConstruction[$constructionId] ?? self::emptyEntry();
    }

    /**
     * @return array{months: list<string>, published: array<string, true>, rectifying: array<string, true>}
     */
    private static function emptyEntry(): array
    {
        return ['months' => [], 'published' => [], 'rectifying' => []];
    }

    /**
     * Competências registradas de cada empreendimento e, delas, as publicadas
     * pelo ciclo e as em retificação. Duas consultas.
     *
     * @param  list<int>  $constructionIds
     * @return array<int, array{months: list<string>, published: array<string, true>, rectifying: array<string, true>}>
     */
    private static function load(array $constructionIds): array
    {
        if ($constructionIds === []) {
            return [];
        }

        $entries = [];
        $month = static fn (mixed $value): string => CarbonImmutable::parse(substr((string) ($value instanceof DateTimeInterface ? $value->format('Y-m-d') : $value), 0, 10))->format('Y-m');

        SalesBoard::query()
            ->whereIn('construction_id', $constructionIds)
            ->orderBy('reference_month')
            ->get(['construction_id', 'reference_month'])
            ->each(function (SalesBoard $salesBoard) use (&$entries, $month): void {
                $entries[(int) $salesBoard->construction_id] ??= self::emptyEntry();
                $entries[(int) $salesBoard->construction_id]['months'][$month($salesBoard->reference_month)] = $month($salesBoard->reference_month);
            });

        SalesBoardCycle::query()
            ->select(['id', 'construction_id', 'reference_month'])
            ->whereIn('construction_id', $constructionIds)
            ->withPublication()
            ->withExists('openRectification')
            ->get()
            ->each(function (SalesBoardCycle $cycle) use (&$entries, $month): void {
                $constructionId = (int) $cycle->construction_id;
                $cycleMonth = $month($cycle->reference_month);

                $entries[$constructionId] ??= self::emptyEntry();
                $entries[$constructionId]['months'][$cycleMonth] = $cycleMonth;
                $entries[$constructionId]['published'][$cycleMonth] = true;

                if ((bool) $cycle->open_rectification_exists) {
                    $entries[$constructionId]['rectifying'][$cycleMonth] = true;
                }
            });

        return array_map(function (array $entry): array {
            $months = array_values($entry['months']);
            sort($months);

            return [...$entry, 'months' => $months];
        }, $entries);
    }

    /**
     * O aviso da conferência para as competências alcançadas, ou `null` quando
     * nenhuma registrada é alcançada -- a forma de antes, sem distinguir como a
     * competência foi registrada.
     *
     * @param  list<string>  $months  competências (`Y-m`) em ordem crescente
     */
    public static function describe(array $months): ?string
    {
        if ($months === []) {
            return null;
        }

        $first = self::label($months[0]);

        if (count($months) === 1) {
            return "Altera fato da competência {$first}, já registrada no Quadro de Vendas.";
        }

        return sprintf(
            'Altera fatos de %d competências já registradas no Quadro de Vendas (%s a %s).',
            count($months),
            $first,
            self::label($months[count($months) - 1]),
        );
    }

    /**
     * Competências registradas só à mão: o quadro manual é revisto com motivo.
     *
     * @param  list<string>  $months
     */
    private static function manualNotice(array $months): string
    {
        if (count($months) === 1) {
            return sprintf(
                'Altera fato da competência %s, registrada manualmente no Quadro de Vendas: revise o quadro dessa competência em “Nova Atualização”, informando o motivo.',
                self::label($months[0]),
            );
        }

        return sprintf(
            'Altera fatos de %d competências registradas manualmente no Quadro de Vendas (%s): revise o quadro dessas competências em “Nova Atualização”, informando o motivo.',
            count($months),
            self::range($months),
        );
    }

    private static function subjectPhrase(string $subject, string $day): string
    {
        $date = CarbonImmutable::parse(substr($day, 0, 10))->format('d/m/Y');

        return match ($subject) {
            self::SUBJECT_SALE => sprintf('A venda de %s', $date),
            self::SUBJECT_SALE_VALUE => sprintf('O valor da venda de %s', $date),
            self::SUBJECT_CANCELLATION => sprintf('O distrato de %s', $date),
            self::SUBJECT_PAYMENT => sprintf('O pagamento de %s', $date),
            self::SUBJECT_INSTALLMENT_CANCELLATION => sprintf('O cancelamento de parcela de %s', $date),
            self::SUBJECT_UNIT_VALUE => sprintf('O valor da unidade com vigência a partir de %s', $date),
            default => sprintf('A data %s', $date),
        };
    }

    /**
     * @param  list<?string>  $dates
     */
    private static function earliest(array $dates): ?string
    {
        $dates = array_values(array_filter($dates, fn (?string $date): bool => filled($date)));

        return $dates === [] ? null : substr((string) min($dates), 0, 10);
    }

    /**
     * @param  list<string>  $months
     */
    private static function range(array $months): string
    {
        $first = self::label($months[0]);
        $last = self::label($months[count($months) - 1]);

        return $first === $last ? $first : $first.' a '.$last;
    }

    private static function label(string $month): string
    {
        return CarbonImmutable::createFromFormat('!Y-m', $month)->format('m/Y');
    }
}

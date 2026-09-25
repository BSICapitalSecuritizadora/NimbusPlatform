<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\Models\SalesBoard;
use Carbon\CarbonImmutable;

/**
 * Competências com posição já registrada no Quadro de Vendas, por
 * empreendimento, para a conferência das importações da fonte.
 *
 * A posição registrada é uma foto: corrigir depois um contrato, uma parcela ou
 * um valor de unidade não muda o número que as garantias e o relatório mensal
 * já leem. O que a conferência pode fazer é avisar, antes de confirmar, quais
 * linhas mexem em fatos de competências já registradas -- para que a correção
 * da fonte venha acompanhada da revisão da posição, e não fique esquecida.
 *
 * A posição é acumulada: um fato datado em março pesa em março e em todas as
 * competências seguintes. Por isso uma data alcança toda competência registrada
 * cujo mês não seja anterior ao dela, e não só a do próprio mês.
 *
 * Só lê. Uma consulta para o arquivo inteiro, qualquer que seja o número de
 * linhas.
 */
final class RegisteredCompetenceIndex
{
    /**
     * @param  array<int, list<string>>  $monthsByConstruction  empreendimento => competências (`Y-m`) em ordem crescente
     */
    private function __construct(private readonly array $monthsByConstruction) {}

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

        if ($ids === []) {
            return new self([]);
        }

        $months = [];

        SalesBoard::query()
            ->whereIn('construction_id', $ids)
            ->orderBy('reference_month')
            ->get(['construction_id', 'reference_month'])
            ->each(function (SalesBoard $salesBoard) use (&$months): void {
                $month = CarbonImmutable::parse($salesBoard->reference_month->toDateString())->format('Y-m');

                $months[(int) $salesBoard->construction_id][$month] = $month;
            });

        return new self(array_map(
            fn (array $constructionMonths): array => array_values($constructionMonths),
            $months,
        ));
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
        $dates = array_values(array_filter($dates, fn (?string $date): bool => filled($date)));

        if (($constructionId === null) || ($dates === [])) {
            return [];
        }

        $earliestMonth = substr((string) min($dates), 0, 7);

        return array_values(array_filter(
            $this->monthsByConstruction[$constructionId] ?? [],
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
        return $constructionId === null ? [] : ($this->monthsByConstruction[$constructionId] ?? []);
    }

    /**
     * O aviso da conferência para as competências alcançadas, ou `null` quando
     * nenhuma registrada é alcançada.
     *
     * @param  list<string>  $months  competências (`Y-m`) em ordem crescente
     */
    public static function describe(array $months): ?string
    {
        if ($months === []) {
            return null;
        }

        $first = CarbonImmutable::createFromFormat('!Y-m', $months[0])->format('m/Y');

        if (count($months) === 1) {
            return "Altera fato da competência {$first}, já registrada no Quadro de Vendas.";
        }

        $last = CarbonImmutable::createFromFormat('!Y-m', $months[count($months) - 1])->format('m/Y');

        return sprintf(
            'Altera fatos de %d competências já registradas no Quadro de Vendas (%s a %s).',
            count($months),
            $first,
            $last,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\Models\SalesBoard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A integridade que o leitor de posição pressupõe e que a unicidade da tabela
 * não garante. Somente leitura.
 *
 * A unique de `sales_boards` inclui a Emissão, e o {@see SalesBoardPositionReader}
 * resolve a posição por empreendimento. Um quadro gravado sob uma Emissão que não
 * é a atual do empreendimento -- empreendimento trocado de Emissão, carga feita
 * por fora -- conta na Emissão errada e deixa o mesmo empreendimento com duas
 * posições na mesma competência.
 *
 * Extraída do `sales-boards:position-drift` sem mudar as consultas, para a
 * "Prévia de prontidão" mostrar o mesmo diagnóstico na tela.
 */
class SalesBoardPositionIntegrityService
{
    /**
     * Quadros cuja Emissão gravada não é a Emissão atual do empreendimento.
     *
     * Com o filtro de Emissões, entra o quadro que envolve alguma delas de
     * qualquer lado: a Emissão em que foi gravado ou a do empreendimento.
     *
     * @param  list<int>  $emissionIds
     * @return Collection<int, SalesBoard>
     */
    public function boardsOutsideConstructionEmission(array $emissionIds = []): Collection
    {
        return SalesBoard::query()
            ->join('constructions', 'constructions.id', '=', 'sales_boards.construction_id')
            ->whereColumn('sales_boards.emission_id', '<>', 'constructions.emission_id')
            ->when($emissionIds !== [], fn (Builder $query): Builder => $query->where(
                fn (Builder $query): Builder => $query
                    ->whereIn('sales_boards.emission_id', $emissionIds)
                    ->orWhereIn('constructions.emission_id', $emissionIds)
            ))
            ->orderBy('sales_boards.construction_id')
            ->orderBy('sales_boards.reference_month')
            ->orderBy('sales_boards.id')
            ->get([
                'sales_boards.id',
                'sales_boards.emission_id',
                'sales_boards.construction_id',
                'sales_boards.reference_month',
                'constructions.emission_id as construction_emission_id',
                'constructions.development_name as construction_name',
            ]);
    }

    /**
     * Pares (empreendimento, competência) com mais de um quadro.
     *
     * A competência é o mês, como o leitor a enxerga: o model normaliza o dia
     * para 01, mas um quadro gravado por fora dele pode trazer outro dia e
     * escapar de um agrupamento pela data exata. `SUBSTR(..., 1, 7)` devolve
     * `AAAA-MM` tanto da coluna DATE do MySQL quanto do texto do SQLite.
     *
     * Dentro de uma Emissão a unique impede a repetição da data, então o par
     * daqui costuma ter quadros em Emissões diferentes -- e o leitor por
     * empreendimento enxerga duas posições para o mesmo mês. Um dia fora do
     * padrão repete o mês até dentro da mesma Emissão, e o par entra do mesmo
     * jeito.
     *
     * @param  list<int>  $emissionIds
     * @return Collection<string, Collection<int, SalesBoard>> chaveada por `construction_id|AAAA-MM`
     */
    public function competencesWithSeveralBoards(array $emissionIds = []): Collection
    {
        $competence = 'SUBSTR(reference_month, 1, 7)';

        $duplicatedPairs = SalesBoard::query()
            ->selectRaw("construction_id, {$competence} as competence")
            ->groupBy('construction_id')
            ->groupByRaw($competence)
            ->havingRaw('COUNT(*) > 1');

        return SalesBoard::query()
            ->joinSub($duplicatedPairs, 'duplicated_pairs', fn (JoinClause $join): JoinClause => $join
                ->on('duplicated_pairs.construction_id', '=', 'sales_boards.construction_id')
                ->on('duplicated_pairs.competence', '=', DB::raw('SUBSTR(sales_boards.reference_month, 1, 7)')))
            ->join('constructions', 'constructions.id', '=', 'sales_boards.construction_id')
            ->orderBy('sales_boards.construction_id')
            ->orderByRaw('SUBSTR(sales_boards.reference_month, 1, 7)')
            ->orderBy('sales_boards.emission_id')
            ->orderBy('sales_boards.id')
            ->get([
                'sales_boards.id',
                'sales_boards.emission_id',
                'sales_boards.construction_id',
                'sales_boards.reference_month',
                'constructions.emission_id as construction_emission_id',
                'constructions.development_name as construction_name',
            ])
            ->groupBy(fn (SalesBoard $board): string => sprintf(
                '%d|%s',
                (int) $board->construction_id,
                $board->reference_month->format('Y-m'),
            ))
            ->filter(fn (Collection $boards): bool => $emissionIds === [] || $boards->contains(
                fn (SalesBoard $board): bool => in_array((int) $board->emission_id, $emissionIds, true)
                    || in_array((int) $board->construction_emission_id, $emissionIds, true)
            ));
    }
}

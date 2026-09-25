<?php

namespace App\Console\Commands;

use App\Models\Emission;
use App\Models\SalesBoard;
use App\Services\SalesBoards\SalesBoardPositionReader;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Mede, sem tocar em nada, o impacto da correção do GF-01.
 *
 * Compara o número de unidades que o painel do relatório mensal mostrava — um
 * único quadro de vendas escolhido por `first()` dentro da competência — com a
 * posição consolidada da emissão lida pelo {@see SalesBoardPositionReader}.
 *
 * Também confere a integridade que o leitor pressupõe e que a unicidade da
 * tabela não garante. A unique de `sales_boards` inclui a Emissão, e o leitor
 * resolve a posição por empreendimento; um quadro gravado sob uma Emissão que
 * não é a atual do empreendimento — empreendimento trocado de Emissão, carga
 * feita por fora — conta na Emissão errada e deixa o mesmo empreendimento com
 * duas posições na mesma competência. Essas linhas saem em seções próprias, e
 * a divergência de uma competência afetada por elas deixa de ser atribuída só
 * ao `first()`.
 *
 * O comando só lê. Não cria quadro, não regenera PDF, não grava comparativo.
 */
class DiagnoseSalesBoardPositionDrift extends Command
{
    protected $signature = 'sales-boards:position-drift
                            {--emission=* : Limita a emissões específicas (id)}
                            {--all : Lista também as competências sem divergência}';

    protected $description = 'Compara (somente leitura) o painel antigo do relatório mensal com a posição consolidada do quadro de vendas e aponta quadros fora da Emissão do empreendimento';

    public function handle(SalesBoardPositionReader $positionReader): int
    {
        $emissionIds = array_map('intval', (array) $this->option('emission'));

        $emissions = Emission::query()
            ->when($emissionIds !== [], fn ($query) => $query->whereIn('id', $emissionIds))
            ->whereHas('salesBoards')
            ->orderBy('id')
            ->get();

        $misplacedBoards = $this->boardsOutsideConstructionEmission($emissionIds);
        $duplicatedCompetences = $this->competencesWithSeveralBoards($emissionIds);

        if ($emissions->isEmpty() && $misplacedBoards->isEmpty() && $duplicatedCompetences->isEmpty()) {
            $this->warn('Nenhuma emissão com quadro de vendas encontrada nesta base.');

            return self::SUCCESS;
        }

        $mismatchSince = $this->mismatchStartByEmission($misplacedBoards);

        $rows = [];
        $divergences = 0;
        $unitsDelta = 0;
        $competencesChecked = 0;

        foreach ($emissions as $emission) {
            $lastCompetence = $emission->salesBoards()->max('reference_month');

            if ($lastCompetence === null) {
                continue;
            }

            foreach ($positionReader->competencesUntil($emission, CarbonImmutable::parse((string) $lastCompetence)) as $competence) {
                $competencesChecked++;

                $previous = $this->previousPanelUnits($emission, $competence);
                $position = $positionReader->forEmission($emission, $competence);

                $differs = $previous !== $position->totalUnits;

                if ($differs) {
                    $divergences++;
                    $unitsDelta += $position->totalUnits - $previous;
                }

                if (! $differs && ! $this->option('all')) {
                    continue;
                }

                $mismatchStart = $mismatchSince[(int) $emission->getKey()] ?? null;

                $rows[] = [
                    $emission->getKey(),
                    $competence->format('m/Y'),
                    $previous,
                    $position->totalUnits,
                    sprintf('%+d', $position->totalUnits - $previous),
                    sprintf('%d/%d', $position->constructionsCovered, $position->constructionsExpected),
                    $this->reason(
                        $position->carriedForwardPositions() !== [],
                        $position->constructionsCovered,
                        $mismatchStart !== null && $mismatchStart->lessThanOrEqualTo($competence),
                    ),
                ];
            }
        }

        if ($rows !== []) {
            $this->table(
                ['Emissão', 'Competência', 'Antes', 'Depois', 'Δ', 'Cobertura', 'Motivo'],
                $rows,
            );
        }

        $this->reportMisplacedBoards($misplacedBoards);
        $this->reportDuplicatedCompetences($duplicatedCompetences);

        $this->newLine();
        $this->line(sprintf('Emissões analisadas: %d', $emissions->count()));
        $this->line(sprintf('Competências analisadas: %d', $competencesChecked));
        $this->line(sprintf('Competências com número diferente: %d', $divergences));
        $this->line(sprintf('Diferença total de unidades: %+d', $unitsDelta));
        $this->line(sprintf('Quadros fora da Emissão do empreendimento: %d', $misplacedBoards->count()));
        $this->line(sprintf('Empreendimentos com mais de um quadro na mesma competência: %d', $duplicatedCompetences->count()));

        if ($divergences === 0) {
            $this->info('Nenhuma divergência: nesta base a regra antiga e a consolidada devolvem o mesmo número.');
        }

        if ($misplacedBoards->isEmpty() && $duplicatedCompetences->isEmpty()) {
            $this->info('Nenhum quadro fora da Emissão do empreendimento e nenhuma competência com mais de um quadro.');
        }

        return self::SUCCESS;
    }

    /**
     * Reproduz a regra antiga do painel: o quadro mais recente dentro da
     * competência, sozinho, representando a emissão inteira.
     */
    private function previousPanelUnits(Emission $emission, CarbonImmutable $competence): int
    {
        $salesBoard = SalesBoard::query()
            ->where('emission_id', $emission->getKey())
            ->whereBetween('reference_month', [
                $competence->startOfMonth()->toDateString(),
                $competence->endOfMonth()->toDateString(),
            ])
            ->orderByDesc('reference_month')
            ->orderByDesc('id')
            ->first();

        return (int) ($salesBoard?->total_units ?? 0);
    }

    /**
     * Quadros cuja Emissão gravada não é a Emissão atual do empreendimento.
     *
     * Com o filtro de Emissões, entra o quadro que envolve alguma delas de
     * qualquer lado: a Emissão em que foi gravado ou a do empreendimento.
     *
     * @param  list<int>  $emissionIds
     * @return Collection<int, SalesBoard>
     */
    private function boardsOutsideConstructionEmission(array $emissionIds): Collection
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
    private function competencesWithSeveralBoards(array $emissionIds): Collection
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

    /**
     * A partir de qual competência cada Emissão é afetada por um quadro fora do
     * lugar: a Emissão em que ele foi gravado conta uma posição que não é dela,
     * e a Emissão atual do empreendimento deixa de contá-la.
     *
     * @param  Collection<int, SalesBoard>  $misplacedBoards
     * @return array<int, CarbonImmutable>
     */
    private function mismatchStartByEmission(Collection $misplacedBoards): array
    {
        $starts = [];

        foreach ($misplacedBoards as $board) {
            $month = CarbonImmutable::parse($board->reference_month->toDateString())->startOfMonth();

            foreach ([(int) $board->emission_id, (int) $board->construction_emission_id] as $emissionId) {
                if (! isset($starts[$emissionId]) || $month->lessThan($starts[$emissionId])) {
                    $starts[$emissionId] = $month;
                }
            }
        }

        return $starts;
    }

    /**
     * @param  Collection<int, SalesBoard>  $misplacedBoards
     */
    private function reportMisplacedBoards(Collection $misplacedBoards): void
    {
        if ($misplacedBoards->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->warn('Quadros gravados sob uma Emissão diferente da atual do empreendimento');

        $this->table(
            ['Quadro', 'Empreendimento', 'Competência', 'Emissão do quadro', 'Emissão do empreendimento'],
            $misplacedBoards->map(fn (SalesBoard $board): array => [
                $board->getKey(),
                $this->constructionLabel($board),
                $board->reference_month->format('m/Y'),
                $board->emission_id,
                $board->construction_emission_id,
            ])->all(),
        );
    }

    /**
     * @param  Collection<string, Collection<int, SalesBoard>>  $duplicatedCompetences
     */
    private function reportDuplicatedCompetences(Collection $duplicatedCompetences): void
    {
        if ($duplicatedCompetences->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->warn('Mais de um quadro para o mesmo empreendimento na mesma competência');

        $this->table(
            ['Empreendimento', 'Competência', 'Emissão do empreendimento', 'Emissões dos quadros', 'Quadros'],
            $duplicatedCompetences->map(function (Collection $boards): array {
                /** @var SalesBoard $first */
                $first = $boards->first();

                return [
                    $this->constructionLabel($first),
                    $first->reference_month->format('m/Y'),
                    $first->construction_emission_id,
                    $boards->pluck('emission_id')->map(fn (mixed $id): int => (int) $id)->unique()->implode(', '),
                    $boards->map(fn (SalesBoard $board): int => (int) $board->getKey())->implode(', '),
                ];
            })->values()->all(),
        );
    }

    private function constructionLabel(SalesBoard $board): string
    {
        return sprintf('%s (#%d)', (string) ($board->construction_name ?? '—'), (int) $board->construction_id);
    }

    private function reason(bool $carriedForward, int $covered, bool $emissionMismatch = false): string
    {
        $reason = match (true) {
            $covered > 1 && $carriedForward => 'Empreendimentos omitidos + posição transportada',
            $covered > 1 => 'Empreendimentos omitidos pelo first()',
            $carriedForward => 'Posição transportada de competência anterior',
            default => '—',
        };

        if (! $emissionMismatch) {
            return $reason;
        }

        return $reason === '—'
            ? 'Quadro fora da Emissão do empreendimento'
            : 'Quadro fora da Emissão do empreendimento + '.$reason;
    }
}

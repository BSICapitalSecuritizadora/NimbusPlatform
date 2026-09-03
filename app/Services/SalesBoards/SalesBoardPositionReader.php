<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\DTOs\SalesBoards\ConstructionSalesPosition;
use App\DTOs\SalesBoards\EmissionSalesPosition;
use App\Enums\SalesBoardPositionStatus;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\SalesBoard;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Fonte única da resposta para "qual era a posição do quadro de vendas nesta
 * competência?".
 *
 * Antes desta classe havia três respostas diferentes para a mesma pergunta: o
 * relatório mensal usava um único quadro do mês para representar a emissão
 * inteira, o histórico do relatório somava apenas os quadros da competência
 * exata, e as garantias liam a última posição de cada empreendimento até o mês.
 * A terceira é a regra correta e é a que vive aqui.
 *
 * O quadro de vendas é por empreendimento; a posição da emissão é a soma das
 * posições dos seus empreendimentos. Um empreendimento sem quadro no mês entra
 * com a última posição conhecida (carry-forward) — nunca com zero. Um
 * empreendimento sem posição alguma não entra na soma e é reportado na
 * cobertura.
 *
 * Esta classe apenas lê. Não cria quadro, não deriva valor de unidade, não sabe
 * quem escreveu a posição.
 */
class SalesBoardPositionReader
{
    /**
     * Posição de um empreendimento na competência.
     */
    public function forConstruction(Construction $construction, CarbonInterface $positionDate): ConstructionSalesPosition
    {
        $salesBoards = $this->sortSalesBoards(
            SalesBoard::query()
                ->where('construction_id', $construction->getKey())
                ->get()
        );

        return $this->resolveConstructionPosition(
            constructionId: (int) $construction->getKey(),
            constructionName: $construction->development_name,
            salesBoards: $salesBoards,
            positionDate: $this->normalizeMonth($positionDate),
        );
    }

    /**
     * Posição consolidada da emissão na competência.
     */
    public function forEmission(Emission $emission, CarbonInterface $positionDate): EmissionSalesPosition
    {
        $emissionId = (int) $emission->getKey();

        return $this->fromLoadedSalesBoards(
            emissionId: $emissionId,
            salesBoards: $this->loadSalesBoards($emissionId),
            constructions: $this->loadConstructions($emissionId),
            positionDate: $positionDate,
        );
    }

    /**
     * Posições consolidadas da emissão em várias competências.
     *
     * Carrega os quadros e os empreendimentos uma única vez e resolve todas as
     * competências em memória: o número de consultas não cresce com o número de
     * empreendimentos nem com o número de meses pedidos.
     *
     * @param  iterable<CarbonInterface>  $positionDates
     * @return Collection<string, EmissionSalesPosition> chaveada por `Y-m`
     */
    public function forEmissionMonths(Emission $emission, iterable $positionDates): Collection
    {
        $emissionId = (int) $emission->getKey();
        $salesBoards = $this->loadSalesBoards($emissionId);
        $constructions = $this->loadConstructions($emissionId);

        /** @var Collection<string, EmissionSalesPosition> $positions */
        $positions = collect();

        foreach ($positionDates as $positionDate) {
            $month = $this->normalizeMonth($positionDate);

            $positions->put($month->format('Y-m'), $this->fromLoadedSalesBoards(
                emissionId: $emissionId,
                salesBoards: $salesBoards,
                constructions: $constructions,
                positionDate: $month,
            ));
        }

        return $positions;
    }

    /**
     * Competências com quadro registrado na emissão até `$until`, da mais
     * antiga para a mais recente.
     *
     * @return list<CarbonImmutable>
     */
    public function competencesUntil(Emission $emission, CarbonInterface $until): array
    {
        $limit = $this->normalizeMonth($until)->endOfMonth();

        return SalesBoard::query()
            ->where('emission_id', $emission->getKey())
            ->where('reference_month', '<=', $limit->toDateString())
            ->orderBy('reference_month')
            ->pluck('reference_month')
            ->map(fn (mixed $referenceMonth): CarbonImmutable => $this->normalizeMonth($referenceMonth))
            ->unique(fn (CarbonImmutable $month): string => $month->toDateString())
            ->values()
            ->all();
    }

    /**
     * Resolve a posição a partir de quadros já carregados em memória.
     *
     * Existe para que os índices operacionais que já carregaram as relações da
     * emissão (garantias) deleguem a regra a esta classe sem disparar consulta
     * nova. É o mesmo cálculo de {@see self::forEmission()}.
     *
     * @param  Collection<int, SalesBoard>  $salesBoards
     * @param  Collection<int, Construction>  $constructions
     */
    public function fromLoadedSalesBoards(
        int $emissionId,
        Collection $salesBoards,
        Collection $constructions,
        CarbonInterface $positionDate,
    ): EmissionSalesPosition {
        $month = $this->normalizeMonth($positionDate);
        $salesBoardsByConstruction = $this->groupByConstruction($salesBoards);

        $positions = [];

        foreach ($this->eligibleConstructions($constructions, $salesBoardsByConstruction) as $constructionId => $constructionName) {
            $positions[] = $this->resolveConstructionPosition(
                constructionId: $constructionId,
                constructionName: $constructionName,
                salesBoards: $salesBoardsByConstruction->get($constructionId, collect()),
                positionDate: $month,
            );
        }

        return EmissionSalesPosition::fromPositions($emissionId, $month, $positions);
    }

    /**
     * Empreendimentos elegíveis: os da emissão, mais qualquer empreendimento
     * que tenha quadro registrado nela.
     *
     * A união importa. Descartar um quadro porque o empreendimento não aparece
     * na lista da emissão apagaria silenciosamente uma posição que existe.
     *
     * @param  Collection<int, Construction>  $constructions
     * @param  Collection<int, Collection<int, SalesBoard>>  $salesBoardsByConstruction
     * @return array<int, string|null> nome por id, em ordem determinística
     */
    private function eligibleConstructions(Collection $constructions, Collection $salesBoardsByConstruction): array
    {
        $eligible = [];

        $ordered = $constructions
            ->sortBy([
                fn (Construction $construction): string => (string) $construction->development_name,
                fn (Construction $construction): int => (int) $construction->getKey(),
            ]);

        foreach ($ordered as $construction) {
            $eligible[(int) $construction->getKey()] = $construction->development_name;
        }

        foreach ($salesBoardsByConstruction->keys()->sort()->values() as $constructionId) {
            if (! array_key_exists((int) $constructionId, $eligible)) {
                $eligible[(int) $constructionId] = null;
            }
        }

        return $eligible;
    }

    /**
     * Última posição do empreendimento cuja competência não ultrapasse o mês.
     *
     * @param  Collection<int, SalesBoard>  $salesBoards  já ordenados por competência desc, id desc
     */
    private function resolveConstructionPosition(
        int $constructionId,
        ?string $constructionName,
        Collection $salesBoards,
        CarbonImmutable $positionDate,
    ): ConstructionSalesPosition {
        $salesBoard = $salesBoards->first(
            fn (SalesBoard $salesBoard): bool => $this->normalizeMonth($salesBoard->reference_month)->lessThanOrEqualTo($positionDate),
        );

        if ($salesBoard instanceof SalesBoard) {
            return ConstructionSalesPosition::fromSalesBoard(
                constructionId: $constructionId,
                constructionName: $constructionName,
                positionDate: $positionDate,
                referenceMonthUsed: $this->normalizeMonth($salesBoard->reference_month),
                salesBoard: $salesBoard,
            );
        }

        return ConstructionSalesPosition::absent(
            constructionId: $constructionId,
            constructionName: $constructionName,
            positionDate: $positionDate,
            status: $salesBoards->isEmpty()
                ? SalesBoardPositionStatus::Unpositioned
                : SalesBoardPositionStatus::NotYetPositioned,
        );
    }

    /**
     * @return Collection<int, SalesBoard>
     */
    private function loadSalesBoards(int $emissionId): Collection
    {
        return $this->sortSalesBoards(
            SalesBoard::query()
                ->where('emission_id', $emissionId)
                ->get()
        );
    }

    /**
     * @return Collection<int, Construction>
     */
    private function loadConstructions(int $emissionId): Collection
    {
        return Construction::query()
            ->where('emission_id', $emissionId)
            ->get();
    }

    /**
     * Ordem determinística: competência desc, id desc. O empate de competência
     * não acontece dentro de uma emissão por causa da unicidade
     * (emission_id, construction_id, reference_month), mas o desempate
     * explícito garante que a mesma entrada devolva sempre a mesma posição.
     *
     * @param  Collection<int, SalesBoard>  $salesBoards
     * @return Collection<int, SalesBoard>
     */
    private function sortSalesBoards(Collection $salesBoards): Collection
    {
        return $salesBoards
            ->filter(fn (SalesBoard $salesBoard): bool => $salesBoard->reference_month !== null)
            ->sortByDesc(fn (SalesBoard $salesBoard): string => sprintf(
                '%s|%020d',
                $this->normalizeMonth($salesBoard->reference_month)->toDateString(),
                (int) $salesBoard->getKey(),
            ))
            ->values();
    }

    /**
     * @param  Collection<int, SalesBoard>  $salesBoards
     * @return Collection<int, Collection<int, SalesBoard>>
     */
    private function groupByConstruction(Collection $salesBoards): Collection
    {
        return $this->sortSalesBoards($salesBoards)
            ->groupBy(fn (SalesBoard $salesBoard): int => (int) $salesBoard->construction_id);
    }

    private function normalizeMonth(mixed $value): CarbonImmutable
    {
        if ($value instanceof CarbonInterface) {
            return CarbonImmutable::parse($value->toDateString())->startOfMonth();
        }

        return CarbonImmutable::parse((string) $value)->startOfMonth();
    }
}

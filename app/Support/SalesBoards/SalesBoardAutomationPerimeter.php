<?php

declare(strict_types=1);

namespace App\Support\SalesBoards;

use App\DTOs\SalesBoards\SalesBoardAutomationEligibleTarget;
use App\Services\SalesBoards\SalesBoardAutomationEligibilityProvider;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * O que a automação atende agora: quais empreendimentos, desde qual competência.
 *
 * É a resposta do provider de elegibilidade num formato que serve a quem não
 * descobre nada -- os lembretes, a aba "Pendentes de ação", o encerramento de
 * alvos órfãos. Sem ela, cada um desses lugares decidia sozinho o que é
 * "automatizado", e decidiam errado: um alvo de Emissão devolvida ao legado
 * continuava lembrando todo dia, e um ciclo manual de Emissão legada entrava no
 * lembrete da Gestão.
 *
 * Deliberadamente derivado do provider, e não de uma consulta própria às
 * colunas de rollout: modo automatizado, competência inicial e escopo homologado
 * íntegro são regras dele, e uma segunda leitura divergiria da primeira na
 * primeira mudança.
 */
final class SalesBoardAutomationPerimeter
{
    /**
     * @param  array<int, CarbonImmutable>  $starts  empreendimento => primeira competência atendida
     */
    private function __construct(private readonly array $starts) {}

    /**
     * @param  list<SalesBoardAutomationEligibleTarget>  $eligible
     */
    public static function fromEligibleTargets(array $eligible): self
    {
        $starts = [];

        foreach ($eligible as $target) {
            $start = CarbonImmutable::parse($target->startReferenceMonth->toDateString())->startOfMonth();
            $current = $starts[$target->constructionId] ?? null;

            $starts[$target->constructionId] = $current === null || $start->lessThan($current) ? $start : $current;
        }

        return new self($starts);
    }

    /**
     * O perímetro de agora, segundo o provider amarrado no container.
     */
    public static function current(): self
    {
        return self::fromEligibleTargets(app(SalesBoardAutomationEligibilityProvider::class)->eligibleTargets());
    }

    public function isEmpty(): bool
    {
        return $this->starts === [];
    }

    /**
     * @return list<int>
     */
    public function constructionIds(): array
    {
        return array_map('intval', array_keys($this->starts));
    }

    public function covers(int $constructionId, CarbonInterface $referenceMonth): bool
    {
        $start = $this->starts[$constructionId] ?? null;

        return $start !== null
            && CarbonImmutable::parse($referenceMonth->toDateString())->startOfMonth()->greaterThanOrEqualTo($start);
    }

    /**
     * Restringe a consulta ao que está dentro do perímetro.
     *
     * Os empreendimentos são agrupados pela competência inicial -- uma Emissão
     * inteira compartilha a mesma --, e a consulta fica com um `OR` por Emissão,
     * não um por empreendimento. Perímetro vazio não devolve nada.
     *
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public function constrain(
        Builder $query,
        string $constructionColumn = 'construction_id',
        string $monthColumn = 'reference_month',
    ): Builder {
        if ($this->starts === []) {
            return $query->whereRaw('1 = 0');
        }

        $groups = $this->groupedByStart();

        return $query->where(function (Builder $query) use ($groups, $constructionColumn, $monthColumn): void {
            foreach ($groups as $start => $constructionIds) {
                $query->orWhere(function (Builder $query) use ($start, $constructionIds, $constructionColumn, $monthColumn): void {
                    $query->whereIn($constructionColumn, $constructionIds)
                        ->where($monthColumn, '>=', $start);
                });
            }
        });
    }

    /**
     * Restringe a consulta ao que está **fora** do perímetro.
     *
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public function exclude(
        Builder $query,
        string $constructionColumn = 'construction_id',
        string $monthColumn = 'reference_month',
    ): Builder {
        if ($this->starts === []) {
            return $query;
        }

        return $query->whereNot(fn (Builder $query): Builder => $this->constrain($query, $constructionColumn, $monthColumn));
    }

    /**
     * @return array<string, list<int>>
     */
    private function groupedByStart(): array
    {
        $groups = [];

        foreach ($this->starts as $constructionId => $start) {
            $groups[$start->toDateString()][] = (int) $constructionId;
        }

        return $groups;
    }
}

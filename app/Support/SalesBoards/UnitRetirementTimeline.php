<?php

declare(strict_types=1);

namespace App\Support\SalesBoards;

use App\Models\ConstructionUnitRetirement;
use App\Support\Contracts\ContractOccupancyPeriod;
use Carbon\CarbonImmutable;

/**
 * Os períodos de baixa das unidades, para quem vai ocupar uma delas.
 *
 * A ocupação -- o contrato `[venda, distrato)`, a permuta `[início, ∞)` -- não
 * pode cruzar um período em que a unidade está baixada: nele a unidade não
 * compõe o Quadro, e uma venda ali sumiria do número sem achado nenhum. É a
 * defesa na entrada; a derivação continua sendo a última, com o bloqueador da
 * unidade baixada ocupada.
 *
 * As datas são comparadas como `Y-m-d`, com sentinela para o fim aberto, como
 * em {@see ContractOccupancyPeriod}. A baixa anulada (reativada na própria
 * data) tem período vazio e nunca é conflito.
 *
 * Uma consulta para todas as unidades pedidas, feita na construção.
 */
final class UnitRetirementTimeline
{
    /**
     * Mais tarde que qualquer data que o sistema guarda: o período aberto
     * compara como infinito sem caso especial.
     */
    private const OPEN_ENDED = '9999-12-31';

    /**
     * @param  array<int, list<array{retired_on: string, reactivated_on: string|null}>>  $periodsByUnit
     */
    private function __construct(private readonly array $periodsByUnit) {}

    /**
     * @param  iterable<int|string|null>  $unitIds
     */
    public static function forUnits(iterable $unitIds): self
    {
        $ids = collect($unitIds)
            ->filter(fn (mixed $id): bool => filled($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return new self([]);
        }

        $periods = [];

        ConstructionUnitRetirement::query()
            ->toBase()
            ->whereIn('construction_unit_id', $ids)
            ->orderBy('retired_on')
            ->orderBy('id')
            ->get(['construction_unit_id', 'retired_on', 'reactivated_on'])
            ->each(function (object $row) use (&$periods): void {
                $periods[(int) $row->construction_unit_id][] = [
                    'retired_on' => self::day($row->retired_on),
                    'reactivated_on' => $row->reactivated_on === null ? null : self::day($row->reactivated_on),
                ];
            });

        return new self($periods);
    }

    /**
     * O primeiro período de baixa da unidade que cruza a ocupação
     * `[startsOn, endsOn)` -- `endsOn` nulo é ocupação sem fim --, ou `null`.
     */
    public function conflictWith(int $unitId, string $startsOn, ?string $endsOn): ?UnitRetirementConflict
    {
        $start = self::day($startsOn);
        $end = $endsOn === null ? self::OPEN_ENDED : self::day($endsOn);

        if ($end <= $start) {
            return null;
        }

        foreach ($this->periodsByUnit[$unitId] ?? [] as $period) {
            $retiredOn = $period['retired_on'];
            $reactivatedOn = $period['reactivated_on'] ?? self::OPEN_ENDED;

            if ($reactivatedOn <= $retiredOn) {
                continue;
            }

            if (($retiredOn < $end) && ($start < $reactivatedOn)) {
                return new UnitRetirementConflict(
                    retiredOn: CarbonImmutable::parse($retiredOn),
                    reactivatedOn: $period['reactivated_on'] === null ? null : CarbonImmutable::parse($period['reactivated_on']),
                );
            }
        }

        return null;
    }

    /**
     * O dia de uma coluna `date` lida sem model: o SQLite devolve o que o
     * Eloquent gravou, com hora; o MySQL, só o dia.
     */
    private static function day(mixed $value): string
    {
        return substr((string) $value, 0, 10);
    }
}

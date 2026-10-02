<?php

declare(strict_types=1);

namespace App\Support\SalesBoards;

use App\DTOs\SalesBoards\SalesBoardDerivedLine;
use App\DTOs\SalesBoards\SalesBoardDerivedPosition;
use App\DTOs\SalesBoards\SalesBoardIssue;
use App\DTOs\SalesBoards\SalesBoardSnapshotLine;
use App\Models\SalesBoardCycleBaseline;
use App\Models\SalesBoardRolloutHomologationConstruction;
use App\Services\SalesBoards\SalesBoardBaselineWriter;

/**
 * Os avisos da apuração na forma em que são congelados: com a versão do ciclo
 * ({@see SalesBoardCycleBaseline::$warnings}, gravada pelo
 * {@see SalesBoardBaselineWriter}) e com cada empreendimento da homologação
 * ({@see SalesBoardRolloutHomologationConstruction::$warnings}).
 *
 * Congelar é preciso porque vários avisos não se reconstroem a partir das linhas
 * e dos movimentos: dependem do status de hoje e do relógio de negócio. A forma
 * é canônica -- lista de `{code, message, construction_unit_id, unit_label,
 * contract_id, contract_code}`, só escalares, ordenada por código, unidade (na
 * ordem natural de {@see UnitDisplayOrder}), contrato e mensagem --, para que a
 * mesma apuração produza sempre o mesmo JSON. `unit_label` sai no formato
 * "bloco / unidade" de {@see SalesBoardSnapshotLine::displayName()}, resolvido
 * pelas linhas da própria posição: depois de congelado, o rótulo não depende do
 * cadastro de unidades.
 *
 * Fica fora dos fingerprints e do resumo da homologação: aviso não decide nada.
 */
final class SalesBoardFrozenWarnings
{
    /**
     * @return list<array{code: string, message: string, construction_unit_id: int|null, unit_label: string|null, contract_id: int|null, contract_code: string|null}>
     */
    public static function fromPosition(SalesBoardDerivedPosition $position): array
    {
        /** @var array<int, SalesBoardDerivedLine> $linesByUnit */
        $linesByUnit = [];

        foreach ($position->lines as $line) {
            $linesByUnit[$line->constructionUnitId] = $line;
        }

        $warnings = array_map(
            static function (SalesBoardIssue $issue) use ($linesByUnit): array {
                $line = $issue->constructionUnitId === null ? null : ($linesByUnit[$issue->constructionUnitId] ?? null);

                return [
                    'code' => $issue->code->value,
                    'message' => $issue->message,
                    'construction_unit_id' => $issue->constructionUnitId,
                    'unit_label' => $line === null ? null : self::unitLabel($line->block, $line->unit),
                    'contract_id' => $issue->contractId,
                    'contract_code' => $issue->contractCode,
                    '_block' => $line?->block,
                    '_unit' => $line?->unit,
                ];
            },
            $position->warnings(),
        );

        usort($warnings, static fn (array $left, array $right): int => [$left['code']] <=> [$right['code']]
            ?: self::compareUnits($left, $right)
            ?: [(string) $left['contract_code'], (int) $left['contract_id'], $left['message']]
                <=> [(string) $right['contract_code'], (int) $right['contract_id'], $right['message']]);

        return array_map(
            static fn (array $warning): array => array_diff_key($warning, ['_block' => true, '_unit' => true]),
            $warnings,
        );
    }

    /**
     * Quantos avisos de cada código, do mais frequente para o menos -- a forma
     * de `warningCounts()` da prontidão, para a mesma apresentação. `null` (versão
     * congelada antes do registro) não tem contagem.
     *
     * @param  list<array<string, mixed>>|null  $frozen
     * @return array<string, int>
     */
    public static function countsByCode(?array $frozen): array
    {
        $counts = [];

        foreach ($frozen ?? [] as $warning) {
            $code = (string) ($warning['code'] ?? '');

            if ($code !== '') {
                $counts[$code] = ($counts[$code] ?? 0) + 1;
            }
        }

        uksort($counts, static fn (string $left, string $right): int => [$counts[$right], $left] <=> [$counts[$left], $right]);

        return $counts;
    }

    /**
     * O rótulo "bloco / unidade", como a tela do ciclo mostra a linha congelada.
     */
    public static function unitLabel(?string $block, ?string $unit): string
    {
        return trim(sprintf('%s / %s', (string) $block, (string) $unit), ' /');
    }

    /**
     * Avisos sem unidade vão para o fim do código.
     *
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private static function compareUnits(array $left, array $right): int
    {
        $leftHasUnit = $left['construction_unit_id'] !== null;
        $rightHasUnit = $right['construction_unit_id'] !== null;

        if ($leftHasUnit !== $rightHasUnit) {
            return $leftHasUnit ? -1 : 1;
        }

        return UnitDisplayOrder::compare($left['_block'], $left['_unit'], $right['_block'], $right['_unit'])
            ?: ((int) $left['construction_unit_id'] <=> (int) $right['construction_unit_id']);
    }
}

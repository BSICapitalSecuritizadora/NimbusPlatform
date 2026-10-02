<?php

declare(strict_types=1);

namespace App\Support\SalesBoards;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * A ordem em que as unidades aparecem para quem confere o Quadro.
 *
 * A ordem alfabética do banco põe "1010" entre "101" e "102", e "Torre 10"
 * antes de "Torre 2" -- o padrão real das obras. A regra aqui é a ordem
 * natural aproximada: comprimento do bloco, bloco, comprimento da unidade,
 * unidade e, para desempatar, a unidade cadastrada. Uma regra só, a mesma em
 * PHP ({@see self::compare()}, nas seções da Validação) e em SQL
 * ({@see self::applyTo()}, na aba Unidades do ciclo), nos dois bancos.
 *
 * É aproximada de propósito: "101A" fica depois de "1010", porque é mais
 * longa. Uma ordem natural completa exigiria quebrar o texto em números e
 * letras dos dois lados, e as duas telas divergiriam na primeira diferença
 * entre o PHP e o SQL. Diferença só de caixa pode ordenar diferente entre o
 * MySQL (collation sem caixa) e o SQLite.
 *
 * Só exibição: snapshot, fingerprint e a ordem do relacionamento das linhas
 * não mudam.
 */
final class UnitDisplayOrder
{
    public static function compare(?string $blockA, ?string $unitA, ?string $blockB, ?string $unitB): int
    {
        return self::compareText($blockA, $blockB) ?: self::compareText($unitA, $unitB);
    }

    public static function applyTo(
        Builder $query,
        string $direction = 'asc',
        string $blockColumn = 'block',
        string $unitColumn = 'unit',
    ): Builder {
        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';

        $length = in_array($query->getConnection()->getDriverName(), ['mysql', 'mariadb'], true)
            ? 'CHAR_LENGTH'
            : 'LENGTH';

        $grammar = $query->getQuery()->getGrammar();
        $block = $grammar->wrap($query->qualifyColumn($blockColumn));
        $unit = $grammar->wrap($query->qualifyColumn($unitColumn));

        return $query
            ->orderByRaw("{$length}(COALESCE({$block}, '')) {$direction}")
            ->orderByRaw("COALESCE({$block}, '') {$direction}")
            ->orderByRaw("{$length}(COALESCE({$unit}, '')) {$direction}")
            ->orderByRaw("COALESCE({$unit}, '') {$direction}")
            ->orderBy($query->qualifyColumn('construction_unit_id'), $direction);
    }

    private static function compareText(?string $left, ?string $right): int
    {
        $left = (string) $left;
        $right = (string) $right;

        return [mb_strlen($left), self::normalize($left)] <=> [mb_strlen($right), self::normalize($right)];
    }

    private static function normalize(string $value): string
    {
        return Str::lower(Str::ascii($value));
    }
}

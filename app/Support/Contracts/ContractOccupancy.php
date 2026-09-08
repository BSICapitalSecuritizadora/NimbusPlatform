<?php

declare(strict_types=1);

namespace App\Support\Contracts;

use App\Models\Contract;
use App\Support\Dates\InclusiveDateBound;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Se um contrato segurava a unidade numa data.
 *
 * A regra é `[sale_date, cancellation_date)` -- a mesma que
 * {@see ContractOccupancyPeriod} já usa para detectar sobreposição. Aqui ela
 * ganha a forma pontual que a derivação precisa: "neste dia, quem estava com
 * esta unidade?".
 *
 * Deliberadamente **não** consulta `Contract.status`. O status diz o que vale
 * hoje; a derivação precisa saber o que valia em 31/07/2026, e um contrato hoje
 * distratado ocupava a unidade normalmente antes do distrato. Ler o status aqui
 * faria toda competência passada responder com a foto de hoje.
 *
 * Meio aberto no fim, de propósito: o distrato de D libera a unidade em D, e a
 * venda que acontece em D já ocupa. É o que faz uma revenda no mesmo dia contar
 * como uma unidade, nunca duas.
 */
final class ContractOccupancy
{
    /**
     * Decidido em PHP sobre um contrato já carregado.
     */
    public static function occupiesAt(Contract $contract, CarbonInterface $date): bool
    {
        if ($contract->deleted_at !== null) {
            return false;
        }

        $day = $date->toDateString();
        $saleDate = $contract->sale_date?->toDateString();

        if (($saleDate === null) || ($saleDate > $day)) {
            return false;
        }

        $cancellationDate = $contract->cancellation_date?->toDateString();

        return ($cancellationDate === null) || ($cancellationDate > $day);
    }

    /**
     * A mesma regra em SQL, para quem precisa filtrar antes de carregar.
     *
     * O limite superior vem de {@see InclusiveDateBound} pelo motivo descrito
     * lá: coluna `date` no SQLite compara como texto com a hora junto.
     *
     * @param  Builder<Contract>  $query
     */
    public static function scopeOccupyingOn(Builder $query, CarbonInterface $date): void
    {
        $bound = InclusiveDateBound::upperBound($date);

        $query
            ->where('sale_date', '<=', $bound)
            ->where(function (Builder $query) use ($bound): void {
                $query->whereNull('cancellation_date')->orWhere('cancellation_date', '>', $bound);
            });
    }
}

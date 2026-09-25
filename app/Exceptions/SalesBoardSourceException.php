<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Support\Arr;
use RuntimeException;

/**
 * Recusas de escrita numa fonte do Quadro de Vendas que já foi lida.
 *
 * A tela já trava esses caminhos -- o campo fica desabilitado, a exclusão não
 * é oferecida. Estas são as recusas do model, para quem escreve por outro
 * caminho: comando, importação, tinker.
 */
class SalesBoardSourceException extends RuntimeException implements ShouldntReport
{
    /**
     * @param  list<string>  $reasons
     */
    public static function unitConstructionLocked(array $reasons): self
    {
        return new self('A unidade não pode trocar de empreendimento: '.self::joinReasons($reasons).'.');
    }

    /**
     * @param  list<string>  $reasons
     */
    public static function constructionEmissionLocked(array $reasons): self
    {
        return new self('A obra não pode trocar de emissão: '.self::joinReasons($reasons).'.');
    }

    /**
     * @param  list<string>  $reasons
     */
    public static function constructionDeletionBlocked(array $reasons): self
    {
        return new self('A obra não pode ser excluída: '.self::joinReasons($reasons).'.');
    }

    /**
     * "a, b e c" -- os motivos como uma frase só.
     *
     * @param  list<string>  $reasons
     */
    public static function joinReasons(array $reasons): string
    {
        return Arr::join($reasons, ', ', ' e ');
    }
}

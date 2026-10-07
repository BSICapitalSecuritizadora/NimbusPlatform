<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Contracts;

use App\Domain\PuCalculator\DTOs\IndexRateData;
use App\Domain\PuCalculator\Enums\PuIndexer;
use Carbon\CarbonImmutable;

/**
 * Observações REALIZADAS de índice: só taxa efetivamente divulgada (ou lançada
 * como fixing histórico verificado) responde aqui -- uma linha projetada nunca.
 *
 * É a única porta que o cálculo realizado do CDI usa. Não existe busca para
 * trás nesta interface: quem pergunta já sabe, pelo calendário e pela regra
 * contratual, QUAL data de observação precisa, e recebe a observação exata ou
 * nada. "A última taxa conhecida" não é uma resposta válida para uma data cuja
 * taxa ainda não existe.
 */
interface RealizedIndexRateProvider
{
    /**
     * A observação realizada exatamente nesta data, ou nula.
     */
    public function realizedRateForDate(PuIndexer $indexer, CarbonImmutable $date): ?IndexRateData;

    /**
     * Data da observação realizada mais recente do indexador -- a fronteira do
     * que já foi divulgado. Separa "ainda não publicado" de "buraco no histórico".
     */
    public function latestRealizedRateDate(PuIndexer $indexer): ?CarbonImmutable;
}

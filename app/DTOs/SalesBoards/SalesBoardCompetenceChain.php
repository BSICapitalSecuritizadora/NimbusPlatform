<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;
use App\Services\SalesBoards\SalesBoardPriorPositionResolver;
use Carbon\CarbonImmutable;

/**
 * O que vem antes de uma competência, por empreendimento: a âncora e as
 * competências canceladas cujos fatos ela absorve ({@see SalesBoardPriorPositionResolver}).
 *
 * Com âncora, os meses cancelados entre ela e a competência ficam na própria
 * âncora ({@see SalesBoardPriorPosition::$skippedCancelledMonths}), e a janela
 * dos movimentos começa no dia seguinte ao fim dela.
 *
 * Sem âncora -- a cadeia de canceladas termina num mês sem ciclo, como na
 * primeira competência automatizada cancelada --, os meses cancelados ficam em
 * `unanchoredCancelledMonths`: a janela começa no primeiro dia do mais antigo
 * deles, e os fatos desses meses entram com o timing "de competência sem
 * posição". Não há comparação de extemporâneos: não existe posição congelada
 * contra a qual comparar, e é isso que mantém a ponte, o portão de ordem e a
 * versão anterior registrada na versão como "sem âncora".
 */
readonly class SalesBoardCompetenceChain extends BaseDTO
{
    /**
     * @param  array<int, SalesBoardPriorPosition>  $anchors  indexado por `construction_id`, só as obras com âncora
     * @param  array<int, list<CarbonImmutable>>  $unanchoredCancelledMonths  indexado por `construction_id`, do mês mais recente para o mais antigo
     */
    public function __construct(
        public array $anchors,
        public array $unanchoredCancelledMonths,
    ) {}

    public static function empty(): self
    {
        return new self([], []);
    }

    public function anchorOf(int $constructionId): ?SalesBoardPriorPosition
    {
        return $this->anchors[$constructionId] ?? null;
    }

    /**
     * As competências canceladas que a competência absorve sem âncora, do mês
     * mais recente para o mais antigo -- vazio quando há âncora.
     *
     * @return list<CarbonImmutable>
     */
    public function unanchoredCancelledMonthsOf(int $constructionId): array
    {
        return $this->unanchoredCancelledMonths[$constructionId] ?? [];
    }

    /**
     * O primeiro dia da janela dos movimentos da competência: o dia seguinte ao
     * fim da âncora; sem âncora, o primeiro dia da competência cancelada mais
     * antiga que ela absorve; e o primeiro dia do mês quando não há nada antes.
     * Com a âncora em M-1 a janela é exatamente o mês.
     */
    public function windowStart(int $constructionId, CarbonImmutable $month): CarbonImmutable
    {
        $anchor = $this->anchorOf($constructionId);

        if ($anchor !== null) {
            $start = $anchor->positionDate->addDay()->startOfDay();

            return $start->greaterThan($month) ? $month : $start;
        }

        $absorbed = $this->unanchoredCancelledMonthsOf($constructionId);

        return $absorbed === [] ? $month : $absorbed[count($absorbed) - 1]->startOfMonth();
    }
}

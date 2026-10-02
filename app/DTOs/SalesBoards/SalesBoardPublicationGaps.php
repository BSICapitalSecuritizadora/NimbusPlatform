<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;
use App\Models\SalesBoardCycle;
use App\Services\SalesBoards\SalesBoardPublicationGapClassifier;

/**
 * Por que um empreendimento ficou sem o quadro publicado da competência, quando
 * a explicação é o ciclo mensal.
 *
 * Dois casos, que os consumidores mostram de jeitos diferentes:
 *
 * - `awaitingPublication`: o ciclo do mês existe (ou ainda vai existir) e não
 *   foi publicado -- a posição vai chegar;
 * - `cancelled`: a Gestão cancelou a competência e nenhum ciclo do mês está
 *   aberto -- a posição não vai chegar por ali, e dizer "ainda não publicada"
 *   seria prometer o que não vem.
 *
 * Quem classifica é o {@see SalesBoardPublicationGapClassifier}.
 */
readonly class SalesBoardPublicationGaps extends BaseDTO
{
    /**
     * @param  list<ConstructionSalesPosition>  $awaitingPublication
     * @param  list<array{position: ConstructionSalesPosition, cycle: SalesBoardCycle}>  $cancelled
     */
    public function __construct(
        public array $awaitingPublication = [],
        public array $cancelled = [],
    ) {}

    public static function none(): self
    {
        return new self;
    }

    public function isEmpty(): bool
    {
        return ($this->awaitingPublication === []) && ($this->cancelled === []);
    }
}

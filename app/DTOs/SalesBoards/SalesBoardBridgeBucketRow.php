<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;
use App\Enums\SalesBoardUnitClassification;

/**
 * Um balde da "Ponte com a competência anterior": de onde ele partiu, o que
 * entrou e saiu com explicação, o que mudou sem explicação e onde chegou.
 *
 * Em unidades, a ponte fecha por construção: anterior + entradas − saídas + sem
 * explicação = atual. Em valor, o que não é unidade entrando ou saindo é
 * reavaliação -- a tabela da unidade em estoque que mudou, a venda revista.
 *
 * Contra um quadro manual da competência anterior só existem os totais: as
 * colunas por movimento ficam nulas, e sobra a diferença.
 */
readonly class SalesBoardBridgeBucketRow extends BaseDTO
{
    public function __construct(
        public SalesBoardUnitClassification $classification,
        public int $previousUnits,
        public ?int $entries,
        public ?int $exits,
        public ?int $unexplained,
        public int $currentUnits,
        public ?int $previousValueCents,
        public ?int $currentValueCents,
        public ?int $revaluationCents,
    ) {}

    public function label(): string
    {
        return $this->classification->label();
    }

    public function unitsDifference(): int
    {
        return $this->currentUnits - $this->previousUnits;
    }

    public function valueDifferenceCents(): ?int
    {
        return ($this->currentValueCents === null) || ($this->previousValueCents === null)
            ? null
            : $this->currentValueCents - $this->previousValueCents;
    }

    /**
     * A conta fecha em unidades. Sem as colunas por movimento (quadro manual),
     * não há conta a fechar.
     */
    public function balances(): bool
    {
        if (($this->entries === null) || ($this->exits === null) || ($this->unexplained === null)) {
            return true;
        }

        return ($this->previousUnits + $this->entries - $this->exits + $this->unexplained) === $this->currentUnits;
    }
}

<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;
use App\Services\SalesBoards\SalesBoardCompetenceBridgeBuilder;

/**
 * A "Ponte com a competência anterior": como a posição congelada da anterior
 * chega à desta versão, unidade a unidade, pelos movimentos congelados
 * ({@see SalesBoardCompetenceBridgeBuilder}).
 *
 * Três formas de âncora: as linhas da versão vigente da anterior (`linhas`), o
 * quadro manual da anterior (`quadro_manual`, só totais e diferença) e nenhuma
 * (`nenhuma`, primeira competência do ciclo mensal -- a seção não aparece).
 */
readonly class SalesBoardCompetenceBridge extends BaseDTO
{
    public const ANCHOR_LINES = 'linhas';

    public const ANCHOR_MANUAL = 'quadro_manual';

    public const ANCHOR_NONE = 'nenhuma';

    /**
     * @param  list<SalesBoardBridgeBucketRow>  $buckets
     * @param  list<SalesBoardBridgeUnitRow>  $unexplainedUnits  na ordem natural das unidades
     * @param  array<string, int>  $explanations  quantas unidades cada explicação moveu ("Vendas", "Inclusões"...)
     */
    public function __construct(
        public string $anchorKind,
        public ?string $previousLabel,
        public ?string $previousVersionLabel,
        public bool $previousPublished,
        public array $buckets,
        public array $unexplainedUnits,
        public int $lateMovementsCount,
        public bool $anchorChangedSinceVersion,
        public array $explanations = [],
    ) {}

    public static function none(int $lateMovementsCount = 0): self
    {
        return new self(
            anchorKind: self::ANCHOR_NONE,
            previousLabel: null,
            previousVersionLabel: null,
            previousPublished: false,
            buckets: [],
            unexplainedUnits: [],
            lateMovementsCount: $lateMovementsCount,
            anchorChangedSinceVersion: false,
        );
    }

    public function hasAnchor(): bool
    {
        return $this->anchorKind !== self::ANCHOR_NONE;
    }

    public function isManualAnchor(): bool
    {
        return $this->anchorKind === self::ANCHOR_MANUAL;
    }

    public function unexplainedCount(): int
    {
        return count($this->unexplainedUnits);
    }

    /**
     * A frase do item informativo do portão da Análise.
     */
    public function summary(): string
    {
        if ($this->isManualAnchor()) {
            return sprintf('Competência anterior (%s) registrada manualmente: sem conciliação por unidade.', (string) $this->previousLabel);
        }

        $sentence = sprintf('%d unidade(s) sem movimento que explique', $this->unexplainedCount());

        return $this->anchorChangedSinceVersion
            ? $sentence.'; a competência anterior foi retificada depois desta versão.'
            : $sentence.'.';
    }
}

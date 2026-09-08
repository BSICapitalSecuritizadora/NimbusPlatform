<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;
use App\Enums\SalesBoardBuilderReviewSection as SectionEnum;
use App\Enums\SalesBoardBuilderReviewSectionStatus;
use App\Support\Money\IntegerMoney;

/**
 * Uma das sete seções, com o que a construtora precisa ver para responder.
 *
 * @template TRow of SalesBoardBuilderWorkspaceUnitRow|SalesBoardBuilderWorkspaceMovementRow
 */
readonly class SalesBoardBuilderWorkspaceSection extends BaseDTO
{
    /**
     * @param  list<SalesBoardBuilderWorkspaceUnitRow|SalesBoardBuilderWorkspaceMovementRow>  $rows
     * @param  list<SalesBoardBuilderDivergence>  $divergences
     */
    public function __construct(
        public int $sectionId,
        public SectionEnum $section,
        public SalesBoardBuilderReviewSectionStatus $status,
        public ?string $comment,
        public array $rows,
        public int $divergenceCount,
        public ?int $totalValueCents = null,
    ) {}

    public function count(): int
    {
        return count($this->rows);
    }

    public function isPosition(): bool
    {
        return $this->section->isPosition();
    }

    /**
     * Um resumo de uma linha para o cabeçalho da seção.
     */
    public function headline(): string
    {
        if ($this->isPosition()) {
            return sprintf(
                '%d unidade(s)%s',
                $this->count(),
                $this->totalValueCents === null ? '' : ' · R$ '.IntegerMoney::format($this->totalValueCents),
            );
        }

        return sprintf('%d lançamento(s) na competência', $this->count());
    }
}

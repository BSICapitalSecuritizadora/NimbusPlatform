<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;
use App\Enums\SalesBoardMovementType;
use App\Models\SalesBoardCycleMovement;
use App\Support\Money\IntegerMoney;
use Carbon\CarbonImmutable;

/**
 * Uma movimentação do mês como a construtora a enxerga.
 *
 * A venda aparece com os fatos comerciais -- contrato, unidade, data, valor -- e
 * nada além. O desconto máximo autorizado, o preço mínimo e o veredito de
 * conformidade são política comercial interna da BSI: continuam congelados no
 * snapshot e visíveis na tela administrativa, mas não são o que se pede à
 * construtora que confirme. Ela confirma o que vendeu; se aquilo respeitou a
 * política é uma avaliação interna, e expô-la aqui entregaria o limite de
 * negociação a quem negocia.
 *
 * A quitação não traz data porque o motor não a apura -- ele prova a transição
 * dentro do mês, não o dia. Inventar uma data para preencher a coluna seria
 * pedir à construtora que confirmasse um fato que o Nimbus não afirmou.
 */
readonly class SalesBoardBuilderWorkspaceMovementRow extends BaseDTO
{
    public function __construct(
        public int $movementId,
        public SalesBoardMovementType $type,
        public ?string $block,
        public ?string $unit,
        public ?string $contractCode,
        public ?CarbonImmutable $eventDate,
        public ?int $saleValueCents,
        public ?int $settlementInstallmentsTotal,
    ) {}

    public static function fromMovement(SalesBoardCycleMovement $movement): self
    {
        return new self(
            movementId: (int) $movement->getKey(),
            type: $movement->movement_type,
            block: $movement->block,
            unit: $movement->unit,
            contractCode: $movement->contract_code,
            eventDate: $movement->event_date === null
                ? null
                : CarbonImmutable::parse($movement->event_date->toDateString()),
            saleValueCents: IntegerMoney::cents($movement->sale_value),
            settlementInstallmentsTotal: $movement->settlement_installments_total,
        );
    }

    public function displayName(): string
    {
        $label = trim(sprintf('%s / %s', (string) $this->block, (string) $this->unit), ' /');

        return $label === '' ? '—' : $label;
    }
}

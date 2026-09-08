<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;
use App\Support\Money\IntegerMoney;
use Carbon\CarbonImmutable;

/**
 * Exatamente o que será escrito no Quadro de Vendas legado.
 *
 * Existe para que o mapeamento entre o vocabulário da apuração e o da tabela
 * legada aconteça num lugar só, verificável, e não espalhado por um serviço, uma
 * tela de confirmação e uma prévia -- que é como três versões do mesmo número
 * nascem e divergem.
 *
 * Todo dinheiro é inteiro em centavos até o último instante. A conversão para o
 * `decimal(15,2)` da coluna acontece em {@see self::toSalesBoardAttributes()},
 * via string decimal: nenhum `float` participa do caminho, e o centavo apurado é
 * o centavo persistido.
 *
 * `settled` vira `paid`: é o único ponto do sistema em que a tradução acontece,
 * e ela é deliberada. A coluna legada se chama "pago"; o que a V2 apura é a
 * quitação do contrato. São o mesmo balde com nomes diferentes, e o lugar de
 * dizer isso é aqui.
 */
readonly class SalesBoardPublicationPayload extends BaseDTO
{
    public function __construct(
        public int $emissionId,
        public int $constructionId,
        public ?string $constructionName,
        public CarbonImmutable $referenceMonth,
        public int $stockUnits,
        public int $stockValueCents,
        public int $financedUnits,
        public int $financedValueCents,
        public int $paidUnits,
        public int $paidValueCents,
        public int $exchangedUnits,
        public int $exchangedValueCents,
        public int $totalUnits,
    ) {}

    /**
     * Os atributos do `SalesBoard`, prontos para persistir.
     *
     * @return array<string, int|string>
     */
    public function toSalesBoardAttributes(): array
    {
        return [
            'emission_id' => $this->emissionId,
            'construction_id' => $this->constructionId,
            'reference_month' => $this->referenceMonth->toDateString(),
            'stock_units' => $this->stockUnits,
            'financed_units' => $this->financedUnits,
            'paid_units' => $this->paidUnits,
            'exchanged_units' => $this->exchangedUnits,
            'total_units' => $this->totalUnits,
            'stock_value' => IntegerMoney::decimalString($this->stockValueCents),
            'financed_value' => IntegerMoney::decimalString($this->financedValueCents),
            'paid_value' => IntegerMoney::decimalString($this->paidValueCents),
            'exchanged_value' => IntegerMoney::decimalString($this->exchangedValueCents),
        ];
    }

    public function totalValueCents(): int
    {
        return $this->stockValueCents
            + $this->financedValueCents
            + $this->paidValueCents
            + $this->exchangedValueCents;
    }

    /**
     * As quatro linhas da prévia de publicação, na ordem do quadro.
     *
     * @return list<array{label: string, units: int, valueCents: int}>
     */
    public function buckets(): array
    {
        return [
            ['label' => 'Estoque', 'units' => $this->stockUnits, 'valueCents' => $this->stockValueCents],
            ['label' => 'Financiado', 'units' => $this->financedUnits, 'valueCents' => $this->financedValueCents],
            ['label' => 'Quitado', 'units' => $this->paidUnits, 'valueCents' => $this->paidValueCents],
            ['label' => 'Permutado', 'units' => $this->exchangedUnits, 'valueCents' => $this->exchangedValueCents],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'emission_id' => $this->emissionId,
            'construction_id' => $this->constructionId,
            'construction' => $this->constructionName,
            'reference_month' => $this->referenceMonth->format('m/Y'),
            'stock_units' => $this->stockUnits,
            'stock_value' => IntegerMoney::decimalString($this->stockValueCents),
            'financed_units' => $this->financedUnits,
            'financed_value' => IntegerMoney::decimalString($this->financedValueCents),
            'paid_units' => $this->paidUnits,
            'paid_value' => IntegerMoney::decimalString($this->paidValueCents),
            'exchanged_units' => $this->exchangedUnits,
            'exchanged_value' => IntegerMoney::decimalString($this->exchangedValueCents),
            'total_units' => $this->totalUnits,
        ];
    }
}

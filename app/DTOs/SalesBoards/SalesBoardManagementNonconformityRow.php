<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;
use App\Enums\SalesBoardNonconformityDecision;
use App\Enums\SalesBoardNonconformityOrigin;
use App\Enums\SalesPriceConformityStatus;
use App\Support\Money\IntegerMoney;
use Carbon\CarbonImmutable;

/**
 * Uma pendência como a Gestão a lê, com o fato ao lado da decisão.
 *
 * Os dois lados vêm de lugares diferentes de propósito. O fato é congelado --
 * veio da declaração da construtora ou do movimento do baseline -- e a decisão é
 * viva enquanto a análise é rascunho. Achatá-los na mesma origem faria a tela
 * perder a distinção que a fase inteira existe para preservar.
 *
 * Para uma declaração da construtora, `systemStatement` e `builderStatement` são
 * o confronto: o que o Nimbus apurou contra o que a construtora afirma. Para uma
 * venda fora da política, `builderStatement` é nulo -- não há declaração, há um
 * cálculo.
 */
readonly class SalesBoardManagementNonconformityRow extends BaseDTO
{
    /**
     * @param  list<array{label: string, value: string}>  $facts  os números congelados
     *                                                            que sustentam o
     *                                                            apontamento
     */
    public function __construct(
        public int $id,
        public SalesBoardNonconformityOrigin $origin,
        public string $typeLabel,
        public ?string $unitLabel,
        public ?string $contractCode,
        public ?string $systemStatement,
        public ?string $builderStatement,
        public ?string $builderReason,
        public array $facts,
        public SalesBoardNonconformityDecision $decision,
        public ?string $decisionReason,
        public ?string $decidedByName,
        public ?CarbonImmutable $decidedAt,
    ) {}

    /**
     * As conclusões que esta pendência admite.
     *
     * @return list<SalesBoardNonconformityDecision>
     */
    public function allowedDecisions(): array
    {
        return array_values(array_filter(
            SalesBoardNonconformityDecision::allowedFor($this->origin),
            fn (SalesBoardNonconformityDecision $decision): bool => ! $decision->isPending(),
        ));
    }

    /**
     * O aviso funcional que a linha precisa exibir, quando precisa de um.
     *
     * Só a conformidade indeterminada tem: as outras origens se explicam pelos
     * próprios números, e um callout em toda linha viraria ruído que ninguém lê
     * -- justamente na tela em que ele precisa ser lido.
     */
    public function callout(): ?string
    {
        return $this->origin === SalesBoardNonconformityOrigin::SystemSaleUndetermined
            ? 'O Nimbus não conseguiu determinar a conformidade desta venda. '
                .'A fonte precisa ser regularizada antes da publicação: não existe limite conhecido contra o qual uma exceção pudesse ser concedida.'
            : null;
    }

    public function isPending(): bool
    {
        return $this->decision->isPending();
    }

    public function blocksApproval(): bool
    {
        return $this->decision->blocksApproval();
    }

    /**
     * A chave pela qual a tela pode agrupar visualmente duas origens que falam
     * do mesmo fato.
     *
     * Agrupar é decisão de apresentação. No domínio elas continuam separadas: a
     * declaração da construtora e o apontamento do Nimbus sobre a mesma venda
     * são dois fatos, com duas decisões possíveis, e fundi-los faria uma das
     * duas desaparecer.
     */
    public function groupKey(): string
    {
        return $this->contractCode ?? $this->unitLabel ?? ('#'.$this->id);
    }

    public static function money(?int $cents): string
    {
        return $cents === null ? '—' : 'R$ '.IntegerMoney::format($cents);
    }

    public static function conformityLabel(?SalesPriceConformityStatus $status): string
    {
        return $status?->label() ?? '—';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'origin' => $this->origin->value,
            'type' => $this->typeLabel,
            'unit' => $this->unitLabel,
            'contract' => $this->contractCode,
            'system' => $this->systemStatement,
            'builder' => $this->builderStatement,
            'decision' => $this->decision->value,
            'decision_reason' => $this->decisionReason,
        ];
    }
}

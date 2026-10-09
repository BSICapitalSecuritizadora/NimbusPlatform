<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

/**
 * Dados corrigidos de uma liquidação: substituem a vigente por um lançamento novo,
 * nunca por edição.
 */
final readonly class PuSettlementCorrectionData
{
    /**
     * @param  array<string, string>|null  $components
     */
    public function __construct(
        public string $settlementDate,
        public string $amount,
        public ?array $components = null,
        public ?string $externalReference = null,
        public string $currency = 'BRL',
    ) {}
}

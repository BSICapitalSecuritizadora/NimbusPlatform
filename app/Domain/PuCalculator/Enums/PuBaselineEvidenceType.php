<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;

enum PuBaselineEvidenceType: string
{
    case FirstIntegralizationDate = 'first_integralization_date';
    case IntegralizedQuantity = 'integralized_quantity';
    case ExternalPuReference = 'external_pu_reference';

    public const EXTERNAL_REFERENCE_STATUSES = ['available_pending_comparison', 'matched', 'divergent'];

    /**
     * Por que o valor não serve a este requisito, ou null se serve.
     *
     * É a mesma regra para o valor digitado e para o valor sugerido pela IA:
     * "validado" na extração significa exatamente "a criação aceitaria".
     */
    public function valueViolation(string $value): ?string
    {
        return match ($this) {
            self::FirstIntegralizationDate => $this->isIsoDate($value)
                ? null
                : 'Informe a data comprovada no formato AAAA-MM-DD.',
            self::IntegralizedQuantity => is_numeric($value) && (float) $value > 0
                ? null
                : 'Informe uma quantidade integralizada maior que zero.',
            self::ExternalPuReference => in_array($value, self::EXTERNAL_REFERENCE_STATUSES, true)
                ? null
                : 'Informe se o gabarito aguarda comparação, foi aderente ou divergiu.',
        };
    }

    /** Só a memória oficial de PU é gabarito; e ela não comprova integralização nem quantidade. */
    public function acceptsDocumentType(PuBaselineEvidenceDocumentType $documentType): bool
    {
        return $this === self::ExternalPuReference
            ? $documentType === PuBaselineEvidenceDocumentType::OfficialPuMemory
            : $documentType !== PuBaselineEvidenceDocumentType::OfficialPuMemory;
    }

    public function label(): string
    {
        return match ($this) {
            self::FirstIntegralizationDate => 'Data da primeira integralização',
            self::IntegralizedQuantity => 'Quantidade efetivamente integralizada',
            self::ExternalPuReference => 'Gabarito externo independente de PU',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $type): array => [$type->value => $type->label()])
            ->all();
    }

    /**
     * O Carbon lança exceção para texto que nem parece data ("15/08/2026"), e
     * antes isso virava erro 500 no formulário em vez de mensagem de validação.
     */
    private function isIsoDate(string $value): bool
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (InvalidFormatException) {
            return false;
        }

        return $date !== null && $date->toDateString() === $value;
    }
}

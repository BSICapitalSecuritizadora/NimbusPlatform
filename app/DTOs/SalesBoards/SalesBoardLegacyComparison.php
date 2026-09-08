<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;
use App\Support\Money\IntegerMoney;

/**
 * Derivado contra publicado, campo a campo.
 *
 * O quadro digitado é referência de homologação, não oráculo: uma divergência
 * abre investigação, não invalida a derivação. Por isso nada aqui é bloqueador,
 * e por isso a ausência de quadro publicado é um estado próprio -- comparar
 * contra zero afirmaria que a posição publicada era zero, que é diferente de não
 * existir posição publicada.
 */
readonly class SalesBoardLegacyComparison extends BaseDTO
{
    public const STATUS_MATCH = 'match';

    public const STATUS_DIFFERENCE = 'difference';

    public const STATUS_NO_LEGACY_POSITION = 'no_legacy_position';

    /**
     * @param  array<string, array{derived: int|string|null, legacy: int|string|null}>  $differences
     */
    public function __construct(
        public string $status,
        public array $differences,
        public ?string $legacyReferenceMonth,
    ) {}

    public static function noLegacyPosition(): self
    {
        return new self(self::STATUS_NO_LEGACY_POSITION, [], null);
    }

    /**
     * @param  array<string, array{derived: int|null, legacy: int|null}>  $rawDifferences
     */
    public static function fromDifferences(array $rawDifferences, ?string $legacyReferenceMonth): self
    {
        $differences = [];

        foreach ($rawDifferences as $field => $pair) {
            if ($pair['derived'] === $pair['legacy']) {
                continue;
            }

            $isMoney = str_ends_with($field, '_value');

            $differences[$field] = [
                'derived' => self::present($pair['derived'], $isMoney),
                'legacy' => self::present($pair['legacy'], $isMoney),
            ];
        }

        return new self(
            $differences === [] ? self::STATUS_MATCH : self::STATUS_DIFFERENCE,
            $differences,
            $legacyReferenceMonth,
        );
    }

    public function matches(): bool
    {
        return $this->status === self::STATUS_MATCH;
    }

    public function hasLegacyPosition(): bool
    {
        return $this->status !== self::STATUS_NO_LEGACY_POSITION;
    }

    private static function present(?int $value, bool $isMoney): int|string|null
    {
        if ($value === null) {
            return null;
        }

        return $isMoney ? IntegerMoney::format($value) : $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'legacy_reference_month' => $this->legacyReferenceMonth,
            'differences' => $this->differences,
        ];
    }
}

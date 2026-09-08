<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;
use App\Support\Money\IntegerMoney;

/**
 * Quanto um balde se moveu entre duas versões.
 *
 * O delta de valor é `null` quando qualquer um dos lados não tinha valor
 * conhecido. Somar contra um desconhecido produziria um número com cara de
 * apuração que não é apuração nenhuma.
 */
readonly class SalesBoardBucketDelta extends BaseDTO
{
    public function __construct(
        public string $bucket,
        public string $label,
        public int $unitsBefore,
        public int $unitsAfter,
        public ?int $valueCentsBefore,
        public ?int $valueCentsAfter,
    ) {}

    public function unitsDelta(): int
    {
        return $this->unitsAfter - $this->unitsBefore;
    }

    public function valueCentsDelta(): ?int
    {
        if (($this->valueCentsBefore === null) || ($this->valueCentsAfter === null)) {
            return null;
        }

        return $this->valueCentsAfter - $this->valueCentsBefore;
    }

    public function hasChange(): bool
    {
        return ($this->unitsDelta() !== 0) || ($this->valueCentsBefore !== $this->valueCentsAfter);
    }

    public function formattedValueDelta(): string
    {
        $delta = $this->valueCentsDelta();

        if ($delta === null) {
            return 'indisponível';
        }

        return ($delta > 0 ? '+' : '').IntegerMoney::format($delta);
    }

    public function formattedUnitsDelta(): string
    {
        $delta = $this->unitsDelta();

        return ($delta > 0 ? '+' : '').$delta;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'bucket' => $this->bucket,
            'label' => $this->label,
            'units_before' => $this->unitsBefore,
            'units_after' => $this->unitsAfter,
            'units_delta' => $this->unitsDelta(),
            'value_before' => $this->valueCentsBefore === null ? null : IntegerMoney::format($this->valueCentsBefore),
            'value_after' => $this->valueCentsAfter === null ? null : IntegerMoney::format($this->valueCentsAfter),
            'value_delta' => $this->valueCentsDelta() === null ? null : IntegerMoney::format($this->valueCentsDelta()),
        ];
    }
}

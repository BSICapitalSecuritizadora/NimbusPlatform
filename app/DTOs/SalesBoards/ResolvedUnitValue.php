<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;
use App\Enums\ResolvedUnitValueSource;
use App\Support\Money\IntegerMoney;
use Carbon\CarbonImmutable;

/**
 * Quanto valia uma unidade numa data, e de onde essa resposta veio.
 *
 * O valor é guardado em centavos inteiros porque é dele que sai o veredito de
 * conformidade de uma venda; `float` perderia o centavo que separa uma venda
 * dentro de uma fora da política.
 */
readonly class ResolvedUnitValue extends BaseDTO
{
    public function __construct(
        public int $constructionUnitId,
        public CarbonImmutable $positionDate,
        public ResolvedUnitValueSource $source,
        public ?int $valueCents,
        public ?CarbonImmutable $effectiveFrom,
    ) {}

    public static function fromHistory(
        int $constructionUnitId,
        CarbonImmutable $positionDate,
        int $valueCents,
        CarbonImmutable $effectiveFrom,
    ): self {
        return new self(
            constructionUnitId: $constructionUnitId,
            positionDate: $positionDate,
            source: ResolvedUnitValueSource::History,
            valueCents: $valueCents,
            effectiveFrom: $effectiveFrom,
        );
    }

    public static function fromBase(
        int $constructionUnitId,
        CarbonImmutable $positionDate,
        int $valueCents,
        CarbonImmutable $effectiveFrom,
    ): self {
        return new self(
            constructionUnitId: $constructionUnitId,
            positionDate: $positionDate,
            source: ResolvedUnitValueSource::Base,
            valueCents: $valueCents,
            effectiveFrom: $effectiveFrom,
        );
    }

    /**
     * Ausência explícita. `valueCents` fica nulo, nunca zero: a unidade sem
     * valor conhecido na data não vale nada apurado, e vale zero é outra coisa.
     */
    public static function absent(int $constructionUnitId, CarbonImmutable $positionDate): self
    {
        return new self(
            constructionUnitId: $constructionUnitId,
            positionDate: $positionDate,
            source: ResolvedUnitValueSource::Absent,
            valueCents: null,
            effectiveFrom: null,
        );
    }

    public function isAbsent(): bool
    {
        return $this->source === ResolvedUnitValueSource::Absent;
    }

    public function isPresent(): bool
    {
        return ! $this->isAbsent();
    }

    public function formattedValue(): ?string
    {
        return $this->valueCents === null ? null : IntegerMoney::format($this->valueCents);
    }

    public function effectiveFromDate(): ?string
    {
        return $this->effectiveFrom?->toDateString();
    }
}

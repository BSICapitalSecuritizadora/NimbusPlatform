<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

use App\Domain\PuCalculator\Enums\PuCurveChangeImpact;
use App\Domain\PuCalculator\Enums\PuCurveChangeKind;
use Carbon\CarbonImmutable;

/**
 * Uma diferença entre dois retratos de insumos: o que mudou, desde quando muda o
 * cálculo e o impacto disso na versão comparada.
 */
final readonly class PuCurveInputChange
{
    public function __construct(
        public PuCurveChangeKind $kind,
        public string $key,
        public ?CarbonImmutable $affectedFrom,
        public mixed $before,
        public mixed $after,
        public PuCurveChangeImpact $impact,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'category' => $this->kind->category(),
            'key' => $this->key,
            'affected_from' => $this->affectedFrom?->toDateString(),
            'before' => $this->before,
            'after' => $this->after,
            'impact' => $this->impact->value,
        ];
    }
}

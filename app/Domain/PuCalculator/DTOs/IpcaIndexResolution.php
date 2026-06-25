<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

use App\Domain\PuCalculator\Enums\IpcaProjectionPolicy;
use Carbon\CarbonImmutable;

/**
 * Resultado da resolução de um número-índice IPCA para um mês de referência: o valor usado, sua data,
 * se é PUBLICADO ou PROJETADO, a fonte e a política aplicada. Alimenta a memória de cálculo da curva
 * para que toda linha registre, de forma auditável, a origem do índice utilizado.
 */
final readonly class IpcaIndexResolution
{
    public function __construct(
        public CarbonImmutable $referenceDate,
        public string $value,
        public bool $isProjected,
        public IpcaProjectionPolicy $policy,
        public ?string $source = null,
        public ?string $projectionSource = null,
        public ?CarbonImmutable $projectionReferenceDate = null,
    ) {}

    public function type(): string
    {
        return $this->isProjected ? 'projected' : 'published';
    }
}

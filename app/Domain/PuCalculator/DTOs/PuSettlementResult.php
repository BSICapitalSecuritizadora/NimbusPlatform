<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

use App\Domain\PuCalculator\Enums\PuSettlementOutcome;
use App\Models\EmissionPuObligation;
use App\Models\EmissionPuSettlement;
use App\Models\EmissionPuSettlementConflict;

/**
 * O que aconteceu com uma tentativa de registrar, corrigir ou estornar liquidação.
 */
final readonly class PuSettlementResult
{
    public function __construct(
        public PuSettlementOutcome $outcome,
        public ?EmissionPuSettlement $settlement = null,
        public ?EmissionPuSettlementConflict $conflict = null,
        public ?EmissionPuObligation $obligation = null,
        public ?string $reason = null,
    ) {}
}

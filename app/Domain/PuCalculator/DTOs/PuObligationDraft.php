<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

use App\Domain\PuCalculator\Enums\PuObligationCalculationState;
use App\Domain\PuCalculator\Enums\PuObligationLifecycle;
use App\Domain\PuCalculator\Enums\PuObligationType;
use App\Models\EmissionPuObligation;

/**
 * Uma obrigação como o cronograma da curva oficial a descreve agora: identidade,
 * data de pagamento, ciclo de vida e -- quando a curva já chegou à data -- os
 * componentes do valor esperado.
 */
final readonly class PuObligationDraft
{
    /**
     * @param  list<int>  $eventIds
     * @param  list<PuObligationComponentData>  $components
     */
    public function __construct(
        public PuObligationType $type,
        public string $contractualDate,
        public int $sequence,
        public string $dueDate,
        public PuObligationLifecycle $lifecycle,
        public PuObligationCalculationState $state,
        public ?string $stateReason = null,
        public array $components = [],
        public array $eventIds = [],
        public ?int $paymentId = null,
        public ?string $supersessionReason = null,
        public ?string $supersededByKey = null,
    ) {}

    public function key(): string
    {
        return EmissionPuObligation::identityKey($this->type, $this->contractualDate, $this->sequence);
    }

    public function hasCalculation(): bool
    {
        return $this->lifecycle === PuObligationLifecycle::Active
            && in_array($this->state, [PuObligationCalculationState::Calculated, PuObligationCalculationState::Unsupported], true)
            && $this->components !== [];
    }

    /**
     * Total canônico: a soma em 2 casas dos componentes, só quando todos têm valor.
     */
    public function total(): ?string
    {
        $total = '0.00';

        foreach ($this->components as $component) {
            if ($component->amount === null) {
                return null;
            }

            $total = bcadd($total, $component->amount, 2);
        }

        return $total;
    }

    public function fingerprint(?int $curveVersionId): string
    {
        $components = array_map(fn (PuObligationComponentData $component): array => $component->toCanonicalArray(), $this->components);
        usort($components, fn (array $left, array $right): int => [$left['component'], $left['owner']] <=> [$right['component'], $right['owner']]);

        return hash('sha256', (string) json_encode([
            'curve_version_id' => $curveVersionId,
            'due_date' => $this->dueDate,
            'components' => $components,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }
}

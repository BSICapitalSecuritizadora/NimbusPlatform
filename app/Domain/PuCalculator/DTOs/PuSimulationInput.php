<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

use Carbon\CarbonImmutable;

/**
 * Hipóteses de uma simulação de PU.
 *
 * Tudo aqui é escalar e vive apenas durante a execução: nada é persistido, e
 * nenhum valor financeiro trafega como float. `firstIntegralizationDate` é o
 * override de destaque -- ele alimenta `curve_start_date` da engine sem criar
 * evidência nem satisfazer qualquer gate documental.
 */
final readonly class PuSimulationInput
{
    /**
     * @param  array<string, string|null>  $overrides  campos de `EmissionPuParameter` informados manualmente
     */
    public function __construct(
        public ?CarbonImmutable $firstIntegralizationDate = null,
        public ?CarbonImmutable $simulationEndDate = null,
        public ?string $quantity = null,
        public array $overrides = [],
        public ?CarbonImmutable $focusDate = null,
    ) {}

    public function override(string $field): ?string
    {
        $value = $this->overrides[$field] ?? null;

        if ($value === null) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    public function hasOverride(string $field): bool
    {
        return $this->override($field) !== null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'first_integralization_date' => $this->firstIntegralizationDate?->toDateString(),
            'simulation_end_date' => $this->simulationEndDate?->toDateString(),
            'quantity' => $this->quantity,
            'focus_date' => $this->focusDate?->toDateString(),
            'override_fields' => array_keys(array_filter(
                $this->overrides,
                fn (?string $value): bool => $value !== null && trim($value) !== '',
            )),
        ];
    }
}

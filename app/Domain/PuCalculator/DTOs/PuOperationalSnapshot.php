<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

use App\Domain\PuCalculator\Enums\PuOperationalConditionType;
use Carbon\CarbonImmutable;

/**
 * Retrato operacional do PU num instante (Fase 6).
 *
 * `checks` diz o que foi de fato verificado: uma verificação que falhou não
 * produz "nenhuma condição" -- ela aparece como falhada, e os incidentes que
 * dependem dela não são resolvidos. Por emissão, `failed_emission_ids` separa a
 * emissão que não pôde ser avaliada das que foram.
 */
final readonly class PuOperationalSnapshot
{
    /**
     * @param  list<PuEmissionOperationalHealth>  $emissions
     * @param  list<array<string, mixed>>  $indexes
     * @param  list<PuOperationalCondition>  $systemConditions  condições fora de uma emissão (índice, fila)
     * @param  array<string, array{status: string, error?: string|null, failed_emission_ids?: list<int>}>  $checks
     * @param  array<string, mixed>  $system
     */
    public function __construct(
        public CarbonImmutable $evaluatedAt,
        public array $emissions,
        public array $indexes,
        public array $systemConditions,
        public array $checks,
        public array $system = [],
    ) {}

    /**
     * @return list<PuOperationalCondition>
     */
    public function conditions(): array
    {
        $conditions = $this->systemConditions;

        foreach ($this->emissions as $emission) {
            array_push($conditions, ...$emission->conditions);
        }

        return $conditions;
    }

    /**
     * @return list<PuOperationalCondition>
     */
    public function actionableConditions(): array
    {
        return array_values(array_filter($this->conditions(), fn (PuOperationalCondition $condition): bool => $condition->alertRequired()));
    }

    /**
     * @return list<PuOperationalCondition>
     */
    public function conditionsOfType(PuOperationalConditionType $type): array
    {
        return array_values(array_filter($this->conditions(), fn (PuOperationalCondition $condition): bool => $condition->type === $type));
    }

    public function emission(int $emissionId): ?PuEmissionOperationalHealth
    {
        foreach ($this->emissions as $emission) {
            if ($emission->emissionId === $emissionId) {
                return $emission;
            }
        }

        return null;
    }

    public function checkSucceeded(string $check): bool
    {
        return ($this->checks[$check]['status'] ?? 'failed') === 'ok';
    }

    public function allChecksSucceeded(): bool
    {
        foreach ($this->checks as $check) {
            if (($check['status'] ?? 'failed') !== 'ok') {
                return false;
            }
        }

        return $this->checks !== [];
    }

    /**
     * Emissões cuja avaliação da verificação falhou (a verificação em si rodou).
     *
     * @return list<int>
     */
    public function failedEmissionIds(string $check): array
    {
        return array_values(array_map('intval', $this->checks[$check]['failed_emission_ids'] ?? []));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'evaluated_at' => $this->evaluatedAt->toIso8601String(),
            'checks' => $this->checks,
            'system' => $this->system,
            'indexes' => $this->indexes,
            'emissions' => array_map(fn (PuEmissionOperationalHealth $emission): array => $emission->toArray(), $this->emissions),
            'conditions' => array_map(fn (PuOperationalCondition $condition): array => $condition->toArray(), $this->conditions()),
        ];
    }
}

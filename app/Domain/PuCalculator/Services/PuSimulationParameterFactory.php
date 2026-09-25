<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuSimulationInput;
use App\Domain\PuCalculator\Enums\PuBaselineEvidenceStatus;
use App\Domain\PuCalculator\Enums\PuBaselineEvidenceType;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuSimulationValueOrigin;
use App\Domain\PuCalculator\ValueObjects\Decimal;
use App\Models\Emission;
use App\Models\EmissionPuBaselineEvidence;
use App\Models\EmissionPuParameter;

/**
 * Monta, EM MEMÓRIA, o `EmissionPuParameter` de uma simulação.
 *
 * A resolução é explícita e rastreada campo a campo, na ordem contratual ->
 * persistido -> override de simulação. O parâmetro devolvido nunca existe no
 * banco (`exists = false`, sem chave primária), então nem um `save()`
 * acidental poderia sobrescrever a configuração real da emissão.
 *
 * Nada aqui grava, e nada aqui inventa: um campo que a baseline não comprova e
 * o usuário não informou fica `null` e entra em `missingFields`.
 */
final class PuSimulationParameterFactory
{
    /**
     * Campos de configuração que a simulação resolve e a tela apresenta.
     *
     * @var list<string>
     */
    public const CONFIGURATION_FIELDS = [
        'indexer',
        'spread_rate',
        'annual_rate',
        'business_day_basis',
        'calendar_code',
        'index_rate_lookup_mode',
        'index_rate_lag_business_days',
        'initial_unit_value',
        'curve_start_date',
        'curve_end_date',
        'first_coupon_pre_integralization_premium_enabled',
        'first_coupon_pre_integralization_business_days',
        'first_coupon_pre_integralization_apply_index_factor',
        'first_coupon_pre_integralization_apply_spread_factor',
    ];

    /**
     * Mínimo matemático para a engine rodar. `spread_rate` e `annual_rate` são
     * validados por indexador, mais abaixo.
     *
     * @var list<string>
     */
    private const REQUIRED_FIELDS = [
        'indexer',
        'business_day_basis',
        'calendar_code',
        'initial_unit_value',
        'curve_start_date',
        'curve_end_date',
    ];

    private const PENDING = 'PENDING';

    public function __construct(
        private readonly PuBaselineCandidateFactory $baselineCandidates,
    ) {}

    /**
     * @return array{
     *     parameter:?EmissionPuParameter,
     *     values:array<string, mixed>,
     *     origins:array<string, string>,
     *     missing:list<string>,
     *     conflicts:list<array<string, mixed>>,
     *     schedule:array<string, mixed>,
     * }
     */
    public function resolve(Emission $emission, PuSimulationInput $input): array
    {
        $contractual = $this->contractualConfiguration($emission);
        $persisted = $this->persistedConfiguration($emission);
        $values = [];
        $origins = [];
        $conflicts = [];

        foreach (self::CONFIGURATION_FIELDS as $field) {
            $contractualValue = $contractual['configuration'][$field] ?? null;
            $contractualValue = $contractualValue === self::PENDING ? null : $contractualValue;
            $persistedValue = $persisted[$field] ?? null;
            $overrideValue = $this->overrideFor($input, $field);

            if ($overrideValue !== null) {
                $values[$field] = $overrideValue;
                $origins[$field] = PuSimulationValueOrigin::SimulationOverride->value;
            } elseif ($persistedValue !== null) {
                $values[$field] = $persistedValue;
                $origins[$field] = PuSimulationValueOrigin::Persisted->value;
            } elseif ($contractualValue !== null) {
                $values[$field] = $contractualValue;
                $origins[$field] = PuSimulationValueOrigin::Contractual->value;
            } else {
                $values[$field] = null;
                $origins[$field] = PuSimulationValueOrigin::Undefined->value;
            }

            // Fontes não são combinadas silenciosamente: uma divergência entre o
            // contrato lido e o parâmetro persistido é reportada para decisão
            // humana, mesmo quando a simulação segue com o persistido.
            if ($contractualValue !== null
                && $persistedValue !== null
                && (string) $contractualValue !== (string) $persistedValue) {
                $conflicts[] = [
                    'field' => $field,
                    'contractual' => (string) $contractualValue,
                    'persisted' => (string) $persistedValue,
                    'used' => (string) $values[$field],
                ];
            }
        }

        $values = $this->applySimulationWindow($values, $origins, $input);
        $missing = $this->missingFields($values);

        return [
            'parameter' => $missing === [] ? $this->inMemoryParameter($values, $persisted['index_rate_calendar_code'] ?? null) : null,
            'values' => $values,
            'origins' => $origins,
            'missing' => $missing,
            'conflicts' => $conflicts,
            'schedule' => $contractual['schedule'],
        ];
    }

    /**
     * Cronograma contratual (primeiro cupom, periodicidade, amortização,
     * convenção de pagamento) tal como a baseline o comprova.
     * A configuração já converte frações jurídicas em pontos percentuais no
     * `PuBaselineCandidateFactory`: spread `0.06` chega aqui como `6.00000000`.
     * Persistidos e overrides usam essa mesma unidade, sem nova conversão.
     *
     * @return array{configuration:array<string, mixed>, schedule:array<string, mixed>}
     */
    private function contractualConfiguration(Emission $emission): array
    {
        if (! $this->baselineCandidates->supports($emission)) {
            return ['configuration' => [], 'schedule' => []];
        }

        $candidate = $this->baselineCandidates->make(
            $emission,
            $this->curveStartEvidence($emission),
        );

        return [
            'configuration' => $candidate->configuration,
            'schedule' => $candidate->contractualSchedule,
        ];
    }

    /**
     * Evidência aprovada de primeira integralização, quando existir. A ausência
     * dela é justamente o caso do Alto Bellevue: a simulação segue com override.
     */
    private function curveStartEvidence(Emission $emission): ?EmissionPuBaselineEvidence
    {
        return $emission->puBaselineEvidences()
            ->where('evidence_type', PuBaselineEvidenceType::FirstIntegralizationDate->value)
            ->where('status', PuBaselineEvidenceStatus::Approved->value)
            ->latest('id')
            ->first();
    }

    /** @return array<string, mixed> */
    private function persistedConfiguration(Emission $emission): array
    {
        $parameter = $emission->relationLoaded('puParameter')
            ? $emission->puParameter
            : $emission->puParameter()->first();

        if (! $parameter instanceof EmissionPuParameter) {
            return [];
        }

        return [
            'indexer' => $parameter->indexer,
            'spread_rate' => $parameter->spread_rate !== null ? (string) $parameter->spread_rate : null,
            'annual_rate' => $parameter->annual_rate !== null ? (string) $parameter->annual_rate : null,
            'business_day_basis' => $parameter->business_day_basis,
            'calendar_code' => $parameter->calendar_code,
            'index_rate_lookup_mode' => $parameter->index_rate_lookup_mode,
            'index_rate_lag_business_days' => $parameter->index_rate_lag_business_days,
            'initial_unit_value' => $parameter->initial_unit_value !== null
                ? (string) $parameter->initial_unit_value
                : null,
            'curve_start_date' => $parameter->curve_start_date?->toDateString(),
            'curve_end_date' => $parameter->curve_end_date?->toDateString(),
            'first_coupon_pre_integralization_premium_enabled' => $parameter->first_coupon_pre_integralization_premium_enabled,
            'first_coupon_pre_integralization_business_days' => $parameter->first_coupon_pre_integralization_business_days,
            'first_coupon_pre_integralization_apply_index_factor' => $parameter->first_coupon_pre_integralization_apply_index_factor,
            'first_coupon_pre_integralization_apply_spread_factor' => $parameter->first_coupon_pre_integralization_apply_spread_factor,
            'index_rate_calendar_code' => $parameter->index_rate_calendar_code,
        ];
    }

    /**
     * `firstIntegralizationDate` e `simulationEndDate` são os dois campos com
     * tratamento próprio: o primeiro é o override de destaque da fase e alimenta
     * `curve_start_date`; o segundo recorta a janela simulada sem nunca estendê-la
     * além do vencimento contratual.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, string>  $origins
     * @return array<string, mixed>
     */
    private function applySimulationWindow(array $values, array &$origins, PuSimulationInput $input): array
    {
        if ($input->firstIntegralizationDate !== null) {
            $values['curve_start_date'] = $input->firstIntegralizationDate->toDateString();
            $origins['curve_start_date'] = PuSimulationValueOrigin::SimulationOverride->value;
        }

        $contractualEnd = $values['curve_end_date'];

        if ($input->simulationEndDate !== null) {
            $requestedEnd = $input->simulationEndDate->toDateString();
            $values['curve_end_date'] = is_string($contractualEnd) && $contractualEnd < $requestedEnd
                ? $contractualEnd
                : $requestedEnd;
            $origins['curve_end_date'] = PuSimulationValueOrigin::SimulationOverride->value;
        }

        $values['contractual_curve_end_date'] = $contractualEnd;

        return $values;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    private function missingFields(array $values): array
    {
        $missing = [];

        foreach (self::REQUIRED_FIELDS as $field) {
            if ($values[$field] === null || $values[$field] === '') {
                $missing[] = $field;
            }
        }

        $indexer = is_string($values['indexer']) ? PuIndexer::tryFrom($values['indexer']) : null;

        if ($indexer === null && ! in_array('indexer', $missing, true)) {
            $missing[] = 'indexer';
        }

        if ($indexer === PuIndexer::Prefixed && ($values['annual_rate'] === null || $values['annual_rate'] === '')) {
            $missing[] = 'annual_rate';
        }

        if ($indexer !== null && $indexer->requiresIndexRates()) {
            if ($values['spread_rate'] === null || $values['spread_rate'] === '') {
                $missing[] = 'spread_rate';
            }

            if ($values['index_rate_lookup_mode'] === null || $values['index_rate_lookup_mode'] === '') {
                $missing[] = 'index_rate_lookup_mode';
            }

            if ($values['index_rate_lag_business_days'] === null || $values['index_rate_lag_business_days'] === '') {
                $missing[] = 'index_rate_lag_business_days';
            }
        }

        if ($this->isTruthy($values['first_coupon_pre_integralization_premium_enabled'])
            && (int) $values['first_coupon_pre_integralization_business_days'] <= 0) {
            $missing[] = 'first_coupon_pre_integralization_business_days';
        }

        return array_values(array_unique($missing));
    }

    /**
     * Instância NÃO persistida: sem chave primária, `exists = false` e sem
     * `emission_id`. A engine só lê atributos, então isto é suficiente -- e
     * remove qualquer caminho em que um save acidental atingisse a emissão real.
     *
     * O calendário de divulgação do índice vem da configuração gravada: sem
     * hipótese na tela, a simulação conta a defasagem onde a geração contaria.
     *
     * @param  array<string, mixed>  $values
     */
    private function inMemoryParameter(array $values, ?string $indexRateCalendarCode = null): EmissionPuParameter
    {
        $parameter = new EmissionPuParameter;
        $parameter->exists = false;
        $parameter->forceFill([
            'indexer' => (string) $values['indexer'],
            'spread_rate' => $values['spread_rate'] !== null ? (string) $values['spread_rate'] : null,
            'annual_rate' => $values['annual_rate'] !== null ? (string) $values['annual_rate'] : null,
            'business_day_basis' => (int) $values['business_day_basis'],
            'calendar_code' => (string) $values['calendar_code'],
            'index_rate_lookup_mode' => $values['index_rate_lookup_mode'] !== null
                ? (string) $values['index_rate_lookup_mode']
                : null,
            'index_rate_lag_business_days' => $values['index_rate_lag_business_days'] !== null
                ? (int) $values['index_rate_lag_business_days']
                : 0,
            'initial_unit_value' => (string) $values['initial_unit_value'],
            'curve_start_date' => (string) $values['curve_start_date'],
            'curve_end_date' => (string) $values['curve_end_date'],
            'first_coupon_pre_integralization_premium_enabled' => $this->isTruthy(
                $values['first_coupon_pre_integralization_premium_enabled'],
            ),
            'first_coupon_pre_integralization_business_days' => $values['first_coupon_pre_integralization_business_days'] !== null
                ? (int) $values['first_coupon_pre_integralization_business_days']
                : null,
            'first_coupon_pre_integralization_apply_index_factor' => $this->isTruthy(
                $values['first_coupon_pre_integralization_apply_index_factor'],
            ),
            'first_coupon_pre_integralization_apply_spread_factor' => $this->isTruthy(
                $values['first_coupon_pre_integralization_apply_spread_factor'],
            ),
            'index_rate_calendar_code' => $indexRateCalendarCode,
        ]);

        return $parameter;
    }

    private function overrideFor(PuSimulationInput $input, string $field): ?string
    {
        $value = $input->override($field);

        return $field === 'spread_rate' && $value !== null
            ? Decimal::of($value)->value()
            : $value;
    }

    private function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array((string) $value, ['1', 'true', 'yes', 'on'], true);
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

use App\Domain\PuCalculator\Enums\PuCalculationProfile;
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
     *                                                 `spread_rate` e `annual_rate` usam pontos percentuais anuais: `7.5`
     *                                                 significa 7,5% a.a. O spread também aceita a escrita local `7,5`.
     * @param  string|null  $quantity  quantidade decimal de posição, sem efeito sobre os parâmetros ou a curva unitária
     * @param  string|null  $indexRateCalendarCode  HIPÓTESE de calendário de OBSERVAÇÃO do índice.
     *                                              Decide apenas em que dia a taxa foi divulgada, isto é, para onde
     *                                              o lag de `BusinessDayLagExact` aponta. NÃO altera o Dia Útil
     *                                              contratual: curva, eventos, DUP/DUT e convenção de pagamento
     *                                              continuam no calendário da baseline. Nulo mantém os dois iguais.
     * @param  string|null  $accrualCalendarCode  HIPÓTESE de calendário de ACCRUAL da curva.
     *                                            Decide quais dias da curva contam como Dia Útil: contagem de DU,
     *                                            DUP/DUT e incidência do fator diário. NÃO altera o calendário
     *                                            CONTRATUAL: eventos, convenção Following e datas de pagamento
     *                                            continuam na baseline, e o prêmio pré-integralização também.
     *                                            Nulo — todo o caminho de produção — preserva o comportamento atual.
     * @param  PuCalculationProfile|null  $calculationProfile  PERFIL DE CÁLCULO da simulação.
     *                                            Nulo é `Contractual`, e é assim em toda chamada que
     *                                            não escolher explicitamente outro. `LegacyCompatibility`
     *                                            é exclusivo desta camada: existe para reconciliar com o
     *                                            sistema anterior e não pode ser persistido, promovido
     *                                            nem homologado.
     */
    public function __construct(
        public ?CarbonImmutable $firstIntegralizationDate = null,
        public ?CarbonImmutable $simulationEndDate = null,
        public ?string $quantity = null,
        public array $overrides = [],
        public ?CarbonImmutable $focusDate = null,
        public ?string $indexRateCalendarCode = null,
        public ?string $accrualCalendarCode = null,
        public ?PuCalculationProfile $calculationProfile = null,
    ) {}

    /**
     * Perfil efetivo desta simulação. Omissão é sempre contratual: nenhuma
     * chamada existente muda de comportamento por não conhecer o parâmetro.
     */
    public function calculationProfile(): PuCalculationProfile
    {
        return $this->calculationProfile ?? PuCalculationProfile::default();
    }

    /**
     * Código do calendário de observação, normalizado. Vazio é ausência de
     * hipótese, nunca calendário inválido.
     */
    public function indexRateCalendarCode(): ?string
    {
        $code = $this->indexRateCalendarCode !== null ? trim($this->indexRateCalendarCode) : '';

        return $code === '' ? null : $code;
    }

    /**
     * Código do calendário de accrual, normalizado. Vazio é ausência de
     * hipótese, nunca calendário inválido.
     */
    public function accrualCalendarCode(): ?string
    {
        $code = $this->accrualCalendarCode !== null ? trim($this->accrualCalendarCode) : '';

        return $code === '' ? null : $code;
    }

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
            'index_rate_calendar_code' => $this->indexRateCalendarCode(),
            'accrual_calendar_code' => $this->accrualCalendarCode(),
            'calculation_profile' => $this->calculationProfile()->value,
            'override_fields' => array_keys(array_filter(
                $this->overrides,
                fn (?string $value): bool => $value !== null && trim($value) !== '',
            )),
        ];
    }
}

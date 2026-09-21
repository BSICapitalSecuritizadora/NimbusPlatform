<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Calculators\DailyFactorCalculator;
use App\Domain\PuCalculator\DTOs\PuIndexRateRequirement;
use App\Domain\PuCalculator\Enums\PuCalculationProfile;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use App\Models\EmissionPuParameter;

final class CdiFactorCompositionService
{
    /**
     * Casas em que o PRODUTÓRIO do Fator DI é truncado após CADA multiplicação,
     * conforme o Termo de Securitização. É truncamento (corte em direção a zero),
     * nunca arredondamento: ver `accumulateIndexFactor()`.
     */
    public const ACCUMULATED_INDEX_FACTOR_TRUNCATION_SCALE = 16;

    public function __construct(
        private readonly DailyFactorCalculator $dailyFactorCalculator,
        private readonly DecimalRounder $rounder,
    ) {}

    public function dailyIndexFactor(
        EmissionPuParameter $parameter,
        PuIndexRateRequirement $requirement,
    ): string {
        $factor = $this->dailyFactorCalculator->factorDiForDay(
            $requirement->rate?->value,
            $requirement->shouldApplyRate(),
            (int) $parameter->business_day_basis,
            DecimalRounder::CALCULATION_SCALE,
        );

        if ($parameter->index_rate_lookup_mode_enum !== PuIndexRateLookupMode::BusinessDayLagExact) {
            return $factor;
        }

        return $this->rounder->normalize(
            $this->rounder->round($factor, 8),
            DecimalRounder::CALCULATION_SCALE,
        );
    }

    /**
     * Produtório dos fatores diários do Fator DI.
     *
     * O Termo manda TRUNCAR o acumulado em 16 casas decimais depois de cada
     * multiplicação, antes de aplicar o fator diário seguinte -- e só ao final
     * do produtório arredondar o Fator DI em 8 casas (`indexFactorForCombination`).
     * Truncar não é arredondar: o corte é em direção a zero, de modo que
     * `1,1234567890123456|9` vira `1,1234567890123456` e nunca `...57`.
     *
     * A conta inteira permanece em string/BCMath. `DecimalRounder::truncate()` é
     * puramente textual (separa parte inteira e decimal e corta a cauda,
     * preservando o sinal), então nenhum estágio passa por `float`.
     *
     * Só os modos "Exact" carregam precisão contratual explícita por estágio; nos
     * demais o acumulado segue na escala de cálculo, como antes.
     */
    public function accumulateIndexFactor(
        EmissionPuParameter $parameter,
        string $accumulatedFactor,
        string $dailyFactor,
    ): string {
        $product = bcmul($accumulatedFactor, $dailyFactor, DecimalRounder::CALCULATION_SCALE + 4);

        if ($parameter->index_rate_lookup_mode_enum !== PuIndexRateLookupMode::BusinessDayLagExact) {
            return $this->rounder->round($product, DecimalRounder::CALCULATION_SCALE);
        }

        return $this->rounder->normalize(
            $this->rounder->truncate($product, self::ACCUMULATED_INDEX_FACTOR_TRUNCATION_SCALE),
            DecimalRounder::CALCULATION_SCALE,
        );
    }

    public function spreadFactor(
        EmissionPuParameter $parameter,
        int $businessDays,
        PuCalculationProfile $profile = PuCalculationProfile::Contractual,
    ): string {
        $factor = $this->dailyFactorCalculator->factorSpreadForBusinessDays(
            (string) $parameter->spread_rate,
            $businessDays,
            (int) $parameter->business_day_basis,
            DecimalRounder::CALCULATION_SCALE,
        );

        // O legado carrega o Fator Spread inteiro para a combinação; o 1,003474409 que a
        // planilha exibe é apresentação em 9 casas, não o valor que ela multiplica.
        if (! $profile->roundsSpreadFactor()) {
            return $this->rounder->normalize($factor, DecimalRounder::CALCULATION_SCALE);
        }

        if ($parameter->index_rate_lookup_mode_enum !== PuIndexRateLookupMode::BusinessDayLagExact) {
            return $factor;
        }

        return $this->rounder->normalize(
            $this->rounder->round($factor, 9),
            DecimalRounder::CALCULATION_SCALE,
        );
    }

    /**
     * Fator Spread BRUTO, antes do arredondamento contratual em 9 casas.
     *
     * Existe exclusivamente para a memória de cálculo poder mostrar o estágio
     * anterior à quantização. Nenhuma conta da engine consome este valor.
     */
    public function unroundedSpreadFactor(EmissionPuParameter $parameter, int $businessDays): string
    {
        // Normalizado na escala de cálculo para poder ser comparado diretamente com o
        // Fator Spread aplicado: sem isso, o atalho de zero Dias Úteis devolveria 16
        // casas e a memória pareceria divergir onde os dois valores são o mesmo.
        return $this->rounder->normalize(
            $this->dailyFactorCalculator->factorSpreadForBusinessDays(
                (string) $parameter->spread_rate,
                $businessDays,
                (int) $parameter->business_day_basis,
                DecimalRounder::CALCULATION_SCALE,
            ),
            DecimalRounder::CALCULATION_SCALE,
        );
    }

    /**
     * Fator DI ENTREGUE à combinação com o Fator Spread.
     *
     * É o ÚNICO estágio em que os dois perfis divergem, e a divergência é
     * comprovada contra a planilha de referência (ver `PuLegacyReferenceFixture`):
     *
     *  - `Contractual`: o Termo manda considerar o Fator DI com 8 casas, com
     *    arredondamento, ANTES de combinar. É a autoridade do Nimbus.
     *  - `LegacyCompatibility`: o sistema legado leva o produtório acumulado
     *    inteiro (16 casas, já truncado progressivamente) para a combinação e
     *    só quantiza depois, no Fator de Juros em 9 casas.
     *
     * Em 31/08/2026 a diferença é literalmente esta: `1,0077946312497466` contra
     * `1,00779463`, que separa um Fator de Juros de `1,011296122` de um de
     * `1,011296121` -- R$ 0,000001 por unidade de PU.
     */
    public function indexFactorForCombination(
        EmissionPuParameter $parameter,
        string $indexFactor,
        PuCalculationProfile $profile = PuCalculationProfile::Contractual,
    ): string {
        if (! $profile->roundsIndexFactorForCombination()) {
            return $indexFactor;
        }

        if ($parameter->index_rate_lookup_mode_enum !== PuIndexRateLookupMode::BusinessDayLagExact) {
            return $indexFactor;
        }

        return $this->rounder->normalize(
            $this->rounder->round($indexFactor, 8),
            DecimalRounder::CALCULATION_SCALE,
        );
    }

    public function combinedFactor(
        EmissionPuParameter $parameter,
        string $indexFactor,
        string $spreadFactor,
        PuCalculationProfile $profile = PuCalculationProfile::Contractual,
    ): string {
        return $this->rounder->round(
            bcmul(
                $this->indexFactorForCombination($parameter, $indexFactor, $profile),
                $spreadFactor,
                DecimalRounder::CALCULATION_SCALE + 4,
            ),
            DecimalRounder::CALCULATION_SCALE,
        );
    }

    public function factorForInterest(EmissionPuParameter $parameter, string $combinedFactor): string
    {
        if (! in_array($parameter->index_rate_lookup_mode_enum, [
            PuIndexRateLookupMode::BusinessDayLagExact,
            PuIndexRateLookupMode::PreviousCalendarDayExact,
        ], true)) {
            return $combinedFactor;
        }

        return $this->rounder->normalize(
            $this->rounder->round($combinedFactor, 9),
            DecimalRounder::CALCULATION_SCALE,
        );
    }
}

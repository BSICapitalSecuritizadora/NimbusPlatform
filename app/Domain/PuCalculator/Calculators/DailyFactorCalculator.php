<?php

namespace App\Domain\PuCalculator\Calculators;

use App\Domain\PuCalculator\Exceptions\PuNumericConvergenceException;
use App\Domain\PuCalculator\Exceptions\PuRateDomainException;
use App\Domain\PuCalculator\Services\DecimalRounder;
use App\Domain\PuCalculator\ValueObjects\Decimal;
use InvalidArgumentException;

class DailyFactorCalculator
{
    private const MAX_NEWTON_ITERATIONS = 60;

    /** @var array<string, string> */
    private array $cache = [];

    public function __construct(
        private readonly DecimalRounder $rounder,
    ) {}

    public function factorDiForDay(?string $annualRate, bool $isBusinessDay, int $businessDayBasis, int $scale = DecimalRounder::FACTOR_SCALE): string
    {
        if (! $isBusinessDay || $annualRate === null) {
            return '1.0000000000000000';
        }

        $base = Decimal::one()
            ->add(Decimal::of($annualRate)->divide(Decimal::of(100), DecimalRounder::INTERNAL_SCALE), DecimalRounder::INTERNAL_SCALE)
            ->value();

        $this->assertPositiveCompoundingBase($annualRate, $base);

        return $this->powRatio($base, 1, $businessDayBasis, $scale);
    }

    public function factorSpreadForBusinessDays(string $spreadRate, int $businessDays, int $businessDayBasis, int $scale = DecimalRounder::FACTOR_SCALE): string
    {
        if ($businessDays <= 0) {
            return '1.0000000000000000';
        }

        $base = Decimal::one()
            ->add(Decimal::of($spreadRate)->divide(Decimal::of(100), DecimalRounder::INTERNAL_SCALE), DecimalRounder::INTERNAL_SCALE)
            ->value();

        $this->assertPositiveCompoundingBase($spreadRate, $base);

        return $this->powRatio($base, $businessDays, $businessDayBasis, $scale);
    }

    /**
     * Fronteira FINANCEIRA de toda taxa anual em pontos percentuais: o fator só existe
     * com base de capitalização 1 + taxa/100 estritamente positiva, isto é, taxa acima
     * de -100% a.a. Taxa negativa acima disso é válida e segue para a raiz.
     *
     * `powRatio()` continua sendo a primitiva matemática -- devolve 0 para base zero e
     * a raiz real de uma base negativa de grau ímpar --, então a recusa precisa
     * acontecer aqui, antes dela: em base ímpar (21, 29, 31...) -150% a.a. virava um
     * fator negativo, e -100% a.a., um fator zero, sem nenhum sinal.
     *
     * Não há teto: taxa positiva extrema que o Newton não certifica já falha fechada
     * em `nthRoot()`.
     *
     * @throws PuRateDomainException
     */
    public function assertPositiveCompoundingBase(string $annualRate, string $base): void
    {
        if (bccomp($base, '0', strlen($base)) <= 0) {
            throw PuRateDomainException::nonPositiveCompoundingBase($annualRate, $base);
        }
    }

    public function powRatio(string $base, int $numerator, int $denominator, int $scale = DecimalRounder::FACTOR_SCALE): string
    {
        if ($denominator <= 0) {
            throw new InvalidArgumentException('The denominator for a rational power must be positive.');
        }

        if ($numerator === 0) {
            return '1.0000000000000000';
        }

        if ($numerator < 0) {
            $positivePower = $this->powRatio($base, abs($numerator), $denominator, $scale + 4);

            return $this->rounder->round(
                bcdiv('1', $positivePower, $scale + 4),
                $scale,
            );
        }

        $cacheKey = implode('|', [$base, $numerator, $denominator, $scale]);

        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $workingScale = $scale + 8;

        if ($denominator === 1) {
            return $this->cache[$cacheKey] = $this->rounder->round(
                Decimal::of($base)->powerInt($numerator, $workingScale)->value(),
                $scale,
            );
        }

        $unitFactor = $this->nthRoot($base, $denominator, $workingScale, $workingScale + 4);

        return $this->cache[$cacheKey] = $this->rounder->round(
            Decimal::of($unitFactor)->powerInt($numerator, $workingScale)->value(),
            $scale,
        );
    }

    /**
     * Raiz n-ésima por Newton em BCMath, que nunca devolve aproximação não convergida.
     *
     * A iteração parte de 1 para qualquer base. O primeiro passo dá 1 + (base - 1) / n,
     * que pela desigualdade de Bernoulli nunca fica abaixo da raiz exata; como
     * x^n - base é convexa e crescente em x > 0, Newton desce dali até a raiz de forma
     * monótona, e quadrática perto dela. Nas faixas financeiras usuais isso custa
     * poucas iterações (no máximo 8 de -50% a 100% a.a., denominadores 2 a 372).
     *
     * O laço tem teto (`MAX_NEWTON_ITERATIONS`), e o domínio em que ele certifica a
     * raiz tem DOIS extremos. Os dois dependem do denominador e da escala, ficam muito
     * longe de qualquer taxa de mercado e não são garantia de contrato:
     *  - taxa positiva extrema (milhares de % a.a.): longe da raiz cada passo só encolhe
     *    a estimativa em cerca de (n-1)/n, e as iterações acabam antes da fase quadrática;
     *  - base positiva ínfima (taxa colada em -100% a.a.): a conta usa precisão ABSOLUTA
     *    finita (`$workingScale` casas) e, para n grande, x^(n-1) = base/x fica da ordem
     *    da própria base. Com a base pequena o bastante (da ordem de 10^-7 para n de 21
     *    a 372), o erro de truncamento passa da casa `$scale`, os iterados oscilam no
     *    ruído e nunca repetem. Denominadores pequenos (n = 2) quase não têm esse piso.
     * Nos dois casos sai `PuNumericConvergenceException`, nunca a última aproximação.
     * Base zero ou negativa nem chega aqui pelas taxas: `assertPositiveCompoundingBase()`
     * a recusa antes, como domínio financeiro.
     *
     * Bases >= 1 partiam da própria base: x^(n-1) explodia, cada passo só encolhia a
     * estimativa em (n-1)/n e o laço esgotava sem convergir (base 252 a partir de
     * 23,78% a.a.; 360, de 16,08%; 372, de 15,51%). A última aproximação saía como se
     * fosse a raiz -- errada já na 8ª casa a partir de 25,33%, 17,13% e 16,50%. Bases
     * < 1 já partiam de 1 e seguem a mesma sequência de antes.
     *
     * Só a base exatamente zero tem raiz zero: comparar na escala de trabalho tomava
     * uma base positiva menor que 10^-workingScale por zero.
     *
     * Convergência: dois iterados consecutivos iguais em `$scale` casas. Antes de sair,
     * o resultado ainda precisa provar que a raiz exata está a menos de uma unidade da
     * última casa (`assertBracketsExactRoot()`). Qualquer outro desfecho lança
     * `PuNumericConvergenceException`.
     */
    private function nthRoot(string $value, int $root, int $scale, int $workingScale): string
    {
        $exactScale = strlen($value);

        if (bccomp($value, '0', $exactScale) === 0) {
            return $this->rounder->round('0', $scale);
        }

        if ($root % 2 === 0 && bccomp($value, '0', $exactScale) < 0) {
            throw new InvalidArgumentException(sprintf('A negative base [%s] has no real root of even degree [%d].', $value, $root));
        }

        $currentApproximation = '1';
        $rootMinusOne = (string) ($root - 1);

        for ($attempt = 1; $attempt <= self::MAX_NEWTON_ITERATIONS; $attempt++) {
            $denominator = Decimal::of($currentApproximation)->powerInt($root - 1, $workingScale)->value();

            if (bccomp($denominator, '0', $workingScale) === 0) {
                throw PuNumericConvergenceException::vanishingDerivative($value, $root, $attempt);
            }

            $numeratorLeft = bcmul($rootMinusOne, $currentApproximation, $workingScale);
            $numeratorRight = bcdiv($value, $denominator, $workingScale);
            $nextApproximation = bcdiv(
                bcadd($numeratorLeft, $numeratorRight, $workingScale),
                (string) $root,
                $workingScale,
            );

            if (bccomp($nextApproximation, $currentApproximation, $scale) === 0) {
                $result = $this->rounder->round($nextApproximation, $scale);

                $this->assertBracketsExactRoot($result, $value, $root, $scale, $workingScale);

                return $result;
            }

            $currentApproximation = $nextApproximation;
        }

        throw PuNumericConvergenceException::didNotConverge($value, $root, self::MAX_NEWTON_ITERATIONS);
    }

    /**
     * Prova que a raiz exata está a menos de uma unidade da última casa de `$candidate`:
     * (candidato - ulp)^n <= base <= (candidato + ulp)^n. Vale porque x^n é crescente
     * em x >= 0 (e, para n ímpar, em toda a reta) e `bcpow` é exato antes de truncar.
     *
     * É uma salvaguarda: quando o Newton converge de fato, o candidato já passa, e
     * nenhuma entrada medida dispara esta recusa pela API pública. Ela existe para o
     * caso em que "dois iterados iguais" não significa convergência -- como perto do
     * piso de base ínfima, onde os iterados oscilam no ruído e podem coincidir por
     * acaso. O teste a exercita diretamente, com candidatos errados.
     */
    private function assertBracketsExactRoot(string $candidate, string $value, int $root, int $scale, int $workingScale): void
    {
        $unitInLastPlace = '0.'.str_repeat('0', $scale - 1).'1';
        $lowerPower = Decimal::of(bcsub($candidate, $unitInLastPlace, $scale))->powerInt($root, $workingScale)->value();
        $upperPower = Decimal::of(bcadd($candidate, $unitInLastPlace, $scale))->powerInt($root, $workingScale)->value();

        if (bccomp($lowerPower, $value, $workingScale) > 0 || bccomp($upperPower, $value, $workingScale) < 0) {
            throw PuNumericConvergenceException::failedVerification($value, $root, $candidate, $unitInLastPlace);
        }
    }
}

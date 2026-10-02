<?php

use App\Domain\PuCalculator\Calculators\DailyFactorCalculator;
use App\Domain\PuCalculator\Exceptions\PuNumericConvergenceException;
use App\Domain\PuCalculator\Services\DecimalRounder;

/**
 * Raiz n-ésima da engine do PU: nunca devolve aproximação não convergida.
 *
 * Os valores esperados NÃO saem do Nimbus: foram calculados à parte, em Python
 * `decimal` com 100 dígitos de precisão, como exp(k·ln(base)/n) arredondado half-up
 * na escala pedida. Além do literal, cada caso prova o invariante raiz^n ≈ base só
 * com `bcpow` (ver `expectCorrectlyRoundedPuRoot()`), sem passar pelo Newton da engine.
 * Nenhuma expectativa é construída com `float`.
 */
function puRootCalculator(): DailyFactorCalculator
{
    return new DailyFactorCalculator(new DecimalRounder);
}

function puRootBaseFromRate(string $annualRate, int $scale = DecimalRounder::INTERNAL_SCALE): string
{
    return bcadd('1', bcdiv($annualRate, '100', $scale), $scale);
}

/**
 * Prova que `$factor` é base^(k/n) arredondado em `$scale` casas:
 * (fator - ½ulp)^n <= base^k <= (fator + ½ulp)^n. Devolve null quando vale, ou a
 * descrição da falha -- para o teste da grade listar todos os casos de uma vez.
 */
function puRootBracketFailure(string $factor, string $base, int $numerator, int $denominator, int $scale): ?string
{
    $halfUnit = '0.'.str_repeat('0', $scale).'5';
    $target = bcpow($base, (string) $numerator, 64);
    $lower = bcpow(bcsub($factor, $halfUnit, $scale + 1), (string) $denominator, 64);
    $upper = bcpow(bcadd($factor, $halfUnit, $scale + 1), (string) $denominator, 64);

    if (bccomp($lower, $target, 64) <= 0 && bccomp($upper, $target, 64) >= 0) {
        return null;
    }

    return sprintf('%s^(%d/%d) em %d casas: obtido %s', $base, $numerator, $denominator, $scale, $factor);
}

function expectCorrectlyRoundedPuRoot(string $factor, string $base, int $numerator, int $denominator, int $scale): void
{
    expect(puRootBracketFailure($factor, $base, $numerator, $denominator, $scale))->toBeNull();
}

// ---------------------------------------------------------------------------
// P0-04: as faixas em que o Newton anterior esgotava as 60 iterações
// ---------------------------------------------------------------------------

/**
 * Primeira taxa de cada denominador (passo de 0,01%) em que a saída de 24 casas do
 * código anterior já saía errada, seguida da primeira em que errava até na 8ª casa
 * do Fator DI contratual (25,33% em base 252; 17,13% em 360; 16,89% em 365; 16,50%
 * em 372) e das taxas citadas na auditoria. O laço já esgotava antes (23,78% em base
 * 252), mas a última aproximação ainda arredondava certo em 24 casas. Em 26,50% a.a.
 * base 252 saía 1,001266942538601986586658; a raiz é 1,000933261099044193858045.
 */
it('returns the exact root where the previous Newton gave up', function (string $annualRate, int $denominator, string $expected) {
    $calculator = puRootCalculator();

    $dailyFactor = $calculator->factorDiForDay($annualRate, true, $denominator, DecimalRounder::CALCULATION_SCALE);
    // Mesmo formato de base do cupom do IPCA (24 casas): a primitiva é a mesma.
    $couponFactor = $calculator->powRatio(
        puRootBaseFromRate($annualRate, DecimalRounder::CALCULATION_SCALE),
        1,
        $denominator,
        DecimalRounder::CALCULATION_SCALE,
    );

    expect($dailyFactor)->toBe($expected)
        ->and($couponFactor)->toBe($expected);

    expectCorrectlyRoundedPuRoot($dailyFactor, puRootBaseFromRate($annualRate), 1, $denominator, DecimalRounder::CALCULATION_SCALE);
})->with([
    ['24.49', 252, '1.000869644609902014879494'],
    ['24.95', 252, '1.000884293420942112833264'],
    ['25.00', 252, '1.000885882445256949393882'],
    ['25.33', 252, '1.000896354154675612882788'],
    ['26.50', 252, '1.000933261099044193858045'],
    ['30.00', 252, '1.001041670195529332022763'],
    ['50.00', 252, '1.001610283640790391743360'],
    ['100.00', 252, '1.002754370376889726616695'],
    ['17.84', 336, '1.000488683611491715976944'],
    ['17.17', 348, '1.000455436109695942939988'],
    ['16.55', 360, '1.000425507667975804628371'],
    ['16.85', 360, '1.000432651552798345709011'],
    ['17.13', 360, '1.000439302695994590201101'],
    ['20.00', 360, '1.000506577035702454424567'],
    ['26.50', 360, '1.000653191353786403900922'],
    ['30.00', 360, '1.000729055255204436598801'],
    ['16.29', 365, '1.000413556409558884187127'],
    ['16.89', 365, '1.000427661655789044653567'],
    ['30.00', 365, '1.000719064607110198439692'],
    ['15.97', 372, '1.000398362526845821641999'],
    ['16.25', 372, '1.000404847677309694239096'],
    ['16.50', 372, '1.000410624839806872520485'],
    ['20.00', 372, '1.000490231836180718639502'],
    ['26.50', 372, '1.000632114006841944019415'],
    ['30.00', 372, '1.000705529049444279659402'],
]);

// ---------------------------------------------------------------------------
// Onde a engine já estava certa, nada muda
// ---------------------------------------------------------------------------

/**
 * Cada literal é, ao mesmo tempo, a saída do código anterior à correção (capturada
 * em 19f6f31) e o valor calculado à parte em Python. Cobre CDI na faixa histórica,
 * Spread acumulado por k dias úteis (inclusive negativo), prefixado em base 360 e as
 * duas chamadas do IPCA (cupom em dut*12 e correção NI em dut, inclusive deflação).
 */
it('preserves the factors the engine already produced', function (string $kind, string $input, int $numerator, int $denominator, int $scale, string $expected) {
    $calculator = puRootCalculator();

    $factor = match ($kind) {
        'di' => $calculator->factorDiForDay($input, true, $denominator, $scale),
        'spread' => $calculator->factorSpreadForBusinessDays($input, $numerator, $denominator, $scale),
        'power' => $calculator->powRatio($input, $numerator, $denominator, $scale),
    };

    expect($factor)->toBe($expected);
})->with([
    'CDI 2,00%' => ['di', '2.00', 1, 252, 24, '1.000078584941984712858361'],
    'CDI 4,40%' => ['di', '4.40', 1, 252, 24, '1.000170885589201526421007'],
    'CDI 6,40%' => ['di', '6.40', 1, 252, 24, '1.000246202489047453180277'],
    'CDI 10,65%' => ['di', '10.65', 1, 252, 24, '1.000401675413897520154481'],
    'CDI 11,65%' => ['di', '11.65', 1, 252, 24, '1.000437392423015810084697'],
    'CDI 13,65%' => ['di', '13.65', 1, 252, 24, '1.000507880373261857798694'],
    'CDI 13,75%' => ['di', '13.75', 1, 252, 24, '1.000511372261169363540809'],
    'CDI 14,15%' => ['di', '14.15', 1, 252, 24, '1.000525309303566928746877'],
    'CDI 14,90%' => ['di', '14.90', 1, 252, 24, '1.000551310641540260093861'],
    'CDI 15,00%' => ['di', '15.00', 1, 252, 24, '1.000554764707492935202647'],
    'CDI 15,25%' => ['di', '15.25', 1, 252, 24, '1.000563386801050129227139'],
    'CDI 14,90% em 16 casas' => ['di', '14.90', 1, 252, 16, '1.0005513106415403'],
    'spread 0,50% 1 DU' => ['spread', '0.50', 1, 252, 24, '1.000019792027252599279429'],
    'spread 1,50% 21 DU' => ['spread', '1.50', 21, 252, 24, '1.001241487716449315926050'],
    'spread 3,00% 63 DU' => ['spread', '3.00', 63, 252, 24, '1.007417071777732952105188'],
    'spread 4,00% 126 DU' => ['spread', '4.00', 126, 252, 24, '1.019803902718556966005645'],
    'spread 5,00% 252 DU' => ['spread', '5.00', 252, 252, 24, '1.050000000000000000000000'],
    'spread 6,00% 1 DU' => ['spread', '6.00', 1, 252, 24, '1.000231252560640620388551'],
    'spread 6,00% 22 DU' => ['spread', '6.00', 22, 252, 24, '1.005099928759515941206594'],
    'spread 6,50% 5 DU' => ['spread', '6.50', 5, 252, 24, '1.001250280933697030625559'],
    'spread 6,50% 5 DU em 16 casas' => ['spread', '6.50', 5, 252, 16, '1.0012502809336970'],
    'spread 8,00% 504 DU' => ['spread', '8.00', 504, 252, 24, '1.166400000000000000000000'],
    'spread 10,00% 1 DU' => ['spread', '10.00', 1, 252, 24, '1.000378286531534243543770'],
    'spread 12,00% 21 DU' => ['spread', '12.00', 21, 252, 24, '1.009488792934582974126355'],
    'prefixado 12,00% base 360' => ['spread', '12.00', 100, 360, 24, '1.031980932225558661978171'],
    'spread negativo -0,50%' => ['spread', '-0.50', 10, 252, 24, '0.999801109391536809594370'],
    'cupom IPCA 6% dut 28' => ['power', '1.060000000000000000000000', 1, 336, 24, '1.000173434407424720393973'],
    'cupom IPCA 6% dut 29' => ['power', '1.060000000000000000000000', 1, 348, 24, '1.000167453409918125897834'],
    'cupom IPCA 7,5% dut 30' => ['power', '1.075000000000000000000000', 1, 360, 24, '1.000200910906503386706429'],
    'cupom IPCA 9% dut 31' => ['power', '1.090000000000000000000000', 1, 372, 24, '1.000231687309126013229040'],
    'correção IPCA 15/30' => ['power', '1.004500000000000000000000', 15, 30, 24, '1.002247474429344720810772'],
    'correção IPCA deflação 10/31' => ['power', '0.996800000000000000000000', 10, 31, 24, '0.998966621094766821218238'],
    'spread inferido da planilha' => ['power', '1.003474409000000000000000', 252, 21, 16, '1.0424989278220291'],
]);

// ---------------------------------------------------------------------------
// Propriedade: raiz^n ≈ base em toda a faixa, para todo denominador usado
// ---------------------------------------------------------------------------

/**
 * 252 é a base de dias úteis; 360/365 são bases configuráveis; 336/348/360/372 são o
 * cupom do IPCA (dut*12) e 28 a 31 a correção pela NI (dut); 2 e 21 aparecem quando a
 * planilha de referência infere o spread por `powRatio(fator, 252, dup)`.
 */
it('returns the correctly rounded root across the whole rate grid', function (int $denominator) {
    $calculator = puRootCalculator();
    $failures = [];

    $annualRates = [
        '-99.99', '-50.00', '-10.00', '-0.50', '-0.00000001', '0', '0.00000001', '0.01', '1.00',
        '5.00', '10.00', '13.65', '14.90', '15.00', '15.51', '16.08', '20.00', '23.78', '24.95',
        '25.00', '26.50', '30.00', '50.00', '100.00', '1000.00',
    ];

    foreach ($annualRates as $annualRate) {
        foreach ([DecimalRounder::FACTOR_SCALE, DecimalRounder::CALCULATION_SCALE] as $scale) {
            $factor = $calculator->factorDiForDay($annualRate, true, $denominator, $scale);
            $failures[] = puRootBracketFailure($factor, puRootBaseFromRate($annualRate), 1, $denominator, $scale);
        }
    }

    expect(array_values(array_filter($failures)))->toBe([]);
})->with([2, 21, 28, 29, 30, 31, 252, 336, 348, 360, 365, 372]);

it('returns the correctly rounded factor accumulated over many business days', function (string $annualRate) {
    $calculator = puRootCalculator();
    $failures = [];

    foreach ([252, 360] as $denominator) {
        foreach ([1, 5, 21, 126, 252, 504] as $businessDays) {
            $factor = $calculator->factorSpreadForBusinessDays($annualRate, $businessDays, $denominator, DecimalRounder::CALCULATION_SCALE);
            $failures[] = puRootBracketFailure($factor, puRootBaseFromRate($annualRate), $businessDays, $denominator, DecimalRounder::CALCULATION_SCALE);
        }
    }

    expect(array_values(array_filter($failures)))->toBe([]);
})->with(['-0.50', '0.50', '6.00', '14.90', '26.50', '100.00']);

// ---------------------------------------------------------------------------
// Falha fechada e domínio
// ---------------------------------------------------------------------------

it('refuses to return a root Newton could not reach', function (string $base) {
    $failure = null;

    try {
        puRootCalculator()->powRatio($base, 1, 252, DecimalRounder::CALCULATION_SCALE);
    } catch (PuNumericConvergenceException $exception) {
        $failure = $exception;
    }

    expect($failure)->toBeInstanceOf(PuNumericConvergenceException::class)
        ->and($failure->base)->toBe($base)
        ->and($failure->root)->toBe(252)
        ->and($failure->getMessage())->toContain('não convergiu em 60 iterações')
        ->toContain($base);
})->with([
    // 10.000% a.a.: fora do alcance das 60 iterações. Antes saía um fator diário de 79,56.
    '10.000% a.a.' => ['101.0000000000000000'],
    // Menor que 10^-36: antes era tomada por zero e a raiz saía 0 (a exata é ~0,69).
    'base positiva ínfima' => ['0.'.str_repeat('0', 39).'1'],
]);

it('fails closed when the Newton step degenerates', function () {
    // Raiz cúbica de -2: o primeiro passo cai exatamente em zero. Antes, o laço
    // alternava entre 1 e 0 e devolvia 1,0 em silêncio.
    puRootCalculator()->powRatio('-2', 1, 3, DecimalRounder::CALCULATION_SCALE);
})->throws(PuNumericConvergenceException::class, 'zerou a derivada');

it('keeps the root of a zero base at zero', function () {
    $calculator = puRootCalculator();

    expect($calculator->factorDiForDay('-100.00000000', true, 252, DecimalRounder::CALCULATION_SCALE))
        ->toBe('0.000000000000000000000000')
        ->and($calculator->powRatio('0', 5, 252, DecimalRounder::CALCULATION_SCALE))
        ->toBe('0.000000000000000000000000');
});

it('refuses a negative base for a root of even degree', function () {
    // -150% a.a. dá base -0,5: não há raiz real de grau 252. Antes saía -1,28e29.
    puRootCalculator()->factorDiForDay('-150.00000000', true, 252, DecimalRounder::CALCULATION_SCALE);
})->throws(InvalidArgumentException::class, 'has no real root of even degree [252]');

it('keeps the real root of a negative base for an odd degree', function () {
    $factor = puRootCalculator()->powRatio('-0.5', 1, 31, DecimalRounder::CALCULATION_SCALE);

    expect($factor)->toBe('-0.977888536335432720265294');
    expectCorrectlyRoundedPuRoot($factor, '-0.5', 1, 31, DecimalRounder::CALCULATION_SCALE);
});

it('keeps rejecting a non-positive denominator', function (int $denominator) {
    puRootCalculator()->powRatio('1.1', 1, $denominator);
})->with([0, -252])->throws(InvalidArgumentException::class, 'The denominator for a rational power must be positive.');

it('keeps rejecting malformed decimals before any iteration', function () {
    $calculator = puRootCalculator();

    expect(fn () => $calculator->factorDiForDay('abc', true, 252))->toThrow(InvalidArgumentException::class, 'Invalid decimal value [abc].')
        ->and(fn () => $calculator->powRatio('abc', 1, 252))->toThrow(ValueError::class);
});

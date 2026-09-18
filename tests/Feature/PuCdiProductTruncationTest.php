<?php

use App\Domain\PuCalculator\DTOs\PuDailyCurveRowData;
use App\Domain\PuCalculator\DTOs\PuSimulationInput;
use App\Domain\PuCalculator\DTOs\PuSimulationResult;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use App\Domain\PuCalculator\Enums\PuSimulationState;
use App\Domain\PuCalculator\Services\CdiFactorCompositionService;
use App\Domain\PuCalculator\Services\DecimalRounder;
use App\Domain\PuCalculator\Services\PuPrecisionPolicy;
use App\Domain\PuCalculator\Services\PuSimulationService;
use App\Filament\Resources\Emissions\Pages\PuCalculatorSimulator;
use App\Models\EmissionPuParameter;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Pu\PuSimulationFixture;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Http::preventStrayRequests();
});

/**
 * Regra contratual do Termo de Securitização para o PRODUTÓRIO do Fator DI:
 *
 *     acumulado = 1
 *     para cada fator diário:
 *         acumulado = acumulado x fator_diario
 *         acumulado = TRUNCAR(acumulado, 16)
 *     fator_di_final = ARREDONDAR(acumulado, 8)
 *
 * Truncar não é arredondar. Os casos deste arquivo são construídos com strings
 * decimais montadas à mão justamente para que truncamento e arredondamento
 * produzam resultados DIFERENTES -- um teste cujo caso não distingue os dois
 * passaria igual com a implementação errada.
 *
 * Nenhuma expectativa deste arquivo é construída com `float`: os valores
 * escolhidos (17 casas decimais) nem sequer são representáveis em IEEE 754.
 */

// ---------------------------------------------------------------------------
// Produtório: truncamento progressivo em 16 casas
// ---------------------------------------------------------------------------

function cdiProductParameter(?PuIndexRateLookupMode $mode = null): EmissionPuParameter
{
    $parameter = PuSimulationFixture::syntheticParameter();

    if ($mode instanceof PuIndexRateLookupMode) {
        $parameter->forceFill(['index_rate_lookup_mode' => $mode->value]);
    }

    return $parameter;
}

function cdiProductComposition(): CdiFactorCompositionService
{
    return app(CdiFactorCompositionService::class);
}

it('truncates the accumulated DI product at sixteen decimals instead of rounding it', function () {
    $composition = cdiProductComposition();
    $rounder = app(DecimalRounder::class);
    $parameter = cdiProductParameter();

    // 1,00000000000000015 -> a 17ª casa é 5. Truncar corta; arredondar sobe.
    $accumulated = $composition->accumulateIndexFactor(
        $parameter,
        $rounder->normalize('1', DecimalRounder::CALCULATION_SCALE),
        '1.00000000000000015',
    );

    expect($accumulated)->toBe('1.000000000000000100000000')
        // A implementação errada (arredondar em 16) daria exatamente isto:
        ->and($rounder->normalize($rounder->round('1.00000000000000015', 16), DecimalRounder::CALCULATION_SCALE))
        ->toBe('1.000000000000000200000000')
        ->and($accumulated)->not->toBe('1.000000000000000200000000')
        // Truncamento decimal real: as casas 17 a 24 do acumulado são zero.
        ->and($accumulated)->toBe($rounder->normalize(
            $rounder->truncate($accumulated, 16),
            DecimalRounder::CALCULATION_SCALE,
        ));
});

it('feeds every following daily factor with the already truncated product', function () {
    $composition = cdiProductComposition();
    $rounder = app(DecimalRounder::class);
    $parameter = cdiProductParameter();

    // 1º fator: 1 x 1,00000000000000015 -> trunca -> 1,0000000000000001
    $first = $composition->accumulateIndexFactor(
        $parameter,
        $rounder->normalize('1', DecimalRounder::CALCULATION_SCALE),
        '1.00000000000000015',
    );

    // 2º fator: entra o acumulado JÁ TRUNCADO.
    //   com truncamento:  1,0000000000000001 x 2 = 2,0000000000000002
    //   sem truncamento:  1,00000000000000015 x 2 = 2,0000000000000003
    $second = $composition->accumulateIndexFactor($parameter, $first, '2');

    // 3º fator: entra o novo acumulado truncado.
    //   com truncamento:  2,0000000000000002 x 3 = 6,0000000000000006
    //   sem truncamento:  2,0000000000000003 x 3 = 6,0000000000000009
    $third = $composition->accumulateIndexFactor($parameter, $second, '3');

    // 4º e 5º fatores: o produtório continua estável para N fatores, e o
    // acumulado permanece exatamente em 16 casas significativas.
    $fourth = $composition->accumulateIndexFactor($parameter, $third, '1');
    $fifth = $composition->accumulateIndexFactor($parameter, $fourth, '1.00000000000000001');

    expect($first)->toBe('1.000000000000000100000000')
        ->and($second)->toBe('2.000000000000000200000000')
        ->and($second)->not->toBe('2.000000000000000300000000')
        ->and($third)->toBe('6.000000000000000600000000')
        ->and($third)->not->toBe('6.000000000000000900000000')
        ->and($fourth)->toBe('6.000000000000000600000000')
        // 6,0000000000000006 x 1,00000000000000001 = 6,000000000000000660000...
        // Truncado em 16 continua 6,0000000000000006; arredondado subiria a ...07.
        ->and($fifth)->toBe('6.000000000000000600000000')
        ->and($fifth)->not->toBe('6.000000000000000700000000')
        ->and($fifth)->toBe($rounder->normalize(
            $rounder->truncate($fifth, 16),
            DecimalRounder::CALCULATION_SCALE,
        ));
});

it('preserves the sign when the accumulated product is negative', function () {
    $composition = cdiProductComposition();
    $parameter = cdiProductParameter();

    // O Fator DI do Alto é positivo; isto apenas prova que o corte é em direção
    // a zero, e não em direção a menos infinito.
    $accumulated = $composition->accumulateIndexFactor(
        $parameter,
        '-1.000000000000000000000000',
        '1.00000000000000015',
    );

    expect($accumulated)->toBe('-1.000000000000000100000000')
        ->and($accumulated)->not->toBe('-1.000000000000000200000000');
});

it('leaves the non exact lookup modes on the calculation scale, as before', function () {
    $composition = cdiProductComposition();
    $rounder = app(DecimalRounder::class);

    foreach ([
        PuIndexRateLookupMode::PreviousAvailableBusinessDay,
        PuIndexRateLookupMode::PreviousCalendarDayExact,
    ] as $mode) {
        $accumulated = $composition->accumulateIndexFactor(
            cdiProductParameter($mode),
            $rounder->normalize('1', DecimalRounder::CALCULATION_SCALE),
            '1.00000000000000015',
        );

        // A 17ª casa continua viva na escala de cálculo: nenhum truncamento em 16.
        expect($accumulated)->toBe('1.000000000000000150000000');
    }
});

it('never converts the DI product path through a float', function () {
    $source = file_get_contents(base_path('app/Domain/PuCalculator/Services/CdiFactorCompositionService.php'));

    expect($source)->not->toContain('(float)')
        ->not->toContain('floatval')
        ->not->toContain('number_format')
        // `round()` nativo do PHP opera em float; a engine usa DecimalRounder.
        ->not->toMatch('/(?<![>:\w])round\(/');

    $truncated = app(DecimalRounder::class)->truncate('1.12345678901234569', 16);

    expect($truncated)->toBeString()->toBe('1.1234567890123456')
        ->and($truncated)->not->toBe('1.1234567890123457');
});

// ---------------------------------------------------------------------------
// Curva do Alto Bellevue: comparação ANTES x DEPOIS
// ---------------------------------------------------------------------------

/** Fim de janela que cobre os três primeiros cupons e o reset de cada um. */
function cdiProductWindowEnd(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-08-31')->startOfDay();
}

function cdiProductSimulate(): PuSimulationResult
{
    $emission = PuSimulationFixture::contractualEmission();
    PuSimulationFixture::seedRequiredRates(
        PuSimulationFixture::integralizationDate(),
        cdiProductWindowEnd(),
    );

    return app(PuSimulationService::class)->simulate($emission, new PuSimulationInput(
        firstIntegralizationDate: PuSimulationFixture::integralizationDate(),
        simulationEndDate: cdiProductWindowEnd(),
    ));
}

/** Datas auditadas: pagamentos, dias seguintes ao reset e dias de acúmulo puro. */
function cdiProductAuditedDates(): array
{
    return [
        '2026-05-18',
        '2026-06-05',
        '2026-06-08',
        '2026-06-09',
        '2026-07-08',
        '2026-07-09',
        '2026-08-10',
        '2026-08-11',
        '2026-08-31',
    ];
}

/**
 * Reexecuta a cadeia de composição da engine a partir dos INSUMOS da memória de
 * cálculo (fator diário, fator spread, prêmio), trocando APENAS a regra do
 * produtório. `$truncatesProduct = true` reproduz a engine atual; `false`
 * reproduz o comportamento anterior (arredondar o acumulado na escala de
 * cálculo), e é o que dá o lado "ANTES" da comparação.
 *
 * Tudo em string/BCMath, sem nenhum `float`.
 *
 * @param  list<PuDailyCurveRowData>  $rows
 * @return array<string, array<string, string|int|null>>
 */
function cdiProductReplay(array $rows, bool $truncatesProduct): array
{
    $composition = cdiProductComposition();
    $rounder = app(DecimalRounder::class);
    // VNb, J e SDa são quantizados em 8 casas SEM arredondamento pela engine; o replay
    // precisa fazer o mesmo, ou deixaria de ser fiel assim que o VNb deixar de ser redondo.
    $precision = app(PuPrecisionPolicy::class);
    $parameter = PuSimulationFixture::syntheticParameter(
        PuSimulationFixture::integralizationDate(),
        cdiProductWindowEnd(),
    );
    $scale = DecimalRounder::CALCULATION_SCALE;
    $work = $scale + 4;

    $accumulated = $rounder->normalize('1', $scale);
    $base = $precision->unitValue('1000');
    $previousHadPayment = false;
    $residual = $base;
    $replay = [];

    foreach ($rows as $index => $row) {
        $memory = $row->calculationMemory;

        if ($index > 0 && $previousHadPayment) {
            $base = $residual;
            $accumulated = $rounder->normalize('1', $scale);
        }

        if ($index === 0) {
            // Linha da integralização: a engine não multiplica o produtório e
            // zera Fator Spread e Fator Spread x DI.
            $combined = $rounder->normalize('0', $scale);
            $interestFactor = $combined;
            $interest = $rounder->normalize('0', $scale);
            $updated = $base;
        } else {
            $accumulated = $truncatesProduct
                ? $composition->accumulateIndexFactor($parameter, $accumulated, $memory['factor_di_raw'])
                : $rounder->round(bcmul($accumulated, $memory['factor_di_raw'], $work), $scale);

            // `combinedFactor` já arredonda o Fator DI em 8 casas antes de
            // multiplicar pelo Fator Spread; `factorForInterest` fecha em 9.
            $combined = $composition->combinedFactor($parameter, $accumulated, $memory['factor_spread_raw']);
            $interestFactor = $composition->factorForInterest($parameter, $combined);

            if (($memory['first_coupon_pre_integralization_premium_applied'] ?? false) === true) {
                $combined = $composition->factorForInterest($parameter, $rounder->round(
                    bcmul(
                        $interestFactor,
                        $memory['first_coupon_pre_integralization_premium']['factor'],
                        $work,
                    ),
                    $scale,
                ));
                $interestFactor = $combined;
            }

            $interest = $precision->unitValue($rounder->round(
                bcmul($base, bcsub($interestFactor, '1', $work), $work),
                $scale,
            ));
            $updated = $precision->unitValue(bcadd($base, $interest, $work));
        }

        $payment = in_array('interest_payment', $memory['event_types'] ?? [], true)
            ? $interest
            : $precision->unitValue('0');
        $residual = $precision->unitValue(bcsub($updated, $payment, $work));

        $replay[$row->date->toDateString()] = [
            'index_rate_date' => $row->indexRateDate?->toDateString(),
            'index_rate_value' => $row->indexRateValue,
            'dup_interest' => $row->dupInterest,
            'factor_di_accumulated' => $accumulated,
            'factor_di_final' => $composition->indexFactorForCombination($parameter, $accumulated),
            'factor_spread' => $memory['factor_spread_raw'],
            'factor_spread_di' => $combined,
            'interest_factor' => $interestFactor,
            'interest' => $interest,
            'payment' => $payment,
            'updated_unit_value' => $updated,
            'residual_unit_value' => $residual,
        ];

        $previousHadPayment = $row->hasUnitPayment();
    }

    return $replay;
}

it('replays the engine exactly, which is what licenses the before and after comparison', function () {
    $result = cdiProductSimulate();
    $rows = $result->rows;
    $replay = cdiProductReplay($rows, truncatesProduct: true);

    expect($result->state)->toBe(PuSimulationState::Calculated);

    // Nenhuma amortização na janela: o pagamento do replay é só juros, como o da engine.
    foreach ($rows as $row) {
        expect($row->amortizationUnitValue)->toBe('0.0000000000000000');
    }

    foreach ($rows as $row) {
        $date = $row->date->toDateString();
        $memory = $row->calculationMemory;

        expect($replay[$date]['factor_di_accumulated'])->toBe($memory['factor_di_accumulated_raw'])
            ->and($replay[$date]['factor_spread_di'])->toBe($memory['factor_spread_di_raw'])
            ->and($replay[$date]['interest'])->toBe($memory['interest_real_unit_value_raw'])
            ->and($replay[$date]['updated_unit_value'])->toBe($memory['updated_unit_value_raw'])
            ->and($replay[$date]['payment'])->toBe($memory['payment_total_unit_value_raw'])
            ->and($replay[$date]['residual_unit_value'])->toBe($memory['residual_unit_value_raw']);
    }
});

it('reports the numeric impact of the progressive truncation on the audited dates', function () {
    $result = cdiProductSimulate();
    $rows = $result->rows;

    $after = cdiProductReplay($rows, truncatesProduct: true);
    $before = cdiProductReplay($rows, truncatesProduct: false);

    $fields = [
        'index_rate_date', 'index_rate_value', 'dup_interest',
        'factor_di_accumulated', 'factor_di_final', 'factor_spread', 'factor_spread_di',
        'interest_factor', 'interest', 'payment', 'updated_unit_value', 'residual_unit_value',
    ];

    $comparison = [];

    foreach (cdiProductAuditedDates() as $date) {
        expect($after)->toHaveKey($date);

        foreach ($fields as $field) {
            $comparison[$date][$field] = [
                'before' => $before[$date][$field],
                'after' => $after[$date][$field],
                'changed' => $before[$date][$field] !== $after[$date][$field],
            ];
        }
    }

    // A tabela completa vai para a saída do teste: o efeito é para ser LIDO, não
    // presumido. Nenhuma expectativa abaixo afirma "não muda nada".
    dump($comparison);

    foreach (cdiProductAuditedDates() as $date) {
        // Insumos que não passam pelo produtório: idênticos por construção.
        expect($after[$date]['index_rate_date'])->toBe($before[$date]['index_rate_date'])
            ->and($after[$date]['index_rate_value'])->toBe($before[$date]['index_rate_value'])
            ->and($after[$date]['dup_interest'])->toBe($before[$date]['dup_interest'])
            ->and($after[$date]['factor_spread'])->toBe($before[$date]['factor_spread']);

        // Cada multiplicação do período pode perder no máximo uma unidade da
        // 16ª casa, e a perda só é reamplificada pelos fatores diários
        // seguintes (~1,0006 cada). A folga de 4 unidades cobre essa
        // reamplificação sem deixar de ser um limite apertado: uma ordem de
        // grandeza a mais reprovaria o teste.
        $bound = bcmul((string) ($after[$date]['dup_interest'] + 4), '0.0000000000000001', 24);

        expect(bccomp(
            app(DecimalRounder::class)->absoluteDifference(
                $after[$date]['factor_di_accumulated'],
                $before[$date]['factor_di_accumulated'],
                20,
            ),
            $bound,
            20,
        ))->toBeLessThanOrEqual(0);
    }
});

it('keeps the accumulated DI factor of every curve row exactly at sixteen decimals', function () {
    $result = cdiProductSimulate();
    $rounder = app(DecimalRounder::class);

    expect($result->rows)->not->toBeEmpty();

    foreach ($result->rows as $row) {
        $raw = $row->calculationMemory['factor_di_accumulated_raw'];

        // Prova direta do truncamento progressivo na curva inteira: se o
        // produtório fosse carregado em escala 24, as casas 17 a 24 do
        // acumulado não seriam zero em nenhuma linha profunda do período.
        expect($raw)->toBe($rounder->normalize(
            $rounder->truncate($raw, 16),
            DecimalRounder::CALCULATION_SCALE,
        ));
    }
});

it('keeps the contractual precision of the other stages untouched', function () {
    $result = cdiProductSimulate();
    $rounder = app(DecimalRounder::class);

    foreach ($result->rows as $index => $row) {
        $memory = $row->calculationMemory;
        $rules = $memory['precision_rules'];

        expect($rules['daily_index_factor'])->toBe(8)
            ->and($rules['index_factor_for_combination'])->toBe(8)
            ->and($rules['spread_factor'])->toBe(9)
            ->and($rules['combined_interest_factor'])->toBe(9)
            ->and($rules['accumulated_index_factor'])->toBe(16)
            ->and($rules['accumulated_index_factor_mode'])->toBe('truncate_after_each_multiplication');

        // Fator DI diário: arredondado (não truncado) em 8 casas, logo estável
        // sob um novo arredondamento em 8.
        expect($memory['factor_di_raw'])->toBe($rounder->normalize(
            $rounder->round($memory['factor_di_raw'], 8),
            DecimalRounder::CALCULATION_SCALE,
        ));

        if ($index === 0) {
            continue;
        }

        // Fator Spread: arredondado em 9 casas.
        expect($memory['factor_spread_raw'])->toBe($rounder->normalize(
            $rounder->round($memory['factor_spread_raw'], 9),
            DecimalRounder::CALCULATION_SCALE,
        ));

        // Fator DI x Fator Spread: o produto bruto fica na escala de cálculo, e
        // é o ARREDONDAMENTO EM 9 CASAS dele que entra nos juros. Reconstruir os
        // juros a partir do fator arredondado em 9 é a prova direta de que essa
        // etapa contratual continua valendo.
        if (($memory['first_coupon_pre_integralization_premium_applied'] ?? false) === true) {
            continue;
        }

        $interestFactor = $rounder->normalize(
            $rounder->round($memory['factor_spread_di_raw'], 9),
            DecimalRounder::CALCULATION_SCALE,
        );

        expect($memory['interest_real_unit_value_raw'])->toBe(app(PuPrecisionPolicy::class)->unitValue(
            $rounder->round(
                bcmul(
                    $memory['base_unit_value_raw'],
                    bcsub($interestFactor, '1', DecimalRounder::CALCULATION_SCALE + 4),
                    DecimalRounder::CALCULATION_SCALE + 4,
                ),
                DecimalRounder::CALCULATION_SCALE,
            ),
        ));
    }
});

// ---------------------------------------------------------------------------
// Invariantes do cupom, do prêmio e do reset
// ---------------------------------------------------------------------------

it('keeps the premium on the first coupon only and the coupon calendar intact', function () {
    $result = cdiProductSimulate();
    $byDate = [];

    foreach ($result->rows as $row) {
        $byDate[$row->date->toDateString()] = $row;
    }

    $coupons = array_values(array_filter(
        $result->rows,
        fn (PuDailyCurveRowData $row): bool => in_array(
            'interest_payment',
            $row->calculationMemory['event_types'] ?? [],
            true,
        ),
    ));

    expect(array_map(fn (PuDailyCurveRowData $row): string => $row->date->toDateString(), $coupons))
        ->toBe(['2026-06-08', '2026-07-08', '2026-08-10'])
        // Prêmio: aplicado no primeiro cupom e em nenhum outro.
        ->and($coupons[0]->calculationMemory['first_coupon_pre_integralization_premium_applied'])->toBeTrue()
        ->and($coupons[1]->calculationMemory['first_coupon_pre_integralization_premium_applied'])->toBeFalse()
        ->and($coupons[2]->calculationMemory['first_coupon_pre_integralization_premium_applied'])->toBeFalse()
        // 10/08 só existe por Following: 08/08 é sábado.
        ->and($byDate['2026-08-08']->isBusinessDay)->toBeFalse();

    // Reset: o dia seguinte a cada cupom reinicia DUP e produtório.
    foreach (['2026-06-09', '2026-07-09', '2026-08-11'] as $dayAfter) {
        expect($byDate[$dayAfter]->dupInterest)->toBe(1)
            ->and($byDate[$dayAfter]->factorDiAccumulated)->toBe($byDate[$dayAfter]->factorDi)
            ->and($byDate[$dayAfter]->calculationMemory['first_coupon_pre_integralization_premium_applied'])->toBeFalse();
    }

    // Dia não útil não avança DUP nem multiplica o produtório: o acumulado e o
    // DUP da véspera atravessam o feriado/fim de semana intactos.
    $previous = null;

    foreach ($result->rows as $row) {
        if ($previous !== null && ! $row->isBusinessDay) {
            expect($row->dupInterest)->toBe($previous->dupInterest)
                ->and($row->factorDiAccumulated)->toBe($previous->factorDiAccumulated);
        }

        $previous = $row->hasUnitPayment() ? null : $row;
    }
});

it('keeps the CDI date exactly five business days behind the curve date', function () {
    $result = cdiProductSimulate();
    $businessDates = [];

    foreach ($result->rows as $row) {
        if ($row->isBusinessDay) {
            $businessDates[] = $row->date->toDateString();
        }
    }

    $checked = 0;

    foreach ($result->rows as $row) {
        $rateDate = $row->indexRateDate?->toDateString();

        // Só as linhas cuja Data CDI já está DENTRO da curva podem ser contadas
        // a partir dos próprios dias úteis da curva.
        if (! $row->isBusinessDay || $rateDate === null || $rateDate < $businessDates[0]) {
            continue;
        }

        $businessDaysBetween = count(array_filter(
            $businessDates,
            fn (string $date): bool => $date > $rateDate && $date <= $row->date->toDateString(),
        ));

        // Lag -5 DU: a taxa observada é a do 5º Dia Útil anterior, contado sobre
        // os dias úteis da própria curva -- sem reusar o shift do calendário.
        expect($businessDaysBetween)->toBe(5);
        $checked++;
    }

    expect($checked)->toBeGreaterThan(50);
});

it('keeps the first coupon paying the whole interest and leaving the principal', function () {
    $result = cdiProductSimulate();
    $presenter = app(App\Support\PuCalculator\PuDecimalPresenter::class);

    $firstCoupon = null;

    foreach ($result->rows as $row) {
        if ($row->date->toDateString() === '2026-06-08') {
            $firstCoupon = $row;
        }
    }

    expect($firstCoupon)->not->toBeNull()
        ->and($firstCoupon->interestPaymentUnitValue)->toBe($firstCoupon->interestRealUnitValue)
        ->and($firstCoupon->residualUnitValue)->toBe('1000.0000000000000000')
        // Apresentação monetária permanece em 8 casas, notação pt-BR.
        ->and($presenter->money($firstCoupon->residualUnitValue))->toBe('1.000,00000000')
        ->and($presenter->money($firstCoupon->updatedUnitValue))->toMatch('/^1\.\d{3},\d{8}$/');
});

// ---------------------------------------------------------------------------
// Memória de cálculo na tela
// ---------------------------------------------------------------------------

it('names the accumulated spread factor and the progressive truncation in the memory panel', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs(makeAdminUser());

    $scenario = PuSimulationFixture::calculableScenario();

    Livewire::test(PuCalculatorSimulator::class, ['record' => $scenario['emission']->getRouteKey()])
        ->set('firstIntegralizationDate', PuSimulationFixture::integralizationDate()->toDateString())
        ->set('simulationEndDate', PuSimulationFixture::windowEndDate()->toDateString())
        ->call('calculate')
        ->assertOk()
        // O valor exibido é o Fator Spread do PERÍODO (DUP dias úteis), nunca o
        // fator de 1 Dia Útil: o rótulo antigo descrevia a conta errada.
        ->assertSee('Fator Spread acumulado')
        ->assertDontSee('Fator spread diário')
        ->assertSee('Produtório DI acumulado')
        ->assertSee('truncamento progressivo em 16 casas após cada multiplicação');
});

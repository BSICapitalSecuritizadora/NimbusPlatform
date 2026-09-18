<?php

use App\Domain\PuCalculator\DTOs\PuDailyCurveRowData;
use App\Domain\PuCalculator\DTOs\PuSimulationInput;
use App\Domain\PuCalculator\Enums\PuAmortizationType;
use App\Domain\PuCalculator\Enums\PuEventType;
use App\Domain\PuCalculator\Enums\PuSimulationState;
use App\Domain\PuCalculator\Services\DecimalRounder;
use App\Domain\PuCalculator\Services\IndexRateLookupService;
use App\Domain\PuCalculator\Services\PuCurveGeneratorService;
use App\Domain\PuCalculator\Services\PuPrecisionPolicy;
use App\Domain\PuCalculator\Services\PuSimulationService;
use App\Models\EmissionPuEvent;
use App\Support\PuCalculator\PuDecimalPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Pu\PuSimulationFixture;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Http::preventStrayRequests();
});

/**
 * Precisão MONETÁRIA contratual: VNb, J, AMi e SDa em 8 casas SEM arredondamento.
 *
 * "Sem arredondamento" é corte. Os casos abaixo são construídos de modo que
 * truncar e arredondar produzam resultados DIFERENTES -- um caso cujo 9º dígito
 * seja menor que 5 passaria igual com a implementação errada.
 *
 * Nenhuma expectativa é montada com `float`: todas são strings decimais.
 */

// ---------------------------------------------------------------------------
// A política, isolada
// ---------------------------------------------------------------------------

it('cuts the ninth decimal of a unit value instead of rounding it up', function (
    string $value,
    string $truncated,
    string $rounded,
) {
    $policy = app(PuPrecisionPolicy::class);
    $rounder = app(DecimalRounder::class);

    expect($policy->unitValue($value))->toBe($truncated)
        ->and($rounder->normalize($rounder->round($value, 8), DecimalRounder::CALCULATION_SCALE))->toBe($rounded)
        ->and($truncated)->not->toBe($rounded);
})->with([
    'nove na nona casa' => [
        '1000.765375999999',
        '1000.765375990000000000000000',
        '1000.765376000000000000000000',
    ],
    'meio exato para cima' => [
        '11.296121205',
        '11.296121200000000000000000',
        '11.296121210000000000000000',
    ],
    'amortização percentual' => [
        '123.456789195',
        '123.456789190000000000000000',
        '123.456789200000000000000000',
    ],
    'negativo corta em direção a zero' => [
        '-11.296121205',
        '-11.296121200000000000000000',
        '-11.296121210000000000000000',
    ],
]);

it('is idempotent and never adds precision that was not there', function () {
    $policy = app(PuPrecisionPolicy::class);
    $once = $policy->unitValue('1013.091371');

    expect($once)->toBe('1013.091371000000000000000000')
        ->and($policy->unitValue($once))->toBe($once)
        ->and($policy->unitValue('0'))->toBe('0.000000000000000000000000')
        ->and($policy->rules())->toMatchArray([
            'unit_base_value' => 8,
            'interest_unit_value' => 8,
            'amortization_unit_value' => 8,
            'residual_unit_value' => 8,
            'quantization' => 'truncate_toward_zero',
        ]);
});

// ---------------------------------------------------------------------------
// A curva do Alto: VNb, J, PU, pagamento e SDa
// ---------------------------------------------------------------------------

function monetaryPrecisionWindowEnd(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-08-31')->startOfDay();
}

function monetaryPrecisionSimulate(): App\Domain\PuCalculator\DTOs\PuSimulationResult
{
    $emission = PuSimulationFixture::contractualEmission();
    PuSimulationFixture::seedRequiredRates(
        PuSimulationFixture::integralizationDate(),
        monetaryPrecisionWindowEnd(),
    );

    return app(PuSimulationService::class)->simulate($emission, new PuSimulationInput(
        firstIntegralizationDate: PuSimulationFixture::integralizationDate(),
        simulationEndDate: monetaryPrecisionWindowEnd(),
    ));
}

it('quantizes every contractual unit value of the curve at eight decimals', function () {
    $result = monetaryPrecisionSimulate();
    $policy = app(PuPrecisionPolicy::class);

    expect($result->state)->toBe(PuSimulationState::Calculated)
        ->and($result->rows)->not->toBeEmpty();

    foreach ($result->rows as $row) {
        $memory = $row->calculationMemory;

        // Prova direta: o valor da engine é IGUAL ao seu próprio truncamento em 8.
        // Se alguma etapa ainda carregasse escala 24, as casas 9 a 24 não seriam zero.
        foreach ([
            'base_unit_value_raw',
            'interest_real_unit_value_raw',
            'updated_unit_value_raw',
            'amortization_unit_value_raw',
            'interest_payment_unit_value_raw',
            'payment_total_unit_value_raw',
            'residual_unit_value_raw',
        ] as $key) {
            expect($memory[$key])->toBe($policy->unitValue($memory[$key]));
        }
    }
});

it('pays and composes the PU from the quantized J, never from a hidden raw J', function () {
    $result = monetaryPrecisionSimulate();
    $rounder = app(DecimalRounder::class);

    foreach ($result->rows as $row) {
        $memory = $row->calculationMemory;

        // PU = VNb + J, com os componentes contratuais -- e não VNb + J bruto.
        expect($memory['updated_unit_value_raw'])->toBe($rounder->normalize(
            bcadd($memory['base_unit_value_raw'], $memory['interest_real_unit_value_raw'], DecimalRounder::CALCULATION_SCALE),
            DecimalRounder::CALCULATION_SCALE,
        ));

        // Pagamento de juros = J contratual, sem versão paralela.
        if (in_array('interest_payment', $memory['event_types'] ?? [], true)) {
            expect($memory['interest_payment_unit_value_raw'])->toBe($memory['interest_real_unit_value_raw']);
        }

        // SDa = PU - pagamento total, também contratual.
        expect($memory['residual_unit_value_raw'])->toBe($rounder->normalize(
            bcsub($memory['updated_unit_value_raw'], $memory['payment_total_unit_value_raw'], DecimalRounder::CALCULATION_SCALE),
            DecimalRounder::CALCULATION_SCALE,
        ));
    }
});

it('keeps the raw J available for audit alongside the contractual J', function () {
    $result = monetaryPrecisionSimulate();
    $policy = app(PuPrecisionPolicy::class);
    $seenDifference = false;

    foreach ($result->rows as $row) {
        $memory = $row->calculationMemory;

        expect($memory)->toHaveKeys([
            'interest_real_unit_value_unquantized_raw',
            'base_unit_value_unquantized_raw',
        ]);

        // O J contratual é EXATAMENTE o corte do J bruto -- a memória não guarda
        // dois números sem relação.
        expect($memory['interest_real_unit_value_raw'])
            ->toBe($policy->unitValue($memory['interest_real_unit_value_unquantized_raw']));

        if ($memory['interest_real_unit_value_raw'] !== $memory['interest_real_unit_value_unquantized_raw']) {
            $seenDifference = true;
        }
    }

    // Nesta janela o VNb é sempre 1.000 exato e o Fator de Juros tem 9 casas, então
    // J cai em múltiplos exatos de 1e-6 e o corte é inócuo. A afirmação é essa, não
    // "o corte nunca faz nada": o teste apenas registra o que se observou.
    expect($seenDifference)->toBeFalse();
});

/**
 * Por que os valores de controle da fase anterior sobrevivem à quantização.
 *
 * Enquanto o VNb for 1.000,00 exato, J = 1.000 x (Fator de Juros - 1) com um
 * Fator de Juros de 9 casas cai SEMPRE em múltiplo exato de 1e-6 -- restam seis
 * casas decimais, e cortar na oitava não tem o que cortar. A garantia é essa, e
 * não uma tabela de PUs fixada no teste: este arquivo NÃO afirma que a curva
 * inteira permanece byte a byte, afirma a propriedade que a torna inevitável
 * NESTA janela, e mede quando ela deixa de valer.
 */
it('leaves the monetary quantization inert while the VNb is a round principal', function () {
    $result = monetaryPrecisionSimulate();
    $rounder = app(DecimalRounder::class);

    foreach ($result->rows as $row) {
        $memory = $row->calculationMemory;

        if ($memory['base_unit_value_raw'] !== '1000.000000000000000000000000') {
            continue;
        }

        // Seis casas decimais: o corte em 8 é inócuo por construção, não por sorte.
        expect($memory['interest_real_unit_value_raw'])->toBe($rounder->normalize(
            $rounder->truncate($memory['interest_real_unit_value_raw'], 6),
            DecimalRounder::CALCULATION_SCALE,
        ))
            ->and($memory['interest_real_unit_value_raw'])
            ->toBe($memory['interest_real_unit_value_unquantized_raw']);
    }
});

it('lets the engine decide the economic value and the presenter only format it', function () {
    $result = monetaryPrecisionSimulate();
    $presenter = app(PuDecimalPresenter::class);
    $policy = app(PuPrecisionPolicy::class);

    foreach ($result->rows as $row) {
        // A apresentação arredonda em 8; a engine já entregou 8. Como os dois
        // coincidem, a tela não pode discordar do pagamento em nenhuma casa.
        expect($presenter->money($row->updatedUnitValue))
            ->toBe($presenter->money($policy->unitValue($row->updatedUnitValue)))
            ->and($presenter->money($row->interestRealUnitValue))
            ->toBe($presenter->money($policy->unitValue($row->interestRealUnitValue)));
    }
});

// ---------------------------------------------------------------------------
// AMi e SDa: cenário COM amortização
// ---------------------------------------------------------------------------

/**
 * Curva com uma amortização percentual cujo valor bruto tem 9 casas decimais.
 * 1.000 x 0,1234567891950000 = 123,456789195 -> truncado 123,45678919.
 * Arredondado seria 123,45678920: os dois casos são distinguíveis.
 *
 * @return list<PuDailyCurveRowData>
 */
function monetaryPrecisionAmortizationRows(): array
{
    $windowEnd = CarbonImmutable::parse('2026-06-30')->startOfDay();
    $emission = PuSimulationFixture::contractualEmission();
    PuSimulationFixture::seedRequiredRates(PuSimulationFixture::integralizationDate(), $windowEnd);

    $parameter = PuSimulationFixture::syntheticParameter(
        PuSimulationFixture::integralizationDate(),
        $windowEnd,
    );

    $amortization = new EmissionPuEvent;
    $amortization->exists = false;
    $amortization->forceFill([
        'event_type' => PuEventType::Amortization->value,
        'original_date' => '2026-06-08',
        'effective_date' => '2026-06-08',
        'amortization_type' => PuAmortizationType::Percentage->value,
        'amortization_value' => '0.1234567891950000',
        'sequence' => 1,
    ]);
    $amortization->setAttribute('id', 1);

    $scenario = clone $emission;
    $scenario->setRelation('puParameter', $parameter);
    $scenario->setRelation('puEvents', new EloquentCollection([$amortization]));
    $scenario->setRelation('integralizationHistories', new EloquentCollection);
    app(IndexRateLookupService::class)->flushCache();

    return app(PuCurveGeneratorService::class)->handle($scenario)->rows;
}

it('truncates AMi at eight decimals instead of rounding it', function () {
    $rows = monetaryPrecisionAmortizationRows();
    $amortizationRow = null;

    foreach ($rows as $row) {
        if ($row->date->toDateString() === '2026-06-08') {
            $amortizationRow = $row;
        }
    }

    expect($amortizationRow)->not->toBeNull();

    $memory = $amortizationRow->calculationMemory;

    // 123,456789195 cortado em 8 = 123,45678919. Arredondado seria ...20.
    expect($memory['amortization_unit_value_raw'])->toBe('123.456789190000000000000000')
        ->and($memory['amortization_unit_value_raw'])->not->toBe('123.456789200000000000000000')
        ->and($amortizationRow->amortizationUnitValue)->toBe('123.4567891900000000');
});

it('carries a quantized SDa into the VNb of the next period', function () {
    $rows = monetaryPrecisionAmortizationRows();
    $policy = app(PuPrecisionPolicy::class);
    $byDate = [];

    foreach ($rows as $row) {
        $byDate[$row->date->toDateString()] = $row;
    }

    $paymentRow = $byDate['2026-06-08'] ?? null;
    $nextRow = $byDate['2026-06-09'] ?? null;

    expect($paymentRow)->not->toBeNull()
        ->and($nextRow)->not->toBeNull();

    // SDa em 8 casas, e é ELE que vira o VNb do período seguinte. Uma cauda em
    // escala 24 atravessaria o reset e contaminaria todos os cupons futuros.
    expect($paymentRow->calculationMemory['residual_unit_value_raw'])
        ->toBe($policy->unitValue($paymentRow->calculationMemory['residual_unit_value_raw']))
        ->and($nextRow->calculationMemory['base_unit_value_raw'])
        ->toBe($paymentRow->calculationMemory['residual_unit_value_raw'])
        ->and($nextRow->unitBaseValue)->toBe($paymentRow->residualUnitValue);
});

// ---------------------------------------------------------------------------
// Nada da fase anterior regrediu
// ---------------------------------------------------------------------------

it('preserves every factor rule and coupon invariant already validated', function () {
    $result = monetaryPrecisionSimulate();
    $rounder = app(DecimalRounder::class);
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
        // Prêmio: primeiro cupom e mais nenhum.
        ->and($coupons[0]->calculationMemory['first_coupon_pre_integralization_premium_applied'])->toBeTrue()
        ->and($coupons[1]->calculationMemory['first_coupon_pre_integralization_premium_applied'])->toBeFalse()
        ->and($coupons[2]->calculationMemory['first_coupon_pre_integralization_premium_applied'])->toBeFalse()
        // Following: 08/08 é sábado.
        ->and($byDate['2026-08-08']->isBusinessDay)->toBeFalse();

    foreach (['2026-06-09', '2026-07-09', '2026-08-11'] as $dayAfterCoupon) {
        expect($byDate[$dayAfterCoupon]->dupInterest)->toBe(1)
            ->and($byDate[$dayAfterCoupon]->factorDiAccumulated)->toBe($byDate[$dayAfterCoupon]->factorDi);
    }

    foreach ($result->rows as $index => $row) {
        $memory = $row->calculationMemory;
        $rules = $memory['precision_rules'];

        // Fatores: truncamento progressivo em 16, DI final 8, Spread 9, Juros 9.
        expect($rules['accumulated_index_factor'])->toBe(16)
            ->and($rules['accumulated_index_factor_mode'])->toBe('truncate_after_each_multiplication')
            ->and($rules['daily_index_factor'])->toBe(8)
            ->and($rules['index_factor_for_combination'])->toBe(8)
            ->and($rules['spread_factor'])->toBe(9)
            ->and($rules['combined_interest_factor'])->toBe(9)
            ->and($memory['factor_di_accumulated_raw'])->toBe($rounder->normalize(
                $rounder->truncate($memory['factor_di_accumulated_raw'], 16),
                DecimalRounder::CALCULATION_SCALE,
            ));

        if ($index === 0) {
            continue;
        }

        // Fator DI aplicado: o acumulado arredondado em 8 casas no contratual.
        expect($memory['factor_di_applied_raw'])->toBe($rounder->normalize(
            $rounder->round($memory['factor_di_accumulated_raw'], 8),
            DecimalRounder::CALCULATION_SCALE,
        ))
            ->and($memory['factor_spread_raw'])->toBe($rounder->normalize(
                $rounder->round($memory['factor_spread_raw'], 9),
                DecimalRounder::CALCULATION_SCALE,
            ))
            ->and($memory['interest_factor_applied_raw'])->toBe($rounder->normalize(
                $rounder->round($memory['interest_factor_applied_raw'], 9),
                DecimalRounder::CALCULATION_SCALE,
            ));
    }
});

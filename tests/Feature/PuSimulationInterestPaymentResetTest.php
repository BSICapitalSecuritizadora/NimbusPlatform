<?php

use App\Domain\PuCalculator\DTOs\PuDailyCurveRowData;
use App\Domain\PuCalculator\DTOs\PuSimulationInput;
use App\Domain\PuCalculator\DTOs\PuSimulationResult;
use App\Domain\PuCalculator\Enums\PuSimulationState;
use App\Domain\PuCalculator\Services\CdiFactorCompositionService;
use App\Domain\PuCalculator\Services\DecimalRounder;
use App\Domain\PuCalculator\Services\IndexRateLookupService;
use App\Domain\PuCalculator\Services\PuCurveGeneratorService;
use App\Domain\PuCalculator\Services\PuSimulationService;
use App\Models\EmissionPuParameter;
use App\Models\IntegralizationHistory;
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
 * Fim de janela estendido até depois do TERCEIRO cupom mensal. A janela curta do
 * fixture padrão (30/06) prova apenas a linha seguinte ao primeiro pagamento, e
 * o mesmo defeito reapareceria silenciosamente nos cupons seguintes. O terceiro
 * cupom importa em particular porque cai num sábado (08/08) e só existe pela
 * convenção Following.
 */
function interestResetWindowEnd(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-08-31')->startOfDay();
}

/**
 * Cenário do Alto Bellevue em modo simulação: baseline contratual confirmada,
 * calendário confirmado, taxas exatamente as exigidas -- e NENHUMA
 * integralização persistida, que é a condição real da emissão e a que expunha o
 * defeito.
 */
function interestResetSimulate(): PuSimulationResult
{
    $emission = PuSimulationFixture::contractualEmission();
    PuSimulationFixture::seedRequiredRates(
        PuSimulationFixture::integralizationDate(),
        interestResetWindowEnd(),
    );

    return app(PuSimulationService::class)->simulate($emission, new PuSimulationInput(
        firstIntegralizationDate: PuSimulationFixture::integralizationDate(),
        simulationEndDate: interestResetWindowEnd(),
    ));
}

/**
 * Parâmetro sintético equivalente ao entregue à engine, usado somente para
 * DERIVAR fatores de conferência -- nunca para recompor a curva.
 */
function interestResetParameter(): EmissionPuParameter
{
    return PuSimulationFixture::syntheticParameter(
        PuSimulationFixture::integralizationDate(),
        interestResetWindowEnd(),
    );
}

/**
 * Linhas com evento de pagamento de juros, na ordem da curva.
 *
 * @param  list<PuDailyCurveRowData>  $rows
 * @return list<PuDailyCurveRowData>
 */
function interestResetCouponRows(array $rows): array
{
    return array_values(array_filter(
        $rows,
        fn (PuDailyCurveRowData $row): bool => in_array(
            'interest_payment',
            $row->calculationMemory['event_types'] ?? [],
            true,
        ),
    ));
}

/**
 * Linha imediatamente posterior a uma data, na ordem da curva.
 *
 * @param  list<PuDailyCurveRowData>  $rows
 */
function interestResetRowAfter(array $rows, CarbonImmutable $date): ?PuDailyCurveRowData
{
    foreach ($rows as $index => $row) {
        if ($row->date->equalTo($date)) {
            return $rows[$index + 1] ?? null;
        }
    }

    return null;
}

// ---------------------------------------------------------------------------
// Primeiro cupom: prêmio preservado, pagamento calculado, residual = principal
// ---------------------------------------------------------------------------

it('keeps the first coupon premium, pays it and leaves the principal as residual', function () {
    $result = interestResetSimulate();
    $coupons = interestResetCouponRows($result->rows);
    $firstCoupon = $coupons[0] ?? null;

    expect($result->state)->toBe(PuSimulationState::Calculated);
    expect($firstCoupon)->not->toBeNull();

    expect($firstCoupon->date->toDateString())->toBe('2026-06-08')
        // O prêmio dos 2 DU anteriores à integralização continua incorporado a
        // esta linha, e só a esta.
        ->and($firstCoupon->calculationMemory['first_coupon_pre_integralization_premium_applied'])->toBeTrue()
        ->and($firstCoupon->calculationMemory['first_coupon_pre_integralization_premium']['business_days_before_start'])->toBe(2)
        ->and($firstCoupon->calculationMemory['factor_spread_di_before_first_coupon_premium_raw'])->not->toBeNull()
        // A linha do evento permanece com o PU CHEIO, não com o residual.
        ->and($firstCoupon->interestPaymentUnitValue)->toBe($firstCoupon->interestRealUnitValue)
        ->and($firstCoupon->updatedUnitValue)->toBe(app(DecimalRounder::class)->round(
            bcadd($firstCoupon->unitBaseValue, $firstCoupon->interestRealUnitValue, DecimalRounder::CALCULATION_SCALE),
            DecimalRounder::UNIT_SCALE,
        ))
        // Pagamento de juros puro: nada amortiza o principal.
        ->and($firstCoupon->amortizationUnitValue)->toBe('0.0000000000000000')
        ->and($firstCoupon->residualUnitValue)->toBe('1000.0000000000000000')
        ->and($firstCoupon->unitBaseValue)->toBe('1000.0000000000000000');
});

it('recognises the coupon as paid by its unit value even with no position in custody', function () {
    $result = interestResetSimulate();
    $firstCoupon = interestResetCouponRows($result->rows)[0];

    // Esta é a raiz do defeito corrigido: a simulação roda sem timeline de
    // integralização, logo a quantidade é zero em toda data e o pagamento
    // FINANCEIRO é zero mesmo no dia do cupom. O encerramento do período passa
    // a depender do pagamento UNITÁRIO, que é o fato do papel.
    expect($firstCoupon->quantity)->toBe('0.0000')
        ->and($firstCoupon->hasPayment())->toBeFalse()
        ->and($firstCoupon->hasUnitPayment())->toBeTrue();
});

// ---------------------------------------------------------------------------
// Linha seguinte: novo período ancorado na última Data de Pagamento
// ---------------------------------------------------------------------------

it('starts a brand new interest period on the day after the first payment', function () {
    $result = interestResetSimulate();
    $rows = $result->rows;
    $firstCoupon = interestResetCouponRows($rows)[0];
    $nextRow = interestResetRowAfter($rows, $firstCoupon->date);

    expect($nextRow)->not->toBeNull();

    $parameter = interestResetParameter();
    $composition = app(CdiFactorCompositionService::class);
    $rounder = app(DecimalRounder::class);

    $expectedSpread = $rounder->round(
        $composition->spreadFactor($parameter, 1),
        DecimalRounder::FACTOR_SCALE,
    );
    $expectedInterest = $rounder->round(
        bcmul(
            $nextRow->unitBaseValue,
            bcsub(
                $composition->factorForInterest($parameter, $nextRow->factorSpreadDi),
                '1',
                DecimalRounder::CALCULATION_SCALE,
            ),
            DecimalRounder::CALCULATION_SCALE,
        ),
        DecimalRounder::UNIT_SCALE,
    );

    expect($nextRow->date->toDateString())->toBe('2026-06-09')
        ->and($nextRow->isBusinessDay)->toBeTrue()
        // Âncora do novo período: a última Data de Pagamento, inclusive.
        ->and($nextRow->calculationMemory['coupon_period_start_date'])->toBe('2026-06-08')
        ->and($nextRow->calculationMemory['coupon_period_end_date'])->toBe('2026-06-09')
        ->and($nextRow->calculationMemory['last_payment_date'])->toBe('2026-06-08')
        // DUP do novo cupom = businessDays([08/06, 09/06)) = 1.
        ->and($nextRow->dupInterest)->toBe(1)
        ->and($nextRow->dutInterest)->toBe(252)
        // Fator DI acumulado reiniciado: vale exatamente o fator de 1 DU.
        ->and($nextRow->factorDiAccumulated)->toBe($nextRow->factorDi)
        // Fator Spread reiniciado: vale exatamente o fator de 1 DU.
        ->and($nextRow->factorSpread)->toBe($expectedSpread)
        // Juros acumulados do cupom reiniciados: um único DU sobre o principal.
        ->and($nextRow->interestRealUnitValue)->toBe($expectedInterest)
        ->and(bccomp($nextRow->interestRealUnitValue, '1', DecimalRounder::UNIT_SCALE))->toBe(-1)
        ->and(bccomp($nextRow->interestRealUnitValue, $firstCoupon->interestRealUnitValue, DecimalRounder::UNIT_SCALE))->toBe(-1)
        // Principal residual preservado, e o PU recomeça sobre ele.
        ->and($nextRow->unitBaseValue)->toBe($firstCoupon->residualUnitValue)
        ->and($nextRow->updatedUnitValue)->toBe($rounder->round(
            bcadd($nextRow->unitBaseValue, $nextRow->interestRealUnitValue, DecimalRounder::CALCULATION_SCALE),
            DecimalRounder::UNIT_SCALE,
        ))
        ->and($nextRow->amortizationUnitValue)->toBe('0.0000000000000000');
});

it('never reapplies the first coupon premium after the payment', function () {
    $result = interestResetSimulate();
    $rows = $result->rows;
    $firstCoupon = interestResetCouponRows($rows)[0];

    $afterPayment = array_values(array_filter(
        $rows,
        fn (PuDailyCurveRowData $row): bool => $row->date->isAfter($firstCoupon->date),
    ));

    expect($afterPayment)->not->toBeEmpty();

    foreach ($afterPayment as $row) {
        expect($row->calculationMemory['first_coupon_pre_integralization_premium_applied'])->toBeFalse()
            ->and($row->calculationMemory['first_coupon_pre_integralization_premium'])->toBeNull()
            ->and($row->calculationMemory['factor_spread_di_before_first_coupon_premium_raw'])->toBeNull();
    }
});

// ---------------------------------------------------------------------------
// Segundo cupom: período independente, sem prêmio, e reset próprio
// ---------------------------------------------------------------------------

it('runs the second monthly coupon on its own period, anchored on the first payment', function () {
    $result = interestResetSimulate();
    $rows = $result->rows;
    $coupons = interestResetCouponRows($rows);
    $firstCoupon = $coupons[0];
    $secondCoupon = $coupons[1] ?? null;

    expect($secondCoupon)->not->toBeNull();
    expect($secondCoupon->date->toDateString())->toBe('2026-07-08');

    // Dias úteis do PRÓPRIO período do segundo cupom: (08/06, 08/07].
    $businessDaysInSecondPeriod = count(array_filter(
        $rows,
        fn (PuDailyCurveRowData $row): bool => $row->isBusinessDay
            && $row->date->isAfter($firstCoupon->date)
            && $row->date->lte($secondCoupon->date),
    ));
    // Dias úteis desde a integralização até a mesma data. É este o número que o
    // DUP do segundo cupom exibia enquanto o período não era encerrado.
    $businessDaysSinceIntegralization = count(array_filter(
        $rows,
        fn (PuDailyCurveRowData $row): bool => $row->isBusinessDay
            && $row->date->isAfter($rows[0]->date)
            && $row->date->lte($secondCoupon->date),
    ));

    expect($secondCoupon->calculationMemory['coupon_period_start_date'])->toBe($firstCoupon->date->toDateString())
        ->and($secondCoupon->calculationMemory['coupon_period_end_date'])->toBe($secondCoupon->date->toDateString())
        ->and($secondCoupon->calculationMemory['last_payment_date'])->toBe($firstCoupon->date->toDateString())
        // DUP do segundo cupom conta somente o próprio período, nunca o acumulado
        // desde a integralização.
        ->and($secondCoupon->dupInterest)->toBe($businessDaysInSecondPeriod)
        ->and($businessDaysInSecondPeriod)->toBeLessThan($businessDaysSinceIntegralization)
        // Fator Spread acumulado do segundo cupom vale exatamente os DU do seu
        // próprio período -- prova direta de que o acumulador foi reiniciado.
        ->and($secondCoupon->factorSpread)->toBe(app(DecimalRounder::class)->round(
            app(CdiFactorCompositionService::class)->spreadFactor(
                interestResetParameter(),
                $businessDaysInSecondPeriod,
            ),
            DecimalRounder::FACTOR_SCALE,
        ))
        // Sem prêmio: a exceção contratual é do PRIMEIRO cupom.
        ->and($secondCoupon->calculationMemory['first_coupon_pre_integralization_premium_applied'])->toBeFalse()
        ->and($secondCoupon->calculationMemory['first_coupon_pre_integralization_premium'])->toBeNull()
        // Interest payment puro: principal intacto.
        ->and($secondCoupon->unitBaseValue)->toBe('1000.0000000000000000')
        ->and($secondCoupon->amortizationUnitValue)->toBe('0.0000000000000000')
        ->and($secondCoupon->residualUnitValue)->toBe('1000.0000000000000000');
});

it('resets again on the day after the second coupon', function () {
    $result = interestResetSimulate();
    $rows = $result->rows;
    $secondCoupon = interestResetCouponRows($rows)[1];
    $nextRow = interestResetRowAfter($rows, $secondCoupon->date);

    expect($nextRow)->not->toBeNull();
    expect($nextRow->isBusinessDay)->toBeTrue()
        ->and($nextRow->dupInterest)->toBe(1)
        ->and($nextRow->calculationMemory['coupon_period_start_date'])->toBe($secondCoupon->date->toDateString())
        ->and($nextRow->calculationMemory['last_payment_date'])->toBe($secondCoupon->date->toDateString())
        ->and($nextRow->factorDiAccumulated)->toBe($nextRow->factorDi)
        ->and($nextRow->unitBaseValue)->toBe('1000.0000000000000000');
});

// ---------------------------------------------------------------------------
// Regressões: nada muda antes do primeiro pagamento
// ---------------------------------------------------------------------------

it('leaves every row before the first payment anchored on the integralization', function () {
    $result = interestResetSimulate();
    $rows = $result->rows;
    $firstCoupon = interestResetCouponRows($rows)[0];
    $start = PuSimulationFixture::integralizationDate()->toDateString();
    $runningBusinessDays = 0;

    expect($rows[0]->date->toDateString())->toBe($start)
        ->and($rows[0]->dupInterest)->toBe(0)
        ->and($rows[0]->updatedUnitValue)->toBe('1000.0000000000000000');

    foreach ($rows as $row) {
        if ($row->date->gte($firstCoupon->date)) {
            break;
        }

        if ($row->isBusinessDay && $row->date->isAfter($rows[0]->date)) {
            $runningBusinessDays++;
        }

        expect($row->calculationMemory['coupon_period_start_date'])->toBe($start)
            ->and($row->calculationMemory['last_payment_date'])->toBeNull()
            ->and($row->dupInterest)->toBe($runningBusinessDays)
            ->and($row->calculationMemory['reset_after_payment'])->toBeFalse();
    }

    // O primeiro cupom fecha o período aberto na integralização.
    expect($firstCoupon->calculationMemory['coupon_period_start_date'])->toBe($start)
        ->and($firstCoupon->calculationMemory['last_payment_date'])->toBeNull()
        ->and($firstCoupon->calculationMemory['reset_after_payment'])->toBeTrue();
});

it('freezes the coupon period across a weekend without triggering a reset', function () {
    $result = interestResetSimulate();
    $rows = collect($result->rows)->keyBy(fn (PuDailyCurveRowData $row): string => $row->date->toDateString());
    /** @var PuDailyCurveRowData $friday */
    $friday = $rows->get('2026-06-05');
    /** @var PuDailyCurveRowData $saturday */
    $saturday = $rows->get('2026-06-06');
    /** @var PuDailyCurveRowData $sunday */
    $sunday = $rows->get('2026-06-07');

    expect($friday->isBusinessDay)->toBeTrue()
        ->and($saturday->isBusinessDay)->toBeFalse()
        ->and($sunday->isBusinessDay)->toBeFalse()
        // Fim de semana não conta DU, não move o PU e não reinicia período.
        ->and($saturday->dupInterest)->toBe($friday->dupInterest)
        ->and($sunday->dupInterest)->toBe($friday->dupInterest)
        ->and($saturday->updatedUnitValue)->toBe($friday->updatedUnitValue)
        ->and($sunday->updatedUnitValue)->toBe($friday->updatedUnitValue)
        ->and($saturday->calculationMemory['coupon_period_start_date'])
        ->toBe($friday->calculationMemory['coupon_period_start_date'])
        ->and($sunday->calculationMemory['last_payment_date'])->toBeNull();
});

it('never amortizes the principal through an interest payment', function () {
    $result = interestResetSimulate();

    foreach ($result->rows as $row) {
        expect($row->unitBaseValue)->toBe('1000.0000000000000000')
            ->and($row->amortizationUnitValue)->toBe('0.0000000000000000')
            ->and($row->residualUnitValue)->toBe(
                $row->calculationMemory['reset_after_payment'] ? '1000.0000000000000000' : $row->updatedUnitValue,
            );
    }
});

it('persists nothing at all while simulating the reset', function () {
    $emission = PuSimulationFixture::contractualEmission();
    PuSimulationFixture::seedRequiredRates(
        PuSimulationFixture::integralizationDate(),
        interestResetWindowEnd(),
    );
    $before = PuSimulationFixture::counts();

    $result = app(PuSimulationService::class)->simulate($emission, new PuSimulationInput(
        firstIntegralizationDate: PuSimulationFixture::integralizationDate(),
        simulationEndDate: interestResetWindowEnd(),
    ));

    expect($result->state)->toBe(PuSimulationState::Calculated)
        ->and(PuSimulationFixture::counts())->toBe($before)
        ->and(EmissionPuParameter::query()->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Regressão operacional: a curva unitária não depende da posição em carteira
// ---------------------------------------------------------------------------

it('produces the same unit curve with and without a position in custody', function () {
    $emission = PuSimulationFixture::contractualEmission();
    PuSimulationFixture::seedRequiredRates(
        PuSimulationFixture::integralizationDate(),
        interestResetWindowEnd(),
    );

    $parameter = interestResetParameter();
    $events = PuSimulationFixture::officialEvents($emission, interestResetWindowEnd());

    $holding = new IntegralizationHistory;
    $holding->exists = false;
    $holding->forceFill([
        'date' => PuSimulationFixture::integralizationDate()->toDateString(),
        'quantity' => '10.0000',
    ]);
    $holding->setAttribute('id', 1);

    $withPosition = clone $emission;
    $withPosition->setRelation('puParameter', $parameter);
    $withPosition->setRelation('puEvents', $events);
    $withPosition->setRelation('integralizationHistories', new EloquentCollection([$holding]));

    $withoutPosition = clone $emission;
    $withoutPosition->setRelation('puParameter', $parameter);
    $withoutPosition->setRelation('puEvents', $events);
    $withoutPosition->setRelation('integralizationHistories', new EloquentCollection);

    app(IndexRateLookupService::class)->flushCache();
    $positioned = app(PuCurveGeneratorService::class)->handle($withPosition)->rows;
    app(IndexRateLookupService::class)->flushCache();
    $unitOnly = app(PuCurveGeneratorService::class)->handle($withoutPosition)->rows;

    expect($unitOnly)->toHaveCount(count($positioned));
    expect($positioned)->not->toBeEmpty();

    // A curva COM posição é a referência operacional já homologada: se ela
    // mudasse, a correção teria efeito colateral em produção. A curva SEM
    // posição precisa reproduzi-la linha a linha em tudo que é unitário --
    // inclusive DUP, fatores e âncora do cupom, que era exatamente o que
    // divergia.
    foreach ($positioned as $index => $reference) {
        $row = $unitOnly[$index];

        expect($row->date->toDateString())->toBe($reference->date->toDateString())
            ->and($row->isBusinessDay)->toBe($reference->isBusinessDay)
            ->and($row->unitBaseValue)->toBe($reference->unitBaseValue)
            ->and($row->factorDi)->toBe($reference->factorDi)
            ->and($row->factorDiAccumulated)->toBe($reference->factorDiAccumulated)
            ->and($row->factorSpread)->toBe($reference->factorSpread)
            ->and($row->factorSpreadDi)->toBe($reference->factorSpreadDi)
            ->and($row->interestRealUnitValue)->toBe($reference->interestRealUnitValue)
            ->and($row->updatedUnitValue)->toBe($reference->updatedUnitValue)
            ->and($row->interestPaymentUnitValue)->toBe($reference->interestPaymentUnitValue)
            ->and($row->amortizationUnitValue)->toBe($reference->amortizationUnitValue)
            ->and($row->residualUnitValue)->toBe($reference->residualUnitValue)
            ->and($row->dupInterest)->toBe($reference->dupInterest)
            ->and($row->dutInterest)->toBe($reference->dutInterest)
            ->and($row->calculationMemory['coupon_period_start_date'])
            ->toBe($reference->calculationMemory['coupon_period_start_date'])
            ->and($row->calculationMemory['last_payment_date'])
            ->toBe($reference->calculationMemory['last_payment_date']);
    }

    // A quantidade continua governando exclusivamente a posição financeira.
    $positionedCoupon = interestResetCouponRows($positioned)[0];
    $unitCoupon = interestResetCouponRows($unitOnly)[0];

    expect($positionedCoupon->quantity)->toBe('10.0000')
        ->and($positionedCoupon->hasPayment())->toBeTrue()
        ->and($unitCoupon->quantity)->toBe('0.0000')
        ->and($unitCoupon->hasPayment())->toBeFalse()
        ->and($unitCoupon->hasUnitPayment())->toBe($positionedCoupon->hasUnitPayment());
});

it('runs the third coupon with no premium and its own period after a Following shift', function () {
    $result = interestResetSimulate();
    $rows = $result->rows;
    $coupons = interestResetCouponRows($rows);
    $secondCoupon = $coupons[1] ?? null;
    $thirdCoupon = $coupons[2] ?? null;

    expect($thirdCoupon)->not->toBeNull();
    // 08/08/2026 é sábado: a convenção Following empurra o cupom para 10/08.
    expect($thirdCoupon->date->toDateString())->toBe('2026-08-10');

    $businessDaysInThirdPeriod = count(array_filter(
        $rows,
        fn (PuDailyCurveRowData $row): bool => $row->isBusinessDay
            && $row->date->isAfter($secondCoupon->date)
            && $row->date->lte($thirdCoupon->date),
    ));

    expect($thirdCoupon->calculationMemory['coupon_period_start_date'])->toBe($secondCoupon->date->toDateString())
        ->and($thirdCoupon->calculationMemory['last_payment_date'])->toBe($secondCoupon->date->toDateString())
        ->and($thirdCoupon->dupInterest)->toBe($businessDaysInThirdPeriod)
        ->and($thirdCoupon->calculationMemory['first_coupon_pre_integralization_premium_applied'])->toBeFalse()
        ->and($thirdCoupon->calculationMemory['first_coupon_pre_integralization_premium'])->toBeNull()
        ->and($thirdCoupon->unitBaseValue)->toBe('1000.0000000000000000')
        ->and($thirdCoupon->amortizationUnitValue)->toBe('0.0000000000000000')
        ->and($thirdCoupon->residualUnitValue)->toBe('1000.0000000000000000')
        ->and($thirdCoupon->factorSpread)->toBe(app(DecimalRounder::class)->round(
            app(CdiFactorCompositionService::class)->spreadFactor(
                interestResetParameter(),
                $businessDaysInThirdPeriod,
            ),
            DecimalRounder::FACTOR_SCALE,
        ));
});

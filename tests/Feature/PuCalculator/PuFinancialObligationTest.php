<?php

use App\Actions\Emissions\InvalidatePuCurve;
use App\Domain\PuCalculator\Enums\PuAmortizationType;
use App\Domain\PuCalculator\Enums\PuEventDateChangeReason;
use App\Domain\PuCalculator\Enums\PuEventStatus;
use App\Domain\PuCalculator\Enums\PuEventType;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuObligationCalculationState;
use App\Domain\PuCalculator\Enums\PuObligationComponent;
use App\Domain\PuCalculator\Enums\PuObligationComponentOwner;
use App\Domain\PuCalculator\Enums\PuObligationLifecycle;
use App\Domain\PuCalculator\Enums\PuObligationType;
use App\Domain\PuCalculator\Enums\PuReconciliationStatus;
use App\Domain\PuCalculator\Enums\PuSettlementState;
use App\Domain\PuCalculator\Services\DecimalRounder;
use App\Domain\PuCalculator\Services\IndexRateCorrectionService;
use App\Domain\PuCalculator\Services\PuCurveExtensionService;
use App\Domain\PuCalculator\Services\PuCurveVersionService;
use App\Models\Emission;
use App\Models\EmissionPuDailyCurve;
use App\Models\EmissionPuObligation;
use App\Models\EmissionPuObligationCalculation;
use App\Models\EmissionPuSettlement;
use App\Models\Payment;
use App\Models\User;
use App\Services\Reports\EmissionMonthlyReportService;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Pu\PuObligationFixture as Fx;

/**
 * Fase 5 -- o contrato determina a obrigação, a curva oficial calcula o esperado,
 * a liquidação registra o que aconteceu e a conciliação compara. Nenhuma camada
 * sobrescreve outra, e o esperado de cada versão continua explicável.
 */
uses(RefreshDatabase::class);

/**
 * Cupom de 09/03; cupom com amortização ordinária de R$ 100/título em 20/03; cupom
 * de 09/04. Prêmio de R$ 15,00 informado no cronograma para 20/03.
 */
function p5oEmission(): Emission
{
    $emission = Fx::emission([
        [PuEventType::InterestPayment, '2026-03-09'],
        [PuEventType::InterestPayment, '2026-03-20'],
        [PuEventType::Amortization, '2026-03-20', ['amortization_type' => PuAmortizationType::UnitValue->value, 'amortization_value' => '100.0000000000000000', 'sequence' => 2]],
        [PuEventType::InterestPayment, '2026-04-09'],
    ]);
    Payment::query()->create([
        'emission_id' => $emission->id,
        'payment_date' => '2026-03-20',
        'premium_value' => '15.00',
        'interest_value' => '1.00',
        'amortization_value' => '2.00',
        'extra_amortization_value' => '0.00',
    ]);

    return $emission->fresh();
}

function p5oObligation(Emission $emission, string $date): EmissionPuObligation
{
    return Fx::obligation($emission, PuObligationType::ScheduledPayment, $date);
}

it('runs the first production use from schedule to settlement, reprocessing and reconciliation', function () {
    // 1-2. CDI PU configurado e cronograma contratual cadastrado.
    $emission = p5oEmission();

    // 3-5. v1 gerada, validada e homologada por outra pessoa.
    $v1 = Fx::generate($emission);
    app(PuCurveVersionService::class)->markValidated($v1, true, ['source' => 'comparação com o sistema antigo'], User::factory()->create()->id);
    Fx::homologate($emission, $v1->fresh());

    expect(Fx::lastDate($v1))->toBe('2026-03-16')
        ->and(EmissionPuObligation::query()->whereBelongsTo($emission)->count())->toBe(3)
        ->and(p5oObligation($emission, '2026-03-09')->calculation_state)->toBe(PuObligationCalculationState::Calculated)
        ->and(p5oObligation($emission, '2026-03-20')->calculation_state)->toBe(PuObligationCalculationState::AwaitingIndex)
        ->and(p5oObligation($emission, '2026-04-09')->calculation_state)->toBe(PuObligationCalculationState::AwaitingIndex)
        // Futuro dependente de CDI não divulgado: nenhum valor fabricado.
        ->and(p5oObligation($emission, '2026-03-20')->current_calculation_id)->toBeNull()
        ->and(EmissionPuObligationCalculation::query()->where('emission_id', $emission->id)->count())->toBe(1);

    // 6-7. O CDI realizado chega e a oficial avança: a obrigação de 20/03 fica calculável.
    Fx::publish('2026-03-16', '2026-03-25');
    $extended = app(PuCurveExtensionService::class)->extendOfficial($emission->fresh());
    $coupon = p5oObligation($emission, '2026-03-20');
    $row = Fx::row($v1, '2026-03-20');
    $calculation = $coupon->currentCalculation;

    // 8. Componentes separados, donos certos, total = soma canônica.
    expect($extended->action)->toBe(PuCurveExtensionService::ACTION_EXTENDED)
        ->and($coupon->calculation_state)->toBe(PuObligationCalculationState::Calculated)
        ->and($calculation->componentAmount(PuObligationComponent::OrdinaryInterest))->toBe(p5oRound2((string) $row->interest_payment_value))
        ->and($calculation->componentAmount(PuObligationComponent::OrdinaryAmortization))->toBe('10000.00')
        ->and($calculation->componentAmount(PuObligationComponent::Premium))->toBe('15.00')
        ->and($calculation->components->firstWhere('component', PuObligationComponent::Premium)->owner)->toBe(PuObligationComponentOwner::InformedSchedule)
        ->and($calculation->components->firstWhere('component', PuObligationComponent::OrdinaryInterest)->owner)->toBe(PuObligationComponentOwner::OfficialCurve)
        ->and($calculation->components->firstWhere('component', PuObligationComponent::OrdinaryAmortization)->owner)->toBe(PuObligationComponentOwner::OfficialCurve)
        ->and((string) $calculation->total_amount)->toBe(bcadd(bcadd($calculation->componentAmount(PuObligationComponent::OrdinaryInterest), '10000.00', 2), '15.00', 2))
        // O juro e a amortização informados na planilha são só previsão: fora do esperado.
        ->and($calculation->components)->toHaveCount(3);

    // 9-10. Liquidação B3 igual ao esperado: liquidada e conciliada.
    $first = p5oObligation($emission, '2026-03-09');
    $firstExpected = Fx::expectedTotal($first);
    $firstSettlement = Fx::settle($first, $firstExpected, reference: 'B3-GF-0309')->settlement;

    expect($first->fresh()->settlement_state)->toBe(PuSettlementState::Settled)
        ->and($first->fresh()->reconciliation_status)->toBe(PuReconciliationStatus::Matched);

    // 11-13. Outra obrigação liquidada com valor diferente: fechada e divergente, sem parcial.
    $couponSettlement = Fx::settle($coupon, bcsub((string) $calculation->total_amount, '3.21', 2), reference: 'B3-GF-0320')->settlement;

    expect($coupon->fresh()->settlement_state)->toBe(PuSettlementState::Settled)
        ->and($coupon->fresh()->reconciliation_status)->toBe(PuReconciliationStatus::Divergent)
        ->and((string) $coupon->fresh()->latestReconciliation->difference)->toBe('-3.21')
        ->and(EmissionPuObligation::query()->whereBelongsTo($emission)->count())->toBe(3);

    // 14. O passado muda pelo caminho governado: o CDI de 04/03 é corrigido. Até a nova
    // versão, o esperado fica em dúvida e a conciliação, indeterminada.
    app(IndexRateCorrectionService::class)->correct(PuIndexer::Cdi, '2026-03-04', '15.50000000', 'Revisão publicada pelo Banco Central.', User::factory()->create()->id);

    expect($first->fresh()->calculation_state)->toBe(PuObligationCalculationState::ReprocessingRequired)
        ->and($first->fresh()->reconciliation_status)->toBe(PuReconciliationStatus::Indeterminate);

    // 15. Nova versão governada, validada e homologada.
    $v2 = Fx::generate($emission);
    app(PuCurveVersionService::class)->markValidated($v2, true, ['source' => 'comparação com o sistema antigo'], User::factory()->create()->id);
    Fx::homologate($emission, $v2->fresh());
    $first->refresh();
    $coupon->refresh();

    // 16. As liquidações continuam exatamente como aconteceram.
    expect(p5oFacts($firstSettlement->fresh()))->toBe(p5oFacts($firstSettlement))
        ->and(p5oFacts($couponSettlement->fresh()))->toBe(p5oFacts($couponSettlement))
        ->and(EmissionPuSettlement::query()->where('emission_id', $emission->id)->count())->toBe(2)
        // 17. A conciliação passa a refletir o esperado novo.
        ->and($first->currentCalculation->curve_version_id)->toBe($v2->id)
        ->and(Fx::expectedTotal($first))->not->toBe($firstExpected)
        ->and($first->reconciliation_status)->toBe(PuReconciliationStatus::Divergent)
        ->and((string) $first->latestReconciliation->difference)->toBe(bcsub($firstExpected, Fx::expectedTotal($first), 2))
        ->and($first->latestReconciliation->divergence['calculation_changed_after_settlement']['expected_total_at_settlement'])->toBe($firstExpected)
        ->and($coupon->currentCalculation->curve_version_id)->toBe($v2->id)
        // 18. O esperado da v1 continua rastreável.
        ->and($first->calculations()->orderBy('id')->first()->curve_version_id)->toBe($v1->id)
        ->and((string) $first->calculations()->orderBy('id')->first()->total_amount)->toBe($firstExpected)
        ->and($first->calculations()->orderBy('id')->first()->supersession_reason)->toBe('new_official_version')
        ->and(EmissionPuObligation::query()->whereBelongsTo($emission)->count())->toBe(3)
        ->and(Activity::query()->where('description', 'pu_settled_obligation_calculation_changed')->count())->toBe(2);
});

it('keeps an obligation matched and unduplicated when a newer version calculates the same amount', function () {
    $emission = p5oEmission();
    Fx::official($emission);
    $obligation = p5oObligation($emission, '2026-03-09');
    $settlement = Fx::settle($obligation, Fx::expectedTotal($obligation), reference: 'B3-SAME')->settlement;

    $v2 = Fx::official($emission);
    $obligation->refresh();

    expect($obligation->currentCalculation->curve_version_id)->toBe($v2->id)
        ->and($obligation->calculations()->count())->toBe(2)
        ->and($obligation->reconciliation_status)->toBe(PuReconciliationStatus::Matched)
        ->and($obligation->latestReconciliation->divergence['calculation_changed_after_settlement']['amount_changed'])->toBeFalse()
        ->and($obligation->activeSettlement->id)->toBe($settlement->id)
        ->and(EmissionPuSettlement::query()->where('obligation_id', $obligation->id)->count())->toBe(1)
        ->and(EmissionPuObligation::query()->whereBelongsTo($emission)->count())->toBe(3);
});

it('keeps the economic identity when the payment convention moves the effective date', function () {
    $emission = Fx::emission([[PuEventType::InterestPayment, '2026-03-09', ['original_date' => '2026-03-08', 'effective_date_reason' => PuEventDateChangeReason::Weekend->value]]]);
    Fx::official($emission);
    $obligation = Fx::obligation($emission, PuObligationType::ScheduledPayment, '2026-03-08');
    $settlement = Fx::settle($obligation, Fx::expectedTotal($obligation), reference: 'B3-MOVE')->settlement;

    // O dia efetivo é corrigido para 10/03 numa versão nova: a obrigação é a mesma.
    $emission->puEvents()->sole()->update(['effective_date' => '2026-03-10']);
    Fx::official($emission);
    $obligation->refresh();

    expect(EmissionPuObligation::query()->whereBelongsTo($emission)->count())->toBe(1)
        ->and($obligation->due_date->toDateString())->toBe('2026-03-10')
        ->and($obligation->contractual_date->toDateString())->toBe('2026-03-08')
        ->and($obligation->activeSettlement->id)->toBe($settlement->id)
        // A liquidação aconteceu em 09/03; o esperado agora diz 10/03: divergência de data.
        ->and($obligation->reconciliation_status)->toBe(PuReconciliationStatus::Divergent)
        ->and($obligation->latestReconciliation->divergence['kinds'])->toContain('date');
});

it('prevents a second economic obligation with the same identity at the database', function () {
    $emission = p5oEmission();
    Fx::official($emission);
    $existing = p5oObligation($emission, '2026-03-09');

    expect(fn () => DB::table('emission_pu_obligations')->insert([
        'emission_id' => $emission->id,
        'obligation_type' => PuObligationType::ScheduledPayment->value,
        'contractual_date' => $existing->getRawOriginal('contractual_date'),
        'sequence' => 1,
        'calculation_state' => PuObligationCalculationState::AwaitingIndex->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);

    // Natureza diferente na mesma data é outra obrigação legítima.
    DB::table('emission_pu_obligations')->insert([
        'emission_id' => $emission->id,
        'obligation_type' => PuObligationType::ExtraordinaryAmortization->value,
        'contractual_date' => $existing->getRawOriginal('contractual_date'),
        'sequence' => 1,
        'calculation_state' => PuObligationCalculationState::AwaitingIndex->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(EmissionPuObligation::query()->whereBelongsTo($emission)->whereDate('contractual_date', '2026-03-09')->count())->toBe(2);
});

it('supersedes, never deletes, an obligation whose event left the official schedule, keeping its settlement', function () {
    $emission = p5oEmission();
    Fx::official($emission);
    $obligation = p5oObligation($emission, '2026-03-09');
    $settlement = Fx::settle($obligation, Fx::expectedTotal($obligation), reference: 'B3-CANCEL')->settlement;

    $emission->puEvents()->whereDate('effective_date', '2026-03-09')->sole()->update([
        'status' => PuEventStatus::Cancelled->value,
        'cancelled_at' => now(),
        'cancellation_reason' => 'Cupom cadastrado em duplicidade.',
    ]);
    Fx::official($emission);
    $obligation->refresh();

    expect($obligation->lifecycle_status)->toBe(PuObligationLifecycle::Superseded)
        ->and($obligation->supersession_reason)->toBe('not_in_official_schedule')
        ->and($obligation->current_calculation_id)->toBeNull()
        ->and($obligation->calculations()->count())->toBe(1)
        ->and($obligation->activeSettlement->id)->toBe($settlement->id)
        ->and($obligation->reconciliation_status)->toBe(PuReconciliationStatus::Indeterminate)
        ->and($obligation->latestReconciliation->reason)->toBe('obligation_superseded');
});

it('never fabricates a future CDI amount and keeps each obligation without settlement pending', function () {
    $emission = p5oEmission();
    $official = Fx::official($emission);

    $future = p5oObligation($emission, '2026-04-09');

    expect($future->calculation_state)->toBe(PuObligationCalculationState::AwaitingIndex)
        ->and($future->calculation_state_reason)->toContain('não é projetado')
        ->and($future->current_calculation_id)->toBeNull()
        ->and($future->calculations()->count())->toBe(0)
        ->and($future->reconciliation_status)->toBe(PuReconciliationStatus::Pending)
        ->and(EmissionPuDailyCurve::query()->where('curve_version_id', $official->id)->whereDate('curve_date', '>', '2026-03-16')->exists())->toBeFalse();
});

it('marks the obligations awaiting a new version when a future contractual change limits the official curve', function () {
    $emission = p5oEmission();
    Fx::official($emission);
    Fx::event($emission, PuEventType::InterestPayment, '2026-03-31');

    expect(p5oObligation($emission, '2026-04-09')->calculation_state)->toBe(PuObligationCalculationState::AwaitingNewVersion)
        ->and(p5oObligation($emission, '2026-03-09')->calculation_state)->toBe(PuObligationCalculationState::Calculated)
        // O evento novo não é obrigação governada até uma versão o aprovar.
        ->and(EmissionPuObligation::query()->whereBelongsTo($emission)->whereDate('contractual_date', '2026-03-31')->exists())->toBeFalse();
});

it('feeds the monthly report with expected, settled, date and divergence as separate facts', function () {
    $emission = Fx::emission([[PuEventType::InterestPayment, '2026-03-09']]);
    Fx::official($emission);
    $obligation = p5oObligation($emission, '2026-03-09');
    $expected = Fx::expectedTotal($obligation);
    Fx::settle($obligation, bcsub($expected, '10.00', 2), reference: 'B3-REPORT');

    $payment = app(EmissionMonthlyReportService::class)->build($emission->fresh(), CarbonImmutable::parse('2026-03-01'))['payment'];
    $rows = collect($payment['rows'])->pluck('value', 'label');

    expect($payment['payment_date'])->toBe('09/03/2026')
        ->and($rows['Total previsto (curva oficial)'])->toBe('R$ '.number_format((float) $expected, 2, ',', '.'))
        ->and($rows['Valor liquidado'])->toBe('R$ '.number_format((float) bcsub($expected, '10.00', 2), 2, ',', '.'))
        ->and($rows['Data da liquidação'])->toBe('09/03/2026')
        ->and($rows['Divergência (liquidado − previsto)'])->toBe('R$ -10,00')
        ->and($rows['Conciliação'])->toBe(PuReconciliationStatus::Divergent->label());

    // Esperado ainda indisponível aparece como indisponível -- nunca como zero.
    $pending = p5oEmission();
    Fx::official($pending);
    $pendingRows = collect(app(EmissionMonthlyReportService::class)->build($pending->fresh(), CarbonImmutable::parse('2026-03-01'))['payment']['rows'])->pluck('value', 'label');

    expect($pendingRows['Total previsto (curva oficial)'])->toBe('Não disponível — aguardando índice')
        ->and($pendingRows['Juros'])->toBe('Não disponível')
        ->and($pendingRows['Valor liquidado'])->toBe('Não liquidado')
        ->and($pendingRows['Conciliação'])->toBe(PuReconciliationStatus::Pending->label());
});

it('shows on the public site only the official expected values, never the settlement or the reconciliation', function () {
    $emission = p5oEmission();
    $emission->forceFill(['is_public' => true, 'if_code' => 'CRI26OBL01'])->save();
    Fx::official($emission);
    Fx::publish('2026-03-16', '2026-03-25');
    app(PuCurveExtensionService::class)->extendOfficial($emission->fresh());
    $obligation = p5oObligation($emission, '2026-03-20');
    Fx::settle($obligation, '7654.32', reference: 'B3-SITE');
    $interest = $obligation->fresh()->currentCalculation->componentAmount(PuObligationComponent::OrdinaryInterest);

    $response = $this->get(route('site.emissions.show', $emission->if_code))->assertOk();
    $payments = $response->viewData('emission')->payments;

    expect($payments->map(fn (Payment $payment): string => $payment->payment_date->toDateString())->all())->toBe(['2026-03-09', '2026-03-20'])
        ->and((string) $payments->last()->interest_value)->toBe($interest)
        ->and((string) $payments->last()->amortization_value)->toBe('10000.00')
        ->and((string) $payments->last()->premium_value)->toBe('15.00')
        ->and($payments->every(fn (Payment $payment): bool => ! $payment->exists))->toBeTrue();
    $response->assertDontSee('7.654,32')->assertDontSee('7654.32')->assertDontSee('Divergente')->assertDontSee('B3-SITE');
});

it('falls back to the informed schedule on the public site when there is no official curve', function () {
    $emission = Fx::emission([[PuEventType::InterestPayment, '2026-03-09']]);
    $emission->forceFill(['is_public' => true, 'if_code' => 'CRI26OBL02'])->save();
    Payment::query()->create(['emission_id' => $emission->id, 'payment_date' => '2026-03-09', 'premium_value' => '0.00', 'interest_value' => '321.00', 'amortization_value' => '0.00', 'extra_amortization_value' => '0.00']);
    $official = Fx::official($emission);
    $officialInterest = Fx::obligation($emission, PuObligationType::ScheduledPayment, '2026-03-09')->currentCalculation->componentAmount(PuObligationComponent::OrdinaryInterest);

    $withOfficial = $this->get(route('site.emissions.show', $emission->if_code))->viewData('emission')->payments;
    app(InvalidatePuCurve::class)->handle($emission->fresh(), $official->calculation_version, User::factory()->create()->id);
    $withoutOfficial = $this->get(route('site.emissions.show', $emission->if_code))->viewData('emission')->payments;

    expect((string) $withOfficial->sole()->interest_value)->toBe($officialInterest)
        ->and($withOfficial->sole()->exists)->toBeFalse()
        ->and((string) $withoutOfficial->sole()->interest_value)->toBe('321.00')
        ->and($withoutOfficial->sole()->exists)->toBeTrue();
});

it('restores the informed schedule from the legacy projection without touching informed rows', function () {
    $emission = Emission::factory()->create();
    $base = ['emission_id' => $emission->id, 'created_at' => now(), 'updated_at' => now()];
    $informed = DB::table('payments')->insertGetId([...$base, 'payment_date' => '2026-03-01', 'premium_value' => '5.00', 'interest_value' => '10.00', 'amortization_value' => '0.00', 'extra_amortization_value' => '0.00']);
    $projectedOverForecast = DB::table('payments')->insertGetId([...$base, 'payment_date' => '2026-03-09', 'premium_value' => '0.00', 'interest_value' => '777.77', 'amortization_value' => '0.00', 'extra_amortization_value' => '0.00', 'value_source' => 'official_curve', 'expected_interest_value' => '999.00', 'expected_amortization_value' => '0.00', 'expected_premium_value' => '50.00', 'calculated_at' => now()]);
    $createdByProjection = DB::table('payments')->insertGetId([...$base, 'payment_date' => '2026-04-09', 'premium_value' => '0.00', 'interest_value' => '555.55', 'amortization_value' => '0.00', 'extra_amortization_value' => '0.00', 'value_source' => 'official_curve', 'calculated_at' => now()]);

    (include database_path('migrations/2026_10_08_120200_restore_informed_payment_schedule.php'))->up();

    $restored = DB::table('payments')->find($projectedOverForecast);

    expect(Payment::query()->findOrFail($informed)->only(['premium_value', 'interest_value']))->toBe(['premium_value' => '5.00', 'interest_value' => '10.00'])
        ->and(Payment::query()->findOrFail($projectedOverForecast)->only(['premium_value', 'interest_value']))->toBe(['premium_value' => '50.00', 'interest_value' => '999.00'])
        ->and($restored->value_source)->toBeNull()
        ->and($restored->expected_interest_value)->toBeNull()
        ->and($restored->calculated_at)->toBeNull()
        ->and(DB::table('payments')->where('id', $createdByProjection)->exists())->toBeFalse();
});

/**
 * @return array{status: string, amount: ?string, settlement_date: ?string, components: mixed}
 */
function p5oFacts(EmissionPuSettlement $settlement): array
{
    return [
        'status' => $settlement->status->value,
        'amount' => $settlement->amount !== null ? (string) $settlement->amount : null,
        'settlement_date' => $settlement->settlement_date?->toDateString(),
        'components' => $settlement->components,
    ];
}

function p5oRound2(string $value): string
{
    return app(DecimalRounder::class)->round($value, 2);
}

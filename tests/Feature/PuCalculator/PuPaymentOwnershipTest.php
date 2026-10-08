<?php

use App\Actions\Emissions\GeneratePuDailyCurve;
use App\Actions\Emissions\HomologatePuCurve;
use App\Actions\Emissions\ImportPaymentsFromSpreadsheet;
use App\Actions\Emissions\InvalidatePuCurve;
use App\Domain\PuCalculator\DTOs\PuSettlementActor;
use App\Domain\PuCalculator\DTOs\PuSettlementData;
use App\Domain\PuCalculator\Enums\PuAmortizationType;
use App\Domain\PuCalculator\Enums\PuEventType;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use App\Domain\PuCalculator\Enums\PuObligationCalculationState;
use App\Domain\PuCalculator\Enums\PuObligationComponent;
use App\Domain\PuCalculator\Enums\PuObligationComponentOwner;
use App\Domain\PuCalculator\Enums\PuObligationComponentStatus;
use App\Domain\PuCalculator\Enums\PuObligationType;
use App\Domain\PuCalculator\Enums\PuReconciliationStatus;
use App\Domain\PuCalculator\Enums\PuSettlementOutcome;
use App\Domain\PuCalculator\Enums\PuSettlementSource;
use App\Domain\PuCalculator\Enums\PuSettlementState;
use App\Domain\PuCalculator\Enums\PuSettlementStatus;
use App\Domain\PuCalculator\Services\DecimalRounder;
use App\Domain\PuCalculator\Services\IndexRateLookupService;
use App\Domain\PuCalculator\Services\IndexRateService;
use App\Domain\PuCalculator\Services\PuSettlementService;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuDailyCurve;
use App\Models\EmissionPuEvent;
use App\Models\EmissionPuObligation;
use App\Models\EmissionPuSettlement;
use App\Models\IndexRate;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

/**
 * Fase 2 -> Fase 5 -- propriedade dos componentes e o fim da liquidação inferida.
 *
 * A curva oficial é dona só do que calcula (juros e amortização ordinários) e
 * nunca escreve o Cronograma de Pagamentos informado: prêmio e amortização
 * extraordinária informados não são zerados nem reescritos por homologação,
 * re-homologação ou invalidação. E -- regra nova da Fase 5 -- um pagamento não
 * fica "liquidado" porque a data passou sob uma curva homologada: só um fato de
 * liquidação registrado liquida, e esse fato nunca é reescrito por versão nova.
 */
uses(RefreshDatabase::class);

function ownershipPublishCdi(string $from, string $to, string $rate = '14.90000000'): void
{
    for ($date = CarbonImmutable::parse($from); $date->lte(CarbonImmutable::parse($to)); $date = $date->addDay()) {
        if ($date->isWeekend()) {
            continue;
        }

        $existing = IndexRate::query()
            ->where('indexer', PuIndexer::Cdi->value)
            ->whereDate('rate_date', $date->toDateString())
            ->first();

        if ($existing instanceof IndexRate) {
            $existing->forceFill(['rate_value' => $rate])->save();

            continue;
        }

        IndexRate::query()->create([
            'indexer' => PuIndexer::Cdi->value,
            'rate_date' => $date->toDateString(),
            'rate_value' => $rate,
            'source' => 'testing',
            'source_reference' => 'ownership',
        ]);
    }

    app(IndexRateService::class)->flushCache();
    app(IndexRateLookupService::class)->flushCache();
}

/**
 * Cupom de juros de 08/03/2026 pago em 09/03 sobre 100 títulos.
 */
function ownershipEmission(): Emission
{
    $emission = Emission::factory()->active()->create(['type' => 'CRI']);
    $emission->integralizationHistories()->create([
        'date' => '2026-03-02',
        'quantity' => '100.0000',
        'unit_value' => '1000.00000000',
        'financial_value' => '100000.00',
        'investor_fund' => 'Head Invest',
    ]);
    $emission->puParameter()->create([
        'curve_start_date' => '2026-03-02',
        'curve_end_date' => '2026-12-31',
        'initial_unit_value' => '1000.0000000000000000',
        'spread_rate' => '6.00000000',
        'indexer' => PuIndexer::Cdi->value,
        'business_day_basis' => 252,
        'calendar_code' => 'B3',
        'index_rate_lookup_mode' => PuIndexRateLookupMode::BusinessDayLagExact->value,
        'index_rate_lag_business_days' => -1,
        'legacy_projection_enabled' => true,
    ]);
    EmissionPuEvent::query()->create([
        'emission_id' => $emission->id,
        'event_type' => PuEventType::InterestPayment->value,
        'original_date' => '2026-03-08',
        'effective_date' => '2026-03-09',
        'amortization_type' => PuAmortizationType::None->value,
        'amortization_value' => null,
        'sequence' => 1,
    ]);
    ownershipPublishCdi('2026-02-27', '2026-03-13');

    return $emission->fresh();
}

function ownershipGenerate(Emission $emission): EmissionPuCurveVersion
{
    $result = app(GeneratePuDailyCurve::class)->handle($emission->fresh());

    return EmissionPuCurveVersion::query()
        ->where('emission_id', $emission->id)
        ->where('calculation_version', $result->calculationVersion)
        ->sole();
}

function ownershipHomologate(Emission $emission, string $calculationVersion): void
{
    app(HomologatePuCurve::class)->handle($emission->fresh(), $calculationVersion, User::factory()->create()->id, 'Conferida.');
}

function ownershipCurveInterest(EmissionPuCurveVersion $version, string $date = '2026-03-09'): string
{
    $row = EmissionPuDailyCurve::query()->where('curve_version_id', $version->id)->whereDate('curve_date', $date)->sole();

    return app(DecimalRounder::class)->round((string) $row->interest_payment_value, 2);
}

/**
 * Previsto da planilha com todos os componentes preenchidos.
 */
function ownershipForecast(Emission $emission, string $date = '2026-03-09'): Payment
{
    return Payment::query()->create([
        'emission_id' => $emission->id,
        'payment_date' => $date,
        'premium_value' => '50.00',
        'interest_value' => '999.00',
        'amortization_value' => '0.00',
        'extra_amortization_value' => '25.00',
    ]);
}

function ownershipObligation(Emission $emission): EmissionPuObligation
{
    return EmissionPuObligation::query()->whereBelongsTo($emission)->whereDate('due_date', '2026-03-09')->sole();
}

it('never writes the informed schedule and takes only the informed premium into the expected value', function () {
    $emission = ownershipEmission();
    $version = ownershipGenerate($emission);
    $payment = ownershipForecast($emission);
    $payment->forceFill(['extra_amortization_value' => '0.00'])->save();

    ownershipHomologate($emission, 'v1');
    $calculation = ownershipObligation($emission)->currentCalculation;

    expect($payment->fresh()->only(Payment::VALUE_FIELDS))->toBe([
        'premium_value' => '50.00',
        'interest_value' => '999.00',
        'amortization_value' => '0.00',
        'extra_amortization_value' => '0.00',
    ])
        ->and($calculation->componentAmount(PuObligationComponent::OrdinaryInterest))->toBe(ownershipCurveInterest($version))
        ->and($calculation->componentAmount(PuObligationComponent::Premium))->toBe('50.00')
        ->and($calculation->components->firstWhere('component', PuObligationComponent::Premium)->owner)->toBe(PuObligationComponentOwner::InformedSchedule)
        ->and((string) $calculation->total_amount)->toBe(bcadd(ownershipCurveInterest($version), '50.00', 2));
});

it('fails closed on an informed extraordinary amortization that no contractual event backs', function () {
    $emission = ownershipEmission();
    ownershipGenerate($emission);
    ownershipForecast($emission);

    ownershipHomologate($emission, 'v1');
    $obligation = ownershipObligation($emission);
    $informed = $obligation->currentCalculation->components->firstWhere('component', PuObligationComponent::ExtraordinaryAmortization);

    // A curva não reduziu o principal por ela: somar seria inventar o efeito.
    expect($obligation->calculation_state)->toBe(PuObligationCalculationState::Unsupported)
        ->and($obligation->currentCalculation->isComplete())->toBeFalse()
        ->and($obligation->currentCalculation->total_amount)->toBeNull()
        ->and($informed->status)->toBe(PuObligationComponentStatus::Unsupported)
        ->and($informed->amount)->toBeNull()
        ->and($informed->source['informed_amount'])->toBe('25.00');
});

it('keeps the informed components across a re-homologation and an invalidation', function () {
    $emission = ownershipEmission();
    $first = ownershipGenerate($emission);
    $payment = ownershipForecast($emission);
    ownershipHomologate($emission, 'v1');
    $second = ownershipGenerate($emission);

    ownershipHomologate($emission, 'v2');
    $obligation = ownershipObligation($emission);

    expect($obligation->currentCalculation->curve_version_id)->toBe($second->id)
        ->and($obligation->calculations()->count())->toBe(2)
        ->and($payment->fresh()->only(Payment::VALUE_FIELDS))->toBe([
            'premium_value' => '50.00',
            'interest_value' => '999.00',
            'amortization_value' => '0.00',
            'extra_amortization_value' => '25.00',
        ]);

    app(InvalidatePuCurve::class)->handle($emission->fresh(), 'v2', User::factory()->create()->id);
    app(InvalidatePuCurve::class)->handle($emission->fresh(), 'v1', User::factory()->create()->id);

    expect($payment->fresh()->only(Payment::VALUE_FIELDS))->toBe([
        'premium_value' => '50.00',
        'interest_value' => '999.00',
        'amortization_value' => '0.00',
        'extra_amortization_value' => '25.00',
    ])
        ->and(ownershipObligation($emission)->calculation_state)->toBe(PuObligationCalculationState::NoOfficialCalculation)
        // Histórico só de inclusão: v1, v2 e de novo v1 (oficial outra vez entre as
        // duas invalidações), todos substituídos -- nenhum reescrito.
        ->and(ownershipObligation($emission)->calculations()->orderBy('id')->pluck('curve_version_id')->all())->toBe([$first->id, $second->id, $first->id])
        ->and(ownershipObligation($emission)->calculations()->whereNull('superseded_at')->count())->toBe(0);
});

it('never treats a past payment as settled just because a homologated curve calculated it before its date', function () {
    $emission = ownershipEmission();
    $this->travelTo(CarbonImmutable::parse('2026-03-02 15:00:00'));
    $first = ownershipGenerate($emission);
    ownershipHomologate($emission, 'v1');
    $firstCalculation = ownershipObligation($emission)->currentCalculation;

    // A data do pagamento passa; o CDI de 04/03 muda e uma nova curva é homologada.
    // Sem fato de liquidação, nada está liquidado: o esperado passa a ser o da v2.
    $this->travelTo(CarbonImmutable::parse('2026-03-20 15:00:00'));
    ownershipPublishCdi('2026-03-04', '2026-03-04', '15.50000000');
    $second = ownershipGenerate($emission);
    ownershipHomologate($emission, 'v2');
    $obligation = ownershipObligation($emission);

    expect(ownershipCurveInterest($second))->not->toBe(ownershipCurveInterest($first))
        ->and($obligation->settlement_state)->toBe(PuSettlementState::Unsettled)
        ->and($obligation->reconciliation_status)->toBe(PuReconciliationStatus::Pending)
        ->and($obligation->currentCalculation->curve_version_id)->toBe($second->id)
        ->and($obligation->currentCalculation->componentAmount(PuObligationComponent::OrdinaryInterest))->toBe(ownershipCurveInterest($second))
        // O cálculo da v1 continua explicável, só deixou de ser o vigente.
        ->and($firstCalculation->fresh()->supersession_reason)->toBe('new_official_version')
        ->and($firstCalculation->fresh()->componentAmount(PuObligationComponent::OrdinaryInterest))->toBe(ownershipCurveInterest($first));
});

it('never rewrites an explicit settlement when a newer official version changes the expected value', function () {
    $emission = ownershipEmission();
    $first = ownershipGenerate($emission);
    ownershipHomologate($emission, 'v1');
    $expected = (string) ownershipObligation($emission)->currentCalculation->total_amount;
    $recorded = app(PuSettlementService::class)->record(new PuSettlementData(
        emissionId: $emission->id,
        settlementDate: '2026-03-09',
        amount: $expected,
        source: PuSettlementSource::B3,
        obligationType: PuObligationType::ScheduledPayment,
        contractualDate: '2026-03-08',
        externalReference: 'B3-OWN-1',
    ), PuSettlementActor::integration('b3-test'));

    ownershipPublishCdi('2026-03-04', '2026-03-04', '15.50000000');
    $second = ownershipGenerate($emission);
    ownershipHomologate($emission, 'v2');
    $obligation = ownershipObligation($emission);
    $settlement = $recorded->settlement->fresh();

    expect($recorded->outcome)->toBe(PuSettlementOutcome::Recorded)
        ->and($settlement->status)->toBe(PuSettlementStatus::Active)
        ->and((string) $settlement->amount)->toBe($expected)
        ->and($settlement->expected_calculation_id)->not->toBe($obligation->current_calculation_id)
        ->and($obligation->settlement_state)->toBe(PuSettlementState::Settled)
        ->and($obligation->reconciliation_status)->toBe(PuReconciliationStatus::Divergent)
        ->and($obligation->latestReconciliation->divergence['calculation_changed_after_settlement']['amount_changed'])->toBeTrue()
        ->and((string) $obligation->latestReconciliation->difference)
        ->toBe(bcsub($expected, (string) $obligation->currentCalculation->total_amount, 2))
        ->and(EmissionPuSettlement::query()->where('obligation_id', $obligation->id)->count())->toBe(1)
        ->and(Activity::query()->where('description', 'pu_settled_obligation_calculation_changed')->count())->toBe(1);
});

it('lets the spreadsheet update the informed premium, which becomes a new expected calculation', function () {
    Storage::fake('local');
    $emission = ownershipEmission();
    $version = ownershipGenerate($emission);
    ownershipHomologate($emission, 'v1');
    $before = ownershipObligation($emission)->currentCalculation;
    Storage::disk('local')->put('imports/ownership.csv', "Data,Premio,Juros\n09/03/2026,\"12,34\",\"1234,56\"\n");

    app(ImportPaymentsFromSpreadsheet::class)->handle(Storage::disk('local')->path('imports/ownership.csv'), $emission->fresh());
    $after = ownershipObligation($emission)->currentCalculation;
    $payment = Payment::query()->whereBelongsTo($emission)->sole();

    expect((string) $payment->premium_value)->toBe('12.34')
        ->and((string) $payment->interest_value)->toBe('1234.56')
        ->and($after->id)->not->toBe($before->id)
        ->and($before->fresh()->supersession_reason)->toBe('expected_components_changed')
        ->and($after->componentAmount(PuObligationComponent::Premium))->toBe('12.34')
        // O juro informado na planilha é previsão: o esperado continua o da curva.
        ->and($after->componentAmount(PuObligationComponent::OrdinaryInterest))->toBe(ownershipCurveInterest($version));
});

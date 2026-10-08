<?php

use App\Domain\PuCalculator\DTOs\PuCurveInputSnapshot;
use App\Domain\PuCalculator\Enums\PuAmortizationType;
use App\Domain\PuCalculator\Enums\PuCurveChangeImpact;
use App\Domain\PuCalculator\Enums\PuCurveChangeKind;
use App\Domain\PuCalculator\Enums\PuEventStatus;
use App\Domain\PuCalculator\Enums\PuEventType;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use App\Domain\PuCalculator\Exceptions\PuCurveInputsException;
use App\Domain\PuCalculator\Services\PuCurveChangeImpactClassifier;
use App\Domain\PuCalculator\Services\PuCurveInputSnapshotService;
use App\Models\EmissionPuEvent;
use App\Models\EmissionPuParameter;
use App\Models\IntegralizationHistory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Fase 4 -- o fingerprint dos insumos contratuais da curva.
 *
 * Determinístico, canônico e restrito ao que a engine usa: a ordem em que as linhas
 * saem do banco não muda nada; valor, data, quantidade, termo contratual e
 * identidade da engine mudam; descrição, justificativa, documento e campo de outro
 * indexador não mudam. Tudo em memória: nenhum teste aqui depende do banco.
 */
function p4fParameter(array $overrides = []): EmissionPuParameter
{
    $parameter = new EmissionPuParameter;
    $parameter->forceFill([
        'id' => 10,
        'emission_id' => 1,
        'curve_start_date' => '2026-03-02',
        'curve_end_date' => '2026-12-31',
        'initial_unit_value' => '1000.0000000000000000',
        'spread_rate' => '6.00000000',
        'annual_rate' => null,
        'indexer' => PuIndexer::Cdi->value,
        'business_day_basis' => 252,
        'calendar_code' => 'B3',
        'index_rate_calendar_code' => null,
        'index_rate_lookup_mode' => PuIndexRateLookupMode::BusinessDayLagExact->value,
        'index_rate_lag_business_days' => -1,
        'first_coupon_pre_integralization_premium_enabled' => false,
        'legacy_projection_enabled' => false,
        'rounding_policy' => 'contractual',
        'method_version' => 'phase1-cdi-v2',
        ...$overrides,
    ]);

    return $parameter;
}

function p4fEvent(int $id, array $attributes): EmissionPuEvent
{
    $event = new EmissionPuEvent;
    $event->forceFill([
        'id' => $id,
        'emission_id' => 1,
        'status' => PuEventStatus::Active->value,
        'original_date' => $attributes['effective_date'],
        'amortization_type' => PuAmortizationType::None->value,
        'amortization_value' => null,
        'sequence' => 1,
        'description' => 'Cronograma do Termo.',
        ...$attributes,
    ]);

    return $event;
}

/**
 * @return EloquentCollection<int, EmissionPuEvent>
 */
function p4fEvents(): EloquentCollection
{
    return new EloquentCollection([
        p4fEvent(1, ['event_type' => PuEventType::InterestPayment->value, 'effective_date' => '2026-03-31']),
        p4fEvent(2, ['event_type' => PuEventType::Amortization->value, 'effective_date' => '2026-06-30', 'amortization_type' => PuAmortizationType::Percentage->value, 'amortization_value' => '0.2500000000000000']),
        p4fEvent(3, ['event_type' => PuEventType::InterestPayment->value, 'effective_date' => '2026-06-30']),
        p4fEvent(4, ['event_type' => PuEventType::Amortization->value, 'effective_date' => '2026-12-31', 'amortization_type' => PuAmortizationType::Residual->value]),
    ]);
}

function p4fIntegralization(int $id, string $date, string $quantity, array $extra = []): IntegralizationHistory
{
    $integralization = new IntegralizationHistory;
    $integralization->forceFill([
        'id' => $id,
        'emission_id' => 1,
        'date' => $date,
        'quantity' => $quantity,
        'unit_value' => '1000.00000000',
        'financial_value' => '100000.00',
        'investor_fund' => 'Head Invest',
        ...$extra,
    ]);

    return $integralization;
}

/**
 * @return EloquentCollection<int, IntegralizationHistory>
 */
function p4fIntegralizations(): EloquentCollection
{
    return new EloquentCollection([
        p4fIntegralization(1, '2026-03-02', '100.0000'),
        p4fIntegralization(2, '2026-04-15', '50.0000'),
    ]);
}

/**
 * @param  EloquentCollection<int, EmissionPuEvent>  $events
 * @param  EloquentCollection<int, IntegralizationHistory>  $integralizations
 */
function p4fMutated(EmissionPuParameter $parameter, EloquentCollection $events, EloquentCollection $integralizations): bool
{
    $attributes = fn (EmissionPuParameter $parameter, EloquentCollection $events, EloquentCollection $integralizations): string => json_encode([
        $parameter->getAttributes(),
        $events->map(fn (EmissionPuEvent $event): array => $event->getAttributes())->all(),
        $integralizations->map(fn (IntegralizationHistory $integralization): array => $integralization->getAttributes())->all(),
    ]);

    return $attributes($parameter, $events, $integralizations) !== $attributes(p4fParameter(), p4fEvents(), p4fIntegralizations());
}

function p4fSnapshot(?EmissionPuParameter $parameter = null, ?EloquentCollection $events = null, ?EloquentCollection $integralizations = null): PuCurveInputSnapshot
{
    return app(PuCurveInputSnapshotService::class)->fromModels(
        $parameter ?? p4fParameter(),
        $events ?? p4fEvents(),
        $integralizations ?? p4fIntegralizations(),
    );
}

it('is identical for the same contract read in any database order', function () {
    $ordered = p4fSnapshot();
    $shuffled = p4fSnapshot(events: new EloquentCollection(p4fEvents()->reverse()->values()->all()), integralizations: new EloquentCollection(p4fIntegralizations()->reverse()->values()->all()));
    $renumbered = p4fSnapshot(events: new EloquentCollection(p4fEvents()->map(function (EmissionPuEvent $event): EmissionPuEvent {
        $event->setAttribute('id', $event->id + 100);

        return $event;
    })->all()));

    expect($shuffled->fingerprint)->toBe($ordered->fingerprint)
        ->and($renumbered->fingerprint)->toBe($ordered->fingerprint)
        // A ordem canônica é a da engine: data, prioridade do tipo (juros antes da
        // amortização), sequência.
        ->and(array_map(fn (array $event): string => $event['effective_date'].' '.$event['event_type'], $ordered->events()))->toBe([
            '2026-03-31 interest_payment',
            '2026-06-30 interest_payment',
            '2026-06-30 amortization',
            '2026-12-31 amortization',
        ]);
});

it('changes with every input the engine uses', function (Closure $mutate) {
    $base = p4fSnapshot();
    [$parameter, $events, $integralizations] = [p4fParameter(), p4fEvents(), p4fIntegralizations()];

    $mutate($parameter, $events, $integralizations);

    expect(p4fMutated($parameter, $events, $integralizations))->toBeTrue()
        ->and(p4fSnapshot($parameter, $events, $integralizations)->fingerprint)->not->toBe($base->fingerprint);
})->with([
    'valor da amortização' => [fn ($parameter, $events) => $events[1]->setAttribute('amortization_value', '0.3000000000000000')],
    'tipo da amortização' => [fn ($parameter, $events) => $events[3]->setAttribute('amortization_type', PuAmortizationType::UnitValue->value)->setAttribute('amortization_value', '10.0000000000000000')],
    'data efetiva do evento' => [fn ($parameter, $events) => $events[0]->setAttribute('effective_date', '2026-04-01')],
    'data original do evento (vai para a linha)' => [fn ($parameter, $events) => $events[0]->setAttribute('original_date', '2026-03-29')],
    'sequência de amortizações na mesma data' => [fn ($parameter, $events) => $events[1]->setAttribute('sequence', 2)],
    'evento cancelado' => [fn ($parameter, $events) => $events[0]->setAttribute('status', PuEventStatus::Cancelled->value)],
    'evento novo' => [fn ($parameter, $events) => $events->push(p4fEvent(9, ['event_type' => PuEventType::InterestPayment->value, 'effective_date' => '2026-09-30']))],
    'quantidade integralizada' => [fn ($parameter, $events, $integralizations) => $integralizations[1]->setAttribute('quantity', '60.0000')],
    'data da integralização' => [fn ($parameter, $events, $integralizations) => $integralizations[1]->setAttribute('date', '2026-04-16')],
    'integralização nova' => [fn ($parameter, $events, $integralizations) => $integralizations->push(p4fIntegralization(3, '2026-05-04', '10.0000'))],
    'spread' => [fn ($parameter) => $parameter->setAttribute('spread_rate', '6.50000000')],
    'base de dias úteis' => [fn ($parameter) => $parameter->setAttribute('business_day_basis', 360)],
    'modo de busca do índice' => [fn ($parameter) => $parameter->setAttribute('index_rate_lookup_mode', PuIndexRateLookupMode::PreviousCalendarDayExact->value)],
    'defasagem do índice' => [fn ($parameter) => $parameter->setAttribute('index_rate_lag_business_days', -2)],
    'calendário contratual' => [fn ($parameter) => $parameter->setAttribute('calendar_code', 'BR_BANKING_ANBIMA')],
    'calendário de divulgação do índice' => [fn ($parameter) => $parameter->setAttribute('index_rate_calendar_code', 'BR_BANKING_ANBIMA')],
    'vencimento' => [fn ($parameter) => $parameter->setAttribute('curve_end_date', '2027-01-29')],
    'VNe' => [fn ($parameter) => $parameter->setAttribute('initial_unit_value', '1000.5000000000000000')],
    'prêmio de primeiro cupom' => [fn ($parameter) => $parameter->setAttribute('first_coupon_pre_integralization_premium_enabled', true)],
]);

it('ignores what the engine never reads', function (Closure $mutate) {
    $base = p4fSnapshot();
    [$parameter, $events, $integralizations] = [p4fParameter(), p4fEvents(), p4fIntegralizations()];

    $mutate($parameter, $events, $integralizations);

    // A mutação rodou de fato: sem isto, um caso "não muda" passaria à toa.
    expect(p4fMutated($parameter, $events, $integralizations))->toBeTrue()
        ->and(p4fSnapshot($parameter, $events, $integralizations)->fingerprint)->toBe($base->fingerprint);
})->with([
    'descrição do evento' => [fn ($parameter, $events) => $events[0]->setAttribute('description', 'Texto novo.')],
    'justificativa e documento da data' => [fn ($parameter, $events) => $events[0]->forceFill(['effective_date_justification' => 'Feriado.', 'effective_date_evidence_reference' => 'Termo, cl. 4', 'document_reference' => 'Aditamento 1'])],
    'valor de amortização num pagamento de juros' => [fn ($parameter, $events) => $events[0]->setAttribute('amortization_value', '0.1000000000000000')],
    'valor de amortização residual' => [fn ($parameter, $events) => $events[3]->setAttribute('amortization_value', '0.9900000000000000')],
    'fundo e financeiro da integralização' => [fn ($parameter, $events, $integralizations) => $integralizations[0]->forceFill(['investor_fund' => 'Outro', 'financial_value' => '99999.99', 'unit_value' => '999.99000000'])],
    'taxa prefixada numa emissão de CDI' => [fn ($parameter) => $parameter->setAttribute('annual_rate', '12.00000000')],
    'projeção legada (inerte)' => [fn ($parameter) => $parameter->setAttribute('legacy_projection_enabled', true)],
    'política de arredondamento registrada' => [fn ($parameter) => $parameter->setAttribute('rounding_policy', 'outra')],
    'id do parâmetro' => [fn ($parameter) => $parameter->setAttribute('id', 99)],
]);

it('sums integralizations of the same date as the engine accumulates them', function () {
    $split = p4fSnapshot(integralizations: new EloquentCollection([
        p4fIntegralization(1, '2026-03-02', '60.0000'),
        p4fIntegralization(7, '2026-03-02', '40.0000'),
        p4fIntegralization(2, '2026-04-15', '50.0000'),
    ]));

    expect($split->fingerprint)->toBe(p4fSnapshot()->fingerprint)
        ->and($split->integralizations())->toBe([
            ['date' => '2026-03-02', 'quantity' => '100.0000'],
            ['date' => '2026-04-15', 'quantity' => '50.0000'],
        ])
        // A proveniência guarda as linhas de origem; o fingerprint não depende delas.
        ->and($split->provenance['integralization_ids']['2026-03-02'])->toBe([1, 7])
        // E prova a quantidade antes e depois de cada integralização.
        ->and($split->provenance['quantity_timeline'])->toBe([
            ['date' => '2026-03-02', 'quantity_before' => '0.0000', 'quantity_change' => '100.0000', 'quantity_after' => '100.0000', 'source_integralization_ids' => [1, 7]],
            ['date' => '2026-04-15', 'quantity_before' => '100.0000', 'quantity_change' => '50.0000', 'quantity_after' => '150.0000', 'source_integralization_ids' => [2]],
        ]);
});

it('is reproducible byte for byte and versioned apart from the engine', function () {
    $snapshot = p4fSnapshot();
    $stored = json_decode(json_encode($snapshot->toArray()), true);
    $reloaded = PuCurveInputSnapshot::fromStored($stored, $snapshot->fingerprint);

    expect($snapshot->schema)->toBe('pu-curve-inputs.v1')
        ->and($snapshot->engine()['engine_version'])->toBe('phase1-cdi-v2')
        ->and($reloaded->fingerprint)->toBe($snapshot->fingerprint)
        ->and($reloaded->payload)->toBe($snapshot->payload)
        // Valor fixo: mudar a canonicalização sem trocar o formato quebra este teste.
        ->and($snapshot->fingerprint)->toBe(P4F_GOLDEN_FINGERPRINT);
});

it('refuses a stored snapshot that does not match its fingerprint or format', function () {
    $stored = p4fSnapshot()->toArray();
    $tampered = $stored;
    $tampered['payload']['terms']['spread_rate'] = '0.00000000';
    $otherFormat = [...$stored, 'schema' => 'pu-curve-inputs.v0'];

    expect(fn () => PuCurveInputSnapshot::fromStored($tampered))->toThrow(PuCurveInputsException::class, 'não corresponde')
        ->and(fn () => PuCurveInputSnapshot::fromStored($otherFormat))->toThrow(PuCurveInputsException::class, 'formato desconhecido')
        ->and(fn () => PuCurveInputSnapshot::fromStored($stored, str_repeat('0', 64)))->toThrow(PuCurveInputsException::class);
});

it('classifies an engine identity change as reprocessing of the whole curve', function () {
    $approved = p4fSnapshot();
    $payload = $approved->payload;
    $payload['engine']['engine_version'] = 'phase4-cdi-v3';
    $live = PuCurveInputSnapshot::make($payload);
    $assessment = app(PuCurveChangeImpactClassifier::class)->compare($approved, $live, CarbonImmutable::parse('2026-03-16'));

    expect($live->fingerprint)->not->toBe($approved->fingerprint)
        ->and($assessment->impact)->toBe(PuCurveChangeImpact::HistoricalReprocessRequired)
        ->and($assessment->earliestAffectedDate?->toDateString())->toBe('2026-03-02')
        ->and($assessment->changes[0]->kind)->toBe(PuCurveChangeKind::EngineIdentity);
});

/*
 * Fingerprint do contrato de `p4fSnapshot()`. Recalcular só ao trocar
 * deliberadamente o formato (`PuCurveInputSnapshot::SCHEMA`).
 */
const P4F_GOLDEN_FINGERPRINT = 'b2a17b526c80ba2cb14d18300a950293779d5b9c00195cdc26adfd0cde9a1cc4';

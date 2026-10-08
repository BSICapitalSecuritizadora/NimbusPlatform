<?php

use App\Actions\Emissions\GeneratePuDailyCurve;
use App\Actions\Emissions\HomologatePuCurve;
use App\Domain\PuCalculator\DTOs\PuCurveGenerationResult;
use App\Domain\PuCalculator\DTOs\PuCurveInputSnapshot;
use App\Domain\PuCalculator\Enums\PuAmortizationType;
use App\Domain\PuCalculator\Enums\PuCalculationMethod;
use App\Domain\PuCalculator\Enums\PuCalculationProfile;
use App\Domain\PuCalculator\Enums\PuCurveChangeImpact;
use App\Domain\PuCalculator\Enums\PuCurveChangeKind;
use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Domain\PuCalculator\Enums\PuEventStatus;
use App\Domain\PuCalculator\Enums\PuEventType;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use App\Domain\PuCalculator\Enums\PuOfficialCurveFreshness;
use App\Domain\PuCalculator\Exceptions\PuCurveGovernanceException;
use App\Domain\PuCalculator\Exceptions\PuCurveInputsException;
use App\Domain\PuCalculator\Factories\PuCalculatorFactory;
use App\Domain\PuCalculator\Services\BusinessDayCalendarService;
use App\Domain\PuCalculator\Services\EmissionPuReader;
use App\Domain\PuCalculator\Services\IndexRateCorrectionService;
use App\Domain\PuCalculator\Services\PuContractualEventService;
use App\Domain\PuCalculator\Services\PuCurveChangeImpactClassifier;
use App\Domain\PuCalculator\Services\PuCurveExtensionService;
use App\Domain\PuCalculator\Services\PuCurveGeneratorService;
use App\Domain\PuCalculator\Services\PuCurveInputSnapshotService;
use App\Domain\PuCalculator\Services\PuCurvePrerequisiteService;
use App\Domain\PuCalculator\Services\PuOfficialCurveFreshnessService;
use App\Domain\PuCalculator\Services\PuOperationalMonitorService;
use App\Domain\PuCalculator\Services\PuPersistedCurveChecksumService;
use App\Domain\PuCalculator\Support\PuCurveChangePolicy;
use App\Enums\AccessPermission;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\PuEventsRelationManager;
use App\Filament\Resources\Emissions\Pages\EditEmission;
use App\Models\BusinessCalendarDate;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuDailyCurve;
use App\Models\EmissionPuEvent;
use App\Models\IndexRate;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Fase 4 -- ciclo de vida contratual da curva de PU.
 *
 * Índice realizado novo estende a curva aprovada; contrato novo não. Toda mudança
 * de insumo contratual (evento, integralização, parâmetro) que a versão
 * homologada não aprovou só chega ao PU oficial por uma versão nova homologada:
 * no futuro, a oficial para na véspera; no passado já gravado, reprocessamento. A
 * oficial nunca é reescrita.
 *
 * Cenário comum: CDI com defasagem de 1 dia útil, 100 títulos em 02/03/2026, CDI
 * de 27/02 a 13/03 -- a v1 homologada é realizada até segunda, 16/03.
 */
uses(RefreshDatabase::class);

function p4lEmission(): Emission
{
    $emission = Emission::factory()->active()->create(['type' => 'CRI', 'issued_quantity' => 1000]);
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
        'legacy_projection_enabled' => false,
    ]);

    if (! BusinessCalendarDate::query()->where('calendar_code', 'B3')->exists()) {
        for ($date = CarbonImmutable::parse('2026-02-23'); $date->lte(CarbonImmutable::parse('2027-01-08')); $date = $date->addDay()) {
            BusinessCalendarDate::query()->create([
                'calendar_code' => 'B3',
                'calendar_date' => $date->toDateString(),
                'is_business_day' => ! $date->isWeekend(),
                'description' => null,
            ]);
        }
    }

    app(BusinessDayCalendarService::class)->flushCache();

    return $emission->fresh();
}

function p4lPublish(string $from, string $to, string $value = '14.90000000'): void
{
    for ($date = CarbonImmutable::parse($from); $date->lte(CarbonImmutable::parse($to)); $date = $date->addDay()) {
        if (! $date->isWeekend() && ! IndexRate::query()->whereDate('rate_date', $date->toDateString())->exists()) {
            IndexRate::factory()->create([
                'indexer' => PuIndexer::Cdi->value,
                'rate_date' => $date->toDateString(),
                'rate_value' => $value,
                'source' => 'bcb_sgs',
                'source_reference' => 'bcb_sgs:4389',
            ]);
        }
    }
}

function p4lInterest(Emission $emission, string $date, array $attributes = []): EmissionPuEvent
{
    return EmissionPuEvent::query()->create([
        'emission_id' => $emission->id,
        'event_type' => PuEventType::InterestPayment->value,
        'original_date' => $date,
        'effective_date' => $date,
        'amortization_type' => PuAmortizationType::None->value,
        'sequence' => 1,
        ...$attributes,
    ]);
}

function p4lAmortization(Emission $emission, string $date, string $percentage, array $attributes = []): EmissionPuEvent
{
    return EmissionPuEvent::query()->create([
        'emission_id' => $emission->id,
        'event_type' => PuEventType::Amortization->value,
        'original_date' => $date,
        'effective_date' => $date,
        'amortization_type' => PuAmortizationType::Percentage->value,
        'amortization_value' => $percentage,
        'sequence' => 1,
        ...$attributes,
    ]);
}

function p4lGenerate(Emission $emission): EmissionPuCurveVersion
{
    $result = app(GeneratePuDailyCurve::class)->handle($emission->fresh());

    return EmissionPuCurveVersion::query()
        ->where('emission_id', $emission->id)
        ->where('calculation_version', $result->calculationVersion)
        ->sole();
}

function p4lHomologate(Emission $emission, EmissionPuCurveVersion $version): EmissionPuCurveVersion
{
    return app(HomologatePuCurve::class)->handle(
        $emission->fresh(),
        $version->calculation_version,
        User::factory()->create()->id,
        'Conferida contra o sistema antigo.',
    );
}

/**
 * Curva oficial realizada até 16/03, com os eventos que `$before` cadastrar ANTES
 * da geração (aprovados no retrato).
 *
 * @return array{0: Emission, 1: EmissionPuCurveVersion}
 */
function p4lOfficial(?Closure $before = null): array
{
    $emission = p4lEmission();

    if ($before instanceof Closure) {
        $before($emission);
    }

    p4lPublish('2026-02-27', '2026-03-13');
    $official = p4lHomologate($emission, p4lGenerate($emission));

    return [$emission->fresh(), $official->fresh()];
}

function p4lLast(EmissionPuCurveVersion $version): string
{
    return CarbonImmutable::parse((string) $version->dailyCurves()->max('curve_date'))->toDateString();
}

/**
 * @return array<int, array<string, string>>
 */
function p4lRows(EmissionPuCurveVersion $version): array
{
    return $version->dailyCurves()
        ->orderBy('id')
        ->get()
        ->mapWithKeys(fn (EmissionPuDailyCurve $row): array => [$row->id => [
            'date' => CarbonImmutable::parse((string) $row->curve_date)->toDateString(),
            'residual' => (string) $row->residual_unit_value,
            'quantity' => (string) $row->quantity,
            'total' => (string) $row->total_value,
            'updated_at' => (string) $row->getRawOriginal('updated_at'),
        ]])
        ->all();
}

function p4lRow(EmissionPuCurveVersion $version, string $date): ?EmissionPuDailyCurve
{
    return $version->dailyCurves()->whereDate('curve_date', $date)->first();
}

function p4lStatus(Emission $emission, string $localDateTime = '2026-03-17 10:00')
{
    return app(PuOfficialCurveFreshnessService::class)->status(
        $emission->fresh(),
        CarbonImmutable::parse($localDateTime, 'America/Sao_Paulo'),
    );
}

function p4lAssess(EmissionPuCurveVersion $version)
{
    return app(PuCurveChangeImpactClassifier::class)->assessVersion($version->fresh());
}

function p4lExtend(Emission $emission)
{
    return app(PuCurveExtensionService::class)->extendOfficial($emission->fresh());
}

it('keeps extending the official curve with new realized CDI when no contractual input changed', function () {
    [$emission, $official] = p4lOfficial();
    p4lPublish('2026-03-16', '2026-03-20');
    $before = p4lLast($official);

    $result = p4lExtend($emission);

    expect($before)->toBe('2026-03-16')
        ->and($result->action)->toBe(PuCurveExtensionService::ACTION_EXTENDED)
        ->and(p4lLast($official))->toBe('2026-03-23')
        ->and(p4lAssess($official)->impact)->toBe(PuCurveChangeImpact::NoCurveImpact)
        ->and($official->fresh()->contractual_change_detected_at)->toBeNull()
        ->and(p4lStatus($emission, '2026-03-23 10:00')->freshness)->toBe(PuOfficialCurveFreshness::Current);
});

it('P1-03: puts integralization quantity in the fingerprint and reprocesses a retroactive integralization into a new homologated version', function () {
    [$emission, $official] = p4lOfficial();
    p4lPublish('2026-03-16', '2026-03-18');
    p4lExtend($emission);
    $approvedInputs = $official->fresh()->curve_inputs;
    $rowsBefore = p4lRows($official);

    // Integralização retroativa: 50 títulos em 05/03, dentro do trecho homologado.
    $emission->integralizationHistories()->create([
        'date' => '2026-03-05',
        'quantity' => '50.0000',
        'unit_value' => '1000.00000000',
        'financial_value' => '50000.00',
        'investor_fund' => 'Outro Fundo',
    ]);
    $live = app(PuCurveInputSnapshotService::class)->capture($emission->fresh());
    p4lPublish('2026-03-19', '2026-03-20');
    $result = p4lExtend($emission);
    $official->refresh();
    $status = p4lStatus($emission, '2026-03-20 10:00');

    expect($live->fingerprint)->not->toBe($official->curve_inputs_fingerprint)
        ->and($live->integralizations())->toBe([
            ['date' => '2026-03-02', 'quantity' => '100.0000'],
            ['date' => '2026-03-05', 'quantity' => '50.0000'],
        ])
        // A oficial não absorve nem é reescrita.
        ->and($result->action)->toBe(PuCurveExtensionService::ACTION_DIVERGED_GOVERNED)
        ->and($result->firstDivergentDate)->toBe('2026-03-05')
        ->and(p4lRows($official))->toBe($rowsBefore)
        ->and($official->curve_inputs)->toBe($approvedInputs)
        ->and($official->status)->toBe(PuCurveStatus::Homologated)
        ->and($official->extension_divergence['cause'])->toBe(PuCurveExtensionService::CAUSE_CONTRACTUAL_INPUT_CHANGED)
        ->and($official->extension_divergence['first_divergent_date'])->toBe('2026-03-05')
        ->and($status->freshness)->toBe(PuOfficialCurveFreshness::ReprocessingRequired)
        ->and($status->reprocessingFrom?->toDateString())->toBe('2026-03-05')
        // O que é anterior à data afetada continua valendo.
        ->and($status->isReliableAt(CarbonImmutable::parse('2026-03-04')))->toBeTrue()
        ->and($status->isReliableAt(CarbonImmutable::parse('2026-03-05')))->toBeFalse();

    // A versão nova usa a quantidade corrigida, mas só vira oficial pela homologação.
    $v2 = p4lGenerate($emission);

    expect($v2->status)->toBe(PuCurveStatus::Generated)
        ->and($emission->fresh()->officialPuCurveVersion()?->id)->toBe($official->id)
        ->and((string) p4lRow($v2, '2026-03-04')->quantity)->toBe('100.0000')
        ->and((string) p4lRow($v2, '2026-03-05')->quantity)->toBe('150.0000')
        ->and((string) p4lRow($official, '2026-03-05')->quantity)->toBe('100.0000')
        ->and($v2->predecessor_version_id)->toBe($official->id)
        ->and($v2->generation_context['reason'])->toBe('contractual_input_changed')
        ->and($v2->generation_context['earliest_affected_date'])->toBe('2026-03-05')
        ->and($v2->generation_context['impact'])->toBe(PuCurveChangeImpact::HistoricalReprocessRequired->value);

    p4lHomologate($emission, $v2);

    expect($emission->fresh()->officialPuCurveVersion()?->id)->toBe($v2->id)
        ->and(p4lRows($official))->toBe($rowsBefore)
        ->and($official->fresh()->curve_inputs)->toBe($approvedInputs);
});

it('treats a quantity correction of an approved integralization as reprocessing from its date', function () {
    [$emission, $official] = p4lOfficial();
    $emission->integralizationHistories()->sole()->update(['quantity' => '120.0000']);

    $assessment = p4lAssess($official);

    expect($assessment->impact)->toBe(PuCurveChangeImpact::HistoricalReprocessRequired)
        ->and($assessment->earliestAffectedDate?->toDateString())->toBe('2026-03-02')
        ->and($assessment->changes[0]->kind)->toBe(PuCurveChangeKind::Integralization)
        ->and($assessment->changes[0]->before)->toBe('100.0000')
        ->and($assessment->changes[0]->after)->toBe('120.0000');
});

it('P1-04: never lets a future event recorded after homologation enter the homologated version', function () {
    [$emission, $official] = p4lOfficial();
    // Cupom de 20/03 cadastrado DEPOIS da homologação da v1.
    p4lInterest($emission, '2026-03-20');
    p4lPublish('2026-03-16', '2026-03-25');

    $assessment = p4lAssess($official);
    $first = p4lExtend($emission);
    $second = p4lExtend($emission);
    $official->refresh();
    $status = p4lStatus($emission, '2026-03-26 10:00');

    expect($assessment->impact)->toBe(PuCurveChangeImpact::FutureVersionRequired)
        ->and($assessment->earliestAffectedDate?->toDateString())->toBe('2026-03-20')
        ->and($assessment->extensionLimit()?->toDateString())->toBe('2026-03-19')
        // Avança só até a véspera do evento não aprovado...
        ->and($first->action)->toBe(PuCurveExtensionService::ACTION_EXTENDED)
        ->and($first->toDate)->toBe('2026-03-19')
        ->and(p4lLast($official))->toBe('2026-03-19')
        // ...e para lá, sem absorver o cupom.
        ->and($second->action)->toBe(PuCurveExtensionService::ACTION_CONTRACTUAL_CHANGE_PENDING)
        ->and(p4lRow($official, '2026-03-20'))->toBeNull()
        ->and(Payment::query()->whereBelongsTo($emission)->whereDate('payment_date', '2026-03-20')->exists())->toBeFalse()
        ->and($official->contractual_change_detected_at)->not->toBeNull()
        ->and($official->contractual_change['impact'])->toBe(PuCurveChangeImpact::FutureVersionRequired->value)
        ->and($official->extension_diverged_at)->toBeNull()
        ->and($status->freshness)->toBe(PuOfficialCurveFreshness::NewVersionRequired)
        ->and($status->contractualChangeFrom?->toDateString())->toBe('2026-03-20')
        ->and($status->isReliableAt(CarbonImmutable::parse('2026-03-19')))->toBeTrue()
        ->and(app(PuOperationalMonitorService::class)->pendingContractualChangeCount())->toBe(1)
        ->and(Activity::query()->where('description', 'pu_curve_contractual_change_detected')->count())->toBe(1);

    // Versão nova com o cupom; só homologada ela leva o evento ao PU oficial.
    $v2 = p4lGenerate($emission);
    p4lHomologate($emission, $v2);
    $extended = p4lExtend($emission);
    $couponRow = p4lRow($v2->fresh(), '2026-03-20');

    expect($extended->action)->toBeIn([PuCurveExtensionService::ACTION_EXTENDED, PuCurveExtensionService::ACTION_UP_TO_DATE])
        ->and($couponRow)->not->toBeNull()
        ->and(bccomp((string) $couponRow->interest_payment_unit_value, '0', 8))->toBe(1)
        ->and(Payment::query()->whereBelongsTo($emission)->whereDate('payment_date', '2026-03-20')->sole()->isCalculatedByOfficialCurve())->toBeTrue()
        ->and(p4lRow($official->fresh(), '2026-03-20'))->toBeNull()
        // A pendência era da v1; com a v2 oficial, o monitor não a conta mais.
        ->and(app(PuOperationalMonitorService::class)->pendingContractualChangeCount())->toBe(0);
});

it('P1-04 for parameters: a future spread change cannot cross into the homologated version and applies only through a new one', function () {
    [$emission, $official] = p4lOfficial(function (Emission $emission): void {
        p4lInterest($emission, '2026-03-31');
        p4lInterest($emission, '2026-04-30');
    });
    // Repactuação: a partir do período que começa no pagamento de 31/03, spread de 8%.
    EmissionPuEvent::query()->create([
        'emission_id' => $emission->id,
        'event_type' => PuEventType::SpreadAmendment->value,
        'effective_date' => '2026-03-31',
        'financial_effect' => ['spread_rate' => '8'],
        'sequence' => 1,
        'document_reference' => '1º aditamento ao Termo, cláusula 4.2',
    ]);
    p4lPublish('2026-03-16', '2026-04-10');

    p4lExtend($emission);
    $pending = p4lExtend($emission);

    expect(p4lAssess($official)->impact)->toBe(PuCurveChangeImpact::FutureVersionRequired)
        ->and(p4lLast($official))->toBe('2026-03-30')
        ->and($pending->action)->toBe(PuCurveExtensionService::ACTION_CONTRACTUAL_CHANGE_PENDING);

    $v2 = p4lGenerate($emission);
    $checksums = app(PuPersistedCurveChecksumService::class);
    $officialRows = $checksums->persistedRows($official->fresh());
    $v2Prefix = array_values(array_filter(
        $checksums->persistedRows($v2),
        fn ($row): bool => $row->date->toDateString() <= '2026-03-30',
    ));

    expect($checksums->checksumForRows($v2Prefix))->toBe($checksums->checksumForRows($officialRows))
        // O pagamento de 31/03 encerra o período antigo com o spread antigo...
        ->and(p4lRow($v2, '2026-03-31')->calculation_memory['spread_rate_applied'])->toBe('6.00000000')
        // ...e o período seguinte capitaliza o novo.
        ->and(p4lRow($v2, '2026-04-01')->calculation_memory['spread_rate_applied'])->toBe('8.00000000')
        ->and((string) p4lRow($v2, '2026-04-01')->factor_spread)->not->toBe((string) p4lRow($v2, '2026-03-03')->factor_spread);

    p4lHomologate($emission, $v2);

    expect($emission->fresh()->officialPuCurveVersion()?->id)->toBe($v2->id)
        ->and(p4lLast($official->fresh()))->toBe('2026-03-30');
});

it('treats an edit of the base spread as a change of the whole contract under the same policy', function () {
    [$emission, $official] = p4lOfficial();
    $emission->puParameter->update(['spread_rate' => '6.50000000']);
    p4lPublish('2026-03-16', '2026-03-18');

    $assessment = p4lAssess($official);
    $result = p4lExtend($emission);

    expect($assessment->impact)->toBe(PuCurveChangeImpact::HistoricalReprocessRequired)
        ->and($assessment->earliestAffectedDate?->toDateString())->toBe('2026-03-02')
        ->and($assessment->changes[0]->key)->toBe('terms.spread_rate')
        ->and($result->action)->toBe(PuCurveExtensionService::ACTION_DIVERGED_GOVERNED)
        ->and(p4lLast($official))->toBe('2026-03-16');
});

it('applies a future event already approved in the homologated version without a new homologation', function () {
    [$emission, $official] = p4lOfficial(function (Emission $emission): void {
        p4lInterest($emission, '2026-03-20');
        p4lAmortization($emission, '2026-03-20', '0.1000000000000000');
    });
    p4lPublish('2026-03-16', '2026-03-25');

    $result = p4lExtend($emission);
    $row = p4lRow($official, '2026-03-20');

    expect(p4lAssess($official)->impact)->toBe(PuCurveChangeImpact::NoCurveImpact)
        ->and($result->action)->toBe(PuCurveExtensionService::ACTION_EXTENDED)
        ->and(p4lLast($official))->toBe('2026-03-26')
        ->and($emission->fresh()->officialPuCurveVersion()?->id)->toBe($official->id)
        ->and($official->fresh()->contractual_change_detected_at)->toBeNull()
        ->and($row?->calculation_memory['event_types'])->toBe(['interest_payment', 'amortization'])
        ->and(bccomp((string) $row->amortization_unit_value, '99.99', 2))->toBe(1)
        ->and(Payment::query()->whereBelongsTo($emission)->whereDate('payment_date', '2026-03-20')->sole()->isCalculatedByOfficialCurve())->toBeTrue();
});

it('keeps the approved snapshot of an event edited after homologation and requires a new version', function () {
    $event = null;
    [$emission, $official] = p4lOfficial(function (Emission $emission) use (&$event): void {
        $event = p4lAmortization($emission, '2026-03-20', '0.1000000000000000');
    });
    $approved = $official->curve_inputs;

    $event->update(['amortization_value' => '0.2000000000000000']);
    p4lPublish('2026-03-16', '2026-03-25');
    p4lExtend($emission);
    $snapshot = PuCurveInputSnapshot::fromStored($official->fresh()->curve_inputs);
    $v2 = p4lGenerate($emission);

    expect($official->fresh()->curve_inputs)->toBe($approved)
        ->and(collect($snapshot->events())->firstWhere('event_type', 'amortization')['amortization_value'])->toBe('0.1000000000000000')
        ->and(p4lAssess($official)->impact)->toBe(PuCurveChangeImpact::FutureVersionRequired)
        ->and(p4lLast($official))->toBe('2026-03-19')
        ->and((string) p4lRow($v2, '2026-03-20')->amortization_ratio)->toBe('0.2000000000000000');
});

it('governs the cancellation of an approved event by its effective date, keeping the evidence', function () {
    Permission::findOrCreate(AccessPermission::PuParametersConfigure->value, 'web');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $actor = User::factory()->create();
    $actor->givePermissionTo(AccessPermission::PuParametersConfigure->value);
    $events = [];
    [$emission, $official] = p4lOfficial(function (Emission $emission) use (&$events): void {
        $events['past'] = p4lInterest($emission, '2026-03-09');
        $events['future'] = p4lInterest($emission, '2026-03-20');
    });

    app(PuContractualEventService::class)->cancel($events['future'], $actor, 'Cupom antecipado por assembleia de 18/03.');
    $futureOnly = p4lAssess($official);
    $cancelled = $events['future']->fresh();

    expect($futureOnly->impact)->toBe(PuCurveChangeImpact::FutureVersionRequired)
        ->and($futureOnly->earliestAffectedDate?->toDateString())->toBe('2026-03-20')
        ->and($cancelled->status)->toBe(PuEventStatus::Cancelled)
        ->and($cancelled->cancelled_by)->toBe($actor->id)
        ->and($cancelled->cancellation_reason)->toBe('Cupom antecipado por assembleia de 18/03.')
        // A versão que o aprovou ainda o tem no retrato.
        ->and(collect(PuCurveInputSnapshot::fromStored($official->fresh()->curve_inputs)->events())->pluck('effective_date')->all())
        ->toBe(['2026-03-09', '2026-03-20']);

    app(PuContractualEventService::class)->cancel($events['past'], $actor, 'Lançado em duplicidade.');

    expect(p4lAssess($official)->impact)->toBe(PuCurveChangeImpact::HistoricalReprocessRequired)
        ->and(p4lAssess($official)->earliestAffectedDate?->toDateString())->toBe('2026-03-09')
        ->and(EmissionPuEvent::query()->whereBelongsTo($emission)->count())->toBe(2)
        ->and(fn () => app(PuContractualEventService::class)->cancel($events['past']->fresh(), $actor, 'De novo.'))
        ->toThrow(ValidationException::class);
});

it('requires reprocessing for a retroactive event and never patches the official curve', function () {
    [$emission, $official] = p4lOfficial();
    $rowsBefore = p4lRows($official);
    p4lInterest($emission, '2026-03-09');
    p4lPublish('2026-03-16', '2026-03-18');

    $result = p4lExtend($emission);
    $status = p4lStatus($emission);

    expect($result->action)->toBe(PuCurveExtensionService::ACTION_DIVERGED_GOVERNED)
        ->and($result->firstDivergentDate)->toBe('2026-03-09')
        ->and(p4lRows($official))->toBe($rowsBefore)
        ->and($status->freshness)->toBe(PuOfficialCurveFreshness::ReprocessingRequired)
        ->and($status->reprocessingFrom?->toDateString())->toBe('2026-03-09')
        ->and(app(EmissionPuReader::class)->officialReadingAt($emission->fresh(), CarbonImmutable::parse('2026-03-06'), CarbonImmutable::parse('2026-03-17 10:00', 'America/Sao_Paulo'))['reading']?->fromOfficialCurve())
        ->toBeTrue();
});

it('does not reprocess anything for operational metadata', function () {
    $event = null;
    [$emission, $official] = p4lOfficial(function (Emission $emission) use (&$event): void {
        $event = p4lInterest($emission, '2026-03-20', ['description' => 'Cronograma.']);
    });

    $event->update([
        'description' => 'Pagamento de juros do 1º período, conferido.',
        'document_reference' => 'Termo, cláusula 4.1',
    ]);
    $emission->puParameter->update(['legacy_projection_enabled' => true, 'rounding_policy' => 'registro']);
    $emission->integralizationHistories()->sole()->update(['investor_fund' => 'Head Invest II', 'financial_value' => '100000.01']);
    p4lPublish('2026-03-16', '2026-03-25');

    $result = p4lExtend($emission);

    expect(p4lAssess($official)->impact)->toBe(PuCurveChangeImpact::NoCurveImpact)
        ->and($result->action)->toBe(PuCurveExtensionService::ACTION_EXTENDED)
        ->and(p4lLast($official))->toBe('2026-03-26')
        ->and($official->fresh()->contractual_change_detected_at)->toBeNull()
        ->and(p4lStatus($emission, '2026-03-26 10:00')->freshness)->toBe(PuOfficialCurveFreshness::Current);
});

it('keeps every homologated input reproducible after the live contract changes', function () {
    $event = null;
    [$emission, $official] = p4lOfficial(function (Emission $emission) use (&$event): void {
        $event = p4lAmortization($emission, '2026-06-30', '0.2500000000000000');
    });
    $stored = $official->curve_inputs;

    // O contrato vivo muda por inteiro depois da homologação.
    $emission->puParameter->update(['spread_rate' => '9.00000000', 'curve_end_date' => '2027-01-29']);
    $event->update(['amortization_value' => '0.5000000000000000']);
    $emission->integralizationHistories()->sole()->update(['quantity' => '80.0000']);

    $official->refresh();
    $snapshot = PuCurveInputSnapshot::fromStored($official->curve_inputs, $official->curve_inputs_fingerprint);
    $recomputed = app(PuCurveGeneratorService::class)->handle(
        app(PuCurveInputSnapshotService::class)->hydrate($emission->fresh(), $snapshot),
    )->rows;
    $checksums = app(PuPersistedCurveChecksumService::class);
    $persisted = $checksums->persistedRows($official);
    $recomputedPrefix = array_values(array_filter($recomputed, fn ($row): bool => $row->date->toDateString() <= '2026-03-16'));

    expect($official->curve_inputs)->toBe($stored)
        ->and($snapshot->terms()['spread_rate'])->toBe('6.00000000')
        ->and($snapshot->horizon()['contractual_maturity_date'])->toBe('2026-12-31')
        ->and($snapshot->integralizations())->toBe([['date' => '2026-03-02', 'quantity' => '100.0000']])
        ->and($snapshot->events()[0]['amortization_value'])->toBe('0.2500000000000000')
        ->and($snapshot->engine()['engine_version'])->toBe('phase1-cdi-v2')
        // O retrato explica a versão: recalculado dele, o trecho gravado sai idêntico.
        ->and($checksums->checksumForRows($recomputedPrefix))->toBe($checksums->checksumForRows($persisted))
        ->and(fn () => $official->forceFill(['curve_inputs' => ['schema' => 'x']])->save())->toThrow(LogicException::class)
        ->and(fn () => p4lRow($official, '2026-03-10')->update(['residual_unit_value' => '1.0']))->toThrow(LogicException::class);
});

it('refuses to homologate a version whose recorded days no longer describe the live contract', function () {
    $emission = p4lEmission();
    p4lPublish('2026-02-27', '2026-03-13');
    $v1 = p4lGenerate($emission);
    $maker = User::factory()->create();

    p4lInterest($emission, '2026-03-09');

    expect(fn () => p4lHomologate($emission, $v1))->toThrow(PuCurveGovernanceException::class, 'não pode ser homologada')
        ->and(fn () => app(HomologatePuCurve::class)->handle($emission->fresh(), $v1->calculation_version, $maker->id, 'Auto.'))
        ->toThrow(PuCurveGovernanceException::class, 'não pode ser homologada')
        ->and($v1->fresh()->status)->toBe(PuCurveStatus::Generated)
        ->and($emission->fresh()->officialPuCurveVersion())->toBeNull();

    // Mudança só no futuro não invalida o que a versão gravou: homologável, e a
    // extensão a limita à véspera da mudança.
    $v2 = p4lGenerate($emission);
    p4lInterest($emission, '2026-04-30');

    expect(p4lHomologate($emission, $v2)->status)->toBe(PuCurveStatus::Homologated)
        ->and(p4lAssess($v2)->impact)->toBe(PuCurveChangeImpact::FutureVersionRequired);
});

it('never records a version whose inputs changed while it was being calculated', function () {
    $emission = p4lEmission();
    $event = p4lInterest($emission, '2026-03-09');
    p4lPublish('2026-02-27', '2026-03-13');

    // A engine calcula com o retrato; no meio do cálculo, alguém altera o evento.
    app()->bind(PuCurveGeneratorService::class, fn ($app) => new class($app->make(PuCalculatorFactory::class), $event) extends PuCurveGeneratorService
    {
        public function __construct($factory, private readonly EmissionPuEvent $event)
        {
            parent::__construct($factory);
        }

        public function handle(Emission $emission, ?string $indexRateCalendarCode = null, ?string $accrualCalendarCode = null, PuCalculationProfile $profile = PuCalculationProfile::Contractual): PuCurveGenerationResult
        {
            $result = parent::handle($emission, $indexRateCalendarCode, $accrualCalendarCode, $profile);
            $this->event->fresh()->update(['effective_date' => '2026-03-10', 'original_date' => '2026-03-10']);

            return $result;
        }
    });

    expect(fn () => app(GeneratePuDailyCurve::class)->handle($emission->fresh()))
        ->toThrow(PuCurveInputsException::class, 'mudaram durante a geração')
        ->and(EmissionPuCurveVersion::query()->where('emission_id', $emission->id)->count())->toBe(0)
        ->and(EmissionPuDailyCurve::query()->where('emission_id', $emission->id)->count())->toBe(0)
        ->and($event->fresh()->governed_at)->toBeNull();
});

it('routes a historical CDI correction through the same reprocessing state and records it on the next version', function () {
    [$emission, $official] = p4lOfficial();
    p4lPublish('2026-03-16', '2026-03-18');
    p4lExtend($emission);

    app(IndexRateCorrectionService::class)->correct(PuIndexer::Cdi, '2026-03-17', '15.40', 'Republicação da Taxa DI.', User::factory()->create()->id);
    $status = p4lStatus($emission, '2026-03-19 10:00');
    $v2 = p4lGenerate($emission);

    expect($official->fresh()->extension_divergence['cause'])->toBe('index_rate_corrected')
        ->and($status->freshness)->toBe(PuOfficialCurveFreshness::ReprocessingRequired)
        ->and($v2->generation_context['reason'])->toBe('index_rate_corrected')
        // Correção de mercado não é mudança contratual: o retrato é o mesmo.
        ->and($v2->curve_inputs_fingerprint)->toBe($official->curve_inputs_fingerprint)
        ->and(PuCurveChangePolicy::decide(PuCurveChangeKind::IndexRateCorrection, CarbonImmutable::parse('2026-03-17'), CarbonImmutable::parse('2026-03-19')))
        ->toBe(PuCurveChangeImpact::HistoricalReprocessRequired)
        ->and(PuCurveChangePolicy::decide(PuCurveChangeKind::IndexRateCorrection, CarbonImmutable::parse('2026-03-25'), CarbonImmutable::parse('2026-03-19')))
        ->toBe(PuCurveChangeImpact::ExtensionSafe)
        ->and(PuCurveChangePolicy::decide(PuCurveChangeKind::NewRealizedIndexObservation, null, CarbonImmutable::parse('2026-03-19')))
        ->toBe(PuCurveChangeImpact::ExtensionSafe);
});

it('decides the versioning matrix from one central policy', function () {
    $matrix = collect(PuCurveChangePolicy::matrix(CarbonImmutable::parse('2026-03-16')))->keyBy('change');
    $expected = [
        'Novo CDI realizado' => PuCurveChangeImpact::ExtensionSafe,
        'Correção histórica de CDI' => PuCurveChangeImpact::HistoricalReprocessRequired,
        'Integralização futura já aprovada no retrato' => PuCurveChangeImpact::NoCurveImpact,
        'Integralização futura fora do retrato aprovado' => PuCurveChangeImpact::FutureVersionRequired,
        'Integralização retroativa' => PuCurveChangeImpact::HistoricalReprocessRequired,
        'Correção de quantidade integralizada' => PuCurveChangeImpact::HistoricalReprocessRequired,
        'Alteração de spread (termo de base)' => PuCurveChangeImpact::HistoricalReprocessRequired,
        'Alteração de spread com vigência futura (evento)' => PuCurveChangeImpact::FutureVersionRequired,
        'Alteração de base de dias úteis' => PuCurveChangeImpact::HistoricalReprocessRequired,
        'Alteração do modo de busca do índice' => PuCurveChangeImpact::HistoricalReprocessRequired,
        'Alteração da defasagem do índice' => PuCurveChangeImpact::HistoricalReprocessRequired,
        'Alteração do calendário contratual' => PuCurveChangeImpact::HistoricalReprocessRequired,
        'Alteração do vencimento (futuro)' => PuCurveChangeImpact::FutureVersionRequired,
        'Alteração do vencimento (antes do último dia gravado)' => PuCurveChangeImpact::HistoricalReprocessRequired,
        'Evento incluído no futuro' => PuCurveChangeImpact::FutureVersionRequired,
        'Evento incluído no passado' => PuCurveChangeImpact::HistoricalReprocessRequired,
        'Evento alterado (futuro)' => PuCurveChangeImpact::FutureVersionRequired,
        'Evento cancelado (futuro)' => PuCurveChangeImpact::FutureVersionRequired,
        'Evento cancelado (passado)' => PuCurveChangeImpact::HistoricalReprocessRequired,
        'Evento movido para trás do último dia gravado' => PuCurveChangeImpact::HistoricalReprocessRequired,
        'Somente metadado operacional' => PuCurveChangeImpact::NoCurveImpact,
    ];

    expect($matrix->keys()->all())->toBe(array_keys($expected));

    foreach ($expected as $change => $impact) {
        $row = $matrix[$change];

        expect($row['impact'])->toBe($impact, $change)
            ->and($row['new_version'])->toBe($impact->requiresNewVersion())
            ->and($row['reprocess'])->toBe($impact === PuCurveChangeImpact::HistoricalReprocessRequired)
            ->and($row['homologation'])->toBe($impact->requiresHomologation());
    }
});

it('records who changed which contractual input, before and after, and its impact on each live version', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $event = null;
    [$emission, $official] = p4lOfficial(function (Emission $emission) use (&$event): void {
        $event = p4lInterest($emission, '2026-03-20');
    });

    $event->update(['effective_date' => '2026-03-23', 'original_date' => '2026-03-23']);
    $log = Activity::query()->where('description', 'pu_contractual_input_changed')->latest('id')->first();

    expect($log)->not->toBeNull()
        ->and($log->causer_id)->toBe($user->id)
        ->and($log->properties['input'])->toBe('pu_event')
        ->and($log->properties['action'])->toBe('updated')
        ->and(substr((string) $log->properties['before']['effective_date'], 0, 10))->toBe('2026-03-20')
        ->and(substr((string) $log->properties['after']['effective_date'], 0, 10))->toBe('2026-03-23')
        ->and($log->properties['effective_date'])->toBe('2026-03-23')
        ->and($log->properties['affected_versions'][0]['curve_version_id'])->toBe($official->id)
        ->and($log->properties['affected_versions'][0]['impact'])->toBe(PuCurveChangeImpact::FutureVersionRequired->value)
        ->and($log->properties['affected_versions'][0]['earliest_affected_date'])->toBe('2026-03-20');
});

it('orders same-day events by contractual priority, never by insertion order', function () {
    $amortizationFirst = p4lEmission();
    p4lAmortization($amortizationFirst, '2026-03-09', '0.1000000000000000');
    p4lInterest($amortizationFirst, '2026-03-09');
    p4lPublish('2026-02-27', '2026-03-13');
    $interestFirst = p4lEmission();
    p4lInterest($interestFirst, '2026-03-09');
    p4lAmortization($interestFirst, '2026-03-09', '0.1000000000000000');

    $left = p4lGenerate($amortizationFirst);
    $right = p4lGenerate($interestFirst);
    $checksums = app(PuPersistedCurveChecksumService::class);

    expect($left->curve_inputs['payload'])->toBe($right->curve_inputs['payload'])
        ->and($left->curve_inputs_fingerprint)->not->toBeNull()
        ->and($checksums->checksum($left))->toBe($checksums->checksum($right))
        ->and(p4lRow($left, '2026-03-09')->calculation_memory['event_types'])->toBe(['interest_payment', 'amortization'])
        ->and(p4lRow($right, '2026-03-09')->calculation_memory['event_types'])->toBe(['interest_payment', 'amortization']);
});

it('enforces event identity, duration and lifecycle in the domain, not only in the form', function () {
    $emission = p4lEmission();
    $coupon = p4lInterest($emission, '2026-03-31');

    // Juros: um por data, recusado pelo domínio antes de chegar ao banco.
    expect(fn () => p4lInterest($emission, '2026-03-31'))->toThrow(ValidationException::class)
        ->and(fn () => p4lInterest($emission, '2026-03-31', ['sequence' => 2]))->toThrow(ValidationException::class)
        // Duas amortizações na mesma data são legítimas com sequências distintas...
        ->and(p4lAmortization($emission, '2026-03-31', '0.1000000000000000')->exists)->toBeTrue()
        ->and(p4lAmortization($emission, '2026-03-31', '0.0500000000000000', ['sequence' => 2])->exists)->toBeTrue()
        // ...e a mesma identidade (tipo, data, sequência) entre ativos, nunca: a unique do banco.
        ->and(fn () => p4lAmortization($emission, '2026-03-31', '0.0500000000000000', ['sequence' => 2]))->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => p4lInterest($emission, '2026-04-30', ['effective_until' => '2026-05-30']))->toThrow(ValidationException::class)
        ->and(fn () => EmissionPuEvent::query()->create(['emission_id' => $emission->id, 'event_type' => PuEventType::GracePeriod->value, 'effective_date' => '2026-05-01', 'effective_until' => '2026-04-01', 'sequence' => 1]))
        ->toThrow(ValidationException::class)
        ->and(fn () => EmissionPuEvent::query()->create(['emission_id' => $emission->id, 'event_type' => PuEventType::SpreadAmendment->value, 'effective_date' => '2026-03-31', 'sequence' => 1]))
        ->toThrow(ValidationException::class)
        ->and(fn () => EmissionPuEvent::query()->create(['emission_id' => $emission->id, 'event_type' => 'stop_interest', 'effective_date' => '2026-03-31', 'sequence' => 1]))
        ->toThrow(ValidationException::class);

    // Cancelado é evidência: não volta e não muda; a identidade fica livre para o substituto.
    $coupon->forceFill(['status' => PuEventStatus::Cancelled, 'cancelled_at' => now(), 'cancellation_reason' => 'Substituído.'])->save();

    expect(fn () => $coupon->fresh()->update(['description' => 'editado']))->toThrow(ValidationException::class)
        ->and(p4lInterest($emission, '2026-03-31')->exists)->toBeTrue()
        ->and(fn () => EmissionPuEvent::query()->create(['emission_id' => $emission->id, 'event_type' => PuEventType::InterestPayment->value, 'status' => PuEventStatus::Cancelled->value, 'effective_date' => '2026-04-30', 'sequence' => 1]))
        ->toThrow(ValidationException::class);
});

it('forbids deleting an event that entered a version snapshot and requires an authorized, justified cancellation', function () {
    $emission = p4lEmission();
    $draft = p4lInterest($emission, '2026-04-30');
    $draft->delete();
    $governed = p4lInterest($emission, '2026-03-09');
    p4lPublish('2026-02-27', '2026-03-13');
    p4lGenerate($emission);
    $governed->refresh();
    $stranger = User::factory()->create();

    expect(EmissionPuEvent::query()->whereKey($draft->id)->exists())->toBeFalse()
        ->and($governed->governed_at)->not->toBeNull()
        ->and(fn () => $governed->delete())->toThrow(ValidationException::class)
        ->and(EmissionPuEvent::query()->whereKey($governed->id)->exists())->toBeTrue()
        ->and(fn () => app(PuContractualEventService::class)->cancel($governed, $stranger, 'Sem permissão.'))->toThrow(AuthorizationException::class)
        ->and($stranger->can('delete', $governed))->toBeFalse();
});

it('blocks the curve explicitly for contractual events the engine does not calculate', function (PuEventType $type) {
    $emission = p4lEmission();
    EmissionPuEvent::query()->create([
        'emission_id' => $emission->id,
        'event_type' => $type->value,
        'effective_date' => '2026-06-01',
        'effective_until' => $type->supportsDuration() ? '2026-06-30' : null,
        'financial_effect' => ['description' => 'efeito a modelar na Fase 5'],
        'sequence' => 1,
    ]);
    p4lPublish('2026-02-27', '2026-03-13');
    $prerequisites = app(PuCurvePrerequisiteService::class)->handle($emission->fresh());

    expect($prerequisites->passes())->toBeFalse()
        ->and($prerequisites->blockingSummary())->toContain('ainda não calcula')
        ->and(fn () => app(GeneratePuDailyCurve::class)->handle($emission->fresh()))->toThrow(InvalidArgumentException::class, 'ainda não calcula')
        // Nem por fora dos pré-requisitos a engine o ignora.
        ->and(fn () => app(PuCurveGeneratorService::class)->handle($emission->fresh()))->toThrow(PuCurveInputsException::class)
        ->and(EmissionPuCurveVersion::query()->where('emission_id', $emission->id)->count())->toBe(0);
})->with(fn (): array => collect(PuEventType::cases())
    ->reject(fn (PuEventType $type): bool => $type->isScheduledPayment() || $type === PuEventType::SpreadAmendment)
    ->mapWithKeys(fn (PuEventType $type): array => [$type->value => [$type]])
    ->all());

it('keeps the event catalog explicit about effect, duration, ordering and engine support', function () {
    $supported = collect(PuEventType::cases())->filter(fn (PuEventType $type): bool => $type->supportedBy() !== [])->map->value->values()->all();
    $durations = collect(PuEventType::cases())->filter->supportsDuration()->map->value->values()->all();
    $priorities = collect(PuEventType::cases())->map->applicationPriority();

    expect($supported)->toBe(['interest_payment', 'amortization', 'spread_amendment'])
        ->and(PuEventType::SpreadAmendment->supportedBy())->toBe([PuCalculationMethod::CdiSpread])
        ->and($durations)->toBe(['waiver', 'grace_period', 'deferral', 'default'])
        ->and($priorities->unique()->count())->toBe(count(PuEventType::cases()))
        ->and(PuEventType::InterestPayment->applicationPriority())->toBeLessThan(PuEventType::Amortization->applicationPriority())
        ->and(PuEventType::Default->label())->toBe('Inadimplemento (mora)')
        ->and(PuEventType::ExtraordinaryInterest->effectClass())->not->toBe(PuEventType::Default->effectClass())
        ->and(PuEventType::EarlyMaturity->effectClass())->not->toBe(PuEventType::MaturityChange->effectClass());
});

it('rejects a spread change in the middle of a capitalization period instead of splitting it', function () {
    $emission = p4lEmission();
    p4lInterest($emission, '2026-03-31');
    EmissionPuEvent::query()->create([
        'emission_id' => $emission->id,
        'event_type' => PuEventType::SpreadAmendment->value,
        'effective_date' => '2026-03-10',
        'financial_effect' => ['spread_rate' => '8'],
        'sequence' => 1,
    ]);
    p4lPublish('2026-02-27', '2026-03-13');

    expect(app(PuCurvePrerequisiteService::class)->handle($emission->fresh())->blockingSummary())->toContain('meio de um período de capitalização')
        ->and(fn () => app(PuCurveGeneratorService::class)->handle($emission->fresh()))
        ->toThrow(PuCurveInputsException::class, 'não coincide com um pagamento');
});

it('keeps a cancelled event out of the calculation and of the schedule checks', function () {
    $emission = p4lEmission();
    $coupon = p4lInterest($emission, '2026-03-09');
    $coupon->forceFill(['status' => PuEventStatus::Cancelled, 'cancelled_at' => now(), 'cancellation_reason' => 'Lançado por engano.'])->save();
    p4lPublish('2026-02-27', '2026-03-13');

    $prerequisites = app(PuCurvePrerequisiteService::class)->handle($emission->fresh());
    $version = p4lGenerate($emission);

    expect(collect($prerequisites->warningMessages())->implode(' '))->toContain('Nenhum evento de juros ou amortizacao')
        ->and($version->curve_inputs['payload']['events'])->toBe([])
        ->and(p4lRow($version, '2026-03-09')->calculation_memory['event_types'])->toBe([])
        ->and(EmissionPuEvent::query()->whereKey($coupon->id)->value('governed_at'))->toBeNull();
});

it('rereads the contractual inputs inside the write and appends nothing when they changed during the calculation', function () {
    [$emission, $official] = p4lOfficial();
    p4lPublish('2026-03-16', '2026-03-18');

    // A extensão já comparou os insumos (iguais) e calcula a cauda; nesse meio
    // tempo um evento retroativo é gravado.
    app()->bind(PuCurveGeneratorService::class, fn ($app) => new class($app->make(PuCalculatorFactory::class), $emission) extends PuCurveGeneratorService
    {
        public function __construct($factory, private readonly Emission $target)
        {
            parent::__construct($factory);
        }

        public function handle(Emission $emission, ?string $indexRateCalendarCode = null, ?string $accrualCalendarCode = null, PuCalculationProfile $profile = PuCalculationProfile::Contractual): PuCurveGenerationResult
        {
            $result = parent::handle($emission, $indexRateCalendarCode, $accrualCalendarCode, $profile);
            p4lInterest($this->target, '2026-03-09');

            return $result;
        }
    });

    $result = app(PuCurveExtensionService::class)->extendOfficial($emission->fresh());

    expect($result->action)->toBe(PuCurveExtensionService::ACTION_DIVERGED_GOVERNED)
        ->and($result->firstDivergentDate)->toBe('2026-03-09')
        ->and(p4lLast($official))->toBe('2026-03-16')
        ->and($official->fresh()->extension_diverged_at)->not->toBeNull();
});

it('lets the PU events table cancel with a reason, and never delete, an event that entered a version', function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $editor = User::factory()->withTwoFactor()->create(['email' => fake()->unique()->safeEmail()]);
    $editor->assignRole('editor');
    $emission = p4lEmission();
    $governed = p4lInterest($emission, '2026-03-09');
    p4lPublish('2026-02-27', '2026-03-13');
    p4lGenerate($emission);
    $draft = p4lInterest($emission, '2026-04-30');
    $governed->refresh();
    $this->actingAs($editor);
    $table = fn () => Livewire::test(PuEventsRelationManager::class, ['ownerRecord' => $emission->fresh(), 'pageClass' => EditEmission::class]);

    $table()
        ->assertActionHidden(TestAction::make('delete')->table($governed))
        ->assertActionVisible(TestAction::make('delete')->table($draft))
        ->assertActionVisible(TestAction::make('cancelEvent')->table($governed))
        ->callTableBulkAction('delete', [$governed, $draft]);

    expect(EmissionPuEvent::query()->whereKey($governed->id)->exists())->toBeTrue()
        ->and(EmissionPuEvent::query()->whereKey($draft->id)->exists())->toBeFalse();

    $table()
        ->callAction(TestAction::make('cancelEvent')->table($governed), ['cancellation_reason' => ''])
        ->assertHasActionErrors(['cancellation_reason' => 'required']);
    $table()->callAction(TestAction::make('cancelEvent')->table($governed), ['cancellation_reason' => 'Cupom lançado em duplicidade.']);
    $cancelled = $governed->fresh();

    expect($cancelled->status)->toBe(PuEventStatus::Cancelled)
        ->and($cancelled->cancellation_reason)->toBe('Cupom lançado em duplicidade.')
        ->and($cancelled->cancelled_by)->toBe($editor->id);

    $table()
        ->assertActionHidden(TestAction::make('cancelEvent')->table($cancelled))
        ->assertActionHidden(TestAction::make('edit')->table($cancelled));
});

it('does not present a complete official curve as current for a contract extended beyond it', function () {
    $emission = p4lEmission();
    $emission->puParameter->update(['curve_end_date' => '2026-03-13']);
    p4lInterest($emission, '2026-03-13');
    $residual = p4lAmortization($emission, '2026-03-13', '0', ['amortization_type' => PuAmortizationType::Residual->value, 'amortization_value' => null]);
    p4lPublish('2026-02-27', '2026-03-13');
    $official = p4lHomologate($emission, p4lGenerate($emission));

    expect(p4lStatus($emission, '2026-03-16 10:00')->freshness)->toBe(PuOfficialCurveFreshness::Complete);

    // Prorrogação do vencimento, com os pagamentos de 13/03 mantidos: a mudança vale
    // depois do fim aprovado. A oficial completa deixa de responder pelo contrato.
    $emission->puParameter->update(['curve_end_date' => '2026-05-29']);
    $pending = p4lStatus($emission, '2026-03-16 10:00');

    expect(p4lAssess($official)->impact)->toBe(PuCurveChangeImpact::FutureVersionRequired)
        ->and($pending->freshness)->toBe(PuOfficialCurveFreshness::NewVersionRequired)
        ->and($pending->contractualChangeFrom?->toDateString())->toBe('2026-03-14')
        ->and($pending->isReliableAt(CarbonImmutable::parse('2026-03-13')))->toBeTrue();

    // O resgate também é movido para o vencimento novo: agora o trecho gravado mudou.
    $residual->update(['original_date' => '2026-05-29', 'effective_date' => '2026-05-29']);

    expect(p4lAssess($official)->impact)->toBe(PuCurveChangeImpact::HistoricalReprocessRequired)
        ->and(p4lStatus($emission, '2026-03-16 10:00')->freshness)->toBe(PuOfficialCurveFreshness::ReprocessingRequired);
});

it('applies a future integralization approved in the version and stops before one recorded after homologation', function () {
    $integralize = fn (Emission $emission, string $date, string $quantity) => $emission->integralizationHistories()->create([
        'date' => $date,
        'quantity' => $quantity,
        'unit_value' => '1000.00000000',
        'financial_value' => bcmul($quantity, '1000', 2),
        'investor_fund' => 'Fundo '.$date,
    ]);
    // Aprovada: a segunda tranche de 20/03 já estava no contrato quando a v1 foi gerada.
    [$emission, $official] = p4lOfficial(fn (Emission $emission) => $integralize($emission, '2026-03-20', '50.0000'));
    p4lPublish('2026-03-16', '2026-03-25');
    p4lExtend($emission);

    expect(p4lAssess($official)->impact)->toBe(PuCurveChangeImpact::NoCurveImpact)
        ->and((string) p4lRow($official, '2026-03-19')->quantity)->toBe('100.0000')
        ->and((string) p4lRow($official, '2026-03-20')->quantity)->toBe('150.0000')
        ->and(PuCurveInputSnapshot::fromStored($official->fresh()->curve_inputs)->provenance['quantity_timeline'][1])->toMatchArray([
            'date' => '2026-03-20',
            'quantity_before' => '100.0000',
            'quantity_change' => '50.0000',
            'quantity_after' => '150.0000',
        ]);

    // Não aprovada: a terceira tranche, de 02/04, entra no cadastro depois.
    $integralize($emission, '2026-04-02', '25.0000');
    p4lPublish('2026-03-26', '2026-04-06');
    p4lExtend($emission);
    $pending = p4lExtend($emission);

    expect(p4lAssess($official)->impact)->toBe(PuCurveChangeImpact::FutureVersionRequired)
        ->and($pending->action)->toBe(PuCurveExtensionService::ACTION_CONTRACTUAL_CHANGE_PENDING)
        ->and(p4lLast($official))->toBe('2026-04-01')
        ->and($official->dailyCurves()->where('quantity', '>', 150)->exists())->toBeFalse();
});

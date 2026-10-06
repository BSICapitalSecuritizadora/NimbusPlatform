<?php

use App\Actions\Emissions\GeneratePuDailyCurve;
use App\Actions\Emissions\HomologatePuCurve;
use App\Domain\PuCalculator\DTOs\PuCurveGenerationResult;
use App\Domain\PuCalculator\Enums\PuCalculationProfile;
use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use App\Domain\PuCalculator\Services\BusinessDayCalendarService;
use App\Domain\PuCalculator\Services\EmissionPuReader;
use App\Domain\PuCalculator\Services\PuCurveExtensionService;
use App\Domain\PuCalculator\Services\PuCurveGeneratorService;
use App\Domain\PuCalculator\Services\PuCurveVersionService;
use App\Domain\PuCalculator\Services\PuOperationalMonitorService;
use App\Jobs\GeneratePuDailyCurveJob;
use App\Models\BusinessCalendarDate;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuDailyCurve;
use App\Models\IndexRate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Fase 3 (P0-03) -- a extensão diária avança a curva OFICIAL.
 *
 * Antes, a rotina seguia a versão "vigente" (a utilizável mais recente): com v1
 * homologada e v2 apenas gerada, o CDI novo ia para v2 e a oficial congelava,
 * enquanto relatório, garantias e site continuavam lendo a v1 parada. Agora a
 * oficial avança primeiro, por conta própria, e a versão de trabalho é estendida
 * à parte, sem nunca virar oficial.
 */
uses(RefreshDatabase::class);

function p3eEmission(): Emission
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
        'legacy_projection_enabled' => false,
    ]);

    for ($date = CarbonImmutable::parse('2026-02-23'); $date->lte(CarbonImmutable::parse('2027-01-08')); $date = $date->addDay()) {
        BusinessCalendarDate::query()->create([
            'calendar_code' => 'B3',
            'calendar_date' => $date->toDateString(),
            'is_business_day' => ! $date->isWeekend(),
            'description' => null,
        ]);
    }

    app(BusinessDayCalendarService::class)->flushCache();

    return $emission->fresh();
}

function p3ePublish(string $from, string $to, string $value = '14.90000000'): void
{
    for ($date = CarbonImmutable::parse($from); $date->lte(CarbonImmutable::parse($to)); $date = $date->addDay()) {
        if (! $date->isWeekend()) {
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

function p3eGenerate(Emission $emission): EmissionPuCurveVersion
{
    $result = app(GeneratePuDailyCurve::class)->handle($emission->fresh());

    return EmissionPuCurveVersion::query()
        ->where('emission_id', $emission->id)
        ->where('calculation_version', $result->calculationVersion)
        ->sole();
}

function p3eHomologate(Emission $emission, EmissionPuCurveVersion $version): EmissionPuCurveVersion
{
    return app(HomologatePuCurve::class)->handle(
        $emission->fresh(),
        $version->calculation_version,
        User::factory()->create()->id,
        'Conferida contra o sistema antigo.',
    );
}

function p3eLastDate(EmissionPuCurveVersion $version): string
{
    return CarbonImmutable::parse((string) $version->dailyCurves()->max('curve_date'))->toDateString();
}

/**
 * @return array<int, array{date: string, residual: string, updated_at: string}>
 */
function p3eRowsSnapshot(EmissionPuCurveVersion $version): array
{
    return $version->dailyCurves()
        ->orderBy('id')
        ->get()
        ->mapWithKeys(fn (EmissionPuDailyCurve $row): array => [$row->id => [
            'date' => CarbonImmutable::parse((string) $row->curve_date)->toDateString(),
            'residual' => (string) $row->residual_unit_value,
            'updated_at' => (string) $row->getRawOriginal('updated_at'),
        ]])
        ->all();
}

/**
 * @return array{emission: Emission, official: EmissionPuCurveVersion}
 */
function p3eHomologatedScenario(): array
{
    $emission = p3eEmission();
    p3ePublish('2026-02-27', '2026-03-13');
    $official = p3eHomologate($emission, p3eGenerate($emission));

    return ['emission' => $emission->fresh(), 'official' => $official->fresh()];
}

it('advances the homologated curve with new realized CDI while a newer generated candidate exists', function () {
    ['emission' => $emission, 'official' => $official] = p3eHomologatedScenario();
    $candidate = p3eGenerate($emission);
    $officialBefore = p3eRowsSnapshot($official);
    $officialLastBefore = p3eLastDate($official);

    p3ePublish('2026-03-16', '2026-03-20');
    $this->artisan('pu:curves:generate-realized')->assertSuccessful();

    $official->refresh();
    $candidate->refresh();
    $reading = app(EmissionPuReader::class)->readingOn($emission->fresh(), CarbonImmutable::parse('2026-03-23'));

    expect($officialLastBefore)->toBe('2026-03-16')
        ->and($official->status)->toBe(PuCurveStatus::Homologated)
        ->and($emission->fresh()->officialPuCurveVersion()?->id)->toBe($official->id)
        ->and(p3eLastDate($official))->toBe('2026-03-23')
        ->and($official->extended_rows_count)->toBe(7)
        ->and($candidate->status)->toBe(PuCurveStatus::Generated)
        ->and($candidate->homologated_at)->toBeNull()
        ->and($reading?->fromOfficialCurve())->toBeTrue()
        ->and($reading?->calculationVersion)->toBe($official->calculation_version)
        ->and($reading?->date->toDateString())->toBe('2026-03-23')
        ->and($reading?->isCarriedForward())->toBeFalse();

    // O trecho homologado não foi reescrito: mesmas linhas, mesmos valores, nenhum toque.
    $officialAfter = p3eRowsSnapshot($official);

    foreach ($officialBefore as $id => $row) {
        expect($officialAfter[$id] ?? null)->toBe($row);
    }

    expect(count($officialAfter) - count($officialBefore))->toBe(7);
});

it('targets the official version explicitly, never the newest workable one', function () {
    ['emission' => $emission, 'official' => $official] = p3eHomologatedScenario();
    $candidate = p3eGenerate($emission);
    p3ePublish('2026-03-16', '2026-03-20');

    $officialResult = app(PuCurveExtensionService::class)->extendOfficial($emission->fresh());
    $workingResult = app(PuCurveExtensionService::class)->extendWorking($emission->fresh());

    expect($officialResult->purpose)->toBe(PuCurveExtensionService::PURPOSE_OFFICIAL)
        ->and($officialResult->action)->toBe(PuCurveExtensionService::ACTION_EXTENDED)
        ->and($officialResult->versionId)->toBe($official->id)
        ->and($workingResult->purpose)->toBe(PuCurveExtensionService::PURPOSE_WORKING)
        ->and($workingResult->versionId)->toBe($candidate->id)
        ->and($workingResult->requiresFullGeneration())->toBeFalse()
        ->and($candidate->fresh()->status)->toBe(PuCurveStatus::Generated);
});

it('keeps extending the official curve when the newest attempt ended in error', function () {
    ['emission' => $emission, 'official' => $official] = p3eHomologatedScenario();
    $versions = app(PuCurveVersionService::class);
    $failed = $versions->markError($versions->startGeneration($emission, null), 'Falha simulada na geração.');
    p3ePublish('2026-03-16', '2026-03-20');
    Queue::fake([GeneratePuDailyCurveJob::class]);

    $this->artisan('pu:curves:generate-realized')->assertSuccessful();

    expect($failed->fresh()->status)->toBe(PuCurveStatus::Error)
        ->and($emission->fresh()->latestPuCurveVersion()->first()?->id)->toBe($failed->id)
        ->and($official->fresh()->status)->toBe(PuCurveStatus::Homologated)
        ->and(p3eLastDate($official->fresh()))->toBe('2026-03-23');

    Queue::assertNotPushed(GeneratePuDailyCurveJob::class);
});

it('extends an unhomologated curve as working version without ever making it official', function () {
    $emission = p3eEmission();
    p3ePublish('2026-02-27', '2026-03-13');
    $generated = p3eGenerate($emission);
    p3ePublish('2026-03-16', '2026-03-20');

    $this->artisan('pu:curves:generate-realized')->assertSuccessful();

    $result = app(EmissionPuReader::class)->officialReadingAt($emission->fresh(), CarbonImmutable::parse('2026-03-23'));

    expect($generated->fresh()->status)->toBe(PuCurveStatus::Generated)
        ->and(p3eLastDate($generated->fresh()))->toBe('2026-03-23')
        ->and($emission->fresh()->officialPuCurveVersion())->toBeNull()
        ->and(app(PuCurveExtensionService::class)->extendOfficial($emission->fresh())->action)
        ->toBe(PuCurveExtensionService::ACTION_NO_OFFICIAL_VERSION)
        ->and($result['reading'])->toBeNull()
        ->and($result['status']->freshness->value)->toBe('no_official_curve')
        ->and(app(EmissionPuReader::class)->readingOn($emission->fresh(), CarbonImmutable::parse('2026-03-23')))->toBeNull();
});

it('records a failed official extension on the version and clears it once the extension runs again', function () {
    ['emission' => $emission, 'official' => $official] = p3eHomologatedScenario();
    p3ePublish('2026-03-16', '2026-03-20');
    $integralizations = $emission->integralizationHistories()->get()->map->getAttributes()->all();
    $emission->integralizationHistories()->delete();

    $blocked = app(PuCurveExtensionService::class)->extendOfficial($emission->fresh());
    $official->refresh();

    expect($blocked->action)->toBe(PuCurveExtensionService::ACTION_PREREQUISITES_BLOCKED)
        ->and($official->extension_failed_at)->not->toBeNull()
        ->and($official->extension_failure['action'])->toBe(PuCurveExtensionService::ACTION_PREREQUISITES_BLOCKED)
        ->and($official->extension_failure['purpose'])->toBe(PuCurveExtensionService::PURPOSE_OFFICIAL)
        ->and(p3eLastDate($official))->toBe('2026-03-16')
        ->and(app(PuOperationalMonitorService::class)->failedOfficialExtensionCount())->toBe(1);

    foreach ($integralizations as $attributes) {
        $emission->integralizationHistories()->create(collect($attributes)->except(['id', 'emission_id'])->all());
    }

    $extended = app(PuCurveExtensionService::class)->extendOfficial($emission->fresh());
    $official->refresh();

    expect($extended->action)->toBe(PuCurveExtensionService::ACTION_EXTENDED)
        ->and($official->extension_failed_at)->toBeNull()
        ->and($official->extension_failure)->toBeNull()
        ->and(app(PuOperationalMonitorService::class)->failedOfficialExtensionCount())->toBe(0);
});

it('does nothing to an official curve that already incorporated every published CDI', function () {
    ['emission' => $emission, 'official' => $official] = p3eHomologatedScenario();
    $before = p3eRowsSnapshot($official);

    $result = app(PuCurveExtensionService::class)->extendOfficial($emission->fresh());

    expect($result->action)->toBe(PuCurveExtensionService::ACTION_UP_TO_DATE)
        ->and(p3eRowsSnapshot($official->fresh()))->toBe($before);
});

it('appends nothing when an index observation used by the new days changes between calculation and write', function () {
    ['emission' => $emission, 'official' => $official] = p3eHomologatedScenario();
    p3ePublish('2026-03-16', '2026-03-20');
    $before = p3eRowsSnapshot($official);
    $realGenerator = app(PuCurveGeneratorService::class);

    // Uma correção concorrente comita depois do recálculo e antes da gravação:
    // a cauda calculada com a taxa antiga não pode entrar na curva oficial.
    app()->instance(PuCurveGeneratorService::class, new class($realGenerator) extends PuCurveGeneratorService
    {
        public function __construct(private readonly PuCurveGeneratorService $inner) {}

        public function handle(
            Emission $emission,
            ?string $indexRateCalendarCode = null,
            ?string $accrualCalendarCode = null,
            PuCalculationProfile $profile = PuCalculationProfile::Contractual,
        ): PuCurveGenerationResult {
            $result = $this->inner->handle($emission, $indexRateCalendarCode, $accrualCalendarCode, $profile);
            DB::table('index_rates')->whereDate('rate_date', '2026-03-17')->update(['rate_value' => '15.40000000']);

            return $result;
        }
    });

    $result = app(PuCurveExtensionService::class)->extendOfficial($emission->fresh());

    expect($result->action)->toBe(PuCurveExtensionService::ACTION_NOT_EXTENDABLE)
        ->and($result->reason)->toContain('O índice de 2026-03-17 mudou durante a extensão')
        ->and(p3eRowsSnapshot($official->fresh()))->toBe($before);
});

<?php

use App\Actions\Emissions\GeneratePuDailyCurve;
use App\Actions\Emissions\HomologatePuCurve;
use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use App\Domain\PuCalculator\Enums\PuOfficialCurveFreshness;
use App\Domain\PuCalculator\Services\BusinessDayCalendarService;
use App\Domain\PuCalculator\Services\EmissionPuReader;
use App\Domain\PuCalculator\Services\IndexRateCorrectionService;
use App\Domain\PuCalculator\Services\PuCurveExtensionService;
use App\Domain\PuCalculator\Services\PuOfficialCurveFreshnessService;
use App\Models\BusinessCalendarDate;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuDailyCurve;
use App\Models\IndexRate;
use App\Models\IndexRateCorrection;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

/**
 * Fase 3 -- corrigir um CDI histórico é governança, não sincronização.
 *
 * A curva homologada que usou a taxa antiga não é recalculada nem reescrita: a
 * correção guarda o valor anterior, quem corrigiu e por quê, lista as curvas
 * que dependiam da observação e deixa a oficial marcada para reprocessamento. O
 * PU homologado continua rastreável à taxa que usou; o novo valor só alcança o
 * PU oficial por uma nova versão revisada e homologada.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00:00', 'America/Sao_Paulo'));
});

function p3hOfficialEmission(): array
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

        if (! $date->isWeekend() && $date->lte(CarbonImmutable::parse('2026-03-13'))) {
            IndexRate::factory()->create([
                'indexer' => PuIndexer::Cdi->value,
                'rate_date' => $date->toDateString(),
                'rate_value' => '14.90000000',
                'source' => 'bcb_sgs',
                'source_reference' => 'bcb_sgs:4389',
            ]);
        }
    }

    app(BusinessDayCalendarService::class)->flushCache();

    $result = app(GeneratePuDailyCurve::class)->handle($emission->fresh());
    $official = app(HomologatePuCurve::class)->handle(
        $emission->fresh(),
        $result->calculationVersion,
        User::factory()->create()->id,
        'Conferida contra o sistema antigo.',
    );

    return [$emission->fresh(), $official->fresh()];
}

/**
 * @return array<int, string>
 */
function p3hResiduals(EmissionPuCurveVersion $version): array
{
    return $version->dailyCurves()->orderBy('id')->pluck('residual_unit_value', 'id')
        ->map(fn ($value): string => (string) $value)
        ->all();
}

it('audits a historical CDI correction and keeps the homologated curve that used the old rate untouched', function () {
    [$emission, $official] = p3hOfficialEmission();
    $actor = User::factory()->create();
    $before = p3hResiduals($official);

    $this->artisan('pu:index-rates:correct', [
        '--date' => '2026-03-05',
        '--value' => '15.40',
        '--reason' => 'BCB republicou a Taxa DI de 05/03.',
        '--user' => $actor->email,
    ])->assertSuccessful();

    $correction = IndexRateCorrection::query()->sole();
    $official->refresh();
    $dependentRow = EmissionPuDailyCurve::query()->where('curve_version_id', $official->id)->whereDate('curve_date', '2026-03-06')->sole();
    $activity = Activity::query()->where('description', 'pu_index_rate_corrected')->sole();

    expect((string) $correction->previous_rate_value)->toBe('14.90000000')
        ->and((string) $correction->new_rate_value)->toBe('15.40000000')
        ->and($correction->previous_source)->toBe('bcb_sgs')
        ->and($correction->corrected_by)->toBe($actor->id)
        ->and($correction->reason)->toBe('BCB republicou a Taxa DI de 05/03.')
        ->and($correction->origin)->toBe(IndexRateCorrection::ORIGIN_MANUAL_CORRECTION)
        ->and($correction->affected_curve_versions)->toBe([[
            'id' => $official->id,
            'emission_id' => $emission->id,
            'calculation_version' => $official->calculation_version,
            'status' => PuCurveStatus::Homologated->value,
            'first_dependent_date' => '2026-03-06',
        ]])
        ->and((string) IndexRate::query()->whereDate('rate_date', '2026-03-05')->value('rate_value'))->toBe('15.40000000')
        // O homologado não mudou e continua dizendo qual taxa usou.
        ->and(p3hResiduals($official))->toBe($before)
        ->and((string) $dependentRow->index_rate_value)->toBe('14.90000000')
        ->and($official->status)->toBe(PuCurveStatus::Homologated)
        ->and($official->extension_diverged_at)->not->toBeNull()
        ->and($official->extension_divergence['cause'])->toBe('index_rate_corrected')
        ->and($official->extension_divergence['index_rate_correction_id'])->toBe($correction->id)
        ->and($official->extension_divergence['first_divergent_date'])->toBe('2026-03-06')
        ->and($activity->causer_id)->toBe($actor->id)
        ->and($activity->properties['previous_rate_value'])->toBe('14.90000000')
        ->and($activity->properties['new_rate_value'])->toBe('15.40000000');
});

it('suspends the official extension after the correction and requires a new governed version', function () {
    [$emission, $official] = p3hOfficialEmission();
    $before = p3hResiduals($official);
    app(IndexRateCorrectionService::class)->correct(
        PuIndexer::Cdi,
        '2026-03-05',
        '15.40',
        'BCB republicou a Taxa DI de 05/03.',
        User::factory()->create()->id,
    );
    IndexRate::factory()->create([
        'indexer' => PuIndexer::Cdi->value,
        'rate_date' => '2026-03-16',
        'rate_value' => '14.90000000',
        'source' => 'bcb_sgs',
        'source_reference' => 'bcb_sgs:4389',
    ]);

    $status = app(PuOfficialCurveFreshnessService::class)->status($emission->fresh());
    $extension = app(PuCurveExtensionService::class)->extendOfficial($emission->fresh());
    $reader = app(EmissionPuReader::class);
    // A curva homologada não muda, mas o que ela gravou a partir de 06/03 (primeira
    // data que usou o CDI corrigido) deixou de ser reproduzível: nenhum consumidor
    // oficial o lê. O trecho anterior continua valendo.
    $reading = $reader->readingOn($emission->fresh(), CarbonImmutable::parse('2026-03-10'));
    $beforeDivergence = $reader->readingOn($emission->fresh(), CarbonImmutable::parse('2026-03-05'));

    expect($status->freshness)->toBe(PuOfficialCurveFreshness::ReprocessingRequired)
        ->and($status->reason)->toContain('foi corrigido de 14.90000000 para 15.40000000')
        ->and($status->reprocessingFrom?->toDateString())->toBe('2026-03-06')
        ->and($extension->action)->toBe(PuCurveExtensionService::ACTION_DIVERGED_GOVERNED)
        ->and(p3hResiduals($official->fresh()))->toBe($before)
        ->and($reading)->toBeNull()
        ->and($beforeDivergence?->calculationVersion)->toBe($official->calculation_version)
        ->and($beforeDivergence?->unitValue)->toBe($before[EmissionPuDailyCurve::query()->where('curve_version_id', $official->id)->whereDate('curve_date', '2026-03-05')->value('id')]);

    // A nova versão governada usa a taxa corrigida e, homologada, passa a ser a oficial.
    $result = app(GeneratePuDailyCurve::class)->handle($emission->fresh());
    $replacement = app(HomologatePuCurve::class)->handle($emission->fresh(), $result->calculationVersion, User::factory()->create()->id, 'Reprocessada após a correção do CDI de 05/03.');

    expect($emission->fresh()->officialPuCurveVersion()?->id)->toBe($replacement->id)
        ->and((string) EmissionPuDailyCurve::query()->where('curve_version_id', $replacement->id)->whereDate('curve_date', '2026-03-06')->value('index_rate_value'))->toBe('15.40000000')
        ->and(app(PuOfficialCurveFreshnessService::class)->status($emission->fresh())->freshness)->not->toBe(PuOfficialCurveFreshness::ReprocessingRequired)
        ->and(p3hResiduals($official->fresh()))->toBe($before);
});

dataset('p3h_refused_corrections', [
    'sem motivo' => [['--date' => '2026-03-05', '--value' => '15.40', '--reason' => ' ', '--user' => 'USER'], '--reason'],
    'sem usuario' => [['--date' => '2026-03-05', '--value' => '15.40', '--reason' => 'x'], '--user'],
    'data sem observacao' => [['--date' => '2026-03-07', '--value' => '15.40', '--reason' => 'x', '--user' => 'USER'], 'Não há observação'],
    'mesmo valor e mesma origem' => [['--date' => '2026-03-05', '--value' => '14.90', '--reason' => 'x', '--user' => 'USER'], 'não há o que corrigir'],
    'fora do dominio financeiro' => [['--date' => '2026-03-05', '--value' => '-100', '--reason' => 'x', '--user' => 'USER'], '-100'],
]);

it('refuses a correction without explicit governance or without anything to correct', function (array $options, string $message) {
    [$emission, $official] = p3hOfficialEmission();
    $user = User::factory()->create();
    $options = array_map(fn ($value) => $value === 'USER' ? (string) $user->id : $value, $options);

    $this->artisan('pu:index-rates:correct', $options)
        ->expectsOutputToContain($message)
        ->assertFailed();

    expect(IndexRateCorrection::query()->count())->toBe(0)
        ->and((string) IndexRate::query()->whereDate('rate_date', '2026-03-05')->value('rate_value'))->toBe('14.90000000')
        ->and($official->fresh()->extension_diverged_at)->toBeNull();
})->with('p3h_refused_corrections');

it('keeps the correction ledger append-only', function () {
    p3hOfficialEmission();
    $correction = app(IndexRateCorrectionService::class)->correct(
        PuIndexer::Cdi,
        '2026-03-05',
        '15.40',
        'BCB republicou a Taxa DI de 05/03.',
        User::factory()->create()->id,
    );

    expect(fn () => $correction->forceFill(['reason' => 'outro'])->save())->toThrow(LogicException::class)
        ->and(fn () => $correction->delete())->toThrow(LogicException::class);
});

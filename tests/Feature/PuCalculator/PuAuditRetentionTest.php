<?php

use App\Domain\PuCalculator\Enums\PuEventType;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuObligationType;
use App\Domain\PuCalculator\Services\IndexRateCorrectionService;
use App\Domain\PuCalculator\Services\PuAuditLogService;
use App\Models\EmissionPuSettlement;
use App\Models\IndexRateCorrection;
use App\Models\IntegralizationHistory;
use App\Models\Payment;
use App\Models\PuHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Pu\PuObligationFixture as Fx;

/**
 * Fase 6 (P1-10) -- a trilha `pu-calculation` mistura evidência financeira e
 * diagnóstico. Antes, tudo nela caía no prazo descartável (365 dias): homologação,
 * correção de CDI, liquidação e decisão de conflito sumiriam junto com a
 * sincronização diária. Agora a evidência fica com a retenção protegida (a mesma
 * política -- ainda pendente de aprovação -- das demais trilhas reguladas), o
 * diagnóstico continua descartável, e nada de outro módulo muda de prazo.
 */
uses(RefreshDatabase::class);

function p6aLog(?string $logName, ?string $event, int $daysAgo, ?string $subjectType = null): Activity
{
    $at = now()->subDays($daysAgo);

    return Activity::query()->create([
        'log_name' => $logName,
        'description' => $event !== null ? 'pu_'.$event : 'sem evento',
        'event' => $event,
        'subject_type' => $subjectType,
        'subject_id' => $subjectType !== null ? 1 : null,
        'properties' => [],
        'created_at' => $at,
        'updated_at' => $at,
    ]);
}

function p6aSurvivors(): array
{
    return Activity::query()->orderBy('id')->get()->map(fn (Activity $activity): string => sprintf('%s|%s|%s', $activity->log_name ?? '∅', $activity->event ?? '∅', class_basename((string) $activity->subject_type)))->all();
}

it('keeps critical PU evidence past the disposable window and still prunes PU diagnostics', function () {
    $disposable = (int) config('audit.retention_disposable_days');
    $protected = (int) config('audit.retention_workflow_days');

    foreach (['homologated', 'invalidated', 'index_rate_corrected', 'settlement_recorded', 'settlement_corrected', 'settlement_reversed', 'settlement_conflict_resolved', 'contractual_input_changed', 'curve_extension_diverged', 'obligation_refresh_retried', 'operational_incident_acknowledged'] as $event) {
        p6aLog('pu-calculation', $event, $disposable + 30);
    }

    foreach (['index_synced', 'exported', 'curve_extension_failed', 'obligations_refreshed', 'obligation_refresh_failed', 'obligation_refresh_recovered'] as $event) {
        p6aLog('pu-calculation', $event, $disposable + 30);
    }

    p6aLog('pu-calculation', 'homologated', $protected + 30);
    p6aLog('pu-calculation', null, $disposable + 30);

    $this->artisan('audit:clean-filtered')->assertSuccessful();
    $survivors = p6aSurvivors();

    expect($survivors)->toHaveCount(11)
        ->and($survivors)->toContain('pu-calculation|homologated|')
        ->and($survivors)->toContain('pu-calculation|settlement_conflict_resolved|')
        ->and($survivors)->not->toContain('pu-calculation|index_synced|')
        ->and($survivors)->not->toContain('pu-calculation|∅|')
        // Passado o prazo protegido, a política protegida vale (não é eterna).
        ->and(Activity::query()->where('event', 'homologated')->count())->toBe(1);
});

it('protects the PU baseline evidence, the business calendar trail and the financial PU inputs logged under default', function () {
    $disposable = (int) config('audit.retention_disposable_days');

    p6aLog('pu-baseline-evidence', 'reviewed', $disposable + 30);
    p6aLog('business_calendars', 'sanitized', $disposable + 30);
    p6aLog('default', 'updated', $disposable + 30, Payment::class);
    p6aLog('default', 'updated', $disposable + 30, PuHistory::class);
    p6aLog('default', 'updated', $disposable + 30, IntegralizationHistory::class);
    // Nada de outro módulo muda de prazo: o `default` genérico continua descartável.
    p6aLog('default', 'updated', $disposable + 30, User::class);
    p6aLog(null, null, $disposable + 30);
    p6aLog('default', 'updated', $disposable - 30, User::class);

    $this->artisan('audit:clean-filtered')->assertSuccessful();

    expect(p6aSurvivors())->toBe([
        'pu-baseline-evidence|reviewed|',
        'business_calendars|sanitized|',
        'default|updated|Payment',
        'default|updated|PuHistory',
        'default|updated|IntegralizationHistory',
        'default|updated|User',
    ]);
});

it('reports the protected and disposable counts in a dry run without deleting anything', function () {
    $disposable = (int) config('audit.retention_disposable_days');
    p6aLog('pu-calculation', 'homologated', $disposable + 30);
    p6aLog('pu-calculation', 'index_synced', $disposable + 30);

    $this->artisan('audit:clean-filtered', ['--dry-run' => true])
        ->expectsOutputToContain('disposable=1')
        ->assertSuccessful();

    expect(Activity::query()->count())->toBe(2);
});

it('refuses to clean when the PU evidence classification is missing from the config', function () {
    p6aLog('pu-calculation', 'homologated', (int) config('audit.retention_disposable_days') + 30);
    config(['audit.protected_events' => []]);

    $this->artisan('audit:clean-filtered')->assertFailed();

    expect(Activity::query()->count())->toBe(1);
});

it('classifies every event the PU audit trail writes as protected evidence or disposable diagnostics', function () {
    $source = (string) file_get_contents(app_path('Domain/PuCalculator/Services/PuAuditLogService.php'));
    preg_match_all("/->event\\('([a-z_]+)'\\)/", $source, $literal);
    preg_match_all("/'(pu_candidate_curve_(?:approved|rejected)|pu_curve_promotion_(?:approved|rejected)|pu_candidate_external_validation_(?:validated|rejected))'/", $source, $dynamic);
    preg_match_all('/\$this->(?:settlementActivity|obligationRefreshActivity)\([^;]*?\'([a-z_]+)\'/', $source, $helpers);
    $written = array_values(array_unique([...$literal[1], ...$dynamic[1], ...$helpers[1], 'generated', 'reprocessed']));
    $protected = config('audit.protected_events.pu-calculation');
    $disposable = config('audit.disposable_events.pu-calculation');
    $unclassified = array_values(array_diff($written, $protected, $disposable));
    sort($unclassified);

    expect($unclassified)->toBe([])
        ->and(array_intersect($protected, $disposable))->toBe([])
        ->and($written)->toContain('settlement_recorded')
        ->and($written)->toContain('obligation_refresh_retried');
});

it('keeps the durable ledgers that reproduce the financial facts out of reach of the log cleanup', function () {
    $emission = Fx::emission([[PuEventType::InterestPayment, '2026-03-09']]);
    Fx::official($emission);
    $obligation = Fx::obligation($emission, PuObligationType::ScheduledPayment, '2026-03-09');
    Fx::settle($obligation, Fx::expectedTotal($obligation), reference: 'B3-RET');
    app(IndexRateCorrectionService::class)->correct(PuIndexer::Cdi, '2026-03-04', '15.10000000', 'Revisão do BCB.', User::factory()->create()->id);
    DB::table('activity_log')->update(['created_at' => now()->subDays((int) config('audit.retention_workflow_days') + 30)]);

    $this->artisan('audit:clean-filtered')->assertSuccessful();

    expect(Activity::query()->where('log_name', PuAuditLogService::LOG_NAME)->count())->toBe(0)
        ->and(EmissionPuSettlement::query()->count())->toBe(1)
        ->and(IndexRateCorrection::query()->count())->toBe(1)
        ->and($emission->fresh()->officialPuCurveVersion()?->homologated_by)->not->toBeNull();
});

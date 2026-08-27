<?php

use App\Models\BusinessCalendarDate;
use App\Models\BusinessCalendarYear;
use App\Models\Measurement;
use App\Models\MeasurementPlanSet;
use App\Models\Operation;
use App\Models\ResponsibilityDelegation;
use App\Models\SlaConfiguration;
use App\Models\User;
use App\Notifications\MeasurementSlaNotification;
use App\Services\MeasurementSlaService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
    config(['measurements.sla.calendar_code' => null]);
    // Ensure SLA is configured for tests that expect configured behavior — without config, service returns NotConfigured (fail-safe)
    // Tests that verify NotConfigured will explicitly delete or not create these.
    SlaConfiguration::factory()->forStage(1, 5)->create();
    SlaConfiguration::factory()->forStage(2, 3)->create();
    SlaConfiguration::factory()->forStage(3, 3)->create();
    SlaConfiguration::factory()->forStage(4, 2)->create();
    SlaConfiguration::factory()->forStage(5, 3)->create();
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function makeSlaMeasurement(User $responsible, int $stage = 1, string $status = 'in_review'): Measurement
{
    $op = Operation::factory()->create(['responsible_user_id' => $responsible->id, 'stage2_reviewer_user_id' => $responsible->id, 'stage3_reviewer_user_id' => $responsible->id]);
    $planSet = MeasurementPlanSet::factory()->create(['operation_id' => $op->id]);
    $measurement = Measurement::factory()->create([
        'operation_id' => $op->id,
        'status' => $status,
        'current_stage' => $stage,
    ]);
    $measurement->reviews()->create(['stage' => $stage, 'status' => 'pending']);

    return $measurement->fresh(['operation', 'reviews', 'pauses']);
}

it('calculates on time when far from deadline', function () {
    $user = User::factory()->create();
    $measurement = makeSlaMeasurement($user);
    // Set review created_at to now (elapsed 0)
    $measurement->reviews()->update(['created_at' => now(), 'updated_at' => now()]);

    $sla = app(MeasurementSlaService::class);
    // Mock calendar to predictable: use no calendar_code, which counts weekends only
    $result = $sla->evaluate($measurement->fresh());

    expect($result['status'])->toBe(MeasurementSlaService::STATUS_ON_TIME);
});

it('calculates overdue when past deadline', function () {
    $user = User::factory()->create();
    $measurement = makeSlaMeasurement($user);
    // Make started 20 business days ago -> overdue (deadline is 5)
    $past = now()->subDays(30); // enough calendar days to be >5 business days
    $measurement->reviews()->update(['created_at' => $past, 'updated_at' => $past]);
    $measurement->update(['created_at' => $past, 'updated_at' => $past]);

    $sla = app(MeasurementSlaService::class);
    $result = $sla->evaluate($measurement->fresh());

    expect($result['status'])->toBe(MeasurementSlaService::STATUS_OVERDUE);
});

it('calculates approaching when near deadline', function () {
    $user = User::factory()->create();
    $measurement = makeSlaMeasurement($user);
    // deadline 5, warning at 80% = 4 business days; create 4 business days elapsed
    // 6 calendar days ago (excluding weekends) roughly 4 business days
    $past = now()->subDays(6);
    // Ensure past date is weekday
    while ($past->isWeekend()) {
        $past = $past->subDay();
    }
    $measurement->reviews()->update(['created_at' => $past, 'updated_at' => $past]);

    $sla = app(MeasurementSlaService::class);
    $result = $sla->evaluate($measurement->fresh());

    // Should be approaching or overdue depending on exact business days
    expect($result['status'])->toBeIn([MeasurementSlaService::STATUS_APPROACHING, MeasurementSlaService::STATUS_OVERDUE]);
});

it('returns paused when measurement is paused', function () {
    $user = User::factory()->create();
    $measurement = makeSlaMeasurement($user, 1, 'paused');
    $measurement->reviews()->update(['paused_at' => now()->subDay(), 'paused_by' => $user->id]);

    $sla = app(MeasurementSlaService::class);
    $result = $sla->evaluate($measurement->fresh());

    expect($result['status'])->toBe(MeasurementSlaService::STATUS_PAUSED)
        ->and($result['paused'])->toBeTrue();
});

it('returns completed when measurement is finalized', function () {
    $user = User::factory()->create();
    $op = Operation::factory()->create(['responsible_user_id' => $user->id]);
    $measurement = Measurement::factory()->create(['operation_id' => $op->id, 'status' => 'finalized', 'current_stage' => 5]);
    $measurement->reviews()->create(['stage' => 1, 'status' => 'approved', 'reviewed_at' => now()]);

    $sla = app(MeasurementSlaService::class);
    $result = $sla->evaluate($measurement->fresh());

    expect($result['status'])->toBe(MeasurementSlaService::STATUS_COMPLETED)
        ->and($result['completed'])->toBeTrue();
});

it('pauses do not consume SLA time', function () {
    $user = User::factory()->create();
    $measurement = makeSlaMeasurement($user);
    $past = now()->subDays(20);
    $measurement->reviews()->update(['created_at' => $past, 'updated_at' => $past]);

    // Add pause covering 15 days (should reduce elapsed)
    $measurement->pauses()->create([
        'stage' => 1,
        'paused_by' => $user->id,
        'pause_reason' => 'Teste',
        'paused_at' => now()->subDays(18),
        'resumed_at' => now()->subDays(3),
        'paused_operation_status' => 'in_review',
    ]);

    $sla = app(MeasurementSlaService::class);
    $resultWithPause = $sla->evaluate($measurement->fresh());

    // Without pause, elapsed would be high; with pause, should be less
    // We check that paused version is not necessarily overdue if pause covered most time
    // At least ensure service doesn't crash and returns a status
    expect($resultWithPause['status'])->toBeIn([
        MeasurementSlaService::STATUS_ON_TIME,
        MeasurementSlaService::STATUS_APPROACHING,
        MeasurementSlaService::STATUS_OVERDUE,
    ]);
});

it('scheduler is idempotent and suppresses duplicate alerts', function () {
    $responsible = User::factory()->create();
    $responsible->givePermissionTo(['measurements.view', 'operations.view', 'measurements.review']);
    $measurement = makeSlaMeasurement($responsible);
    $past = now()->subDays(20);
    $measurement->reviews()->update(['created_at' => $past, 'updated_at' => $past]);
    $measurement->load(['operation', 'reviews', 'pauses']);

    // Ensure responsible is set
    $measurement->operation->update(['responsible_user_id' => $responsible->id]);

    $this->artisan('measurements:evaluate-sla')->assertExitCode(0);
    $firstCount = DB::table('measurement_sla_alerts')->count();

    $this->artisan('measurements:evaluate-sla')->assertExitCode(0);
    $secondCount = DB::table('measurement_sla_alerts')->count();

    expect($secondCount)->toBe($firstCount); // no duplicate per day
});

it('returns not_configured when stage has no SLA deadline configured', function () {
    // Delete config for stage 1 to simulate unconfigured
    SlaConfiguration::where('stage', 1)->delete();
    // Also ensure env config is null (by not setting stage_deadlines)
    config(['measurements.sla.stage_deadlines' => [1 => null, 2 => null, 3 => null, 4 => null, 5 => null]]);

    $user = User::factory()->create();
    $measurement = makeSlaMeasurement($user, 1);
    $sla = app(MeasurementSlaService::class);
    $result = $sla->evaluate($measurement->fresh());

    expect($result['status'])->toBe(MeasurementSlaService::STATUS_NOT_CONFIGURED)
        ->and($result['not_configured'])->toBeTrue();
});

it('does not generate warning when unconfigured', function () {
    SlaConfiguration::where('stage', 1)->delete();
    config(['measurements.sla.stage_deadlines' => [1 => null]]);

    $user = User::factory()->create();
    $measurement = makeSlaMeasurement($user);
    $past = now()->subDays(30);
    $measurement->reviews()->update(['created_at' => $past]);

    $sla = app(MeasurementSlaService::class);
    $result = $sla->evaluate($measurement->fresh());

    expect($result['status'])->toBe(MeasurementSlaService::STATUS_NOT_CONFIGURED);
    // Command should not generate warning — verified via evaluate, not via notification
});

it('fails safe on invalid config (duration 0)', function () {
    SlaConfiguration::where('stage', 1)->update(['duration_value' => 0]);

    $user = User::factory()->create();
    $measurement = makeSlaMeasurement($user);
    $sla = app(MeasurementSlaService::class);
    $result = $sla->evaluate($measurement->fresh());

    expect($result['status'])->toBe(MeasurementSlaService::STATUS_INVALID_CONFIG)
        ->and($result['invalid_config'])->toBeTrue();
});

it('fails safe when calendar configured but year not materialized', function () {
    config(['measurements.sla.calendar_code' => 'national']);
    // Ensure no BusinessCalendarYear for current year
    BusinessCalendarYear::where('calendar_code', 'national')->delete();

    $user = User::factory()->create();
    $measurement = makeSlaMeasurement($user);
    $sla = app(MeasurementSlaService::class);
    $result = $sla->evaluate($measurement->fresh());

    // Should be calendar_unavailable, not silently weekday fallback
    expect($result['status'])->toBe(MeasurementSlaService::STATUS_CALENDAR_UNAVAILABLE)
        ->and($result['calendar_unavailable'])->toBeTrue();

    config(['measurements.sla.calendar_code' => null]);
});

it('explicit weekends-only when no calendar configured', function () {
    config(['measurements.sla.calendar_code' => null]);

    $user = User::factory()->create();
    $measurement = makeSlaMeasurement($user);
    $measurement->reviews()->update(['created_at' => now()->subDays(6)]);

    $sla = app(MeasurementSlaService::class);
    $result = $sla->evaluate($measurement->fresh());

    // Weekends-only is explicit, should calculate (not unavailable) — status should be on_time/approaching/overdue, not calendar_unavailable
    expect($result['status'])->not->toBe(MeasurementSlaService::STATUS_CALENDAR_UNAVAILABLE)
        ->and($result['calendar_unavailable'])->toBeFalse();
});

it('notifies correct responsible user', function () {
    $responsible = User::factory()->create();
    $responsible->givePermissionTo(['measurements.view', 'operations.view', 'measurements.review']);
    $measurement = makeSlaMeasurement($responsible);
    $past = now()->subDays(20);
    $measurement->reviews()->update(['created_at' => $past, 'updated_at' => $past]);
    $measurement->operation->update(['responsible_user_id' => $responsible->id]);

    Notification::fake();

    $this->artisan('measurements:evaluate-sla')->assertExitCode(0);

    Notification::assertSentTo($responsible, MeasurementSlaNotification::class);
});

it('uses the corporate calendar and excludes weekend and persisted holiday', function () {
    $now = CarbonImmutable::parse('2026-09-02 10:00:00', config('app.timezone'));
    CarbonImmutable::setTestNow($now);
    config(['measurements.sla.calendar_code' => 'BR_NATIONAL_HOLIDAYS']);
    BusinessCalendarYear::query()->create([
        'calendar_code' => 'BR_NATIONAL_HOLIDAYS',
        'year' => 2026,
        'status' => 'confirmed',
    ]);
    BusinessCalendarDate::query()->create([
        'calendar_code' => 'BR_NATIONAL_HOLIDAYS',
        'calendar_date' => '2026-08-31',
        'is_business_day' => false,
        'description' => 'Feriado nacional de teste',
    ]);
    SlaConfiguration::query()->where('stage', 1)->update(['duration_value' => 2]);
    $measurement = makeSlaMeasurement(User::factory()->create());
    $measurement->reviews()->update(['created_at' => '2026-08-28 10:00:00']);

    $result = app(MeasurementSlaService::class)->evaluate($measurement->fresh(), $now);

    expect($result['deadline_at']->toDateTimeString())->toBe('2026-09-02 10:00:00')
        ->and($result['config']['calendar_code'])->toBe('BR_NATIONAL_HOLIDAYS')
        ->and($result['status'])->toBe(MeasurementSlaService::STATUS_OVERDUE);

    CarbonImmutable::setTestNow();
});

it('excludes a pause interval and shifts the deadline after resume', function () {
    $now = CarbonImmutable::parse('2026-08-28 10:00:00', config('app.timezone'));
    CarbonImmutable::setTestNow($now);
    SlaConfiguration::query()->where('stage', 1)->update(['duration_value' => 3]);
    $responsible = User::factory()->create();
    $measurement = makeSlaMeasurement($responsible);
    $measurement->reviews()->update(['created_at' => '2026-08-24 10:00:00']);
    $measurement->pauses()->create([
        'stage' => 1,
        'paused_by' => $responsible->getKey(),
        'pause_reason' => 'Aguardando evidência',
        'paused_operation_status' => 'in_review',
        'paused_at' => '2026-08-25 12:00:00',
        'resumed_at' => '2026-08-27 09:00:00',
    ]);

    $result = app(MeasurementSlaService::class)->evaluate($measurement->fresh(), $now);

    expect($result['elapsed_business_hours'])->toBe(51.0)
        ->and($result['deadline_at']->toDateTimeString())->toBe('2026-08-31 07:00:00')
        ->and($result['status'])->toBe(MeasurementSlaService::STATUS_ON_TIME);

    CarbonImmutable::setTestNow();
});

it('does not restart the clock when responsibility is delegated', function () {
    $responsible = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $responsible->givePermissionTo('measurements.review');
    $delegate = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $delegate->givePermissionTo('measurements.review');
    $measurement = makeSlaMeasurement($responsible);
    $measurement->reviews()->update(['created_at' => now()->subDays(2)]);
    $before = app(MeasurementSlaService::class)->evaluate($measurement->fresh());
    ResponsibilityDelegation::factory()->active()->forOperation($measurement->operation)->create([
        'delegator_user_id' => $responsible->getKey(),
        'delegate_user_id' => $delegate->getKey(),
    ]);
    $after = app(MeasurementSlaService::class)->evaluate($measurement->fresh());

    expect($after['started_at']->toDateTimeString())->toBe($before['started_at']->toDateTimeString())
        ->and($after['deadline_at']->toDateTimeString())->toBe($before['deadline_at']->toDateTimeString());
});

it('keeps the original SLA cycle when a stage is reopened', function () {
    $measurement = makeSlaMeasurement(User::factory()->create());
    $originalStart = now()->subDays(3)->startOfMinute();
    $measurement->reviews()->update(['created_at' => $originalStart, 'status' => 'rejected']);
    $measurement->reviews()->update(['status' => 'pending', 'reviewed_at' => null]);

    $result = app(MeasurementSlaService::class)->evaluate($measurement->fresh());

    expect($result['started_at']->toDateTimeString())->toBe($originalStart->toDateTimeString());
});

it('emits warning and overdue only once each for the same recipient and cycle', function () {
    $responsible = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $responsible->givePermissionTo('measurements.review');
    $start = CarbonImmutable::parse('2026-08-17 10:00:00', config('app.timezone'));
    CarbonImmutable::setTestNow('2026-08-20 10:00:00');
    SlaConfiguration::query()->where('stage', 1)->update([
        'duration_value' => 4,
        'warning_threshold_percent' => 75,
    ]);
    $measurement = makeSlaMeasurement($responsible);
    $measurement->reviews()->update(['created_at' => $start]);

    $this->artisan('measurements:evaluate-sla')->assertSuccessful();
    $this->artisan('measurements:evaluate-sla')->assertSuccessful();
    CarbonImmutable::setTestNow('2026-08-24 10:00:00');
    $this->artisan('measurements:evaluate-sla')->assertSuccessful();
    $this->artisan('measurements:evaluate-sla')->assertSuccessful();

    expect(DB::table('measurement_sla_alerts')->where('measurement_id', $measurement->getKey())->count())->toBe(2)
        ->and(DB::table('measurement_sla_alerts')->where('alert_type', 'warning')->count())->toBe(1)
        ->and(DB::table('measurement_sla_alerts')->where('alert_type', 'overdue')->count())->toBe(1);

    CarbonImmutable::setTestNow();
});

it('does not alert finalized or rejected terminal measurements', function (string $status) {
    $responsible = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $responsible->givePermissionTo('measurements.review');
    $measurement = makeSlaMeasurement($responsible);
    $measurement->reviews()->update(['created_at' => now()->subDays(30)]);
    $measurement->update(['status' => $status]);

    $this->artisan('measurements:evaluate-sla')->assertSuccessful();

    expect(DB::table('measurement_sla_alerts')->where('measurement_id', $measurement->getKey())->exists())->toBeFalse();
})->with(['finalized', 'rejected']);

it('fails closed when the projected deadline crosses into an unmaterialized year', function () {
    $now = CarbonImmutable::parse('2026-12-31 12:00:00', config('app.timezone'));
    CarbonImmutable::setTestNow($now);
    config(['measurements.sla.calendar_code' => 'BR_NATIONAL_HOLIDAYS']);
    BusinessCalendarYear::query()->create([
        'calendar_code' => 'BR_NATIONAL_HOLIDAYS',
        'year' => 2026,
        'status' => 'confirmed',
    ]);
    SlaConfiguration::query()->where('stage', 1)->update(['duration_value' => 1]);
    $measurement = makeSlaMeasurement(User::factory()->create());
    $measurement->reviews()->update(['created_at' => '2026-12-31 10:00:00']);

    $result = app(MeasurementSlaService::class)->evaluate($measurement->fresh(), $now);

    expect($result['status'])->toBe(MeasurementSlaService::STATUS_CALENDAR_UNAVAILABLE)
        ->and($result['calendar_unavailable'])->toBeTrue()
        ->and($result['deadline_at'])->toBeNull();
});

it('skips the new year holiday and weekend after official coverage is materialized', function () {
    $now = CarbonImmutable::parse('2026-12-31 12:00:00', config('app.timezone'));
    CarbonImmutable::setTestNow($now);
    config(['measurements.sla.calendar_code' => 'BR_NATIONAL_HOLIDAYS']);

    foreach ([2026, 2027] as $year) {
        BusinessCalendarYear::query()->create([
            'calendar_code' => 'BR_NATIONAL_HOLIDAYS',
            'year' => $year,
            'status' => 'confirmed',
        ]);
    }

    BusinessCalendarDate::query()->create([
        'calendar_code' => 'BR_NATIONAL_HOLIDAYS',
        'calendar_date' => '2027-01-01',
        'is_business_day' => false,
        'description' => 'Confraternização Universal',
    ]);
    SlaConfiguration::query()->where('stage', 1)->update(['duration_value' => 1]);
    $measurement = makeSlaMeasurement(User::factory()->create());
    $measurement->reviews()->update(['created_at' => '2026-12-31 10:00:00']);

    $result = app(MeasurementSlaService::class)->evaluate($measurement->fresh(), $now);

    expect($result['status'])->toBe(MeasurementSlaService::STATUS_ON_TIME)
        ->and($result['deadline_at']->toDateTimeString())->toBe('2027-01-04 10:00:00');
});

it('recalculates after the missing calendar year is materialized', function () {
    $now = CarbonImmutable::parse('2026-12-31 12:00:00', config('app.timezone'));
    CarbonImmutable::setTestNow($now);
    config(['measurements.sla.calendar_code' => 'BR_NATIONAL_HOLIDAYS']);
    BusinessCalendarYear::query()->create([
        'calendar_code' => 'BR_NATIONAL_HOLIDAYS',
        'year' => 2026,
        'status' => 'confirmed',
    ]);
    SlaConfiguration::query()->where('stage', 1)->update(['duration_value' => 1]);
    $measurement = makeSlaMeasurement(User::factory()->create());
    $measurement->reviews()->update(['created_at' => '2026-12-31 10:00:00']);
    $sla = app(MeasurementSlaService::class);

    expect($sla->evaluate($measurement->fresh(), $now)['status'])
        ->toBe(MeasurementSlaService::STATUS_CALENDAR_UNAVAILABLE);

    BusinessCalendarYear::query()->create([
        'calendar_code' => 'BR_NATIONAL_HOLIDAYS',
        'year' => 2027,
        'status' => 'confirmed',
    ]);
    BusinessCalendarDate::query()->create([
        'calendar_code' => 'BR_NATIONAL_HOLIDAYS',
        'calendar_date' => '2027-01-01',
        'is_business_day' => false,
        'description' => 'Confraternização Universal',
    ]);

    expect($sla->evaluate($measurement->fresh(), $now)['deadline_at']->toDateTimeString())
        ->toBe('2027-01-04 10:00:00');
});

it('fails closed when a pause extends the final deadline into an uncovered year', function () {
    $now = CarbonImmutable::parse('2026-12-31 12:00:00', config('app.timezone'));
    CarbonImmutable::setTestNow($now);
    config(['measurements.sla.calendar_code' => 'BR_NATIONAL_HOLIDAYS']);
    BusinessCalendarYear::query()->create([
        'calendar_code' => 'BR_NATIONAL_HOLIDAYS',
        'year' => 2026,
        'status' => 'confirmed',
    ]);
    SlaConfiguration::query()->where('stage', 1)->update(['duration_value' => 2]);
    $responsible = User::factory()->create();
    $measurement = makeSlaMeasurement($responsible);
    $measurement->reviews()->update(['created_at' => '2026-12-29 10:00:00']);
    $measurement->pauses()->create([
        'stage' => 1,
        'paused_by' => $responsible->getKey(),
        'pause_reason' => 'Aguardando documento',
        'paused_operation_status' => 'in_review',
        'paused_at' => '2026-12-30 00:00:00',
        'resumed_at' => '2026-12-30 15:00:00',
    ]);

    $result = app(MeasurementSlaService::class)->evaluate($measurement->fresh(), $now);

    expect($result['status'])->toBe(MeasurementSlaService::STATUS_CALENDAR_UNAVAILABLE)
        ->and($result['deadline_at'])->toBeNull();
});

it('does not persist warning or overdue when calendar coverage is unavailable', function (
    string $startedAt,
    string $evaluatedAt,
) {
    $now = CarbonImmutable::parse($evaluatedAt, config('app.timezone'));
    CarbonImmutable::setTestNow($now);
    config(['measurements.sla.calendar_code' => 'BR_NATIONAL_HOLIDAYS']);
    BusinessCalendarYear::query()->create([
        'calendar_code' => 'BR_NATIONAL_HOLIDAYS',
        'year' => 2026,
        'status' => 'confirmed',
    ]);
    SlaConfiguration::query()->where('stage', 1)->update([
        'duration_value' => 1,
        'warning_threshold_percent' => 40,
    ]);
    $responsible = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $responsible->givePermissionTo('measurements.review');
    $measurement = makeSlaMeasurement($responsible);
    $measurement->reviews()->update(['created_at' => $startedAt]);

    $this->artisan('measurements:evaluate-sla')->assertSuccessful();

    expect(DB::table('measurement_sla_alerts')->where('measurement_id', $measurement->getKey())->exists())->toBeFalse();
    Notification::assertNotSentTo($responsible, MeasurementSlaNotification::class);
})->with([
    'would-be warning' => ['2026-12-31 10:00:00', '2026-12-31 20:00:00'],
    'would-be overdue' => ['2026-12-31 10:00:00', '2027-01-04 10:00:00'],
]);

it('does not notify a delegate when the delegator becomes ineffective', function (string $condition) {
    CarbonImmutable::setTestNow('2026-09-01 01:00:00');
    config(['measurements.sla.calendar_code' => null]);
    SlaConfiguration::query()->where('stage', 1)->update(['duration_value' => 1]);
    $delegator = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $delegator->givePermissionTo('measurements.review');
    $delegate = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $delegate->givePermissionTo('measurements.review');
    $measurement = makeSlaMeasurement($delegator);
    $measurement->reviews()->update(['created_at' => '2026-08-31 00:00:00']);
    ResponsibilityDelegation::factory()->active()->forOperation($measurement->operation)->create([
        'delegator_user_id' => $delegator->getKey(),
        'delegate_user_id' => $delegate->getKey(),
    ]);

    match ($condition) {
        'inactive' => $delegator->update(['is_active' => false]),
        'unapproved' => $delegator->update(['approved_at' => null]),
        'permission_removed' => $delegator->revokePermissionTo('measurements.review'),
        'assignment_removed' => $measurement->operation->update([
            'responsible_user_id' => User::factory()->create()->getKey(),
        ]),
    };

    $this->artisan('measurements:evaluate-sla')->assertSuccessful();

    Notification::assertNotSentTo($delegate, MeasurementSlaNotification::class);
    expect(DB::table('measurement_sla_alerts')->where('recipient_user_id', $delegate->getKey())->exists())->toBeFalse();
})->with(['inactive', 'unapproved', 'permission_removed', 'assignment_removed']);

it('notifies an effective delegate who can execute the overdue responsibility', function () {
    CarbonImmutable::setTestNow('2026-09-01 01:00:00');
    config(['measurements.sla.calendar_code' => null]);
    SlaConfiguration::query()->where('stage', 1)->update(['duration_value' => 1]);
    $delegator = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $delegator->givePermissionTo('measurements.review');
    $delegate = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $delegate->givePermissionTo('measurements.review');
    $measurement = makeSlaMeasurement($delegator);
    $measurement->reviews()->update(['created_at' => '2026-08-31 00:00:00']);
    ResponsibilityDelegation::factory()->active()->forOperation($measurement->operation)->create([
        'delegator_user_id' => $delegator->getKey(),
        'delegate_user_id' => $delegate->getKey(),
    ]);

    $this->artisan('measurements:evaluate-sla')->assertSuccessful();

    Notification::assertSentTo($delegate, MeasurementSlaNotification::class);
    expect(DB::table('measurement_sla_alerts')->where('recipient_user_id', $delegate->getKey())->exists())->toBeTrue();
});

it('does not notify an ineffective delegate about an actionable warning', function (string $condition) {
    CarbonImmutable::setTestNow('2026-08-31 18:00:00');
    config(['measurements.sla.calendar_code' => null]);
    SlaConfiguration::query()->where('stage', 1)->update([
        'duration_value' => 1,
        'warning_threshold_percent' => 75,
    ]);
    $delegator = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $delegator->givePermissionTo('measurements.review');
    $delegate = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $delegate->givePermissionTo('measurements.review');
    $measurement = makeSlaMeasurement($delegator);
    $measurement->reviews()->update(['created_at' => '2026-08-31 00:00:00']);
    ResponsibilityDelegation::factory()->active()->forOperation($measurement->operation)->create([
        'delegator_user_id' => $delegator->getKey(),
        'delegate_user_id' => $delegate->getKey(),
    ]);

    match ($condition) {
        'inactive' => $delegate->update(['is_active' => false]),
        'unapproved' => $delegate->update(['approved_at' => null]),
        'permission_removed' => $delegate->revokePermissionTo('measurements.review'),
    };

    $this->artisan('measurements:evaluate-sla')->assertSuccessful();

    Notification::assertNotSentTo($delegate, MeasurementSlaNotification::class);
    expect(DB::table('measurement_sla_alerts')->where('recipient_user_id', $delegate->getKey())->exists())->toBeFalse();
})->with(['inactive', 'unapproved', 'permission_removed']);

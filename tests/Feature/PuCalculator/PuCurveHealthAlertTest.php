<?php

use App\Actions\PuCalculator\SendPuCurveHealthAlertsAction;
use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Mail\PuCurveHealthAlertMail;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    config()->set('pu_calculator.alerts.recipients', ['ops@example.com']);
    config()->set('pu_calculator.alerts.cooldown_minutes', 180);
});

function stuckProcessingEmission(): void
{
    $emission = Emission::factory()->create(['type' => 'CRI', 'status' => 'active']);
    $version = EmissionPuCurveVersion::factory()->create([
        'emission_id' => $emission->id,
        'status' => PuCurveStatus::Processing->value,
    ]);
    EmissionPuCurveVersion::query()->whereKey($version->id)->update(['updated_at' => now()->subHours(2)]);
}

it('sends an alert email when there are critical issues', function () {
    Mail::fake();
    stuckProcessingEmission();

    $sent = app(SendPuCurveHealthAlertsAction::class)->handle();

    expect($sent)->toBeTrue();
    Mail::assertSent(PuCurveHealthAlertMail::class);
});

it('does not send an alert when the system is healthy', function () {
    Mail::fake();

    $sent = app(SendPuCurveHealthAlertsAction::class)->handle();

    expect($sent)->toBeFalse();
    Mail::assertNothingSent();
});

it('does not send an alert when there are no recipients configured', function () {
    Mail::fake();
    config()->set('pu_calculator.alerts.recipients', []);
    stuckProcessingEmission();

    expect(app(SendPuCurveHealthAlertsAction::class)->handle())->toBeFalse();
    Mail::assertNothingSent();
});

it('respects the cooldown for the same set of problems', function () {
    Mail::fake();
    stuckProcessingEmission();

    expect(app(SendPuCurveHealthAlertsAction::class)->handle())->toBeTrue();
    expect(app(SendPuCurveHealthAlertsAction::class)->handle())->toBeFalse();

    Mail::assertSent(PuCurveHealthAlertMail::class, 1);
});

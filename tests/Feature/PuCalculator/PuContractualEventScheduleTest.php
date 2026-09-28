<?php

use App\Domain\PuCalculator\Enums\PuAmortizationType;
use App\Domain\PuCalculator\Enums\PuEventType;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use App\Domain\PuCalculator\Services\PuContractualEventScheduleService;
use App\Domain\PuCalculator\Services\PuCurvePrerequisiteService;
use App\Domain\PuCalculator\Support\BusinessCalendarRegistry;
use App\Enums\AccessPermission;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\PuEventsRelationManager;
use App\Filament\Resources\Emissions\Pages\EditEmission;
use App\Models\Emission;
use App\Models\EmissionPuDailyCurve;
use App\Models\EmissionPuEvent;
use App\Models\EmissionPuParameter;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Tests\Support\Pu\PuCandidateGovernanceFixture;

uses(RefreshDatabase::class);

/**
 * Emissão com o cronograma do Termo confirmado: juros mensais no dia 8, de
 * 08/06/2026 a 08/05/2031, bullet no vencimento e dia útil seguinte no
 * calendário de feriados nacionais -- 60 juros e 1 amortização.
 */
function contractualScheduleEmission(): Emission
{
    $emission = PuCandidateGovernanceFixture::emission();
    PuCandidateGovernanceFixture::proveBaseline($emission);
    PuCandidateGovernanceFixture::confirmCalendar();

    return $emission->fresh();
}

function puEventsConfigurator(): User
{
    foreach (['emissions.view', 'emissions.update', AccessPermission::PuParametersConfigure->value] as $permission) {
        Permission::findOrCreate($permission);
    }

    $user = User::factory()->create();
    $user->givePermissionTo(['emissions.view', 'emissions.update', AccessPermission::PuParametersConfigure->value]);

    return $user;
}

function contractualInterestEvent(Emission $emission, string $originalDate, string $effectiveDate): EmissionPuEvent
{
    return EmissionPuEvent::query()->create([
        'emission_id' => $emission->id,
        'event_type' => PuEventType::InterestPayment->value,
        'original_date' => $originalDate,
        'effective_date' => $effectiveDate,
        'amortization_type' => PuAmortizationType::None->value,
        'amortization_value' => null,
        'sequence' => 1,
        'description' => 'Cadastro manual',
    ]);
}

function contractualSchedule(): PuContractualEventScheduleService
{
    return app(PuContractualEventScheduleService::class);
}

it('plans the full contractual schedule through maturity', function () {
    $plan = contractualSchedule()->plan(contractualScheduleEmission());
    $events = collect($plan['contractual_events']);

    expect($plan['available'])->toBeTrue()
        ->and($plan['maturity_date'])->toBe('2031-05-08')
        ->and($events)->toHaveCount(61)
        ->and($events->where('event_type', PuEventType::InterestPayment->value))->toHaveCount(60)
        ->and($events->firstWhere('original_date', '2026-11-08')['effective_date'])->toBe('2026-11-09')
        ->and($events->firstWhere('event_type', PuEventType::Amortization->value))->toMatchArray([
            'original_date' => '2031-05-08',
            'effective_date' => '2031-05-08',
            'amortization_type' => PuAmortizationType::Residual->value,
        ])
        ->and($plan['creatable_events'])->toHaveCount(61);
});

it('creates only the events after the last calculated day and keeps the manual ones', function () {
    $emission = contractualScheduleEmission();
    $manual = contractualInterestEvent($emission, '2026-06-08', '2026-06-08');
    contractualInterestEvent($emission, '2026-07-08', '2026-07-08');
    EmissionPuDailyCurve::factory()->create(['emission_id' => $emission->id, 'curve_date' => '2026-09-30']);

    $result = contractualSchedule()->write($emission, puEventsConfigurator());
    $activity = Activity::query()->where('event', 'event_changed')->latest('id')->first();

    expect($result['action'])->toBe(PuContractualEventScheduleService::ACTION_CREATED)
        ->and($result['created'])->toBe(57)
        ->and($result['first_date'])->toBe('2026-10-08')
        ->and(collect($result['plan']['missing_in_calculated_period'])->pluck('effective_date')->all())
        ->toBe(['2026-08-10', '2026-09-08'])
        ->and(EmissionPuEvent::query()->whereBelongsTo($emission)->count())->toBe(59)
        ->and(EmissionPuEvent::query()->whereBelongsTo($emission)->whereDate('effective_date', '2026-08-10')->exists())->toBeFalse()
        ->and($manual->fresh()->description)->toBe('Cadastro manual')
        ->and($activity?->properties['action'])->toBe('contractual_schedule_generated')
        ->and($activity?->properties['inserted_event_ids'])->toHaveCount(57);

    $again = contractualSchedule()->write($emission->fresh(), puEventsConfigurator());

    expect($again['action'])->toBe(PuContractualEventScheduleService::ACTION_NOTHING_TO_CREATE)
        ->and(EmissionPuEvent::query()->whereBelongsTo($emission)->count())->toBe(59);
});

it('keeps an event that diverges from the contract without creating a duplicate', function () {
    $emission = contractualScheduleEmission();
    $divergent = contractualInterestEvent($emission, '2027-02-08', '2027-02-10');

    $result = contractualSchedule()->write($emission, puEventsConfigurator());

    expect($result['created'])->toBe(60)
        ->and(collect($result['plan']['conflicting_events'])->pluck('reason')->all())->toBe(['event_semantics_mismatch'])
        ->and($divergent->fresh()->effective_date->toDateString())->toBe('2027-02-10')
        ->and(EmissionPuEvent::query()->whereBelongsTo($emission)->whereDate('original_date', '2027-02-08')->count())->toBe(1);
});

it('refuses to create events for a user without PU configuration permission', function () {
    $emission = contractualScheduleEmission();

    expect(fn () => contractualSchedule()->write($emission, User::factory()->create()))
        ->toThrow(AuthorizationException::class)
        ->and(EmissionPuEvent::query()->whereBelongsTo($emission)->count())->toBe(0);
});

it('blocks curve generation while contractual events are missing and releases it once they exist', function () {
    $emission = contractualScheduleEmission();
    EmissionPuParameter::factory()->create([
        'emission_id' => $emission->id,
        'calendar_code' => BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS,
        'index_rate_lookup_mode' => PuIndexRateLookupMode::BusinessDayLagExact->value,
        'index_rate_lag_business_days' => -5,
    ]);
    $blockingKeys = fn (): array => collect(app(PuCurvePrerequisiteService::class)->handle($emission->fresh())->blockingIssues())
        ->pluck('key')
        ->all();

    $before = collect(app(PuCurvePrerequisiteService::class)->handle($emission->fresh())->blockingIssues())
        ->firstWhere('key', 'pu_events_contractual_schedule');

    expect($before?->message)->toContain('Faltam 61 evento(s)')
        ->and($before?->message)->toContain('08/06/2026');

    contractualSchedule()->write($emission->fresh(), puEventsConfigurator());

    expect($blockingKeys())->not->toContain('pu_events_contractual_schedule');
});

it('only warns when a registered event diverges from the contract', function () {
    $emission = contractualScheduleEmission();
    EmissionPuParameter::factory()->create([
        'emission_id' => $emission->id,
        'calendar_code' => BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS,
    ]);
    contractualInterestEvent($emission, '2027-02-08', '2027-02-10');
    contractualSchedule()->write($emission->fresh(), puEventsConfigurator());

    $result = app(PuCurvePrerequisiteService::class)->handle($emission->fresh());

    expect(collect($result->blockingIssues())->pluck('key')->all())->not->toContain('pu_events_contractual_schedule')
        ->and(collect($result->warningMessages())->contains(fn (string $message): bool => str_contains($message, '1 evento(s) cadastrado(s) divergem do cronograma contratual')))
        ->toBeTrue();
});

it('leaves emissions without a contractual schedule outside the guard', function () {
    $parameter = EmissionPuParameter::factory()->create();

    $result = app(PuCurvePrerequisiteService::class)->handle($parameter->emission->fresh());

    expect(contractualSchedule()->plan($parameter->emission)['available'])->toBeFalse()
        ->and(collect($result->issues)->pluck('key')->all())->not->toContain('pu_events_contractual_schedule');
});

it('generates the schedule from the events tab', function () {
    $emission = contractualScheduleEmission();
    $this->actingAs(puEventsConfigurator());

    Livewire::test(PuEventsRelationManager::class, [
        'ownerRecord' => $emission,
        'pageClass' => EditEmission::class,
    ])
        ->assertActionVisible(TestAction::make('generateContractualSchedule')->table())
        ->callAction(TestAction::make('generateContractualSchedule')->table())
        ->assertNotified('61 evento(s) criado(s).');

    expect(EmissionPuEvent::query()->whereBelongsTo($emission)->count())->toBe(61);
});

it('hides the schedule action from users who cannot configure PU', function () {
    $emission = contractualScheduleEmission();
    Permission::findOrCreate('emissions.view');
    $viewer = User::factory()->create();
    $viewer->givePermissionTo('emissions.view');
    $this->actingAs($viewer);

    Livewire::test(PuEventsRelationManager::class, [
        'ownerRecord' => $emission,
        'pageClass' => EditEmission::class,
    ])->assertActionHidden(TestAction::make('generateContractualSchedule')->table());
});

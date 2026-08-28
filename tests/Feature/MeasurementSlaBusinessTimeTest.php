<?php

use App\Filament\Resources\ResponsibilityDelegations\Pages\CreateResponsibilityDelegation;
use App\Models\BusinessCalendarDate;
use App\Models\BusinessCalendarYear;
use App\Models\Measurement;
use App\Models\Operation;
use App\Models\ResponsibilityDelegation;
use App\Models\SlaConfiguration;
use App\Models\User;
use App\Services\MeasurementSlaService;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
    config(['measurements.sla.calendar_code' => 'BR_NATIONAL_HOLIDAYS']);
    SlaConfiguration::factory()->forStage(1, 5)->create();
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/**
 * Instante brasileiro, escrito como o operador o leria. O fuso é literal de
 * propósito: estes testes fixam o requisito de negócio em termos absolutos, e não
 * o que a configuração disser — do contrário fixture e código deslizariam juntos e
 * o deslocamento de fuso deixaria de ser detectável.
 */
function businessInstant(string $localDateTime): CarbonImmutable
{
    return CarbonImmutable::parse($localDateTime, 'America/Sao_Paulo');
}

function materializeYear(int $year): void
{
    BusinessCalendarYear::query()->firstOrCreate([
        'calendar_code' => 'BR_NATIONAL_HOLIDAYS',
        'year' => $year,
    ], ['status' => 'confirmed']);
}

function makeBusinessTimeMeasurement(string $startedAtLocal, ?User $responsible = null): Measurement
{
    $responsible ??= User::factory()->create();
    $operation = Operation::factory()->create([
        'responsible_user_id' => $responsible->getKey(),
        'stage2_reviewer_user_id' => $responsible->getKey(),
        'stage3_reviewer_user_id' => $responsible->getKey(),
    ]);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->getKey(),
        'status' => 'in_review',
        'current_stage' => 1,
    ]);
    $measurement->reviews()->create(['stage' => 1, 'status' => 'pending']);
    $measurement->reviews()->update([
        'created_at' => businessInstant($startedAtLocal)->utc()->toDateTimeString(),
    ]);

    return $measurement->fresh(['operation', 'reviews', 'pauses']);
}

/**
 * Segundos úteis decorridos entre o início do ciclo e o instante local informado.
 */
function elapsedBusinessSecondsBetween(string $startedAtLocal, string $nowLocal): int
{
    materializeYear(businessInstant($startedAtLocal)->year);
    materializeYear(businessInstant($nowLocal)->year);
    $measurement = makeBusinessTimeMeasurement($startedAtLocal);

    return app(MeasurementSlaService::class)
        ->evaluate($measurement, businessInstant($nowLocal))['elapsed_business_seconds'];
}

it('configura o fuso de negócio separado do fuso técnico da aplicação', function () {
    expect(BusinessTime::timezone())->toBe('America/Sao_Paulo')
        ->and(config('app.timezone'))->toBe('UTC')
        ->and(BusinessTime::at(CarbonImmutable::parse('2026-09-08 01:00:00', 'UTC'))->toDateString())
        ->toBe('2026-09-07');
});

it('trata as 22h de um feriado nacional como feriado, e não como o dia útil seguinte', function () {
    materializeYear(2026);
    BusinessCalendarDate::query()->create([
        'calendar_code' => 'BR_NATIONAL_HOLIDAYS',
        'calendar_date' => '2026-09-07',
        'is_business_day' => false,
        'description' => 'Independência',
    ]);

    // 07/09 20:00 → 23:00 local cai inteiramente no feriado; em UTC atravessaria
    // para 08/09, que é dia útil, e a janela seria indevidamente contada.
    expect(elapsedBusinessSecondsBetween('2026-09-07 20:00', '2026-09-07 23:00'))->toBe(0);
});

it('mantém as 22h de sexta-feira pertencendo à sexta-feira', function () {
    materializeYear(2026);

    // 28/08/2026 é sexta. Em UTC a janela cairia no sábado e seria descartada.
    expect(elapsedBusinessSecondsBetween('2026-08-28 21:00', '2026-08-28 23:00'))->toBe(7200);
});

it('não trata as 22h de domingo como segunda-feira útil', function () {
    materializeYear(2026);

    // 30/08/2026 é domingo. Em UTC a janela cairia na segunda e seria contada.
    expect(elapsedBusinessSecondsBetween('2026-08-30 21:00', '2026-08-30 23:00'))->toBe(0);
});

it('corta o dia útil exatamente na meia-noite local', function () {
    materializeYear(2026);

    expect(elapsedBusinessSecondsBetween('2026-08-28 23:00', '2026-08-29 01:00'))->toBe(3600)
        ->and(elapsedBusinessSecondsBetween('2026-08-28 22:00', '2026-08-29 00:00'))->toBe(7200)
        ->and(elapsedBusinessSecondsBetween('2026-08-29 00:00', '2026-08-29 02:00'))->toBe(0);
});

it('resolve a cobertura de calendário pelo ano civil de negócio na virada do ano', function () {
    materializeYear(2026);
    SlaConfiguration::query()->where('stage', 1)->update([
        'duration_value' => 63,
        'duration_unit' => 'hours',
    ]);
    $measurement = makeBusinessTimeMeasurement('2026-12-29 07:00');

    // 63h úteis a partir de 29/12 07:00 local terminam em 31/12 22:00 local, que em
    // UTC é 01/01/2027 — ano sem cobertura. O ano consultado precisa ser o de negócio.
    $result = app(MeasurementSlaService::class)->evaluate($measurement, businessInstant('2026-12-31 09:00'));

    expect($result['status'])->not->toBe(MeasurementSlaService::STATUS_CALENDAR_UNAVAILABLE)
        ->and($result['deadline_at'])->not->toBeNull()
        ->and(BusinessTime::at($result['deadline_at'])->toDateTimeString())->toBe('2026-12-31 22:00:00')
        ->and($result['deadline_at']->utc()->toDateTimeString())->toBe('2027-01-01 01:00:00');
});

it('desconta pausa que atravessa a meia-noite local pela grade de negócio', function () {
    materializeYear(2026);
    $measurement = makeBusinessTimeMeasurement('2026-08-27 09:00');
    $measurement->pauses()->create([
        'stage' => 1,
        'paused_by' => User::factory()->create()->getKey(),
        'pause_reason' => 'Aguardando documento',
        'paused_operation_status' => 'in_review',
        'paused_at' => businessInstant('2026-08-27 22:00')->utc()->toDateTimeString(),
        'resumed_at' => businessInstant('2026-08-28 02:00')->utc()->toDateTimeString(),
    ]);

    $result = app(MeasurementSlaService::class)
        ->evaluate($measurement->fresh(['operation', 'reviews', 'pauses']), businessInstant('2026-08-28 09:00'));

    // 24h corridas de dias úteis menos 4h de pausa.
    expect($result['elapsed_business_seconds'])->toBe(20 * 3600);
});

it('continua falhando fechado quando o ano de negócio não tem cobertura', function () {
    materializeYear(2026);
    $measurement = makeBusinessTimeMeasurement('2026-12-30 09:00');
    SlaConfiguration::query()->where('stage', 1)->update(['duration_value' => 5]);

    $result = app(MeasurementSlaService::class)->evaluate($measurement, businessInstant('2026-12-31 09:00'));

    expect($result['status'])->toBe(MeasurementSlaService::STATUS_CALENDAR_UNAVAILABLE)
        ->and($result['deadline_at'])->toBeNull();
});

it('ignora exclude_weekends e exclude_holidays no cálculo do prazo', function () {
    materializeYear(2026);
    BusinessCalendarDate::query()->create([
        'calendar_code' => 'BR_NATIONAL_HOLIDAYS',
        'calendar_date' => '2026-09-07',
        'is_business_day' => false,
        'description' => 'Independência',
    ]);
    $measurement = makeBusinessTimeMeasurement('2026-09-04 09:00');
    $now = businessInstant('2026-09-09 09:00');

    SlaConfiguration::query()->where('stage', 1)->update([
        'exclude_weekends' => true,
        'exclude_holidays' => true,
    ]);
    $withFlags = app(MeasurementSlaService::class)->evaluate($measurement, $now);

    SlaConfiguration::query()->where('stage', 1)->update([
        'exclude_weekends' => false,
        'exclude_holidays' => false,
    ]);
    $withoutFlags = app(MeasurementSlaService::class)->evaluate($measurement, $now);

    expect($withoutFlags['deadline_at']->toDateTimeString())->toBe($withFlags['deadline_at']->toDateTimeString())
        ->and($withoutFlags['elapsed_business_seconds'])->toBe($withFlags['elapsed_business_seconds'])
        ->and($withoutFlags['status'])->toBe($withFlags['status']);
});

it('aplica a configuração de SLA vigente a medições já em andamento', function () {
    materializeYear(2026);
    $measurement = makeBusinessTimeMeasurement('2026-08-24 09:00');
    $now = businessInstant('2026-08-27 09:00');

    $underOriginalConfig = app(MeasurementSlaService::class)->evaluate($measurement, $now);

    SlaConfiguration::query()->where('stage', 1)->update(['duration_value' => 1]);
    $underNewConfig = app(MeasurementSlaService::class)->evaluate($measurement, $now);

    expect($underOriginalConfig['status'])->toBe(MeasurementSlaService::STATUS_ON_TIME)
        ->and($underNewConfig['status'])->toBe(MeasurementSlaService::STATUS_OVERDUE)
        ->and($underNewConfig['deadline_at']->lessThan($underOriginalConfig['deadline_at']))->toBeTrue();
});

it('não emite alerta quando o ciclo não tem instante de início', function () {
    materializeYear(2026);
    CarbonImmutable::setTestNow(businessInstant('2026-09-10 09:00'));
    $measurement = makeBusinessTimeMeasurement('2026-08-24 09:00');

    DB::table('measurement_reviews')->where('measurement_id', $measurement->getKey())->update(['created_at' => null]);
    DB::table('measurements')->where('id', $measurement->getKey())->update(['created_at' => null]);

    $evaluation = app(MeasurementSlaService::class)->evaluate($measurement->fresh(['operation', 'reviews', 'pauses']));

    $this->artisan('measurements:evaluate-sla')->assertSuccessful();

    expect($evaluation['started_at'])->toBeNull()
        ->and($evaluation['status'])->toBe(MeasurementSlaService::STATUS_NOT_CONFIGURED)
        ->and(DB::table('measurement_sla_alerts')->count())->toBe(0);
});

it('registra o business_day do alerta na data civil de negócio', function () {
    materializeYear(2026);
    // 01/09 22:00 local = 02/09 01:00 UTC: a data de negócio é 01/09.
    CarbonImmutable::setTestNow(businessInstant('2026-09-01 22:00'));
    $responsible = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $responsible->givePermissionTo('measurements.review');
    makeBusinessTimeMeasurement('2026-08-20 09:00', $responsible);

    $this->artisan('measurements:evaluate-sla')->assertSuccessful();

    $alert = DB::table('measurement_sla_alerts')->first();

    expect($alert)->not->toBeNull()
        ->and($alert->business_day)->toStartWith('2026-09-01')
        ->and(CarbonImmutable::now()->utc()->toDateString())->toBe('2026-09-02');
});

it('registra o warning_date da delegação na data civil de negócio', function () {
    CarbonImmutable::setTestNow(businessInstant('2026-09-01 22:00'));
    $delegator = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $delegate = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    ResponsibilityDelegation::factory()->create([
        'delegator_user_id' => $delegator->getKey(),
        'delegate_user_id' => $delegate->getKey(),
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addHours(10),
        'revoked_at' => null,
    ]);

    $this->artisan('delegations:warn-expiring')->assertSuccessful();

    $warning = DB::table('delegation_expiration_warnings')->first();

    expect($warning)->not->toBeNull()
        ->and($warning->warning_date)->toStartWith('2026-09-01')
        ->and(CarbonImmutable::now()->utc()->toDateString())->toBe('2026-09-02');
});

it('mantém a vigência da delegação decidida pelo instante, não pela data civil', function () {
    $startsAt = businessInstant('2026-09-01 21:00');
    $endsAt = businessInstant('2026-09-05 21:00');
    $delegation = ResponsibilityDelegation::factory()->create([
        'starts_at' => $startsAt->utc()->toDateTimeString(),
        'ends_at' => $endsAt->utc()->toDateTimeString(),
        'revoked_at' => null,
    ]);

    $cases = [
        'antes do início' => [$startsAt->subSecond(), false],
        'exatamente no início' => [$startsAt, true],
        'durante' => [$startsAt->addDay(), true],
        'exatamente no fim' => [$endsAt, true],
        'depois do fim' => [$endsAt->addSecond(), false],
    ];

    foreach ($cases as $label => [$instant, $expected]) {
        CarbonImmutable::setTestNow($instant);
        $localAnswer = $delegation->fresh()->isActive();

        // O mesmo instante escrito em UTC precisa produzir a mesma resposta.
        CarbonImmutable::setTestNow($instant->utc());
        $utcAnswer = $delegation->fresh()->isActive();

        expect([$label => $localAnswer])->toBe([$label => $expected])
            ->and([$label => $utcAnswer])->toBe([$label => $expected]);
    }
});

it('apresenta a vigência no fuso de negócio e persiste no fuso da aplicação', function () {
    CarbonImmutable::setTestNow(businessInstant('2026-09-01 09:00'));
    $delegator = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $delegator->assignRole('editor');
    $delegate = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $delegate->givePermissionTo('measurements.review');
    $operation = Operation::factory()->create(['responsible_user_id' => $delegator->getKey()]);

    $this->actingAs($delegator);

    Livewire::test(CreateResponsibilityDelegation::class)
        ->fillForm([
            'delegator_user_id' => $delegator->getKey(),
            'delegate_user_id' => $delegate->getKey(),
            'scope_type' => ResponsibilityDelegation::SCOPE_OPERATION,
            'scope_operation_id' => $operation->getKey(),
            'starts_at' => '2026-09-01 22:00',
            'ends_at' => '2026-09-05 22:00',
            'reason' => 'Férias do responsável',
        ])
        // O operador digita no fuso de negócio; o estado do formulário já sai
        // convertido para o fuso da aplicação, que é o que será persistido.
        ->assertFormSet([
            'starts_at' => '2026-09-02 01:00',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $delegation = ResponsibilityDelegation::query()->latest('id')->firstOrFail();

    expect($delegation->starts_at->utc()->toDateTimeString())->toBe('2026-09-02 01:00:00')
        ->and($delegation->ends_at->utc()->toDateTimeString())->toBe('2026-09-06 01:00:00')
        ->and(BusinessTime::at($delegation->starts_at)->format('Y-m-d H:i'))->toBe('2026-09-01 22:00');
});

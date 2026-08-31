<?php

use App\Models\BusinessCalendar;
use App\Models\BusinessCalendarYear;
use App\Models\Measurement;
use App\Models\Operation;
use App\Models\SlaConfiguration;
use App\Models\User;
use App\Services\MeasurementOperationalReadModel;
use App\Services\MeasurementSlaService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * Quando o agendador roda e não sai alerta nenhum, a pergunta do operador é
 * sempre a mesma: foi porque está tudo no prazo, ou porque alguma coisa quebrou?
 *
 * Até aqui a execução não deixava rastro -- o resumo saía por `$this->info()`, e
 * saída de comando agendado não é capturada por ninguém --, e o calendário
 * faltante virava uma linha de WARNING por medição por hora. Estes testes fixam
 * o resumo agregado, os motivos que ele separa, e o teto de uma linha por
 * problema.
 */
beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
    config(['measurements.sla.calendar_code' => null]);
    CarbonImmutable::setTestNow('2026-09-01 12:00:00');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function slaDiagnosticsReviewer(): User
{
    $user = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $user->givePermissionTo(['measurements.view', 'operations.view', 'measurements.review']);

    return $user;
}

function overdueSlaMeasurement(User $responsible): Measurement
{
    $operation = Operation::factory()->create([
        'responsible_user_id' => $responsible->getKey(),
        'assigned_user_id' => $responsible->getKey(),
    ]);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->getKey(),
        'status' => 'in_review',
        'current_stage' => 1,
    ]);
    $measurement->reviews()->create(['stage' => 1, 'status' => 'pending']);
    $past = now()->subDays(30);
    $measurement->reviews()->update(['created_at' => $past, 'updated_at' => $past]);

    return $measurement;
}

/** @return array<string, mixed> */
function captureSlaSummary(): array
{
    $summaries = [];
    Log::listen(function ($message) use (&$summaries): void {
        if ($message->message === 'SLA evaluation complete') {
            $summaries[] = $message->context;
        }
    });

    test()->artisan('measurements:evaluate-sla')->assertSuccessful();

    return $summaries === [] ? [] : $summaries[array_key_last($summaries)];
}

it('summarises a healthy run in a single log line', function () {
    SlaConfiguration::factory()->forStage(1, 5)->create();
    $responsible = slaDiagnosticsReviewer();
    overdueSlaMeasurement($responsible);

    $summary = captureSlaSummary();

    expect($summary['evaluated'])->toBe(1)
        ->and($summary['overdue'])->toBe(1)
        ->and($summary['alerts_created'])->toBe(1)
        ->and($summary['alerts_deduplicated'])->toBe(0)
        ->and($summary['calendar_unavailable'])->toBe(0)
        ->and($summary['recipients_unavailable'])->toBe(0)
        ->and($summary['dry_run'])->toBeFalse();
});

it('counts the alert it refused to repeat instead of creating it twice', function () {
    SlaConfiguration::factory()->forStage(1, 5)->create();
    $responsible = slaDiagnosticsReviewer();
    overdueSlaMeasurement($responsible);

    captureSlaSummary();
    $second = captureSlaSummary();

    expect($second['alerts_created'])->toBe(0)
        ->and($second['alerts_deduplicated'])->toBe(1)
        ->and(DB::table('measurement_sla_alerts')->count())->toBe(1);
});

it('separates a stage with no SLA configuration from a healthy one', function () {
    $responsible = slaDiagnosticsReviewer();
    overdueSlaMeasurement($responsible);

    $summary = captureSlaSummary();

    expect($summary['not_configured'])->toBe(1)
        ->and($summary['alerts_created'])->toBe(0)
        ->and($summary['overdue'])->toBe(0);
});

it('counts an overdue measurement that has nobody left to warn', function () {
    SlaConfiguration::factory()->forStage(1, 5)->create();
    $responsible = slaDiagnosticsReviewer();
    overdueSlaMeasurement($responsible);

    // O responsável saiu de cena e não há delegação: prazo estourando e nenhum
    // destinatário efetivo. Era o caso que passava calado.
    $responsible->forceFill(['is_active' => false])->save();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $summary = captureSlaSummary();

    expect($summary['overdue'])->toBe(1)
        ->and($summary['recipients_unavailable'])->toBe(1)
        ->and($summary['alerts_created'])->toBe(0)
        ->and(DB::table('measurement_sla_alerts')->count())->toBe(0);
});

it('names the calendar and the year that is missing', function () {
    config(['measurements.sla.calendar_code' => 'BR_NATIONAL_HOLIDAYS']);
    BusinessCalendar::query()->firstOrCreate(['code' => 'BR_NATIONAL_HOLIDAYS'], ['name' => 'Feriados nacionais']);
    SlaConfiguration::factory()->forStage(1, 5)->create();
    $responsible = slaDiagnosticsReviewer();
    $measurement = overdueSlaMeasurement($responsible);

    $evaluation = app(MeasurementSlaService::class)->evaluate($measurement->fresh());

    expect($evaluation['status'])->toBe(MeasurementSlaService::STATUS_CALENDAR_UNAVAILABLE)
        ->and($evaluation['calendar_unavailable_reason'])
        ->toContain('BR_NATIONAL_HOLIDAYS')
        ->and($evaluation['calendar_unavailable_reason'])->toContain('2026')
        // A mesma frase chega à tela, no lugar do genérico que havia antes.
        ->and(app(MeasurementOperationalReadModel::class)->slaDescription($measurement->fresh()))
        ->toBe($evaluation['calendar_unavailable_reason']);
});

it('logs one warning per calendar problem, not one per measurement', function () {
    config(['measurements.sla.calendar_code' => 'BR_NATIONAL_HOLIDAYS']);
    BusinessCalendar::query()->firstOrCreate(['code' => 'BR_NATIONAL_HOLIDAYS'], ['name' => 'Feriados nacionais']);
    SlaConfiguration::factory()->forStage(1, 5)->create();
    $responsible = slaDiagnosticsReviewer();

    foreach (range(1, 12) as $ignored) {
        overdueSlaMeasurement($responsible);
    }

    $calendarWarnings = 0;
    Log::listen(function ($message) use (&$calendarWarnings): void {
        if ($message->level === 'warning' && str_contains($message->message, 'SLA calendar unavailable')) {
            $calendarWarnings++;
        }
    });

    $this->artisan('measurements:evaluate-sla')->assertSuccessful();

    // Doze medições, um problema: um ano de calendário não materializado. Antes
    // eram doze linhas idênticas por execução, de hora em hora.
    expect($calendarWarnings)->toBe(1);
});

it('escalates to a warning only when the run ends with something to resolve', function () {
    config(['measurements.sla.calendar_code' => 'BR_NATIONAL_HOLIDAYS']);
    BusinessCalendar::query()->firstOrCreate(['code' => 'BR_NATIONAL_HOLIDAYS'], ['name' => 'Feriados nacionais']);
    BusinessCalendarYear::query()->firstOrCreate(
        ['calendar_code' => 'BR_NATIONAL_HOLIDAYS', 'year' => 2026],
        ['status' => 'confirmed'],
    );
    SlaConfiguration::factory()->forStage(1, 5)->create();
    $responsible = slaDiagnosticsReviewer();
    overdueSlaMeasurement($responsible);

    $unresolved = 0;
    Log::listen(function ($message) use (&$unresolved): void {
        if ($message->message === 'SLA evaluation finished with unresolved conditions') {
            $unresolved++;
        }
    });

    $this->artisan('measurements:evaluate-sla')->assertSuccessful();

    // Execução saudável: resumo em INFO e nenhum alarme.
    expect($unresolved)->toBe(0);
});

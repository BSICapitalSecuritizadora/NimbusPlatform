<?php

use App\Models\Measurement;
use App\Models\MeasurementPlanSet;
use App\Models\Operation;
use App\Models\SlaConfiguration;
use App\Models\User;
use App\Services\MeasurementSlaService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * A chave que impede o alerta repetido -- medição, etapa, tipo, destinatário e
 * início do ciclo -- é um índice UNIQUE, e é o banco que decide o que UNIQUE
 * considera igual. Nenhum dos dois compara NULL com NULL, então enquanto
 * `stage_started_at` fosse anulável a chave não protegeria nada naquele estado:
 * linhas idênticas com o início do ciclo nulo coexistiriam.
 *
 * Estes testes medem a invariante nas três camadas em que ela agora existe: a
 * coluna é NOT NULL, a chave recusa o ciclo repetido, e a avaliação sem relógio
 * de etapa não emite alerta nenhum -- de modo que a aplicação nunca chega a
 * tentar a linha que o schema recusa. Rodam nos dois bancos, e a resposta é a
 * mesma nos dois.
 */
pest()->group('parity');

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
    config(['measurements.sla.calendar_code' => null]);
});

/** @param array<string, mixed> $overrides */
function slaAlertRow(array $overrides = []): array
{
    return array_merge([
        'stage' => 2,
        'alert_type' => 'warning',
        'stage_started_at' => '2026-08-01 09:00:00',
        'business_day' => '2026-08-31',
        'notified_at' => '2026-08-31 10:00:00',
        'created_at' => '2026-08-31 10:00:00',
        'updated_at' => '2026-08-31 10:00:00',
    ], $overrides);
}

function slaAlertMeasurement(User $responsible, int $stage = 2): Measurement
{
    $operation = Operation::factory()->create([
        'assigned_user_id' => $responsible->getKey(),
        'responsible_user_id' => $responsible->getKey(),
        'stage2_reviewer_user_id' => $responsible->getKey(),
        'stage3_reviewer_user_id' => $responsible->getKey(),
        'payment_manager_user_id' => $responsible->getKey(),
        'payment_receipt_uploader_user_id' => $responsible->getKey(),
        'payment_finalizer_user_id' => $responsible->getKey(),
    ]);
    MeasurementPlanSet::factory()->create(['operation_id' => $operation->getKey()]);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->getKey(),
        'status' => 'in_review',
        'current_stage' => $stage,
    ]);

    foreach (range(1, $stage - 1) as $approved) {
        $measurement->reviews()->create([
            'stage' => $approved,
            'status' => 'approved',
            'reviewer_user_id' => $responsible->getKey(),
        ]);
    }

    $measurement->reviews()->create(['stage' => $stage, 'status' => 'pending']);

    return $measurement;
}

it('rejects a second alert for the same recipient and cycle', function () {
    $recipient = User::factory()->create();
    $measurement = slaAlertMeasurement($recipient);
    $row = slaAlertRow([
        'measurement_id' => $measurement->getKey(),
        'recipient_user_id' => $recipient->getKey(),
    ]);

    DB::table('measurement_sla_alerts')->insert($row);

    expect(fn () => DB::table('measurement_sla_alerts')->insert($row))
        ->toThrow(QueryException::class)
        ->and(DB::table('measurement_sla_alerts')->count())->toBe(1);
});

it('accepts alerts of the same cycle for different recipients', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();
    $measurement = slaAlertMeasurement($first);
    $row = slaAlertRow(['measurement_id' => $measurement->getKey()]);

    DB::table('measurement_sla_alerts')->insert($row + ['recipient_user_id' => $first->getKey()]);
    DB::table('measurement_sla_alerts')->insert($row + ['recipient_user_id' => $second->getKey()]);

    expect(DB::table('measurement_sla_alerts')->count())->toBe(2);
});

it('refuses an alert without a cycle start', function () {
    $recipient = User::factory()->create();
    $measurement = slaAlertMeasurement($recipient);
    $row = slaAlertRow([
        'measurement_id' => $measurement->getKey(),
        'recipient_user_id' => $recipient->getKey(),
        'stage_started_at' => null,
    ]);

    // Enquanto a coluna aceitava NULL, duas linhas assim coexistiam: nenhum dos
    // dois bancos compara NULL com NULL, e a chave do ciclo não protegia nada
    // naquele estado. Agora a linha não chega a existir.
    expect(fn () => DB::table('measurement_sla_alerts')->insert($row))
        ->toThrow(QueryException::class)
        ->and(DB::table('measurement_sla_alerts')->count())->toBe(0);
});

it('never writes an alert whose cycle start is null', function () {
    SlaConfiguration::factory()->forStage(2, 1)->create();
    $recipient = User::factory()->create();
    $recipient->givePermissionTo(['measurements.view', 'operations.view', 'measurements.review']);
    $measurement = slaAlertMeasurement($recipient);

    // Sem `created_at` na etapa nem na medição não há relógio de onde partir: é
    // o único estado em que `stage_started_at` sairia nulo.
    DB::table('measurement_reviews')
        ->where('measurement_id', $measurement->getKey())
        ->update(['created_at' => null, 'updated_at' => null]);
    DB::table('measurements')
        ->where('id', $measurement->getKey())
        ->update(['created_at' => null, 'updated_at' => null]);

    $this->artisan('measurements:evaluate-sla')->assertSuccessful();

    expect(DB::table('measurement_sla_alerts')->count())->toBe(0)
        ->and(app(MeasurementSlaService::class)->evaluate($measurement->fresh()))
        ->toMatchArray([
            'stage' => 2,
            'started_at' => null,
            'status' => MeasurementSlaService::STATUS_NOT_CONFIGURED,
        ]);
});

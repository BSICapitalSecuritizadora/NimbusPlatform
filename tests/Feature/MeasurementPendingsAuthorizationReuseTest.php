<?php

use App\Enums\MeasurementResponsibility;
use App\Models\Measurement;
use App\Models\Operation;
use App\Models\ResponsibilityDelegation;
use App\Models\SlaConfiguration;
use App\Models\User;
use App\Services\MeasurementAuthorizationService;
use App\Services\MeasurementPendingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * My Pendings resolve a autorização de cada pendência uma vez só e reutiliza a
 * mesma resolução no payload. Estes testes provam as duas metades: que o
 * contexto de delegação exibido é exatamente o que a resolução legada devolvia,
 * e que a contagem de consultas parou de crescer com uma segunda resolução.
 */
beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    config(['measurements.sla.calendar_code' => null]);

    foreach (range(1, 5) as $stage) {
        SlaConfiguration::factory()->forStage($stage, 5)->create();
    }
});

function reuseUser(): User
{
    $user = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $user->givePermissionTo([
        'operations.view',
        'measurements.view',
        'measurements.review',
        'measurements.pay',
        'measurements.receipts',
        'measurements.finalize',
    ]);

    return $user;
}

/**
 * Uma medição parada exatamente na etapa de `$responsibility`, com o estado que
 * torna aquela responsabilidade acionável.
 */
function reuseMeasurement(User $holder, MeasurementResponsibility $responsibility, string $status): Measurement
{
    $operation = Operation::factory()->create([
        $responsibility->operationColumn() => $holder->getKey(),
    ]);

    $stage = $responsibility->stage();
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->getKey(),
        'status' => $status,
        'current_stage' => $status === 'paused' ? $stage : match ($status) {
            'awaiting_payment' => 4,
            'awaiting_receipt', 'approved' => 5,
            default => $stage,
        },
    ]);

    $measurement->reviews()->create([
        'stage' => $stage,
        'status' => 'pending',
        'paused_at' => $status === 'paused' ? now()->subDay() : null,
        'created_at' => now()->subDays(3),
    ]);

    if ($status === 'paused') {
        $measurement->pauses()->create([
            'stage' => $stage,
            'paused_by' => $holder->getKey(),
            'pause_reason' => 'teste',
            'paused_operation_status' => 'in_review',
            'paused_at' => now()->subDay(),
        ]);
    }

    return $measurement;
}

it('mantém rótulo, link e contexto de delegação de cada responsabilidade acionável', function (
    string $responsibilityValue,
    string $status,
    string $expectedLabel,
) {
    $responsibility = MeasurementResponsibility::from($responsibilityValue);
    $responsible = reuseUser();
    $measurement = reuseMeasurement($responsible, $responsibility, $status);

    $summary = app(MeasurementPendingService::class)->summaryFor($responsible);

    expect($summary['count'])->toBe(1)
        ->and($summary['delegated_count'])->toBe(0)
        ->and($summary['items'][0]['measurement_id'])->toBe($measurement->getKey())
        ->and($summary['items'][0]['action_label'])->toBe($expectedLabel)
        ->and($summary['items'][0]['responsibility_label'])->toBe($responsibility->label())
        ->and($summary['items'][0]['delegated'])->toBeFalse()
        ->and($summary['items'][0]['delegation_id'])->toBeNull()
        ->and($summary['items'][0]['url'])->toContain('/measurements/'.$measurement->getKey());
})->with([
    ['engineering_reviewer', 'in_review', 'Revisão de Engenharia'],
    ['payment_manager', 'awaiting_payment', 'Registrar e aprovar pagamento'],
    ['receipt_uploader', 'awaiting_receipt', 'Enviar comprovante'],
    ['finalizer', 'approved', 'Finalizar medição'],
    ['engineering_reviewer', 'paused', 'Retomar etapa'],
]);

it('exibe o mesmo contexto de delegação que a resolução legada devolvia', function (
    string $responsibilityValue,
    string $status,
) {
    $responsibility = MeasurementResponsibility::from($responsibilityValue);
    $responsible = reuseUser();
    $delegate = reuseUser();
    $measurement = reuseMeasurement($responsible, $responsibility, $status);

    $delegation = ResponsibilityDelegation::factory()->active()->forOperation($measurement->operation)->create([
        'delegator_user_id' => $responsible->getKey(),
        'delegate_user_id' => $delegate->getKey(),
    ]);

    $summary = app(MeasurementPendingService::class)->summaryFor($delegate);

    // A referência é o caminho que o payload usava antes de reaproveitar a
    // resolução da decisão. Ele continua existindo e não foi alterado: se as
    // duas divergirem, a otimização mudou o que a tela mostra.
    $legacyDelegation = app(MeasurementAuthorizationService::class)
        ->activeDelegationFor($delegate, $measurement->fresh(), $responsibility);

    expect($summary['count'])->toBe(1)
        ->and($summary['delegated_count'])->toBe(1)
        ->and($summary['items'][0]['delegated'])->toBeTrue()
        ->and($summary['items'][0]['delegation_id'])->toBe($delegation->getKey())
        ->and($summary['items'][0]['delegation_id'])->toBe($legacyDelegation?->getKey())
        ->and($summary['items'][0]['delegator_name'])->toBe($responsible->name)
        ->and($summary['items'][0]['delegation_ends_at']?->toDateTimeString())
        ->toBe($delegation->ends_at->toDateTimeString());
})->with([
    ['engineering_reviewer', 'in_review'],
    ['payment_manager', 'awaiting_payment'],
    ['receipt_uploader', 'awaiting_receipt'],
    ['finalizer', 'approved'],
    ['engineering_reviewer', 'paused'],
]);

it('não atribui contexto de delegação ao administrador, como antes da reutilização', function () {
    $responsible = reuseUser();
    $administrator = reuseUser();
    $administrator->assignRole('admin');
    $measurement = reuseMeasurement($responsible, MeasurementResponsibility::EngineeringReviewer, 'in_review');

    ResponsibilityDelegation::factory()->active()->forOperation($measurement->operation)->create([
        'delegator_user_id' => $responsible->getKey(),
        'delegate_user_id' => $administrator->getKey(),
    ]);

    $summary = app(MeasurementPendingService::class)->summaryFor($administrator->fresh());
    $legacyDelegation = app(MeasurementAuthorizationService::class)->activeDelegationFor(
        $administrator->fresh(),
        $measurement->fresh(),
        MeasurementResponsibility::EngineeringReviewer,
    );

    expect($summary['count'])->toBe(1)
        ->and($summary['delegated_count'])->toBe(0)
        ->and($summary['items'][0]['delegated'])->toBeFalse()
        ->and($summary['items'][0]['delegation_id'])->toBeNull()
        ->and($legacyDelegation)->toBeNull();
});

it('lista cada medição uma única vez quando várias responsabilidades são do mesmo ator', function () {
    $responsible = reuseUser();
    $operation = Operation::factory()->create(array_fill_keys(
        array_map(
            fn (MeasurementResponsibility $responsibility): string => $responsibility->operationColumn(),
            MeasurementResponsibility::cases(),
        ),
        $responsible->getKey(),
    ));

    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->getKey(),
        'status' => 'in_review',
        'current_stage' => 1,
    ]);
    $measurement->reviews()->create(['stage' => 1, 'status' => 'pending', 'created_at' => now()->subDay()]);

    $summary = app(MeasurementPendingService::class)->summaryFor($responsible, previewLimit: 10);

    expect($summary['count'])->toBe(1)
        ->and(collect($summary['items'])->pluck('measurement_id')->all())->toBe([$measurement->getKey()]);
});

it('não resolve a autorização de uma pendência delegada duas vezes', function () {
    $responsible = reuseUser();
    $delegate = reuseUser();

    $operation = Operation::factory()->create([
        'responsible_user_id' => $responsible->getKey(),
    ]);
    ResponsibilityDelegation::factory()->active()->forOperation($operation)->create([
        'delegator_user_id' => $responsible->getKey(),
        'delegate_user_id' => $delegate->getKey(),
    ]);

    $measure = function (int $volume) use ($operation, $delegate): int {
        Measurement::query()->delete();

        foreach (range(1, $volume) as $index) {
            $measurement = Measurement::factory()->create([
                'operation_id' => $operation->getKey(),
                'status' => 'in_review',
                'current_stage' => 1,
            ]);
            $measurement->reviews()->create([
                'stage' => 1,
                'status' => 'pending',
                'created_at' => now()->subDays(3),
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $summary = app(MeasurementPendingService::class)->summaryFor($delegate);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();

        expect($summary['count'])->toBe($volume)
            ->and($summary['delegated_count'])->toBe($volume);

        return $queries;
    };

    $small = $measure(8);
    $large = $measure(24);

    // Cada pendência delegada custa uma resolução de delegação -- não duas. A
    // margem cobre o custo fixo do lote (medições, operação, reviews, SLA), que
    // não acompanha o volume.
    expect($large - $small)->toBeLessThanOrEqual(24 - 8 + 4);
});

<?php

use App\Enums\MeasurementResponsibility;
use App\Models\BusinessCalendar;
use App\Models\Measurement;
use App\Models\Operation;
use App\Models\ResponsibilityDelegation;
use App\Models\SlaConfiguration;
use App\Models\User;
use App\Services\MeasurementPendingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    config(['measurements.sla.calendar_code' => null]);
    SlaConfiguration::factory()->forStage(1, 5)->create();
});

function scaleUser(): User
{
    $user = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $user->givePermissionTo(['operations.view', 'measurements.view', 'measurements.review']);

    return $user;
}

/**
 * Medições em massa numa operação, inseridas direto na tabela: o que este
 * arquivo exercita é o custo de percorrê-las, não o de criá-las.
 */
function scaleMeasurements(Operation $operation, int $volume): void
{
    $rows = [];
    $now = now();

    for ($index = 0; $index < $volume; $index++) {
        $rows[] = [
            'operation_id' => $operation->getKey(),
            'reference_month' => $now->copy()->subMonths($index % 12)->format('Y-m-01'),
            'filename' => "scale-{$index}.pdf",
            'storage_path' => "measurements/scale-{$index}.pdf",
            'status' => 'in_review',
            'current_stage' => 1,
            'workflow_revision' => 0,
            'uploaded_at' => $now,
            'created_at' => $now->copy()->subDays(3),
            'updated_at' => $now,
        ];
    }

    foreach (array_chunk($rows, 200) as $chunk) {
        DB::table('measurements')->insert($chunk);
    }

    $reviews = Measurement::query()
        ->where('operation_id', $operation->getKey())
        ->pluck('id')
        ->map(fn (int $id): array => [
            'measurement_id' => $id,
            'stage' => 1,
            'status' => 'pending',
            'created_at' => $now->copy()->subDays(3),
            'updated_at' => $now,
        ])
        ->all();

    foreach (array_chunk($reviews, 200) as $chunk) {
        DB::table('measurement_reviews')->insert($chunk);
    }
}

/** @return array{queries: int, summary: array<string, mixed>} */
function scaleProbe(User $user): array
{
    app()->forgetInstance(MeasurementPendingService::class);
    DB::flushQueryLog();
    DB::enableQueryLog();

    $summary = app(MeasurementPendingService::class)->summaryFor($user);

    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();
    DB::flushQueryLog();

    return ['queries' => $queries, 'summary' => $summary];
}

/**
 * O chunk de `lazyById` é 100: abaixo disso a paginação nunca precisa do último
 * id e o defeito ficava invisível. Com o alias errado, esta carga estourava
 * RuntimeException em vez de listar as pendências.
 */
it('percorre mais medições do que cabem em um chunk sem quebrar a paginação', function () {
    $user = scaleUser();
    $operation = Operation::factory()->create(['responsible_user_id' => $user->getKey()]);
    scaleMeasurements($operation, 150);

    $probe = scaleProbe($user);

    expect($probe['summary']['count'])->toBe(150)
        ->and($probe['summary']['items'])->toHaveCount(3);
});

it('não faz o número de consultas crescer junto com o volume de pendências diretas', function () {
    $user = scaleUser();
    $operation = Operation::factory()->create(['responsible_user_id' => $user->getKey()]);

    scaleMeasurements($operation, 20);
    $small = scaleProbe($user);

    Measurement::query()->delete();
    scaleMeasurements($operation, 220);
    $large = scaleProbe($user);

    expect($small['summary']['count'])->toBe(20)
        ->and($large['summary']['count'])->toBe(220)
        // O crescimento é o da paginação (um punhado de chunks), não o do volume.
        ->and($large['queries'] - $small['queries'])->toBeLessThan(20);
});

it('mantém a resolução de delegação dentro de um orçamento por pendência', function () {
    $principal = scaleUser();
    $delegate = scaleUser();
    $operation = Operation::factory()->create(['responsible_user_id' => $principal->getKey()]);
    ResponsibilityDelegation::factory()->active()->forOperation($operation)->create([
        'delegator_user_id' => $principal->getKey(),
        'delegate_user_id' => $delegate->getKey(),
    ]);
    $volume = 40;
    scaleMeasurements($operation, $volume);

    $probe = scaleProbe($delegate);

    // Antes da P2.4 eram ~14 consultas por pendência delegada, quase todas
    // eager loads de roles/permissions que ninguém lia. A margem cobre a
    // resolução em si sem deixar aquele patamar voltar despercebido.
    expect($probe['summary']['count'])->toBe($volume)
        ->and($probe['summary']['delegated_count'])->toBe($volume)
        ->and($probe['queries'])->toBeLessThan($volume * 4);
});

/**
 * Custo medido e aceito na P2.4, não um descuido: o ano de calendário ausente é
 * reconsultado a cada medição porque a resposta negativa não pode ser
 * memorizada -- materializar o ano no meio da execução tem de passar a valer na
 * avaliação seguinte, e há teste de SLA exigindo exatamente isso. Quem for
 * "otimizar" isto precisa antes decidir por quanto tempo uma negativa vale.
 */
it('reconsulta o calendário ausente a cada medição, preservando a recalculação imediata', function () {
    config(['measurements.sla.calendar_code' => 'SCALE_CALENDAR']);
    BusinessCalendar::factory()->create(['code' => 'SCALE_CALENDAR']);

    $user = scaleUser();
    $operation = Operation::factory()->create(['responsible_user_id' => $user->getKey()]);
    scaleMeasurements($operation, 40);

    DB::flushQueryLog();
    DB::enableQueryLog();
    app()->forgetInstance(MeasurementPendingService::class);
    $summary = app(MeasurementPendingService::class)->summaryFor($user);
    $calendarQueries = count(array_filter(
        DB::getQueryLog(),
        fn (array $entry): bool => str_contains((string) $entry['query'], 'business_calendar_years'),
    ));
    DB::disableQueryLog();

    // O calendário em si é verificado uma vez só; o ano é que não.
    expect($summary['count'])->toBe(40)
        ->and($calendarQueries)->toBe(40);
});

it('preserva a semântica das pendências ao percorrer um volume grande', function () {
    $principal = scaleUser();
    $delegate = scaleUser();
    $outsider = scaleUser();

    $directOperation = Operation::factory()->create(['responsible_user_id' => $delegate->getKey()]);
    scaleMeasurements($directOperation, 60);

    $delegatedOperation = Operation::factory()->create(['responsible_user_id' => $principal->getKey()]);
    ResponsibilityDelegation::factory()->active()->forOperation($delegatedOperation)->create([
        'delegator_user_id' => $principal->getKey(),
        'delegate_user_id' => $delegate->getKey(),
    ]);
    scaleMeasurements($delegatedOperation, 60);

    $summary = app(MeasurementPendingService::class)->summaryFor($delegate);
    $item = $summary['items'][0];

    expect($summary['count'])->toBe(120)
        // Participação direta prevalece; só as 60 delegadas contam como delegadas.
        ->and($summary['delegated_count'])->toBe(60)
        ->and($summary['items'])->toHaveCount(3)
        ->and($item['responsibility_label'])->toBe(MeasurementResponsibility::EngineeringReviewer->label())
        ->and($item['url'])->toContain((string) $item['measurement_id'])
        ->and(collect($summary['items'])->pluck('measurement_id')->unique())->toHaveCount(3)
        ->and(app(MeasurementPendingService::class)->summaryFor($outsider)['count'])->toBe(0);
});

it('mantém o nome do delegante no payload mesmo sem pré-carregar a relação', function () {
    $principal = scaleUser();
    $delegate = scaleUser();
    $operation = Operation::factory()->create(['responsible_user_id' => $principal->getKey()]);
    ResponsibilityDelegation::factory()->active()->forOperation($operation)->create([
        'delegator_user_id' => $principal->getKey(),
        'delegate_user_id' => $delegate->getKey(),
    ]);
    scaleMeasurements($operation, 5);

    $summary = app(MeasurementPendingService::class)->summaryFor($delegate);

    expect($summary['items'][0]['delegated'])->toBeTrue()
        ->and($summary['items'][0]['delegator_name'])->toBe($principal->name)
        ->and($summary['items'][0]['delegation_ends_at'])->not->toBeNull();
});

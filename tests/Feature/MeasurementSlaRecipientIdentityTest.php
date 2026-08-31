<?php

use App\Models\Measurement;
use App\Models\Operation;
use App\Models\ResponsibilityDelegation;
use App\Models\SlaConfiguration;
use App\Models\User;
use App\Notifications\MeasurementSlaNotification;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * O comando de SLA resolve cada usuário uma vez por execução, num mapa de
 * identidade local. Estes testes provam que isso não muda quem é alertado:
 * a efetividade continua sendo decidida por operação e responsabilidade, e o
 * mesmo usuário pode ser direto numa medição e delegado em outra dentro da
 * MESMA execução.
 */
beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
    config(['measurements.sla.calendar_code' => null]);
    CarbonImmutable::setTestNow('2026-09-01 01:00:00');

    foreach (range(1, 5) as $stage) {
        SlaConfiguration::factory()->forStage($stage, 5)->create();
    }

    SlaConfiguration::query()->where('stage', 1)->update(['duration_value' => 1]);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function slaReviewer(): User
{
    $user = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $user->givePermissionTo('measurements.review');

    return $user;
}

/** Uma medição de etapa 1 já vencida, sob o responsável direto informado. */
function overdueMeasurementFor(User $responsible): Measurement
{
    $operation = Operation::factory()->create(['responsible_user_id' => $responsible->getKey()]);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->getKey(),
        'status' => 'in_review',
        'current_stage' => 1,
    ]);
    $measurement->reviews()->create(['stage' => 1, 'status' => 'pending']);
    $measurement->reviews()->update(['created_at' => '2026-08-31 00:00:00']);

    return $measurement->fresh(['operation']);
}

/** @return list<int> */
function alertRecipientsFor(Measurement $measurement): array
{
    return DB::table('measurement_sla_alerts')
        ->where('measurement_id', $measurement->getKey())
        ->orderBy('recipient_user_id')
        ->pluck('recipient_user_id')
        ->map(fn ($id): int => (int) $id)
        ->all();
}

it('distingue participação direta de indicação delegada para o mesmo usuário na mesma execução', function () {
    $actor = slaReviewer();
    $otherResponsible = slaReviewer();

    // O ator é responsável direto aqui...
    $direct = overdueMeasurementFor($actor);

    // ...e delegado ali, na mesma execução do comando.
    $delegated = overdueMeasurementFor($otherResponsible);
    $delegation = ResponsibilityDelegation::factory()->active()->forOperation($delegated->operation)->create([
        'delegator_user_id' => $otherResponsible->getKey(),
        'delegate_user_id' => $actor->getKey(),
    ]);

    $this->artisan('measurements:evaluate-sla')->assertSuccessful();

    expect(alertRecipientsFor($direct))->toBe([$actor->getKey()])
        ->and(alertRecipientsFor($delegated))
        ->toBe(collect([$otherResponsible->getKey(), $actor->getKey()])->sort()->values()->all());

    // O mapa reutiliza a identidade do usuário, nunca o contexto de delegação:
    // a mesma pessoa é alertada sem delegação numa medição e com a delegação
    // correta na outra.
    Notification::assertSentTo(
        $actor,
        MeasurementSlaNotification::class,
        function (MeasurementSlaNotification $notification) use ($direct): bool {
            return $notification->measurement->is($direct) && $notification->delegation === null;
        },
    );

    Notification::assertSentTo(
        $actor,
        MeasurementSlaNotification::class,
        function (MeasurementSlaNotification $notification) use ($delegated, $delegation): bool {
            return $notification->measurement->is($delegated)
                && $notification->delegation?->getKey() === $delegation->getKey()
                && $notification->delegation->delegator->is($delegation->delegator);
        },
    );
});

it('deduplica o destinatário que é responsável direto e delegado da mesma responsabilidade', function () {
    $responsible = slaReviewer();
    $measurement = overdueMeasurementFor($responsible);

    ResponsibilityDelegation::factory()->active()->forOperation($measurement->operation)->create([
        'delegator_user_id' => $responsible->getKey(),
        'delegate_user_id' => $responsible->getKey(),
    ]);

    $this->artisan('measurements:evaluate-sla')->assertSuccessful();

    expect(alertRecipientsFor($measurement))->toBe([$responsible->getKey()])
        ->and(DB::table('measurement_sla_alerts')->count())->toBe(1);
});

it('mantém a inefetividade decidida a cada execução, e não pela primeira resolução do usuário', function (
    string $condition,
) {
    $responsible = slaReviewer();
    $first = overdueMeasurementFor($responsible);
    $second = overdueMeasurementFor($responsible);

    match ($condition) {
        'inactive' => $responsible->update(['is_active' => false]),
        'unapproved' => $responsible->update(['approved_at' => null]),
        'permission_removed' => $responsible->revokePermissionTo('measurements.review'),
    };

    $this->artisan('measurements:evaluate-sla')->assertSuccessful();

    // Nenhuma das duas: o usuário é resolvido uma vez, mas com o estado atual --
    // e uma medição não herda a efetividade avaliada para a outra.
    expect(alertRecipientsFor($first))->toBe([])
        ->and(alertRecipientsFor($second))->toBe([])
        ->and(DB::table('measurement_sla_alerts')->count())->toBe(0);
})->with(['inactive', 'unapproved', 'permission_removed']);

it('percebe a revogação de RBAC ocorrida entre duas execuções do comando', function () {
    $responsible = slaReviewer();
    $measurement = overdueMeasurementFor($responsible);

    $this->artisan('measurements:evaluate-sla')->assertSuccessful();
    expect(alertRecipientsFor($measurement))->toBe([$responsible->getKey()]);

    DB::table('measurement_sla_alerts')->delete();
    $responsible->revokePermissionTo('measurements.review');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // O mapa morre com a execução anterior: a segunda começa do banco.
    $this->artisan('measurements:evaluate-sla')->assertSuccessful();
    expect(alertRecipientsFor($measurement))->toBe([]);
});

it('não busca o mesmo usuário uma vez por medição avaliada', function () {
    $responsible = slaReviewer();
    $delegate = slaReviewer();

    $measure = function (int $volume) use ($responsible, $delegate): int {
        // A delegação sai antes da operação: `scope_operation_id` é RESTRICT, e
        // apagar a operação primeiro esbarra na chave estrangeira.
        ResponsibilityDelegation::query()->delete();
        Measurement::query()->delete();
        Operation::query()->delete();
        DB::table('measurement_sla_alerts')->delete();

        foreach (range(1, $volume) as $index) {
            $measurement = overdueMeasurementFor($responsible);
            ResponsibilityDelegation::factory()->active()->forOperation($measurement->operation)->create([
                'delegator_user_id' => $responsible->getKey(),
                'delegate_user_id' => $delegate->getKey(),
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->artisan('measurements:evaluate-sla', ['--dry-run' => true])->assertSuccessful();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();

        return $queries;
    };

    $small = $measure(6);
    $large = $measure(18);

    // Antes, cada medição custava ~5 consultas: o usuário direto, as suas
    // permissions, a consulta de delegações e os dois principais dela. Só a de
    // delegações depende da operação; as outras quatro eram a mesma pergunta
    // repetida. A margem cobre o custo fixo do lote.
    expect($large - $small)->toBeLessThanOrEqual((18 - 6) * 2);
});

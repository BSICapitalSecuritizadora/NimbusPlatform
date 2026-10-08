<?php

use App\Filament\Resources\Constructions\ConstructionResource;
use App\Filament\Resources\Constructions\Pages\EditConstruction;
use App\Filament\Resources\Constructions\Pages\ListConstructions;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\MeasurementPlanVersion;
use App\Models\Operation;
use App\Models\User;
use App\Services\MeasurementPlanVersionService;
use App\Services\SalesBoards\SalesBoardSourceGuard;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\MeasurementPhysicalProgressScenario;

/**
 * A exclusão da obra que tem plano de medição, pela tela da obra.
 *
 * A FK do plano solta a obra (SET NULL) sem passar pela guarda do plano, e o
 * `deleting` da obra recusa a que tem plano já ativado ou com medição
 * registrada. A policy da obra diz o mesmo antes do clique: a exclusão aparece
 * desabilitada com o motivo, a exclusão em massa conta a recusa com ele, e a
 * recusa do domínio nunca escapa como erro de servidor. Plano só com o
 * rascunho da V1 ainda é planejamento e não prende a obra.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    Notification::fake();
    // A medição enviada guarda o arquivo no disco privado falso.
    config()->set('filesystems.private_disk', 'local');
});

/**
 * A Torre Aurora com plano de medição numa operação em andamento, criado pelo
 * serviço em 15/05/2026: a V1 nasce em rascunho, com 10% previstos por mês de
 * 05/2026 a 07/2026. O administrador está em todos os papéis e logado.
 *
 * @return array{actor: User, emission: Emission, construction: Construction, operation: Operation, planSet: MeasurementPlanSet}
 */
function planConstructionDeletionUiScenario(): array
{
    test()->travelTo(CarbonImmutable::parse('2026-05-15 12:00:00'));

    $actor = makeAdminUser();
    $emission = Emission::factory()->create();
    $construction = Construction::factory()->create(['emission_id' => $emission->id, 'development_name' => 'Torre Aurora']);
    $operation = Operation::factory()->forEmission($emission)->create(array_fill_keys(Operation::RESPONSIBILITY_FIELDS, $actor->id));
    $planSet = app(MeasurementPlanVersionService::class)->createPlan($operation, $actor, [
        'name' => 'Plano Torre Aurora',
        'construction_id' => $construction->id,
        'is_default' => true,
        'initial_incurred_amount' => '0.00',
    ], ['construction_fund_amount' => '1000000.00'], [
        ['sequence_number' => 1, 'planned_monthly_percent' => '10.00', 'planned_cumulative_percent' => '10.00', 'measurement_date' => '2026-05'],
        ['sequence_number' => 2, 'planned_monthly_percent' => '10.00', 'planned_cumulative_percent' => '20.00', 'measurement_date' => '2026-06'],
        ['sequence_number' => 3, 'planned_monthly_percent' => '10.00', 'planned_cumulative_percent' => '30.00', 'measurement_date' => '2026-07'],
    ]);
    test()->actingAs($actor);

    return compact('actor', 'emission', 'construction', 'operation', 'planSet');
}

/**
 * Ativa a V1 pelo serviço, com o contador que a pessoa viu.
 *
 * @param  array{actor: User, planSet: MeasurementPlanSet}  $scenario
 */
function planConstructionDeletionUiActivate(array $scenario): MeasurementPlanVersion
{
    $draft = $scenario['planSet']->draftVersion()->firstOrFail();

    return app(MeasurementPlanVersionService::class)->activate($draft, $scenario['actor'], (int) $draft->revision);
}

/**
 * As linhas do que a exclusão da obra alcançaria -- a obra, os planos, as
 * versões e as linhas --, exatamente como estão no banco.
 *
 * @return array<string, list<array<string, mixed>>>
 */
function planConstructionDeletionUiFootprint(): array
{
    $rows = fn (string $table): array => DB::table($table)
        ->orderBy('id')
        ->get()
        ->map(fn (object $row): array => (array) $row)
        ->all();

    return [
        'constructions' => $rows('constructions'),
        'measurement_plan_sets' => $rows('measurement_plan_sets'),
        'measurement_plan_versions' => $rows('measurement_plan_versions'),
        'measurement_plan_lines' => $rows('measurement_plan_lines'),
    ];
}

/**
 * Notificações do Filament na sessão, lidas sem consumir -- o
 * `assertNotified()` compara só o título e esvazia a sessão.
 *
 * @return list<array{title: string, body: string|null, status: string|null}>
 */
function planConstructionDeletionUiNotifications(): array
{
    $notifications = session()->get('filament.claimed_notifications') ?? session()->get('filament.notifications') ?? [];

    return array_values(array_map(fn (array $notification): array => [
        'title' => (string) ($notification['title'] ?? ''),
        'body' => isset($notification['body']) ? (string) $notification['body'] : null,
        'status' => $notification['status'] ?? null,
    ], $notifications));
}

function planConstructionDeletionUiForgetNotifications(): void
{
    session()->forget(['filament.notifications', 'filament.claimed_notifications']);
}

it('disables the deletion of a construction whose plan already took effect, with the reason and without an error escaping', function (string $history, string $reason) {
    $scenario = planConstructionDeletionUiScenario();
    $v1 = planConstructionDeletionUiActivate($scenario);
    $measured = [
        ...$scenario,
        'lines' => $v1->lines()->get()->keyBy(fn (MeasurementPlanLine $line): string => $line->measurement_date->format('Y-m'))->all(),
    ];

    match ($history) {
        'plano ativado, sem medição' => null,
        'medição aguardando a Engenharia' => MeasurementPhysicalProgressScenario::measurement($measured, '2026-05'),
        'medição aprovada pela Engenharia' => MeasurementPhysicalProgressScenario::measured($measured, '2026-05', 10),
    };

    $message = sprintf('A obra não pode ser excluída: %s.', $reason);
    $before = planConstructionDeletionUiFootprint();
    planConstructionDeletionUiForgetNotifications();

    Livewire::test(EditConstruction::class, ['record' => $scenario['construction']->getRouteKey()])
        ->assertActionVisible(DeleteAction::class)
        ->assertActionDisabled(DeleteAction::class)
        ->assertActionExists(DeleteAction::class, fn (Action $action): bool => $action->getTooltip() === $message)
        // O clique no botão desabilitado não monta a exclusão...
        ->callAction(DeleteAction::class)
        ->assertActionNotMounted()
        // ...e nem a requisição forjada chega ao `deleting` da obra, cuja
        // recusa escaparia como erro de servidor.
        ->set('mountedActions', [['name' => 'delete', 'arguments' => [], 'context' => []]])
        ->call('callMountedAction')
        ->assertSuccessful();

    expect(app(SalesBoardSourceGuard::class)->constructionDeletionBlockers($scenario['construction']->fresh()))->toBe([$reason])
        ->and(ConstructionResource::getDeleteAuthorizationResponse($scenario['construction']->fresh())->message())->toBe($message)
        ->and(planConstructionDeletionUiFootprint())->toBe($before)
        ->and($scenario['planSet']->fresh()->construction_id)->toBe($scenario['construction']->id)
        ->and(planConstructionDeletionUiNotifications())->toBe([]);
})->with([
    'plano ativado, sem medição' => ['plano ativado, sem medição', 'tem plano de medição já ativado ou com medição registrada'],
    'medição aguardando a Engenharia' => ['medição aguardando a Engenharia', 'tem plano de medição já ativado ou com medição registrada'],
    // A aprovação da Engenharia é motivo próprio: a frase não repete o plano.
    'medição aprovada pela Engenharia' => ['medição aprovada pela Engenharia', 'tem medição aprovada pela Engenharia'],
]);

it('keeps a construction whose plan took effect out of the bulk deletion, telling why', function () {
    $scenario = planConstructionDeletionUiScenario();
    planConstructionDeletionUiActivate($scenario);
    $free = Construction::factory()->create(['emission_id' => $scenario['emission']->id, 'development_name' => 'Torre Livre']);
    planConstructionDeletionUiForgetNotifications();

    Livewire::test(ListConstructions::class)
        ->callTableBulkAction('delete', [$scenario['construction'], $free])
        ->assertSuccessful();

    // A obra sem plano sai; a do plano em vigor fica, e o aviso diz o motivo
    // em vez do genérico "não pôde ser excluída".
    expect(planConstructionDeletionUiNotifications())->toBe([[
        'title' => 'Excluídos 1 de 2',
        'body' => '<p>A obra não pode ser excluída: tem plano de medição já ativado ou com medição registrada.</p>',
        'status' => 'warning',
    ]])
        ->and(Construction::query()->whereKey($scenario['construction']->id)->exists())->toBeTrue()
        ->and(Construction::query()->whereKey($free->id)->exists())->toBeFalse()
        ->and($scenario['planSet']->fresh()->construction_id)->toBe($scenario['construction']->id);
});

it('deletes from the edit page a construction whose plan only has the V1 draft, releasing the plan', function () {
    $scenario = planConstructionDeletionUiScenario();
    $v1 = $scenario['planSet']->draftVersion()->firstOrFail();
    $versionBefore = (array) DB::table('measurement_plan_versions')->where('id', $v1->id)->sole();
    $linesBefore = planConstructionDeletionUiFootprint()['measurement_plan_lines'];
    planConstructionDeletionUiForgetNotifications();

    Livewire::test(EditConstruction::class, ['record' => $scenario['construction']->getRouteKey()])
        ->assertActionEnabled(DeleteAction::class)
        ->assertActionExists(DeleteAction::class, fn (Action $action): bool => $action->getTooltip() === null)
        ->callAction(DeleteAction::class)
        ->assertHasNoActionErrors()
        ->assertRedirect(ConstructionResource::getUrl('index'));

    // O rascunho da V1 ainda é planejamento: a obra sai, o plano fica sem ela
    // e a V1, com o cronograma, não muda.
    expect(planConstructionDeletionUiNotifications())->toBe([['title' => 'Excluído', 'body' => null, 'status' => 'success']])
        ->and(Construction::query()->whereKey($scenario['construction']->id)->exists())->toBeFalse()
        ->and($scenario['planSet']->fresh()->construction_id)->toBeNull()
        ->and((array) DB::table('measurement_plan_versions')->where('id', $v1->id)->sole())->toBe($versionBefore)
        ->and(planConstructionDeletionUiFootprint()['measurement_plan_lines'])->toBe($linesBefore);
});

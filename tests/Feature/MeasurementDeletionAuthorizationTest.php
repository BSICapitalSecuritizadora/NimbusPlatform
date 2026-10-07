<?php

use App\Exceptions\MeasurementWorkflowException;
use App\Filament\Resources\Emissions\Pages\EditEmission;
use App\Filament\Resources\Emissions\Pages\ListEmissions;
use App\Filament\Resources\Measurements\MeasurementResource;
use App\Filament\Resources\Measurements\Pages\EditMeasurement;
use App\Filament\Resources\Measurements\Pages\ListMeasurements;
use App\Filament\Resources\Operations\Pages\ViewOperation;
use App\Filament\Resources\Operations\RelationManagers\PlanSetsRelationManager;
use App\Models\Emission;
use App\Models\Measurement;
use App\Models\MeasurementAsset;
use App\Models\MeasurementPause;
use App\Models\MeasurementPayment;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\MeasurementReview;
use App\Models\Operation;
use App\Models\ResponsibilityDelegation;
use App\Models\User;
use App\Services\MeasurementWorkflow;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\MeasurementPhysicalProgressScenario;
use Tests\Support\MeasurementReceiptEvidenceScenario;

uses(RefreshDatabase::class);

/**
 * A exclusão de medição saiu da interface -- nem em massa na lista, nem pela
 * página de edição -- e virou invariante do model: a medição que entrou no
 * fluxo de análise não se apaga por nenhum caminho Eloquent.
 *
 * Os dois furos fechados aqui: a ação em massa, que o Filament 5 autoriza só
 * por `deleteAny` -- método que a policy não tinha, e a ausência virava
 * "liberado" para qualquer um que abrisse a lista --, e a exclusão pelo
 * super-admin, que passa pelo `Gate::before` sem consultar as cláusulas de
 * integridade da policy.
 *
 * O que se confere é o dado -- medição, análises, arquivos, pausas e pagamento
 * continuam lá --, não só o botão. Quem está só na lista "Notificar em caso de
 * recusa" fica de fora dos perfis de propósito: essa lista deixa de dar acesso
 * à medição.
 */
beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    Notification::fake();
});

function measurementDeletionUser(Role|string $role): User
{
    $user = User::factory()->withTwoFactor()->create();
    $user->assignRole($role);

    return $user;
}

/**
 * Quem tenta excluir e a operação que essa pessoa enxerga, com o acesso que o
 * perfil tem de verdade: super-admin e admin enxergam todas as operações; o
 * editor e o papel só de visualização participam da operação; o delegado
 * enxerga por uma delegação ativa do responsável pela Engenharia.
 *
 * @return array{actor: User, operation: Operation}
 */
function measurementDeletionAccess(string $profile): array
{
    $engineering = measurementDeletionUser('editor');
    $actor = measurementDeletionUser(match ($profile) {
        'super-admin', 'admin' => $profile,
        'editor', 'delegate' => 'editor',
        'viewer' => Role::firstOrCreate(['name' => 'measurement-deletion-viewer'])->syncPermissions(['measurements.view']),
    });
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $operation = Operation::factory()->create([
        'responsible_user_id' => $engineering->id,
        'assigned_user_id' => in_array($profile, ['editor', 'viewer'], true) ? $actor->id : $engineering->id,
    ]);

    if ($profile === 'delegate') {
        ResponsibilityDelegation::factory()->active()->forOperation($operation)->create([
            'delegator_user_id' => $engineering->id,
            'delegate_user_id' => $actor->id,
        ]);
    }

    return compact('actor', 'operation');
}

function measurementDeletionAttachFile(Measurement $measurement): MeasurementAsset
{
    $path = "nimbus_docs/measurements/assets/deletion-{$measurement->id}.pdf";
    Storage::disk('local')->put($path, '%PDF-1.7 medição de teste');

    return $measurement->assets()->create(['storage_path' => $path, 'storage_disk' => 'local']);
}

/**
 * Uma medição como a interface a deixa: o envio abre a análise da Engenharia
 * na mesma transação da criação, então toda medição da tela já nasce com
 * histórico de fluxo.
 */
function measurementDeletionInReview(Operation $operation): Measurement
{
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->id,
        'storage_path' => null,
        'status' => 'in_review',
        'current_stage' => 1,
    ]);
    $measurement->reviews()->create(['stage' => 1, 'status' => 'pending']);
    measurementDeletionAttachFile($measurement);

    return $measurement;
}

/**
 * A medição parada no ponto do fluxo que o caso descreve. Quando o
 * MeasurementWorkflow alcança o estado com pouco preparo, é ele quem leva a
 * medição até lá; a aprovação da Engenharia, que exige o cronograma completo,
 * vem gravada direto.
 */
function measurementDeletionInState(string $state): Measurement
{
    if ($state === 'returned_with_payment') {
        $scenario = MeasurementReceiptEvidenceScenario::open();
        app(MeasurementWorkflow::class)->returnToStage($scenario['measurement']->fresh(), $scenario['actor'], 1, 'Correção do arquivo da Engenharia.');

        return $scenario['measurement']->fresh();
    }

    $engineering = measurementDeletionUser('editor');
    $operation = Operation::factory()->create([
        'responsible_user_id' => $engineering->id,
        'assigned_user_id' => $engineering->id,
    ]);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->id,
        'storage_path' => null,
        'status' => 'pending',
        'current_stage' => 1,
        'uploaded_by' => $engineering->id,
    ]);
    measurementDeletionAttachFile($measurement);

    if ($state === 'engineering_approved') {
        $measurement->reviews()->create(['stage' => 1, 'status' => 'approved', 'reviewer_user_id' => $engineering->id, 'reviewed_at' => now()]);
        $measurement->reviews()->create(['stage' => 2, 'status' => 'pending']);
        $measurement->forceFill(['status' => 'in_review', 'current_stage' => 2])->save();

        return $measurement->fresh();
    }

    $workflow = app(MeasurementWorkflow::class);
    $workflow->startReview($measurement, $engineering);

    if ($state === 'paused') {
        $workflow->pause($measurement->fresh(), $engineering, 'Aguardando a planilha corrigida da construtora.');
    }

    if ($state === 'rejected') {
        $workflow->reject($measurement->fresh(), $engineering, 'Arquivo de outra competência.');
    }

    return $measurement->fresh();
}

/**
 * Tudo o que a exclusão levaria junto pela FK em cascata, sem passar pelos
 * models nem pela trilha.
 *
 * @return array{measurement: bool, reviews: int, assets: int, pauses: int, payments: int}
 */
function measurementDeletionFootprint(Measurement $measurement): array
{
    return [
        'measurement' => Measurement::query()->whereKey($measurement->id)->exists(),
        'reviews' => MeasurementReview::query()->where('measurement_id', $measurement->id)->count(),
        'assets' => MeasurementAsset::query()->where('measurement_id', $measurement->id)->count(),
        'pauses' => MeasurementPause::query()->where('measurement_id', $measurement->id)->count(),
        'payments' => MeasurementPayment::query()->where('measurement_id', $measurement->id)->count(),
    ];
}

/**
 * A exclusão em massa como requisição forjada: a ação é montada à mão no
 * estado do componente, como faria quem manipula o payload do Livewire.
 *
 * @param  list<Measurement>  $measurements
 */
function measurementDeletionForgeBulkDelete(Testable $page, array $measurements): void
{
    $page->selectTableRecords($measurements)
        ->set('mountedActions', [['name' => 'delete', 'arguments' => [], 'context' => ['bulk' => true, 'table' => true]]])
        ->call('callMountedAction');
}

/**
 * O mesmo, para a exclusão do cabeçalho da página de edição.
 */
function measurementDeletionForgePageDelete(Testable $page): void
{
    $page->set('mountedActions', [['name' => 'delete', 'arguments' => [], 'context' => []]])
        ->call('callMountedAction');
}

it('offers no bulk deletion of measurements to any profile, not even through a forged request', function (string $profile) {
    $access = measurementDeletionAccess($profile);
    $measurement = measurementDeletionInReview($access['operation']);
    $before = measurementDeletionFootprint($measurement);
    $this->actingAs($access['actor']);

    expect($before)->toMatchArray(['measurement' => true, 'reviews' => 1, 'assets' => 1]);

    $page = Livewire::test(ListMeasurements::class)->assertCanSeeTableRecords([$measurement]);

    measurementDeletionForgeBulkDelete($page, [$measurement]);

    expect(measurementDeletionFootprint($measurement))->toBe($before);

    $page->assertTableBulkActionDoesNotExist('delete');
})->with([
    'super-admin' => ['super-admin'],
    'admin' => ['admin'],
    'editor participante' => ['editor'],
    'somente visualização' => ['viewer'],
    'delegado' => ['delegate'],
]);

it('offers no deletion on the measurement edit page, for the super-admin too', function (string $profile) {
    $access = measurementDeletionAccess($profile);
    $measurement = measurementDeletionInReview($access['operation']);
    $before = measurementDeletionFootprint($measurement);
    $this->actingAs($access['actor']);

    $page = Livewire::test(EditMeasurement::class, ['record' => $measurement->getRouteKey()])->assertSuccessful();

    measurementDeletionForgePageDelete($page);

    expect(measurementDeletionFootprint($measurement))->toBe($before);

    $page->assertActionDoesNotExist('delete');
})->with([
    'super-admin' => ['super-admin'],
    'admin' => ['admin'],
    'editor participante' => ['editor'],
]);

it('refuses to erase a measurement that entered the workflow, whoever asks', function (string $state, string $status, bool $engineeringApproved, int $payments) {
    $measurement = measurementDeletionInState($state);
    $before = measurementDeletionFootprint($measurement);

    expect($measurement->reviews()->exists())->toBeTrue()
        ->and($measurement->status)->toBe($status)
        ->and($measurement->hasApprovedEngineering())->toBe($engineeringApproved)
        ->and($before['payments'])->toBe($payments);

    $this->actingAs(measurementDeletionUser('super-admin'));

    expect(fn () => $measurement->fresh()->delete())
        ->toThrow(MeasurementWorkflowException::class, 'Uma medição que já entrou no fluxo de análise não pode ser excluída');

    expect(measurementDeletionFootprint($measurement))->toBe($before);
})->with([
    'em análise na Engenharia' => ['in_review', 'in_review', false, 0],
    'pausada na Engenharia' => ['paused', 'paused', false, 0],
    'recusada pela Engenharia' => ['rejected', 'rejected', false, 0],
    'devolvida da Finalização à Engenharia, com pagamento' => ['returned_with_payment', 'in_review', false, 1],
    'aprovada pela Engenharia' => ['engineering_approved', 'in_review', true, 0],
]);

it('refuses to erase a finalized measurement even when no review was recorded', function () {
    $measurement = Measurement::factory()->create([
        'storage_path' => null,
        'status' => 'finalized',
        'current_stage' => 5,
    ]);

    expect($measurement->reviews()->exists())->toBeFalse();

    $this->actingAs(measurementDeletionUser('super-admin'));

    expect(fn () => $measurement->fresh()->delete())
        ->toThrow(MeasurementWorkflowException::class, 'Uma medição que já entrou no fluxo de análise não pode ser excluída');

    expect(Measurement::query()->whereKey($measurement->id)->exists())->toBeTrue();
});

it('still erases a measurement that never entered the workflow', function () {
    $measurement = Measurement::factory()->create([
        'storage_path' => null,
        'status' => 'pending',
        'current_stage' => 1,
    ]);

    expect($measurement->reviews()->exists())->toBeFalse()
        ->and($measurement->delete())->toBeTrue()
        ->and(Measurement::query()->whereKey($measurement->id)->exists())->toBeFalse();
});

it('keeps the policy delete rule unchanged, with the super-admin still let through by the gate', function () {
    $editor = measurementDeletionAccess('editor');
    $admin = measurementDeletionUser('admin');
    $superAdmin = measurementDeletionUser('super-admin');
    $neverSubmitted = Measurement::factory()->create(['operation_id' => $editor['operation']->id, 'storage_path' => null, 'status' => 'pending']);
    $inReview = measurementDeletionInReview($editor['operation']);
    $legacyFinalized = Measurement::factory()->create(['operation_id' => $editor['operation']->id, 'storage_path' => null, 'status' => 'finalized', 'current_stage' => 5]);

    expect(Gate::forUser($admin)->allows('delete', $neverSubmitted))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('delete', $inReview))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('delete', $legacyFinalized))->toBeFalse()
        ->and(Gate::forUser($editor['actor'])->allows('delete', $neverSubmitted))->toBeFalse()
        ->and(Gate::forUser($superAdmin)->allows('delete', $inReview))->toBeTrue();
});

it('answers deleteAny with no for every profile but the super-admin', function (string $profile, bool $allowed) {
    $access = measurementDeletionAccess($profile);
    $this->actingAs($access['actor']);

    expect(MeasurementResource::canDeleteAny())->toBe($allowed)
        ->and(Gate::forUser($access['actor'])->allows('deleteAny', Measurement::class))->toBe($allowed);
})->with([
    'super-admin' => ['super-admin', true],
    'admin' => ['admin', false],
    'editor participante' => ['editor', false],
    'somente visualização' => ['viewer', false],
    'delegado' => ['delegate', false],
]);

// ── Cascatas que alcançariam a medição: Emissão e Plano de Medição ──────────

/**
 * Notificação do Filament da última requisição, lida sem consumir a sessão.
 */
function measurementDeletionNotificationBody(string $title): ?string
{
    $notifications = session()->get('filament.claimed_notifications') ?? session()->get('filament.notifications') ?? [];
    $body = collect($notifications)->firstWhere('title', $title)['body'] ?? null;

    return $body === null ? null : (string) $body;
}

/**
 * Um plano com um único tipo de vínculo de medição -- arquivo, pagamento ou
 * linha medida -- ou com todos eles, como a medição paga devolvida à
 * Engenharia. Quem tenta excluir é um editor que coordena a operação: a
 * exclusão do plano é autorizada só pelo `update` da operação.
 *
 * @return array{actor: User, operation: Operation, planSet: MeasurementPlanSet}
 */
function measurementDeletionPlanSetInState(string $state): array
{
    if ($state === 'medição paga devolvida à Engenharia') {
        $scenario = MeasurementReceiptEvidenceScenario::open();
        app(MeasurementWorkflow::class)->returnToStage($scenario['measurement']->fresh(), $scenario['actor'], 1, 'Correção do arquivo da Engenharia.');
        $operation = $scenario['operation'];
        $planSet = MeasurementPlanSet::query()->findOrFail($scenario['payment']->plan_set_id);
    } else {
        $scenario = MeasurementPhysicalProgressScenario::plan();
        $operation = $scenario['operation'];
        $planSet = $scenario['planSet'];
        $legacy = fn (): Measurement => Measurement::factory()->create([
            'operation_id' => $operation->id,
            'status' => 'rejected',
            'storage_path' => null,
            'filename' => null,
        ]);

        match ($state) {
            'arquivo de medição em análise' => MeasurementPhysicalProgressScenario::measurement($scenario, '2026-05'),
            'pagamento registrado' => MeasurementPayment::factory()->create([
                'operation_id' => $operation->id,
                'measurement_id' => $legacy()->id,
                'plan_set_id' => $planSet->id,
            ]),
            'linha medida' => DB::table('measurement_plan_lines')
                ->where('id', $scenario['lines']['2026-05']->id)
                ->update(['measurement_id' => $legacy()->id]),
        };
    }

    $editor = measurementDeletionUser('editor');
    $operation->forceFill(['assigned_user_id' => $editor->id])->saveQuietly();

    return ['actor' => $editor, 'operation' => $operation->fresh(), 'planSet' => $planSet->fresh()];
}

/**
 * Tudo o que a exclusão do plano levaria ou reescreveria pela FK: as linhas em
 * cascata e o `plan_set_id`/`plan_line_id` dos arquivos e dos pagamentos.
 *
 * @return array{plan_set: bool, lines: array<int, int|null>, assets: array<int, int|null>, payments: list<int>}
 */
function measurementDeletionPlanSetFootprint(MeasurementPlanSet $planSet): array
{
    return [
        'plan_set' => MeasurementPlanSet::query()->whereKey($planSet->id)->exists(),
        'lines' => MeasurementPlanLine::query()->where('plan_set_id', $planSet->id)->orderBy('id')->pluck('measurement_id', 'id')->all(),
        'assets' => MeasurementAsset::query()->where('plan_set_id', $planSet->id)->orderBy('id')->pluck('plan_line_id', 'id')->all(),
        'payments' => MeasurementPayment::query()->where('plan_set_id', $planSet->id)->orderBy('id')->pluck('id')->all(),
    ];
}

it('refuses to delete an emission whose operation has a measurement, for the super-admin too', function (string $profile, string $state) {
    $measurement = measurementDeletionInState($state);
    $emission = $measurement->operation->emission;
    $operationId = $measurement->operation_id;
    $before = measurementDeletionFootprint($measurement);
    $this->actingAs(measurementDeletionUser($profile));

    $list = Livewire::test(ListEmissions::class)
        ->assertTableActionVisible('delete', $emission)
        ->assertTableActionDisabled('delete', $emission);

    expect($list->instance()->getTable()->getAction('delete')->record($emission)->getTooltip())
        ->toBe('A emissão não pode ser excluída: tem operação com medição registrada.');

    $list->callTableAction('delete', $emission);

    $edit = Livewire::test(EditEmission::class, ['record' => $emission->getRouteKey()])
        ->assertActionHidden('delete');
    measurementDeletionForgePageDelete($edit);

    expect(Emission::query()->whereKey($emission->id)->exists())->toBeTrue()
        ->and(Operation::query()->whereKey($operationId)->exists())->toBeTrue()
        ->and(measurementDeletionFootprint($measurement))->toBe($before);
})->with([
    'super-admin, medição em análise' => ['super-admin', 'in_review'],
    'admin, medição em análise' => ['admin', 'in_review'],
    'super-admin, medição devolvida com pagamento' => ['super-admin', 'returned_with_payment'],
    'admin, medição devolvida com pagamento' => ['admin', 'returned_with_payment'],
]);

it('refuses to delete a plan set that carries measurement history, by the row action and in bulk', function (string $state) {
    $access = measurementDeletionPlanSetInState($state);
    $before = measurementDeletionPlanSetFootprint($access['planSet']);
    $refusal = 'Este plano já tem medição registrada (arquivo, pagamento ou linha medida) e não pode ser excluído: o histórico da medição depende dele.';
    $this->actingAs($access['actor']);

    expect(Gate::forUser($access['actor'])->allows('update', $access['operation']))->toBeTrue();

    Livewire::test(PlanSetsRelationManager::class, ['ownerRecord' => $access['operation'], 'pageClass' => ViewOperation::class])
        ->callTableAction('delete', $access['planSet'])
        ->assertActionHalted(TestAction::make('delete')->table($access['planSet']));

    $rowRefusal = measurementDeletionNotificationBody('Plano não excluído.');

    Livewire::test(PlanSetsRelationManager::class, ['ownerRecord' => $access['operation'], 'pageClass' => ViewOperation::class])
        ->callTableBulkAction('delete', [$access['planSet']]);

    expect($rowRefusal)->toBe($refusal)
        ->and(measurementDeletionNotificationBody('Falha ao excluir'))->toBe("<p>{$refusal}</p>")
        ->and(measurementDeletionPlanSetFootprint($access['planSet']))->toBe($before)
        ->and(fn () => $access['planSet']->fresh()->delete())->toThrow(MeasurementWorkflowException::class, $refusal);
})->with([
    'arquivo de medição em análise',
    'pagamento registrado',
    'linha medida',
    'medição paga devolvida à Engenharia',
]);

/**
 * O SQLite não trava linha: aqui se prova a ordem -- a Operation lida como
 * primeira instrução da transação que exclui o plano, e a guarda reavaliada
 * depois dela. O lock em si (`FOR UPDATE`) só o MySQL prova.
 */
it('reads the operation first, in the transaction that deletes a plan set without measurement history', function (string $action) {
    $scenario = MeasurementPhysicalProgressScenario::plan();
    $editor = measurementDeletionUser('editor');
    $scenario['operation']->forceFill(['assigned_user_id' => $editor->id])->saveQuietly();
    $spare = MeasurementPlanSet::factory()->create(['operation_id' => $scenario['operation']->id, 'name' => 'Plano cadastrado por engano']);
    MeasurementPlanLine::factory()->create([
        'operation_id' => $scenario['operation']->id,
        'plan_set_id' => $spare->id,
        'sequence_number' => 1,
        'measurement_date' => '2026-05-01',
    ]);
    $this->actingAs($editor);
    $manager = Livewire::test(PlanSetsRelationManager::class, ['ownerRecord' => $scenario['operation']->fresh(), 'pageClass' => ViewOperation::class]);
    $statements = [];
    Event::listen(TransactionBeginning::class, function () use (&$statements): void {
        $statements[] = 'BEGIN';
    });
    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    match ($action) {
        'exclusão da linha' => $manager->callTableAction('delete', $spare),
        'exclusão em massa' => $manager->callTableBulkAction('delete', [$spare]),
    };

    $deletion = array_search(true, array_map(fn (string $statement): bool => str_starts_with($statement, 'delete from "measurement_plan_sets"'), $statements), true);
    $opening = null;

    foreach ($statements as $index => $statement) {
        if ($deletion !== false && $index < $deletion && $statement === 'BEGIN') {
            $opening = $index;
        }
    }

    expect(MeasurementPlanSet::query()->whereKey($spare->id)->exists())->toBeFalse()
        ->and(MeasurementPlanLine::query()->where('plan_set_id', $spare->id)->exists())->toBeFalse()
        ->and($deletion)->not->toBeFalse()
        ->and($opening)->not->toBeNull()
        ->and($statements[$opening + 1] ?? null)->toMatch('/^select \* from "operations" where "operations"\."id" = \? limit 1$/');
})->with(['exclusão da linha', 'exclusão em massa']);

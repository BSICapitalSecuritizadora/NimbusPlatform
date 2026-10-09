<?php

use App\Enums\MeasurementRevisionStatus;
use App\Exceptions\MeasurementWorkflowException;
use App\Filament\Resources\Measurements\MeasurementResource;
use App\Filament\Resources\Measurements\Pages\EditMeasurement;
use App\Filament\Resources\Measurements\Pages\ListMeasurements;
use App\Filament\Resources\Measurements\Pages\ViewMeasurement;
use App\Models\Measurement;
use App\Models\User;
use App\Services\MeasurementRevisionService;
use App\Services\MeasurementWorkflow;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\MeasurementRevisionScenario as Scenario;

uses(RefreshDatabase::class);

/**
 * Corpo da notificação do Filament da última requisição, lido sem consumir a
 * sessão -- o `assertNotified` compara só o título.
 */
function revisionUiNotificationBody(string $title): ?string
{
    $body = collect(session()->get('filament.claimed_notifications') ?? session()->get('filament.notifications') ?? [])
        ->firstWhere('title', $title)['body'] ?? null;

    return $body === null ? null : (string) $body;
}

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    Notification::fake();
});

it('offers the revision of a finalized measurement and opens the new draft', function () {
    $scenario = Scenario::plan();
    $may = Scenario::finalized($scenario, '2026-05', 10);
    $this->actingAs($scenario['actor']);

    $page = Livewire::test(ViewMeasurement::class, ['record' => $may->getRouteKey()])
        ->assertActionVisible('createRevision')
        ->assertActionEnabled('createRevision')
        ->callAction('createRevision', data: [
            'reason' => 'Percentual de maio lançado com a casa decimal errada.',
            'expected_revision' => (int) $may->fresh()->workflow_revision,
        ])
        ->assertHasNoActionErrors();

    $revision = Measurement::query()->where('revision_family_id', $may->id)->where('revision_number', 1)->sole();

    $page->assertRedirect(MeasurementResource::getUrl('view', ['record' => $revision]));

    expect($revision->revisionStatus())->toBe(MeasurementRevisionStatus::Draft)
        ->and($revision->revision_reason)->toBe('Percentual de maio lançado com a casa decimal errada.');
});

it('hides the revision action from users without the permission and ignores a forged call', function () {
    $scenario = Scenario::plan();
    $may = Scenario::finalized($scenario, '2026-05', 10);
    $viewer = User::factory()->withTwoFactor()->create();
    $viewer->givePermissionTo(['measurements.view', 'measurements.review', 'operations.view']);
    $scenario['operation']->forceFill(['stage2_reviewer_user_id' => $viewer->id])->save();
    $this->actingAs($viewer);

    // A chamada que o navegador faria pelo console: montar a ação oculta e
    // enviá-la. O Filament não monta ação oculta, e nada é criado.
    Livewire::test(ViewMeasurement::class, ['record' => $may->getRouteKey()])
        ->assertActionHidden('createRevision')
        ->call('mountAction', 'createRevision')
        ->set('mountedActions.0.data', ['reason' => 'Tentativa forjada.', 'expected_revision' => (int) $may->fresh()->workflow_revision])
        ->call('callMountedAction');

    expect(Measurement::query()->where('revision_number', '>', 0)->exists())->toBeFalse();
});

it('disables the revision of a paid measurement that is not finalized and refuses the forged call with the reason', function () {
    $scenario = Scenario::plan();
    $may = Scenario::awaitingPayment($scenario, '2026-05', 10);
    Scenario::pay($scenario, $may, Scenario::expected(10));
    $this->actingAs($scenario['actor']);

    Livewire::test(ViewMeasurement::class, ['record' => $may->getRouteKey()])
        ->assertActionVisible('createRevision')
        ->assertActionDisabled('createRevision')
        ->mountAction('createRevision')
        ->setActionData(['reason' => 'Tentativa.', 'expected_revision' => (int) $may->fresh()->workflow_revision])
        ->callMountedAction();

    // O Filament não monta a ação desabilitada; o domínio, chamado direto como
    // um envio que pulasse a tela, recusa pelo mesmo motivo do tooltip.
    expect(app(MeasurementRevisionService::class)->creationBlockReason($may->fresh()))->toBe(MeasurementRevisionService::ELIGIBILITY_REFUSAL)
        ->and(fn () => app(MeasurementRevisionService::class)->create($may->fresh(), $scenario['actor'], 'Tentativa forjada.'))
        ->toThrow(MeasurementWorkflowException::class, MeasurementRevisionService::ELIGIBILITY_REFUSAL)
        ->and(Measurement::query()->where('revision_number', '>', 0)->exists())->toBeFalse();
});

it('submits and cancels drafts from the measurement page', function () {
    $scenario = Scenario::plan();
    $may = Scenario::finalized($scenario, '2026-05', 10);
    $draft = Scenario::revise($scenario, $may);
    $this->actingAs($scenario['actor']);

    Livewire::test(ViewMeasurement::class, ['record' => $draft->getRouteKey()])
        ->assertActionVisible('submitRevision')
        ->assertActionVisible('cancelRevision')
        ->assertActionVisible('editRevision')
        ->assertActionHidden('createRevision')
        ->assertActionHidden('approve')
        ->callAction('submitRevision', data: ['expected_revision' => (int) $draft->fresh()->workflow_revision])
        ->assertNotified('Revisão enviada para a Engenharia.');

    expect($draft->fresh()->revisionStatus())->toBe(MeasurementRevisionStatus::UnderReview)
        ->and($draft->fresh()->current_stage)->toBe(MeasurementWorkflow::STAGE_ENGINEERING);

    app(MeasurementWorkflow::class)->reject($draft->fresh(), $scenario['actor'], 'Sem lastro.');
    $second = Scenario::revise($scenario, $may);

    Livewire::test(ViewMeasurement::class, ['record' => $second->getRouteKey()])
        ->callAction('cancelRevision', data: [
            'reason' => 'Correção feita por outro caminho.',
            'expected_revision' => (int) $second->fresh()->workflow_revision,
        ])
        ->assertNotified('Revisão cancelada.');

    expect($second->fresh()->revisionStatus())->toBe(MeasurementRevisionStatus::Cancelled)
        ->and($second->fresh()->revision_closed_reason)->toBe('Correção feita por outro caminho.');
});

it('shows the revision history and the unresolved financial difference warning', function () {
    $scenario = Scenario::plan();
    $may = Scenario::finalized($scenario, '2026-05', 10);
    $revision = Scenario::effective($scenario, $may, 8, 'Percentual superestimado em maio.');
    $this->actingAs($scenario['actor']);

    Livewire::test(ViewMeasurement::class, ['record' => $revision->getRouteKey()])
        ->assertSee('Revisões da medição')
        ->assertSee('Esta revisão possui diferença financeira não resolvida. Os pagamentos históricos não foram modificados.')
        ->assertSee('R0 · Original')
        ->assertSee('Percentual superestimado em maio.')
        ->assertSee('Diferença negativa')
        ->assertSee('Valor pago a maior — decisão pendente');

    Livewire::test(ViewMeasurement::class, ['record' => $may->getRouteKey()])
        ->assertSee('Substituída pela revisão')
        ->assertActionHidden('createRevision');
});

it('lists the current revision of each measurement by default and the history on request', function () {
    $scenario = Scenario::plan();
    $may = Scenario::finalized($scenario, '2026-05', 10);
    $revision = Scenario::effective($scenario, $may, 12);
    $this->actingAs($scenario['actor']);

    Livewire::test(ListMeasurements::class)
        ->assertCanSeeTableRecords([$revision])
        ->assertCanNotSeeTableRecords([$may])
        ->filterTable('revision_scope', true)
        ->assertCanSeeTableRecords([$revision, $may])
        ->filterTable('revision_scope', false)
        ->assertCanSeeTableRecords([$may])
        ->assertCanNotSeeTableRecords([$revision]);
});

it('sends the edit link of a closed revision back to the measurement with the reason', function () {
    $scenario = Scenario::plan();
    $may = Scenario::finalized($scenario, '2026-05', 10);
    $draft = Scenario::revise($scenario, $may);
    app(MeasurementRevisionService::class)->cancel($draft->fresh(), $scenario['actor'], 'Desnecessária.');
    $this->actingAs($scenario['actor']);

    $page = Livewire::test(EditMeasurement::class, ['record' => $draft->getRouteKey()])
        ->assertRedirect(MeasurementResource::getUrl('view', ['record' => $draft]));

    expect(revisionUiNotificationBody('Medição não atualizada.'))
        ->toBe('Esta revisão da medição está encerrada e é só histórico: ela não pode mais ser editada.');

    $page->assertNotified('Medição não atualizada.');
});

it('shows the suspended payment stage of a measurement whose revision is under review', function () {
    $scenario = Scenario::plan();
    $may = Scenario::awaitingPayment($scenario, '2026-05', 10);
    Scenario::submit($scenario, Scenario::revise($scenario, $may));
    $this->actingAs($scenario['actor']);

    Livewire::test(ViewMeasurement::class, ['record' => $may->getRouteKey()])
        ->assertSee('Suspensa pela revisão')
        ->assertSee('Bloqueada pela revisão R1 em análise')
        ->assertActionHidden('registerPayment')
        ->assertActionHidden('approve')
        ->assertActionHidden('pause');
});

it('compares the revision with the one it corrects in percentage points and keeps the plan version', function () {
    $scenario = Scenario::plan();
    $may = Scenario::finalized($scenario, '2026-05', 10);
    $revision = Scenario::effective($scenario, $may, 12);
    $this->actingAs($scenario['actor']);

    Livewire::test(ViewMeasurement::class, ['record' => $revision->getRouteKey()])
        ->assertSee('+2,00 p.p.')
        ->assertSee('Versão do plano: V1 → V1 (mantida)')
        ->assertSee('Pagamento complementar pendente');
});

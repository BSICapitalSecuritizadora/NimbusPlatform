<?php

use App\Domain\PuCalculator\Enums\PuEventType;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuOperationalActivationStatus;
use App\Domain\PuCalculator\Services\PuOperationalActivationPolicy;
use App\Enums\AccessPermission;
use App\Filament\Resources\Emissions\EmissionResource;
use App\Filament\Resources\Emissions\Pages\PuOperation;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuOperationalStatusChange;
use App\Models\User;
use App\Support\PuCalculator\PuReadinessPresentation;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Pu\PuObligationFixture as Fx;
use Tests\Support\Pu\PuOperationalActivationFixture as Activation;

/**
 * Fase 8 (UI-4): a página "Operação do PU" mostra os dois portões e decide
 * ativação/suspensão só pelo serviço da Fase 7. Com o interruptor global
 * desligado, nada se ativa -- nem por chamada forjada.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Activation::withoutAutomaticActivation();
});

/**
 * @param  list<AccessPermission>  $permissions
 */
function p8aUser(array $permissions): User
{
    $user = User::factory()->withTwoFactor()->create();
    $user->givePermissionTo([AccessPermission::EmissionsView->value, AccessPermission::PuCurveView->value, ...array_map(fn (AccessPermission $permission): string => $permission->value, $permissions)]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->fresh();
}

function p8aEmission(): Emission
{
    return Fx::emission([[PuEventType::InterestPayment, '2026-03-09']]);
}

function p8aPage(Emission $emission): Testable
{
    return Livewire::test(PuOperation::class, ['record' => $emission->getRouteKey()]);
}

it('shows both gates, the indexer certification and the next step without implying authorization', function () {
    $emission = p8aEmission();
    $this->actingAs(p8aUser([]));

    p8aPage($emission)
        ->assertSuccessful()
        ->assertSee([
            'Interruptor global do ambiente',
            'Ligadas neste ambiente',
            'Configurado, operação do PU não ativada',
            'Certificado com condições',
            'Avaliar a prontidão e decidir a ativação',
            'Sem curva oficial',
            'Nenhuma decisão registrada',
        ]);
});

it('explains that nothing runs while the environment switch is off', function () {
    config()->set('pu_calculator.operations.enabled', false);
    $emission = p8aEmission();
    $this->actingAs(p8aUser([AccessPermission::PuOperationsActivate]));

    p8aPage($emission)
        ->assertSee(['Desligadas neste ambiente', 'Operações do PU desligadas neste ambiente'])
        ->assertActionVisible(TestAction::make('activateOperations'))
        ->assertActionDisabled(TestAction::make('activateOperations'))
        ->call('mountAction', 'activateOperations')
        ->assertSet('mountedActions', []);

    expect(EmissionPuOperationalStatusChange::query()->count())->toBe(0)
        ->and(app(PuOperationalActivationPolicy::class)->status($emission))->toBe(PuOperationalActivationStatus::Inactive);
});

it('hides activation from users without the dedicated authority', function () {
    $emission = p8aEmission();
    $this->actingAs(p8aUser([AccessPermission::PuCurveHomologate, AccessPermission::PuSettlementCorrect, AccessPermission::PuOperationsRecover]));

    p8aPage($emission)
        ->assertActionHidden(TestAction::make('activateOperations'))
        ->call('mountAction', 'activateOperations')
        ->assertSet('mountedActions', []);

    expect(EmissionPuOperationalStatusChange::query()->count())->toBe(0);
});

it('activates through the governed service with reason, approval reference and confirmations, and generates nothing', function () {
    $emission = p8aEmission();
    $user = p8aUser([AccessPermission::PuOperationsActivate]);
    $this->actingAs($user);

    p8aPage($emission)
        ->mountAction(TestAction::make('activateOperations'))
        ->assertMountedActionModalSee(['O que a ativação faz e não faz', 'Isto não é autorização operacional'])
        ->setActionData(['reason' => '', 'approval_reference' => '', 'checklist_covered' => false, 'not_homologation' => false])
        ->callMountedAction()
        ->assertHasFormErrors(['reason', 'approval_reference', 'checklist_covered', 'not_homologation'])
        ->setActionData(['reason' => 'Revisão contratual concluída.', 'approval_reference' => 'ATA-2026-10-14', 'checklist_covered' => true, 'not_homologation' => true])
        ->callMountedAction()
        ->assertHasNoFormErrors()
        ->assertNotified('Operação do PU ativada.');

    $change = EmissionPuOperationalStatusChange::query()->sole();

    expect($change->status)->toBe(PuOperationalActivationStatus::Activated)
        ->and($change->decided_by)->toBe($user->id)
        ->and($change->decided_via)->toBe('ui')
        ->and($change->approval_reference)->toBe('ATA-2026-10-14')
        ->and(EmissionPuCurveVersion::query()->count())->toBe(0);
});

it('suspends with a reason and keeps the official history', function () {
    $emission = p8aEmission();
    Activation::activate($emission->id);
    $official = Fx::official($emission);
    $this->actingAs(p8aUser([AccessPermission::PuOperationsActivate]));

    p8aPage($emission)
        ->assertActionHidden(TestAction::make('activateOperations'))
        ->callAction(TestAction::make('suspendOperations'), data: ['reason' => 'Revisão do Termo em andamento.', 'confirm' => true])
        ->assertNotified('Operação do PU suspensa.');

    expect(app(PuOperationalActivationPolicy::class)->status($emission))->toBe(PuOperationalActivationStatus::Suspended)
        ->and($official->fresh()->status->isOfficial())->toBeTrue();
});

it('shows a non certified indexer as simulation only and blocks activation', function () {
    $emission = p8aEmission();
    $emission->puParameter->forceFill(['indexer' => PuIndexer::Prefixed->value, 'annual_rate' => '10.00000000'])->save();
    $this->actingAs(p8aUser([AccessPermission::PuOperationsActivate]));

    p8aPage($emission)
        ->assertSee(['Prefixado', 'Não certificado (só simulação)', 'Indexador só para simulação'])
        ->assertActionDisabled(TestAction::make('activateOperations'));
});

it('groups readiness without ever presenting manual or unverified checks as passed', function () {
    $emission = p8aEmission();
    $this->actingAs(p8aUser([]));

    p8aPage($emission)
        ->mountAction(TestAction::make('assessReadiness'))
        ->assertMountedActionModalSee([
            'Isto não é autorização operacional',
            'Pré-requisitos contratuais',
            'Verificação manual necessária',
            'Não verificado',
        ]);

    $presentation = PuReadinessPresentation::build(null, ['items' => [
        ['requirement' => 'Backup e restauração do banco (tabelas do PU)', 'result' => 'manual', 'evidence' => 'x', 'action' => 'y'],
        ['requirement' => 'Interruptor global (PU_OPERATIONS_ENABLED)', 'result' => 'ok', 'evidence' => 'Desligado', 'action' => 'z'],
        ['requirement' => 'Certificação do indexador PREFIXED', 'result' => 'ok', 'evidence' => 'Não certificado', 'action' => 'w'],
    ]]);
    $results = collect($presentation['groups'])->flatMap(fn (array $group): array => $group['items'])->mapWithKeys(fn (array $item): array => [$item['label'] => $item['result']]);

    $web = PuReadinessPresentation::build(null, ['items' => [
        ['requirement' => 'Agenda do PU', 'result' => 'attention', 'evidence' => 'Nenhuma tarefa do PU agendada.', 'action' => 'x'],
    ]], scheduleReadable: false);

    expect(collect($web['groups'])->flatMap(fn (array $group): array => $group['items'])->firstWhere('label', 'Agenda do PU')['result'])->toBe(PuReadinessPresentation::NOT_VERIFIED)
        ->and($results['Backup e restauração do banco (tabelas do PU)'])->toBe(PuReadinessPresentation::MANUAL)
        ->and($results['Interruptor global (PU_OPERATIONS_ENABLED)'])->toBe(PuReadinessPresentation::INFO)
        ->and($results['Certificação do indexador PREFIXED'])->toBe(PuReadinessPresentation::INFO);
});

it('runs the environment preflight only for operators who may see it', function () {
    $emission = p8aEmission();
    $this->actingAs(p8aUser([AccessPermission::PuOperationsMonitor]));

    p8aPage($emission)
        ->mountAction(TestAction::make('assessReadiness'))
        ->assertMountedActionModalSee(['Monitoramento, alertas e incidentes', 'Backup e restauração do banco'])
        ->assertMountedActionModalDontSee('não foi executada para este usuário');
});

it('is linked from the emission and refused without the curve view permission', function () {
    $emission = p8aEmission();
    $this->actingAs(p8aUser([]));

    $this->get(EmissionResource::getUrl('pu-operation', ['record' => $emission]))->assertSuccessful();

    $viewer = User::factory()->withTwoFactor()->create();
    $viewer->givePermissionTo(AccessPermission::EmissionsView->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs($viewer->fresh());

    $this->get(EmissionResource::getUrl('pu-operation', ['record' => $emission]))->assertForbidden();
});

it('never presents a stale official PU as the PU of today', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-16 10:00', 'America/Sao_Paulo'));
    $emission = p8aEmission();
    Activation::activate($emission->id);
    $official = Fx::official($emission);
    $last = Fx::lastDate($official);
    Fx::publish('2026-03-16', '2026-03-27');
    $this->travelTo(CarbonImmutable::parse('2026-03-30 10:00', 'America/Sao_Paulo'));
    $this->actingAs(p8aUser([]));

    p8aPage($emission)
        ->assertSee([
            'Atrasada: há índice realizado não incorporado',
            'Valor carregado de '.CarbonImmutable::parse($last)->format('d/m/Y'),
            'NÃO responde por 30/03/2026',
        ]);
});

it('never labels a generated candidate as the official curve', function () {
    $emission = p8aEmission();
    Activation::activate($emission->id);
    $candidate = Fx::generate($emission);
    $this->actingAs(p8aUser([]));

    p8aPage($emission)
        ->assertSee(['Sem curva oficial', 'Última versão de trabalho (não oficial)', 'Versão '.$candidate->calculation_version, $candidate->status->label().' — candidata', 'Só vira oficial pela homologação maker/checker'])
        ->assertDontSee($candidate->status->label().' — oficial');
});

it('shows that a contractual change still needs a new homologated version', function () {
    $emission = Fx::emission([[PuEventType::InterestPayment, '2026-03-09'], [PuEventType::InterestPayment, '2026-04-09']]);
    Activation::activate($emission->id);
    Fx::official($emission);
    Fx::event($emission, PuEventType::InterestPayment, '2026-03-31');
    $this->actingAs(p8aUser([]));

    p8aPage($emission)->assertSee(['Mudança contratual não homologada a partir de 31/03/2026', 'nova versão necessária']);
});

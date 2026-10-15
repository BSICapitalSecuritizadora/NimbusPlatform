<?php

use App\Domain\PuCalculator\Enums\PuAmortizationType;
use App\Domain\PuCalculator\Enums\PuEventType;
use App\Domain\PuCalculator\Enums\PuIncidentStatus;
use App\Domain\PuCalculator\Enums\PuObligationRefreshStatus;
use App\Domain\PuCalculator\Enums\PuObligationType;
use App\Domain\PuCalculator\Enums\PuOperationalConditionType;
use App\Domain\PuCalculator\Enums\PuOperationalFailureCategory;
use App\Domain\PuCalculator\Enums\PuOperationalSeverity;
use App\Domain\PuCalculator\Enums\PuSettlementStatus;
use App\Domain\PuCalculator\Services\PuOperationalIncidentService;
use App\Enums\AccessPermission;
use App\Filament\Pages\PuOperationalIncidents;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuSettlement;
use App\Models\EmissionPuSettlementConflict;
use App\Models\PuObligationRefreshRequest;
use App\Models\PuOperationalIncident;
use App\Models\User;
use App\Support\PuCalculator\PuIncidentGuidance;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Pu\PuObligationFixture as Fx;

/**
 * Fase 8 (P0-C): os incidentes duráveis da Fase 6 ganham tela. Reconhecer só
 * registra que alguém está tratando; nunca resolve, nem muda fato financeiro.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * @param  list<AccessPermission>  $permissions
 */
function p8iUser(array $permissions): User
{
    $user = User::factory()->create();
    $user->givePermissionTo([AccessPermission::EmissionsView->value, ...array_map(fn (AccessPermission $permission): string => $permission->value, $permissions)]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->fresh();
}

/**
 * @param  array<string, mixed>  $attributes
 */
function p8iIncident(PuOperationalConditionType $type, PuOperationalSeverity $severity, ?Emission $emission = null, array $attributes = []): PuOperationalIncident
{
    $scope = $emission !== null ? 'emission:'.$emission->id : 'system';

    return PuOperationalIncident::factory()->create([
        'incident_key' => $type->value.':'.$scope,
        'type' => $type->value,
        'check_name' => $type->check(),
        'severity' => $severity->value,
        'emission_id' => $emission?->id,
        'indexer' => null,
        'reason' => 'Motivo sintético: '.$type->label(),
        ...$attributes,
    ]);
}

it('is reserved to users who monitor PU operations', function () {
    $this->actingAs(p8iUser([]));
    expect(PuOperationalIncidents::canAccess())->toBeFalse();

    $this->actingAs(p8iUser([AccessPermission::PuOperationsMonitor]));
    expect(PuOperationalIncidents::canAccess())->toBeTrue();
});

it('lists open incidents with severity, affected emission, recurrence and the recommended action', function () {
    $emission = Emission::factory()->create(['name' => 'CRI Incidente']);
    $critical = p8iIncident(PuOperationalConditionType::SettlementConflictOpen, PuOperationalSeverity::Critical, $emission, ['detection_count' => 4]);
    $resolved = p8iIncident(PuOperationalConditionType::IndexSyncFailed, PuOperationalSeverity::Warning, null, ['status' => PuIncidentStatus::Resolved->value, 'resolved_at' => now(), 'indexer' => 'CDI']);

    $this->actingAs(p8iUser([AccessPermission::PuOperationsMonitor]));

    Livewire::test(PuOperationalIncidents::class)
        ->assertCanSeeTableRecords([$critical])
        ->assertCanNotSeeTableRecords([$resolved])
        ->assertSee(['Crítico', 'CRI Incidente', '4 detecção(ões)', 'Ativo'])
        ->mountAction(TestAction::make('incidentDetails')->table($critical))
        ->assertMountedActionModalSee([
            'Ação recomendada',
            PuIncidentGuidance::recommendedAction(PuOperationalConditionType::SettlementConflictOpen),
            'Abrir obrigações da emissão',
            'Reconhecer não altera o PU oficial',
        ]);
});

it('shows resolved incidents only when asked', function () {
    $resolved = p8iIncident(PuOperationalConditionType::IndexSyncFailed, PuOperationalSeverity::Warning, null, ['status' => PuIncidentStatus::Resolved->value, 'resolved_at' => now(), 'indexer' => 'CDI']);
    $this->actingAs(p8iUser([AccessPermission::PuOperationsMonitor]));

    Livewire::test(PuOperationalIncidents::class)
        ->filterTable('situation', PuIncidentStatus::Resolved->value)
        ->assertCanSeeTableRecords([$resolved]);
});

it('acknowledges an incident without touching the conflict, the settlement or the official curve', function () {
    $emission = Fx::emission([[PuEventType::Amortization, '2026-03-09', ['amortization_type' => PuAmortizationType::UnitValue->value, 'amortization_value' => '1.0000000000000000']]]);
    $official = Fx::official($emission);
    $obligation = Fx::obligation($emission, PuObligationType::ScheduledPayment, '2026-03-09');
    Fx::settle($obligation, '95.00', reference: 'B3-INC-1');
    Fx::settle($obligation, '100.00', reference: 'B3-INC-1');
    $conflict = EmissionPuSettlementConflict::query()->sole();
    $incident = p8iIncident(PuOperationalConditionType::SettlementConflictOpen, PuOperationalSeverity::Critical, $emission, ['settlement_conflict_id' => $conflict->id, 'obligation_id' => $obligation->id]);
    $before = [
        'conflict' => $conflict->fresh()->toArray(),
        'settlements' => EmissionPuSettlement::query()->orderBy('id')->get()->toArray(),
        'official' => $official->fresh()->only(['status', 'homologated_at', 'calculation_version']),
        'reconciliation' => $obligation->fresh()->reconciliation_status,
    ];
    $user = p8iUser([AccessPermission::PuOperationsMonitor, AccessPermission::PuOperationsRecover]);
    $this->actingAs($user);

    Livewire::test(PuOperationalIncidents::class)
        ->callAction(TestAction::make('acknowledge')->table($incident), data: ['note' => 'Falando com o agente.'])
        ->assertNotified('Incidente reconhecido.');

    $incident->refresh();

    expect($incident->status)->toBe(PuIncidentStatus::Acknowledged)
        ->and($incident->isOpen())->toBeTrue()
        ->and($incident->acknowledged_by)->toBe($user->id)
        ->and($incident->acknowledgement_note)->toBe('Falando com o agente.')
        ->and($conflict->fresh()->toArray())->toBe($before['conflict'])
        ->and(EmissionPuSettlement::query()->orderBy('id')->get()->toArray())->toBe($before['settlements'])
        ->and(EmissionPuCurveVersion::query()->find($official->id)->only(['status', 'homologated_at', 'calculation_version']))->toEqual($before['official'])
        ->and($obligation->fresh()->reconciliation_status)->toBe($before['reconciliation'])
        ->and(EmissionPuSettlement::query()->where('status', PuSettlementStatus::Active->value)->sole()->amount)->toBe('95.00');
});

it('keeps acknowledgement away from monitor-only users, even when the call is forged', function () {
    $incident = p8iIncident(PuOperationalConditionType::IndexSyncFailed, PuOperationalSeverity::Warning, null, ['indexer' => 'CDI']);
    $this->actingAs(p8iUser([AccessPermission::PuOperationsMonitor]));

    Livewire::test(PuOperationalIncidents::class)
        ->assertActionHidden(TestAction::make('acknowledge')->table($incident))
        ->call('mountAction', 'acknowledge', [], ['table' => true, 'recordKey' => (string) $incident->getKey()])
        ->assertSet('mountedActions', []);

    expect($incident->fresh()->status)->toBe(PuIncidentStatus::Active);
});

it('refuses a stale acknowledgement when the monitor resolved the incident meanwhile', function () {
    $incident = p8iIncident(PuOperationalConditionType::IndexSyncFailed, PuOperationalSeverity::Warning, null, ['indexer' => 'CDI']);
    $user = p8iUser([AccessPermission::PuOperationsMonitor, AccessPermission::PuOperationsRecover]);
    $this->actingAs($user);

    $component = Livewire::test(PuOperationalIncidents::class)->mountAction(TestAction::make('acknowledge')->table($incident));
    PuOperationalIncident::query()->whereKey($incident->id)->update(['status' => PuIncidentStatus::Resolved->value, 'resolved_at' => now()]);

    // A ação é reavaliada no servidor com o estado de agora: resolvido, o incidente
    // sai da lista de abertos e nada é gravado -- e o domínio recusaria do mesmo jeito.
    $component->callMountedAction();

    expect($incident->fresh()->acknowledged_at)->toBeNull()
        ->and(fn () => app(PuOperationalIncidentService::class)->acknowledge($incident->fresh(), $user))
        ->toThrow(InvalidArgumentException::class);
});

it('retries an exhausted obligation refresh through the governed recovery, with a reason', function () {
    $emission = Fx::emission([[PuEventType::Amortization, '2026-03-09', ['amortization_type' => PuAmortizationType::UnitValue->value, 'amortization_value' => '1.0000000000000000']]]);
    Fx::official($emission);
    $request = PuObligationRefreshRequest::factory()->create([
        'emission_id' => $emission->id,
        'status' => PuObligationRefreshStatus::Exhausted->value,
        'attempts' => 5,
        'last_error_category' => PuOperationalFailureCategory::TransientDatabase->value,
        'last_error_message' => 'Deadlock simulado.',
    ]);
    $incident = p8iIncident(PuOperationalConditionType::ObligationRefreshExhausted, PuOperationalSeverity::Critical, $emission);
    $this->actingAs(p8iUser([AccessPermission::PuOperationsMonitor, AccessPermission::PuOperationsRecover]));

    Livewire::test(PuOperationalIncidents::class)
        ->mountAction(TestAction::make('retryRefresh')->table($incident))
        ->assertMountedActionModalSee(['Tentativas esgotadas', 'Sim (falha passageira)'])
        ->setActionData(['reason' => ''])
        ->callMountedAction()
        ->assertHasFormErrors(['reason'])
        ->setActionData(['reason' => 'Banco estabilizado depois da janela de manutenção.'])
        ->callMountedAction()
        ->assertNotified('Obrigações atualizadas.');

    expect($request->fresh()->status)->toBeIn([PuObligationRefreshStatus::Succeeded, PuObligationRefreshStatus::Superseded]);
});

it('requires confirming that a permanent failure was fixed before retrying a blocked refresh', function () {
    $emission = Fx::emission([[PuEventType::Amortization, '2026-03-09', ['amortization_type' => PuAmortizationType::UnitValue->value, 'amortization_value' => '1.0000000000000000']]]);
    Fx::official($emission);
    PuObligationRefreshRequest::factory()->create([
        'emission_id' => $emission->id,
        'status' => PuObligationRefreshStatus::Blocked->value,
        'attempts' => 1,
        'last_error_category' => PuOperationalFailureCategory::Governance->value,
        'last_error_message' => 'Recusa de governança simulada.',
    ]);
    $incident = p8iIncident(PuOperationalConditionType::ObligationRefreshBlocked, PuOperationalSeverity::Critical, $emission);
    $this->actingAs(p8iUser([AccessPermission::PuOperationsMonitor, AccessPermission::PuOperationsRecover]));

    Livewire::test(PuOperationalIncidents::class)
        ->mountAction(TestAction::make('retryRefresh')->table($incident))
        ->assertMountedActionModalSee(['corrigir a causa antes'])
        ->setActionData(['reason' => 'Tentativa.', 'permanent_cause_fixed' => false])
        ->callMountedAction()
        ->assertHasFormErrors(['permanent_cause_fixed']);

    expect(PuObligationRefreshRequest::query()->where('status', PuObligationRefreshStatus::Blocked->value)->count())->toBe(1);
});

it('links every incident to where it is handled, or to nothing when there is no screen for it', function () {
    $emission = Emission::factory()->create();

    expect(PuIncidentGuidance::link(p8iIncident(PuOperationalConditionType::SettlementDivergent, PuOperationalSeverity::Warning, $emission))['url'] ?? null)
        ->toContain('relation=')
        ->and(PuIncidentGuidance::link(p8iIncident(PuOperationalConditionType::OperationsSuspended, PuOperationalSeverity::Info, $emission))['url'] ?? null)
        ->toContain('/pu-operation')
        ->and(PuIncidentGuidance::link(p8iIncident(PuOperationalConditionType::QueuedJobsFailed, PuOperationalSeverity::Warning)))
        ->toBeNull();

    foreach (PuOperationalConditionType::cases() as $type) {
        expect(PuIncidentGuidance::recommendedAction($type))->not->toBe('');
    }
});

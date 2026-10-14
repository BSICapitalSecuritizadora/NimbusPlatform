<?php

use App\Domain\PuCalculator\Enums\PuAmortizationType;
use App\Domain\PuCalculator\Enums\PuEventType;
use App\Domain\PuCalculator\Enums\PuIncidentStatus;
use App\Domain\PuCalculator\Enums\PuObligationType;
use App\Domain\PuCalculator\Enums\PuOperationalActivationStatus;
use App\Domain\PuCalculator\Enums\PuOperationalConditionType;
use App\Domain\PuCalculator\Enums\PuOperationalSeverity;
use App\Domain\PuCalculator\Enums\PuReconciliationStatus;
use App\Domain\PuCalculator\Enums\PuSettlementConflictStatus;
use App\Domain\PuCalculator\Enums\PuSettlementStatus;
use App\Domain\PuCalculator\Services\PuOperationalActivationPolicy;
use App\Enums\AccessPermission;
use App\Filament\Pages\PuOperationalIncidents;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\PuObligationsRelationManager;
use App\Filament\Resources\Emissions\Pages\EditEmission;
use App\Filament\Resources\Emissions\Pages\PuOperation;
use App\Filament\Resources\IndexRates\Pages\ListIndexRates;
use App\Models\EmissionPuSettlement;
use App\Models\EmissionPuSettlementConflict;
use App\Models\IndexRate;
use App\Models\IndexRateCorrection;
use App\Models\PuOperationalIncident;
use App\Models\User;
use App\Support\PuCalculator\PuOperationalDashboardData;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Pu\PuObligationFixture as Fx;
use Tests\Support\Pu\PuOperationalActivationFixture as Activation;

/**
 * Fase 8 no MySQL real: as telas novas chegam aos serviços governados pelas mesmas
 * travas e consultas que a produção executa (subconsultas de contagem, ORDER BY com
 * CASE, agregações da tela de incidentes e do painel), sem atalho de gravação.
 * Nenhum caminho novo de trava: a concorrência continua coberta pelos testes MySQL
 * das Fases 5-7.
 */
pest()->group('mysql');

uses(RefreshDatabase::class);

beforeEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requer MySQL para exercitar as telas da Fase 8 sobre o banco de produção.');
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * @param  list<AccessPermission>  $permissions
 */
function p8mUser(array $permissions): User
{
    $user = User::factory()->withTwoFactor()->create();
    $user->givePermissionTo([AccessPermission::EmissionsView->value, AccessPermission::EmissionsUpdate->value, AccessPermission::PuCurveView->value, ...array_map(fn (AccessPermission $permission): string => $permission->value, $permissions)]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->fresh();
}

it('records, corrects, reverses and decides settlements from the screen on MySQL', function () {
    $emission = Fx::emission([[PuEventType::Amortization, '2026-03-09', ['amortization_type' => PuAmortizationType::UnitValue->value, 'amortization_value' => '1.0000000000000000']]]);
    Fx::official($emission);
    $obligation = Fx::obligation($emission, PuObligationType::ScheduledPayment, '2026-03-09');
    $this->actingAs(p8mUser([AccessPermission::PuReconciliationView, AccessPermission::PuSettlementRecord, AccessPermission::PuSettlementCorrect]));
    $table = fn () => Livewire::test(PuObligationsRelationManager::class, ['ownerRecord' => $emission->fresh(), 'pageClass' => EditEmission::class]);

    $table()->callAction(TestAction::make('recordSettlement')->table($obligation), data: ['settlement_date' => '2026-03-09', 'amount' => '95,00', 'source' => 'manual', 'external_reference' => 'MY-1'])
        ->assertNotified('Liquidação registrada.');
    expect($obligation->fresh()->reconciliation_status)->toBe(PuReconciliationStatus::Divergent);

    $table()->callAction(TestAction::make('correctSettlement')->table($obligation), data: ['amount' => '100,00', 'reason' => 'Comprovante revisto.', 'confirm' => true])
        ->assertNotified('Liquidação corrigida.');
    expect($obligation->fresh()->reconciliation_status)->toBe(PuReconciliationStatus::Matched);

    Fx::settle($obligation, '90.00', reference: 'MY-1');
    $conflict = EmissionPuSettlementConflict::query()->sole();
    $table()->callAction(TestAction::make('resolveConflict')->table($obligation), data: ['conflict_id' => $conflict->id, 'decision' => 'reject', 'reason' => 'Reenvio errado.', 'confirm' => true])
        ->assertNotified('Conflito rejeitado.');
    expect($conflict->fresh()->status)->toBe(PuSettlementConflictStatus::Rejected);

    $table()->callAction(TestAction::make('reverseSettlement')->table($obligation), data: ['reason' => 'Lançado em duplicidade.', 'confirm' => true])
        ->assertNotified('Liquidação estornada.');

    expect(EmissionPuSettlement::query()->where('obligation_id', $obligation->id)->orderBy('id')->pluck('status')->map(fn ($status) => $status->value)->all())
        ->toBe([PuSettlementStatus::Corrected->value, PuSettlementStatus::Reversed->value, PuSettlementStatus::Reversal->value])
        ->and($obligation->fresh()->reconciliation_status)->toBe(PuReconciliationStatus::Pending);
});

it('activates and suspends from the operation page on MySQL, and refuses while the environment is off', function () {
    Activation::withoutAutomaticActivation();
    $emission = Fx::emission([[PuEventType::InterestPayment, '2026-03-09']]);
    $this->actingAs(p8mUser([AccessPermission::PuOperationsActivate]));

    config()->set('pu_calculator.operations.enabled', false);
    Livewire::test(PuOperation::class, ['record' => $emission->getRouteKey()])
        ->assertActionDisabled(TestAction::make('activateOperations'));
    config()->set('pu_calculator.operations.enabled', true);

    Livewire::test(PuOperation::class, ['record' => $emission->getRouteKey()])
        ->callAction(TestAction::make('activateOperations'), data: ['reason' => 'Aprovado.', 'approval_reference' => 'ATA-MY', 'checklist_covered' => true, 'not_homologation' => true])
        ->assertNotified('Operação do PU ativada.');

    Livewire::test(PuOperation::class, ['record' => $emission->getRouteKey()])
        ->callAction(TestAction::make('suspendOperations'), data: ['reason' => 'Pausa.', 'confirm' => true])
        ->assertNotified('Operação do PU suspensa.');

    expect(app(PuOperationalActivationPolicy::class)->status($emission))->toBe(PuOperationalActivationStatus::Suspended);
});

it('lists, orders and acknowledges incidents and summarizes them on the dashboard on MySQL', function () {
    $emission = Fx::emission();
    $critical = PuOperationalIncident::factory()->create([
        'incident_key' => PuOperationalConditionType::SettlementConflictOpen->value.':emission:'.$emission->id,
        'type' => PuOperationalConditionType::SettlementConflictOpen->value,
        'check_name' => PuOperationalConditionType::SettlementConflictOpen->check(),
        'severity' => PuOperationalSeverity::Critical->value,
        'emission_id' => $emission->id,
        'indexer' => null,
    ]);
    $warning = PuOperationalIncident::factory()->create();
    $this->actingAs(p8mUser([AccessPermission::PuOperationsMonitor, AccessPermission::PuOperationsRecover]));

    Livewire::test(PuOperationalIncidents::class)
        ->assertCanSeeTableRecords([$critical, $warning], inOrder: true)
        ->callAction(TestAction::make('acknowledge')->table($critical))
        ->assertNotified('Incidente reconhecido.');

    expect($critical->fresh()->status)->toBe(PuIncidentStatus::Acknowledged)
        ->and(app(PuOperationalDashboardData::class)->overview()['health']['tone'])->toBe('danger');
});

it('corrects a CDI observation from the index screen on MySQL', function () {
    Fx::emission();
    $rate = IndexRate::query()->where('indexer', 'CDI')->whereDate('rate_date', '2026-03-03')->sole();
    $this->actingAs(p8mUser([AccessPermission::PuIndexImport, AccessPermission::PuIndexCorrect]));

    Livewire::test(ListIndexRates::class)
        ->callAction(TestAction::make('correctObservation')->table($rate), data: ['new_value' => '15.05000000', 'reason' => 'Revisão do BCB.', 'confirm' => true])
        ->assertNotified('Observação corrigida.');

    expect(IndexRateCorrection::query()->sole()->new_rate_value)->toBe('15.05000000')
        ->and((string) $rate->fresh()->rate_value)->toBe('15.05000000');
});

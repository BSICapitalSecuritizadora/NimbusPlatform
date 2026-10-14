<?php

use App\Domain\PuCalculator\DTOs\PuSettlementActor;
use App\Domain\PuCalculator\DTOs\PuSettlementCorrectionData;
use App\Domain\PuCalculator\DTOs\PuSettlementData;
use App\Domain\PuCalculator\Enums\PuAmortizationType;
use App\Domain\PuCalculator\Enums\PuEventType;
use App\Domain\PuCalculator\Enums\PuObligationType;
use App\Domain\PuCalculator\Enums\PuReconciliationStatus;
use App\Domain\PuCalculator\Enums\PuSettlementConflictStatus;
use App\Domain\PuCalculator\Enums\PuSettlementEntryType;
use App\Domain\PuCalculator\Enums\PuSettlementSource;
use App\Domain\PuCalculator\Enums\PuSettlementStatus;
use App\Domain\PuCalculator\Services\PuSettlementService;
use App\Enums\AccessPermission;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\PuObligationsRelationManager;
use App\Filament\Resources\Emissions\Pages\EditEmission;
use App\Filament\Resources\Emissions\Pages\ViewEmission;
use App\Models\Emission;
use App\Models\EmissionPuObligation;
use App\Models\EmissionPuSettlement;
use App\Models\EmissionPuSettlementConflict;
use App\Models\User;
use App\Support\BusinessTime;
use App\Support\PuCalculator\PuDisplay;
use App\Support\PuCalculator\PuObligationDetail;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Pu\PuObligationFixture as Fx;

/**
 * Fase 8 (P0-A/P0-B): a tela de obrigações separa cronograma, esperado oficial e
 * liquidação, e toda liquidação passa pelo serviço governado -- inclusive quando
 * o formulário é manipulado ou ficou velho.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * @param  list<AccessPermission>  $permissions
 */
function p8oUser(array $permissions): User
{
    $user = User::factory()->create();
    $user->givePermissionTo([AccessPermission::EmissionsView->value, AccessPermission::EmissionsUpdate->value, ...array_map(fn (AccessPermission $permission): string => $permission->value, $permissions)]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->fresh();
}

/**
 * Amortização ordinária de R$ 1,00 por título em 09/03, 100 títulos: esperado R$ 100,00.
 *
 * @return array{0: Emission, 1: EmissionPuObligation}
 */
function p8oHundred(): array
{
    $emission = Fx::emission([[PuEventType::Amortization, '2026-03-09', ['amortization_type' => PuAmortizationType::UnitValue->value, 'amortization_value' => '1.0000000000000000']]]);
    Fx::official($emission);

    return [$emission, Fx::obligation($emission, PuObligationType::ScheduledPayment, '2026-03-09')];
}

function p8oPage(Emission $emission, string $page = EditEmission::class): Testable
{
    return Livewire::test(PuObligationsRelationManager::class, ['ownerRecord' => $emission, 'pageClass' => $page]);
}

it('lists the official expected value, the settlement and the difference as separate facts', function () {
    [$emission, $obligation] = p8oHundred();
    Fx::settle($obligation, '95.00', reference: 'B3-UI-1');

    $this->actingAs(p8oUser([AccessPermission::PuReconciliationView]));

    p8oPage($emission)
        ->assertCanSeeTableRecords([$obligation])
        ->assertTableColumnStateSet('expected_total', 'R$ 100,00', $obligation)
        ->assertTableColumnStateSet('settled_amount', 'R$ 95,00', $obligation)
        ->assertTableColumnStateSet('difference', PuDisplay::MINUS.'R$ 5,00', $obligation)
        ->assertTableColumnFormattedStateSet('reconciliation_status', 'Divergente', $obligation)
        ->assertDontSee('Liquidada parcialmente')
        ->assertDontSee('Saldo residual')
        ->assertDontSee('95%');
});

it('shows an unavailable expected value with its reason instead of zero', function () {
    $emission = Fx::emission([[PuEventType::InterestPayment, '2026-06-01']]);
    Fx::official($emission);
    $future = Fx::obligation($emission, PuObligationType::ScheduledPayment, '2026-06-01');

    expect($future->calculation_state->value)->not->toBe('calculated');

    $this->actingAs(p8oUser([AccessPermission::PuReconciliationView]));

    p8oPage($emission)
        ->assertTableColumnStateSet('expected_total', PuDisplay::unavailableExpected($future->calculation_state), $future)
        ->assertTableColumnStateSet('settled_amount', PuDisplay::NOT_SETTLED, $future)
        ->assertTableColumnStateNotSet('expected_total', 'R$ 0,00', $future);
});

it('details the components, the expected history by version and the ledger', function () {
    [$emission, $obligation] = p8oHundred();
    Fx::settle($obligation, '100.00', reference: 'B3-UI-2');

    $this->actingAs(p8oUser([AccessPermission::PuReconciliationView]));

    p8oPage($emission)
        ->mountAction(TestAction::make('obligationDetails')->table($obligation))
        ->assertMountedActionModalSee([
            'Cronograma contratual (informado)',
            'Esperado oficial (curva homologada)',
            'Liquidação registrada',
            'Amortização ordinária',
            'Histórico do esperado por versão da curva',
            'Livro de liquidações',
            'Conciliada',
            'não informado',
        ]);
});

it('records a settlement through the governed service', function () {
    [$emission, $obligation] = p8oHundred();
    $user = p8oUser([AccessPermission::PuReconciliationView, AccessPermission::PuSettlementRecord]);
    $this->actingAs($user);

    p8oPage($emission)
        ->callAction(TestAction::make('recordSettlement')->table($obligation), data: [
            'settlement_date' => '2026-03-09',
            'amount' => '100,00',
            'source' => 'manual',
            'external_reference' => 'COMPROVANTE-1',
        ])
        ->assertHasNoFormErrors()
        ->assertNotified('Liquidação registrada.');

    $settlement = EmissionPuSettlement::query()->sole();

    expect($settlement->amount)->toBe('100.00')
        ->and($settlement->recorded_by)->toBe($user->id)
        ->and($settlement->recorded_via)->toBe('user')
        ->and($settlement->components)->toBeNull()
        ->and($obligation->fresh()->reconciliation_status)->toBe(PuReconciliationStatus::Matched);
});

it('refuses three decimal places instead of rounding the settled amount', function () {
    [$emission, $obligation] = p8oHundred();
    $this->actingAs(p8oUser([AccessPermission::PuReconciliationView, AccessPermission::PuSettlementRecord]));

    p8oPage($emission)
        ->callAction(TestAction::make('recordSettlement')->table($obligation), data: [
            'settlement_date' => '2026-03-09',
            'amount' => '99,995',
            'source' => 'manual',
        ])
        ->assertHasFormErrors(['amount']);

    expect(EmissionPuSettlement::query()->count())->toBe(0);
});

it('lets the domain reject components that do not add up, without saving', function () {
    [$emission, $obligation] = p8oHundred();
    $this->actingAs(p8oUser([AccessPermission::PuReconciliationView, AccessPermission::PuSettlementRecord]));

    p8oPage($emission)
        ->callAction(TestAction::make('recordSettlement')->table($obligation), data: [
            'settlement_date' => '2026-03-09',
            'amount' => '100,00',
            'source' => 'bank',
            'has_components' => true,
            'components' => ['ordinary_amortization' => '90,00'],
        ])
        ->assertNotified('Liquidação não registrada.');

    expect(EmissionPuSettlement::query()->count())->toBe(0);
});

it('hides settlement recording from users without the permission and refuses a forged call', function () {
    [$emission, $obligation] = p8oHundred();
    $user = p8oUser([AccessPermission::PuReconciliationView]);
    $this->actingAs($user);

    p8oPage($emission)
        ->assertActionHidden(TestAction::make('recordSettlement')->table($obligation))
        ->assertActionHidden(TestAction::make('correctSettlement')->table($obligation))
        ->call('mountAction', 'recordSettlement', [], ['table' => true, 'recordKey' => (string) $obligation->getKey()])
        ->assertSet('mountedActions', [])
        ->call('callMountedAction');

    expect(EmissionPuSettlement::query()->count())->toBe(0)
        ->and(fn () => app(PuSettlementService::class)->record(
            new PuSettlementData($emission->id, '2026-03-09', '100.00', PuSettlementSource::Manual, obligationId: $obligation->id),
            PuSettlementActor::user($user),
        ))->toThrow(AuthorizationException::class);
});

it('disables settlement recording with the reason while PU operations are globally off', function () {
    [$emission, $obligation] = p8oHundred();
    config()->set('pu_calculator.operations.enabled', false);
    $this->actingAs(p8oUser([AccessPermission::PuReconciliationView, AccessPermission::PuSettlementRecord]));

    p8oPage($emission)
        ->assertActionDisabled(TestAction::make('recordSettlement')->table($obligation))
        ->assertActionExists(
            TestAction::make('recordSettlement')->table($obligation),
            fn (Action $action): bool => str_contains((string) $action->getTooltip(), 'PU_OPERATIONS_ENABLED')
                && str_contains((string) $action->getTooltip(), 'Nada foi gravado'),
        )
        ->call('mountAction', 'recordSettlement', [], ['table' => true, 'recordKey' => (string) $obligation->getKey()])
        ->assertSet('mountedActions', []);

    expect(EmissionPuSettlement::query()->count())->toBe(0);
});

it('corrects a settlement by appending a new entry and keeps the original in the ledger', function () {
    [$emission, $obligation] = p8oHundred();
    Fx::settle($obligation, '95.00', reference: 'B3-UI-3');
    $original = EmissionPuSettlement::query()->sole();
    $this->actingAs(p8oUser([AccessPermission::PuReconciliationView, AccessPermission::PuSettlementCorrect]));

    p8oPage($emission)
        ->mountAction(TestAction::make('correctSettlement')->table($obligation))
        ->assertSchemaStateSet(['settlement_id' => $original->id, 'amount' => '95,00'])
        ->setActionData(['amount' => '100,00'])
        ->assertMountedActionModalSee(['Antes e depois', 'R$ 95,00', 'R$ 100,00', 'alterado'])
        ->setActionData(['reason' => 'Comprovante da B3 reemitido.', 'confirm' => true])
        ->callMountedAction()
        ->assertHasNoFormErrors()
        ->assertNotified('Liquidação corrigida.');

    $original->refresh();
    $correction = EmissionPuSettlement::query()->where('predecessor_id', $original->id)->sole();

    expect($original->status)->toBe(PuSettlementStatus::Corrected)
        ->and($original->amount)->toBe('95.00')
        ->and($correction->entry_type)->toBe(PuSettlementEntryType::Correction)
        ->and($correction->amount)->toBe('100.00')
        ->and($correction->reason)->toBe('Comprovante da B3 reemitido.')
        ->and($obligation->fresh()->reconciliation_status)->toBe(PuReconciliationStatus::Matched);
});

it('requires the confirmation and the reason before correcting', function () {
    [$emission, $obligation] = p8oHundred();
    Fx::settle($obligation, '95.00', reference: 'B3-UI-4');
    $this->actingAs(p8oUser([AccessPermission::PuReconciliationView, AccessPermission::PuSettlementCorrect]));

    p8oPage($emission)
        ->callAction(TestAction::make('correctSettlement')->table($obligation), data: ['amount' => '100,00', 'reason' => '', 'confirm' => false])
        ->assertHasFormErrors(['reason', 'confirm']);

    expect(EmissionPuSettlement::query()->count())->toBe(1);
});

it('refuses a correction aimed at a settlement of another obligation', function () {
    [$emission, $obligation] = p8oHundred();
    $other = Fx::emission([[PuEventType::Amortization, '2026-03-09', ['amortization_type' => PuAmortizationType::UnitValue->value, 'amortization_value' => '1.0000000000000000']]]);
    Fx::official($other);
    $otherObligation = Fx::obligation($other, PuObligationType::ScheduledPayment, '2026-03-09');
    Fx::settle($obligation, '95.00', reference: 'B3-UI-5');
    Fx::settle($otherObligation, '95.00', reference: 'B3-UI-6');
    $foreign = EmissionPuSettlement::query()->where('obligation_id', $otherObligation->id)->sole();
    $this->actingAs(p8oUser([AccessPermission::PuReconciliationView, AccessPermission::PuSettlementCorrect]));

    p8oPage($emission)
        ->mountAction(TestAction::make('correctSettlement')->table($obligation))
        ->setActionData(['settlement_id' => $foreign->id, 'amount' => '1,00', 'reason' => 'Forjado.', 'confirm' => true])
        ->callMountedAction()
        ->assertNotified('Operação recusada.');

    expect($foreign->fresh()->status)->toBe(PuSettlementStatus::Active)
        ->and(EmissionPuSettlement::query()->count())->toBe(2);
});

it('does not correct a settlement that another user already corrected while the form was open', function () {
    [$emission, $obligation] = p8oHundred();
    Fx::settle($obligation, '95.00', reference: 'B3-UI-7');
    $original = EmissionPuSettlement::query()->sole();
    $this->actingAs(p8oUser([AccessPermission::PuReconciliationView, AccessPermission::PuSettlementCorrect]));

    $component = p8oPage($emission)->mountAction(TestAction::make('correctSettlement')->table($obligation));

    app(PuSettlementService::class)->correct(
        $original->id,
        new PuSettlementCorrectionData('2026-03-09', '99.00'),
        'Correção concorrente.',
        Fx::integration(),
    );

    $component->setActionData(['amount' => '100,00', 'reason' => 'Correção velha.', 'confirm' => true])
        ->callMountedAction()
        ->assertNotified('Correção não registrada.');

    expect(EmissionPuSettlement::query()->count())->toBe(2)
        ->and(EmissionPuSettlement::query()->where('status', PuSettlementStatus::Active->value)->sole()->amount)->toBe('99.00');
});

it('reverses a settlement and the obligation goes back to unsettled', function () {
    [$emission, $obligation] = p8oHundred();
    Fx::settle($obligation, '100.00', reference: 'B3-UI-8');
    $original = EmissionPuSettlement::query()->sole();
    $this->actingAs(p8oUser([AccessPermission::PuReconciliationView, AccessPermission::PuSettlementCorrect]));

    p8oPage($emission)
        ->callAction(TestAction::make('reverseSettlement')->table($obligation), data: ['reason' => 'Lançado na obrigação errada.', 'confirm' => true])
        ->assertHasNoFormErrors()
        ->assertNotified('Liquidação estornada.');

    expect($original->fresh()->status)->toBe(PuSettlementStatus::Reversed)
        ->and(EmissionPuSettlement::query()->where('entry_type', PuSettlementEntryType::Reversal->value)->count())->toBe(1)
        ->and($obligation->fresh()->activeSettlement)->toBeNull()
        ->and($obligation->fresh()->reconciliation_status)->toBe(PuReconciliationStatus::Pending);
});

it('reviews and decides a settlement conflict only with the correction permission', function () {
    [$emission, $obligation] = p8oHundred();
    Fx::settle($obligation, '95.00', reference: 'B3-UI-9');
    Fx::settle($obligation, '100.00', reference: 'B3-UI-9');
    $conflict = EmissionPuSettlementConflict::query()->sole();

    $this->actingAs(p8oUser([AccessPermission::PuReconciliationView, AccessPermission::PuSettlementRecord]));
    p8oPage($emission)->assertActionHidden(TestAction::make('resolveConflict')->table($obligation));

    $this->actingAs(p8oUser([AccessPermission::PuReconciliationView, AccessPermission::PuSettlementCorrect]));
    p8oPage($emission)
        ->mountAction(TestAction::make('resolveConflict')->table($obligation))
        ->assertSchemaStateSet(['conflict_id' => $conflict->id])
        ->assertMountedActionModalSee(['Registrado × recebido', 'Mesma referência com dados diferentes', 'R$ 95,00', 'R$ 100,00'])
        ->setActionData(['decision' => 'accept', 'reason' => 'Valor confirmado com o agente.', 'confirm' => true])
        ->callMountedAction()
        ->assertNotified('Conflito aceito como correção.');

    expect($conflict->fresh()->status)->toBe(PuSettlementConflictStatus::Accepted)
        ->and(EmissionPuSettlement::query()->where('status', PuSettlementStatus::Active->value)->sole()->amount)->toBe('100.00')
        ->and($obligation->fresh()->reconciliation_status)->toBe(PuReconciliationStatus::Matched);
});

it('keeps the tab away from users without the reconciliation permission', function () {
    [$emission] = p8oHundred();

    $this->actingAs(p8oUser([]));
    expect(PuObligationsRelationManager::canViewForRecord($emission, EditEmission::class))->toBeFalse();

    $this->actingAs(p8oUser([AccessPermission::PuReconciliationView]));
    expect(PuObligationsRelationManager::canViewForRecord($emission, ViewEmission::class))->toBeTrue();
});

it('exports the reconciliation only with the export permission', function () {
    [$emission] = p8oHundred();

    $this->actingAs(p8oUser([AccessPermission::PuReconciliationView]));
    p8oPage($emission)->assertActionHidden(TestAction::make('exportReconciliation')->table());

    $this->actingAs(p8oUser([AccessPermission::PuReconciliationView, AccessPermission::PuReconciliationExport]));
    p8oPage($emission)
        ->callAction(TestAction::make('exportReconciliation')->table())
        ->assertFileDownloaded(sprintf('conciliacao-pu-emissao-%d-%s.csv', $emission->id, BusinessTime::dateString()));
});

it('keeps the expected history by curve version without merging it into the current value', function () {
    [$emission, $obligation] = p8oHundred();
    Fx::settle($obligation, '100.00', reference: 'B3-UI-10');
    // Uma nova versão homologada recalcula a mesma obrigação (v2); a v1 fica no
    // histórico como substituída, e a liquidação guarda o esperado do registro.
    Fx::official($emission);
    $this->actingAs(p8oUser([AccessPermission::PuReconciliationView]));

    $detail = PuObligationDetail::build($obligation->fresh());

    expect(array_column($detail['calculations'], 'version'))->toBe(['v2', 'v1'])
        ->and(array_column($detail['calculations'], 'current'))->toBe([true, false])
        ->and($detail['settlement']['expected_version_at_recording'])->toBe('v1')
        ->and($detail['expected']['version'])->toBe('v2');

    p8oPage($emission)
        ->mountAction(TestAction::make('obligationDetails')->table($obligation))
        ->assertMountedActionModalSee(['Vigente', 'Substituído', 'Esperado no registro']);
});

it('renders the obligations table without one query per obligation', function () {
    $queriesFor = function (int $payments): int {
        $dates = ['2026-03-09', '2026-03-10', '2026-03-11', '2026-03-12', '2026-03-13', '2026-03-16'];
        $emission = Fx::emission(array_map(fn (string $date): array => [PuEventType::InterestPayment, $date], array_slice($dates, 0, $payments)));
        Fx::official($emission);
        $this->actingAs(p8oUser([AccessPermission::PuReconciliationView, AccessPermission::PuSettlementRecord, AccessPermission::PuSettlementCorrect]));

        DB::flushQueryLog();
        DB::enableQueryLog();
        p8oPage($emission)->assertSuccessful();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $few = $queriesFor(2);
    $many = $queriesFor(6);

    expect(EmissionPuObligation::query()->count())->toBe(8)
        ->and($many - $few)->toBeLessThanOrEqual(2);
});

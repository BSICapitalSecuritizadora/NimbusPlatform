<?php

use App\Domain\PuCalculator\DTOs\PuCurveInputSnapshot;
use App\Domain\PuCalculator\DTOs\PuSettlementActor;
use App\Domain\PuCalculator\DTOs\PuSettlementCorrectionData;
use App\Domain\PuCalculator\DTOs\PuSettlementData;
use App\Domain\PuCalculator\Enums\PuAmortizationType;
use App\Domain\PuCalculator\Enums\PuEventType;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuObligationCalculationState;
use App\Domain\PuCalculator\Enums\PuObligationComponent;
use App\Domain\PuCalculator\Enums\PuObligationType;
use App\Domain\PuCalculator\Enums\PuReconciliationStatus;
use App\Domain\PuCalculator\Enums\PuSettlementConflictKind;
use App\Domain\PuCalculator\Enums\PuSettlementConflictStatus;
use App\Domain\PuCalculator\Enums\PuSettlementEntryType;
use App\Domain\PuCalculator\Enums\PuSettlementOutcome;
use App\Domain\PuCalculator\Enums\PuSettlementSource;
use App\Domain\PuCalculator\Enums\PuSettlementState;
use App\Domain\PuCalculator\Enums\PuSettlementStatus;
use App\Domain\PuCalculator\Services\IndexRateCorrectionService;
use App\Domain\PuCalculator\Services\PuCurveExtensionService;
use App\Domain\PuCalculator\Services\PuCurveInputSnapshotService;
use App\Domain\PuCalculator\Services\PuObligationMonitorSnapshot;
use App\Domain\PuCalculator\Services\PuObligationReader;
use App\Domain\PuCalculator\Services\PuReconciliationExportService;
use App\Domain\PuCalculator\Services\PuSettlementService;
use App\Enums\AccessPermission;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuDailyCurve;
use App\Models\EmissionPuEvent;
use App\Models\EmissionPuObligation;
use App\Models\EmissionPuReconciliation;
use App\Models\EmissionPuSettlement;
use App\Models\EmissionPuSettlementConflict;
use App\Models\User;
use App\Services\Guarantees\OutstandingBalanceResolver;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Pu\PuObligationFixture as Fx;

/**
 * Fase 5 -- liquidação é um FATO registrado, fechado e imutável.
 *
 * A obrigação está liquidada ou não. Liquidação B3 de valor diferente do
 * esperado é liquidação fechada com divergência: nunca parcial, nunca saldo
 * residual, nunca mudança de principal. A mesma mensagem é idempotente; dados
 * diferentes viram conflito; correção e estorno são lançamentos novos.
 */
uses(RefreshDatabase::class);

/**
 * Amortização ordinária de R$ 1,00 por título em 09/03 (sem pagamento de juros):
 * com 100 títulos, a obrigação esperada é exatamente R$ 100,00.
 */
function p5sHundred(): array
{
    $emission = Fx::emission([[PuEventType::Amortization, '2026-03-09', ['amortization_type' => PuAmortizationType::UnitValue->value, 'amortization_value' => '1.0000000000000000']]]);
    $official = Fx::official($emission);

    return [$emission, $official, Fx::obligation($emission, PuObligationType::ScheduledPayment, '2026-03-09')];
}

/**
 * Juros e amortização ordinária (R$ 100 por título) no mesmo pagamento de 09/03.
 */
function p5sComponents(): array
{
    $emission = Fx::emission([
        [PuEventType::InterestPayment, '2026-03-09'],
        [PuEventType::Amortization, '2026-03-09', ['amortization_type' => PuAmortizationType::UnitValue->value, 'amortization_value' => '100.0000000000000000', 'sequence' => 2]],
    ]);
    $official = Fx::official($emission);

    return [$emission, $official, Fx::obligation($emission, PuObligationType::ScheduledPayment, '2026-03-09')];
}

it('closes a B3 settlement below the expected value as a divergence, never as a partial settlement', function () {
    [$emission, $official, $obligation] = p5sHundred();
    $rowBefore = Fx::row($official, '2026-03-09')->toArray();

    $result = Fx::settle($obligation, '80.00', reference: 'B3-EV-1');
    $obligation->refresh();
    $reconciliation = $obligation->latestReconciliation;

    expect(Fx::expectedTotal($obligation))->toBe('100.00')
        ->and($result->outcome)->toBe(PuSettlementOutcome::Recorded)
        ->and($obligation->settlement_state)->toBe(PuSettlementState::Settled)
        ->and($obligation->reconciliation_status)->toBe(PuReconciliationStatus::Divergent)
        ->and((string) $reconciliation->difference)->toBe('-20.00')
        ->and($reconciliation->divergence['kinds'])->toBe(['amount'])
        ->and($reconciliation->divergence['amount'])->toBe(['expected' => '100.00', 'actual' => '80.00', 'difference' => '-20.00'])
        // Nenhuma obrigação residual de 20, nenhum "80% pago", nenhum estado parcial.
        ->and(EmissionPuObligation::query()->whereBelongsTo($emission)->count())->toBe(1)
        ->and(array_map(fn (PuSettlementState $state): string => $state->value, PuSettlementState::cases()))->toBe(['unsettled', 'settled'])
        ->and(EmissionPuSettlement::query()->where('obligation_id', $obligation->id)->count())->toBe(1)
        // A liquidação não toca a curva nem o principal.
        ->and(Fx::row($official, '2026-03-09')->toArray())->toBe($rowBefore)
        ->and(EmissionPuCurveVersion::query()->where('emission_id', $emission->id)->count())->toBe(1);
});

it('closes a B3 settlement above the expected value as a divergence and leaves the principal alone', function () {
    [$emission, $official, $obligation] = p5sHundred();
    $residual = (string) Fx::row($official, '2026-03-09')->residual_unit_value;

    Fx::settle($obligation, '120.00', reference: 'B3-EV-2');
    $obligation->refresh();

    expect($obligation->settlement_state)->toBe(PuSettlementState::Settled)
        ->and($obligation->reconciliation_status)->toBe(PuReconciliationStatus::Divergent)
        ->and((string) $obligation->latestReconciliation->difference)->toBe('20.00')
        ->and((string) Fx::row($official, '2026-03-09')->residual_unit_value)->toBe($residual)
        ->and(EmissionPuObligation::query()->whereBelongsTo($emission)->count())->toBe(1);
});

it('matches an exact settlement at the canonical monetary scale, with no invented tolerance', function () {
    [, , $obligation] = p5sHundred();

    Fx::settle($obligation, '100.00', reference: 'B3-EV-3');
    $matched = $obligation->fresh();
    $reversed = app(PuSettlementService::class)->reverse($matched->activeSettlement->id, 'Teste de tolerância.', Fx::integration());
    Fx::settle($obligation, '100.01', reference: 'B3-EV-3B');

    expect($matched->reconciliation_status)->toBe(PuReconciliationStatus::Matched)
        ->and((string) $matched->latestReconciliation->difference)->toBe('0.00')
        ->and($matched->latestReconciliation->divergence['component_reconciliation'])->toBe('total_only')
        ->and($reversed->outcome)->toBe(PuSettlementOutcome::Recorded)
        // Um centavo é divergência: não existe tolerância de liquidação no produto.
        ->and($obligation->fresh()->reconciliation_status)->toBe(PuReconciliationStatus::Divergent)
        ->and((string) $obligation->fresh()->latestReconciliation->difference)->toBe('0.01');
});

it('flags a settlement on another date as a date divergence, without creating charges', function () {
    [$emission, , $obligation] = p5sHundred();

    Fx::settle($obligation, '100.00', '2026-03-10', 'B3-EV-4');
    $obligation->refresh();

    expect($obligation->reconciliation_status)->toBe(PuReconciliationStatus::Divergent)
        ->and($obligation->latestReconciliation->divergence['kinds'])->toBe(['date'])
        ->and($obligation->latestReconciliation->divergence['date'])->toBe(['expected' => '2026-03-09', 'actual' => '2026-03-10'])
        ->and((string) $obligation->latestReconciliation->difference)->toBe('0.00')
        ->and(EmissionPuObligation::query()->whereBelongsTo($emission)->count())->toBe(1);
});

it('keeps components distinct and reconciles them only when the source provides them', function () {
    [, , $obligation] = p5sComponents();
    $calculation = $obligation->currentCalculation;
    $interest = $calculation->componentAmount(PuObligationComponent::OrdinaryInterest);
    $amortization = $calculation->componentAmount(PuObligationComponent::OrdinaryAmortization);
    $total = (string) $calculation->total_amount;

    // Só o total: nada é rateado, nada é comparado por componente.
    $totalOnly = Fx::settle($obligation, $total, reference: 'B3-EV-5');
    $afterTotalOnly = $obligation->fresh();
    app(PuSettlementService::class)->reverse($totalOnly->settlement->id, 'Refazer com componentes.', Fx::integration());

    // Componentes que somam o total, mas repartidos de outro jeito: divergência de componentes.
    Fx::settle($obligation, $total, reference: 'B3-EV-6', components: [
        PuObligationComponent::OrdinaryInterest->value => bcadd($interest, '1.00', 2),
        PuObligationComponent::OrdinaryAmortization->value => bcsub($amortization, '1.00', 2),
    ]);
    $splitDiffers = $obligation->fresh();

    expect($amortization)->toBe('10000.00')
        ->and($total)->toBe(bcadd($interest, $amortization, 2))
        ->and($totalOnly->settlement->components)->toBeNull()
        ->and($afterTotalOnly->reconciliation_status)->toBe(PuReconciliationStatus::Matched)
        ->and($afterTotalOnly->latestReconciliation->divergence['component_reconciliation'])->toBe('total_only')
        ->and($splitDiffers->reconciliation_status)->toBe(PuReconciliationStatus::Divergent)
        ->and($splitDiffers->latestReconciliation->divergence['kinds'])->toBe(['components'])
        ->and($splitDiffers->latestReconciliation->divergence['components'][PuObligationComponent::OrdinaryInterest->value]['difference'])->toBe('1.00')
        ->and($splitDiffers->latestReconciliation->divergence['components'][PuObligationComponent::OrdinaryAmortization->value]['difference'])->toBe('-1.00')
        // O total liquidado não apaga a identidade dos componentes esperados.
        ->and($splitDiffers->currentCalculation->components->pluck('component')->map->value->sort()->values()->all())
        ->toBe([PuObligationComponent::OrdinaryAmortization->value, PuObligationComponent::OrdinaryInterest->value]);
});

it('keeps an obligation without settlement pending, and past due only by reading the business day', function () {
    [$emission, , $obligation] = p5sHundred();
    $this->travelTo(CarbonImmutable::parse('2026-03-20 10:00', 'America/Sao_Paulo'));

    $snapshot = app(PuObligationMonitorSnapshot::class)->snapshot($emission->id);

    expect($obligation->reconciliation_status)->toBe(PuReconciliationStatus::Pending)
        ->and($obligation->settlement_state)->toBe(PuSettlementState::Unsettled)
        ->and($snapshot['unsettled_past_due'])->toBe([$obligation->id]);
});

it('ignores a repeated message with the same reference and data', function () {
    [, , $obligation] = p5sHundred();

    $first = Fx::settle($obligation, '100.00', reference: 'B3-DUP');
    $second = Fx::settle($obligation, '100.00', reference: 'B3-DUP');

    expect($first->outcome)->toBe(PuSettlementOutcome::Recorded)
        ->and($second->outcome)->toBe(PuSettlementOutcome::Duplicate)
        ->and($second->settlement->id)->toBe($first->settlement->id)
        ->and(EmissionPuSettlement::query()->where('obligation_id', $obligation->id)->count())->toBe(1)
        ->and(EmissionPuReconciliation::query()->where('obligation_id', $obligation->id)->count())->toBe(2);
});

it('opens a conflict when the same reference arrives with other data, without touching the original', function () {
    [, , $obligation] = p5sHundred();
    $original = Fx::settle($obligation, '100.00', reference: 'B3-CONF')->settlement;

    $conflicting = Fx::settle($obligation, '95.00', reference: 'B3-CONF');
    $again = Fx::settle($obligation, '95.00', reference: 'B3-CONF');

    expect($conflicting->outcome)->toBe(PuSettlementOutcome::Conflict)
        ->and($conflicting->conflict->kind)->toBe(PuSettlementConflictKind::ReferenceDataMismatch)
        ->and($conflicting->conflict->existing_settlement_id)->toBe($original->id)
        ->and($conflicting->conflict->incoming_payload['amount'])->toBe('95.00')
        ->and($again->outcome)->toBe(PuSettlementOutcome::Conflict)
        ->and($again->conflict->id)->toBe($conflicting->conflict->id)
        ->and(EmissionPuSettlementConflict::query()->count())->toBe(1)
        ->and($original->fresh()->status)->toBe(PuSettlementStatus::Active)
        ->and((string) $original->fresh()->amount)->toBe('100.00')
        ->and($obligation->fresh()->reconciliation_status)->toBe(PuReconciliationStatus::Conflict)
        ->and(Activity::query()->where('description', 'pu_settlement_conflict_detected')->count())->toBe(1);

    // Rejeitar descarta os dados novos; a conciliação volta a olhar só a liquidação.
    app(PuSettlementService::class)->resolveConflict($conflicting->conflict->id, false, 'A B3 confirmou o valor original.', Fx::integration());

    expect($conflicting->conflict->fresh()->status)->toBe(PuSettlementConflictStatus::Rejected)
        ->and($obligation->fresh()->reconciliation_status)->toBe(PuReconciliationStatus::Matched);
});

it('turns an accepted conflict into a correction and keeps the original as evidence', function () {
    [, , $obligation] = p5sHundred();
    $original = Fx::settle($obligation, '100.00', reference: 'B3-ACC')->settlement;
    $conflict = Fx::settle($obligation, '95.00', reference: 'B3-ACC')->conflict;

    $resolved = app(PuSettlementService::class)->resolveConflict($conflict->id, true, 'Valor corrigido pela B3.', Fx::integration());
    $replay = Fx::settle($obligation, '95.00', reference: 'B3-ACC');

    expect($resolved->settlement->entry_type)->toBe(PuSettlementEntryType::Correction)
        ->and($resolved->settlement->predecessor_id)->toBe($original->id)
        ->and($original->fresh()->status)->toBe(PuSettlementStatus::Corrected)
        ->and($conflict->fresh()->status)->toBe(PuSettlementConflictStatus::Accepted)
        ->and($conflict->fresh()->resolution_settlement_id)->toBe($resolved->settlement->id)
        ->and((string) $obligation->fresh()->latestReconciliation->difference)->toBe('-5.00')
        // A mesma mensagem, de novo, já é o que está vigente: idempotente.
        ->and($replay->outcome)->toBe(PuSettlementOutcome::Duplicate)
        ->and($replay->settlement->id)->toBe($resolved->settlement->id);
});

it('never accepts a second settlement for an obligation already closed', function () {
    [, , $obligation] = p5sHundred();
    $first = Fx::settle($obligation, '100.00', reference: 'B3-ONE');

    $second = Fx::settle($obligation, '100.00', reference: 'B3-TWO');

    expect($second->outcome)->toBe(PuSettlementOutcome::Conflict)
        ->and($second->conflict->kind)->toBe(PuSettlementConflictKind::ObligationAlreadySettled)
        ->and($second->conflict->existing_settlement_id)->toBe($first->settlement->id)
        ->and(EmissionPuSettlement::query()->where('obligation_id', $obligation->id)->where('status', PuSettlementStatus::Active->value)->count())->toBe(1);
});

it('corrects a settlement by appending a new entry, preserving the original, the reason and the actor', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    [, , $obligation] = p5sHundred();
    $admin = makeAdminUser();
    $first = Fx::settle($obligation, '80.00', reference: 'B3-COR')->settlement;

    $corrected = app(PuSettlementService::class)->correct(
        $first->id,
        new PuSettlementCorrectionData('2026-03-09', '100.00', externalReference: 'B3-COR-R1'),
        'Arquivo da B3 reprocessado.',
        PuSettlementActor::user($admin),
    );
    $again = app(PuSettlementService::class)->correct($first->id, new PuSettlementCorrectionData('2026-03-09', '99.00'), 'Outra.', PuSettlementActor::user($admin));
    $obligation->refresh();

    expect($corrected->outcome)->toBe(PuSettlementOutcome::Recorded)
        ->and($first->fresh()->status)->toBe(PuSettlementStatus::Corrected)
        ->and((string) $first->fresh()->amount)->toBe('80.00')
        ->and($first->fresh()->superseded_by_settlement_id)->toBe($corrected->settlement->id)
        ->and($corrected->settlement->predecessor_id)->toBe($first->id)
        ->and($corrected->settlement->reason)->toBe('Arquivo da B3 reprocessado.')
        ->and($corrected->settlement->recorded_by)->toBe($admin->id)
        ->and($obligation->activeSettlement->id)->toBe($corrected->settlement->id)
        ->and($obligation->reconciliation_status)->toBe(PuReconciliationStatus::Matched)
        ->and($obligation->latestReconciliation->settlement_id)->toBe($corrected->settlement->id)
        // A original já não é vigente: corrigir de novo por ela é recusado.
        ->and($again->outcome)->toBe(PuSettlementOutcome::Rejected)
        ->and(Activity::query()->where('description', 'pu_settlement_corrected')->sole()->properties['previous']['amount'])->toBe('80.00');
});

it('reverses a settlement without erasing it and never resurrects it from a replayed message', function () {
    [, , $obligation] = p5sHundred();
    $settlement = Fx::settle($obligation, '100.00', reference: 'B3-REV')->settlement;

    $reversal = app(PuSettlementService::class)->reverse($settlement->id, 'Liquidação lançada na emissão errada.', Fx::integration());
    $replay = Fx::settle($obligation, '100.00', reference: 'B3-REV');
    $obligation->refresh();

    expect($reversal->settlement->entry_type)->toBe(PuSettlementEntryType::Reversal)
        ->and($reversal->settlement->status)->toBe(PuSettlementStatus::Reversal)
        ->and($settlement->fresh()->status)->toBe(PuSettlementStatus::Reversed)
        ->and($obligation->settlement_state)->toBe(PuSettlementState::Unsettled)
        ->and($obligation->reconciliation_status)->toBe(PuReconciliationStatus::Pending)
        ->and($replay->outcome)->toBe(PuSettlementOutcome::Duplicate)
        ->and(EmissionPuSettlement::query()->where('obligation_id', $obligation->id)->count())->toBe(2);
});

it('refuses to edit or delete settlement facts, calculations and reconciliation results', function () {
    [, , $obligation] = p5sHundred();
    $settlement = Fx::settle($obligation, '100.00', reference: 'B3-IMM')->settlement;
    $calculation = $obligation->fresh()->currentCalculation;
    $component = $calculation->components->first();
    $reconciliation = $obligation->fresh()->latestReconciliation;

    expect(fn () => $settlement->fresh()->forceFill(['amount' => '90.00'])->save())->toThrow(LogicException::class)
        ->and(fn () => $settlement->fresh()->forceFill(['settlement_date' => '2026-03-10'])->save())->toThrow(LogicException::class)
        ->and(fn () => $settlement->fresh()->forceFill(['status' => PuSettlementStatus::Reversal])->save())->toThrow(LogicException::class)
        ->and(fn () => $settlement->fresh()->delete())->toThrow(LogicException::class)
        ->and(fn () => $calculation->fresh()->forceFill(['total_amount' => '1.00'])->save())->toThrow(LogicException::class)
        ->and(fn () => $calculation->fresh()->delete())->toThrow(LogicException::class)
        ->and(fn () => $component->forceFill(['amount' => '1.00'])->save())->toThrow(LogicException::class)
        ->and(fn () => $reconciliation->forceFill(['status' => PuReconciliationStatus::Divergent])->save())->toThrow(LogicException::class)
        ->and(fn () => $reconciliation->delete())->toThrow(LogicException::class)
        ->and(fn () => $obligation->fresh()->delete())->toThrow(LogicException::class)
        ->and(fn () => $obligation->fresh()->forceFill(['contractual_date' => '2026-03-10'])->save())->toThrow(LogicException::class);
});

it('authorizes every settlement mutation on the server, apart from editing the emission', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    [, , $obligation] = p5sHundred();
    $editor = User::factory()->create();
    $editor->assignRole('editor');
    $admin = makeAdminUser();
    $data = new PuSettlementData(
        emissionId: $obligation->emission_id,
        settlementDate: '2026-03-09',
        amount: '100.00',
        source: PuSettlementSource::Manual,
        obligationId: $obligation->id,
    );

    expect($editor->can(AccessPermission::EmissionsUpdate->value))->toBeTrue()
        ->and(fn () => app(PuSettlementService::class)->record($data, PuSettlementActor::user($editor)))->toThrow(AuthorizationException::class)
        ->and(EmissionPuSettlement::query()->count())->toBe(0);

    $recorded = app(PuSettlementService::class)->record($data, PuSettlementActor::user($admin));

    expect($recorded->outcome)->toBe(PuSettlementOutcome::Recorded)
        ->and(fn () => app(PuSettlementService::class)->reverse($recorded->settlement->id, 'Sem permissão.', PuSettlementActor::user($editor)))->toThrow(AuthorizationException::class)
        ->and(fn () => app(PuSettlementService::class)->correct($recorded->settlement->id, new PuSettlementCorrectionData('2026-03-09', '99.00'), 'Sem permissão.', PuSettlementActor::user($editor)))->toThrow(AuthorizationException::class)
        ->and(fn () => app(PuReconciliationExportService::class)->rows($obligation->emission, $editor))->toThrow(AuthorizationException::class)
        ->and(app(PuReconciliationExportService::class)->rows($obligation->emission, $admin)[0]['reconciliation_status'])->toBe(PuReconciliationStatus::Matched->value)
        ->and($admin->can(AccessPermission::PuSettlementCorrect->value))->toBeTrue()
        ->and($editor->can(AccessPermission::PuReconciliationView->value))->toBeFalse()
        ->and(fn () => app(PuObligationReader::class)->forEmission($obligation->emission, $editor))->toThrow(AuthorizationException::class)
        ->and(app(PuObligationReader::class)->forEmission($obligation->emission, $admin)->sole()->id)->toBe($obligation->id);
});

it('rejects a settlement without obligation or with invalid data, writing nothing but the attempt', function (array $override, string $reason) {
    [, , $obligation] = p5sHundred();
    $data = new PuSettlementData(...[
        'emissionId' => $obligation->emission_id,
        'settlementDate' => '2026-03-09',
        'amount' => '100.00',
        'source' => PuSettlementSource::B3,
        'obligationType' => PuObligationType::ScheduledPayment,
        'contractualDate' => '2026-03-09',
        ...$override,
    ]);

    $result = app(PuSettlementService::class)->record($data, Fx::integration());

    expect($result->outcome)->toBe(PuSettlementOutcome::Rejected)
        ->and($result->reason)->toContain($reason)
        ->and(EmissionPuSettlement::query()->count())->toBe(0)
        ->and(Activity::query()->where('description', 'pu_settlement_rejected')->count())->toBe(1);
})->with([
    'obrigação inexistente' => [['contractualDate' => '2026-03-10'], 'Nenhuma obrigação'],
    'três casas decimais' => [['amount' => '100.001'], 'até duas casas'],
    'valor negativo' => [['amount' => '-1.00'], 'não negativo'],
    'moeda estrangeira' => [['currency' => 'USD'], 'BRL'],
    'componentes que não somam o total' => [['components' => ['ordinary_amortization' => '90.00']], 'somam'],
    'componente desconhecido' => [['components' => ['bonus' => '100.00']], 'desconhecido'],
    'data inválida' => [['settlementDate' => '2026-02-30'], 'data válida'],
]);

it('records a settlement while the expected value awaits reprocessing, and leaves the reconciliation indeterminate', function () {
    [$emission, , $obligation] = p5sHundred();
    Fx::publish('2026-03-16', '2026-03-18');

    // Correção de CDI usado pela oficial: o trecho a partir de 05/03 fica em dúvida.
    app(IndexRateCorrectionService::class)->correct(PuIndexer::Cdi, '2026-03-04', '15.50000000', 'Revisão do Banco Central.', User::factory()->create()->id);
    $obligation->refresh();
    $result = Fx::settle($obligation, '100.00', reference: 'B3-REPROC');
    $obligation->refresh();

    expect($obligation->calculation_state)->toBe(PuObligationCalculationState::ReprocessingRequired)
        ->and($result->outcome)->toBe(PuSettlementOutcome::Recorded)
        ->and($obligation->settlement_state)->toBe(PuSettlementState::Settled)
        ->and($obligation->reconciliation_status)->toBe(PuReconciliationStatus::Indeterminate)
        ->and($obligation->latestReconciliation->reason)->toBe(PuObligationCalculationState::ReprocessingRequired->value);
});

it('records a settlement for an obligation still awaiting the index and reconciles it once the official curve gets there', function () {
    $emission = Fx::emission([[PuEventType::InterestPayment, '2026-03-20']]);
    Fx::official($emission);
    $obligation = Fx::obligation($emission, PuObligationType::ScheduledPayment, '2026-03-20');

    $result = Fx::settle($obligation, '500.00', reference: 'B3-FUT');
    $before = $obligation->fresh();
    Fx::publish('2026-03-16', '2026-03-25');
    app(PuCurveExtensionService::class)->extendOfficial($emission->fresh());
    $after = $obligation->fresh();

    expect($before->calculation_state)->toBe(PuObligationCalculationState::AwaitingIndex)
        ->and($result->outcome)->toBe(PuSettlementOutcome::Recorded)
        ->and($result->settlement->expected_calculation_id)->toBeNull()
        ->and($before->reconciliation_status)->toBe(PuReconciliationStatus::Indeterminate)
        ->and($before->latestReconciliation->reason)->toBe(PuObligationCalculationState::AwaitingIndex->value)
        ->and($after->calculation_state)->toBe(PuObligationCalculationState::Calculated)
        ->and($after->reconciliation_status)->toBe(PuReconciliationStatus::Divergent)
        ->and((string) $after->latestReconciliation->difference)->toBe(bcsub('500.00', Fx::expectedTotal($after), 2))
        ->and($after->activeSettlement->id)->toBe($result->settlement->id);
});

it('never lets a settlement change the contract, the curve inputs or the curve rows', function () {
    [$emission, $official, $obligation] = p5sHundred();
    $snapshots = app(PuCurveInputSnapshotService::class);
    $fingerprintBefore = $snapshots->capture($emission->fresh())->fingerprint;
    $rowsBefore = EmissionPuDailyCurve::query()->where('curve_version_id', $official->id)->orderBy('curve_date')->get()->map->toArray()->all();
    $eventsBefore = EmissionPuEvent::query()->whereBelongsTo($emission)->get()->map->toArray()->all();
    $balanceBefore = app(OutstandingBalanceResolver::class)->resolve($emission->fresh(), '2026-03-01');

    Fx::settle($obligation, '37.00', reference: 'B3-CONTRACT');

    expect($snapshots->capture($emission->fresh())->fingerprint)->toBe($fingerprintBefore)
        ->and($snapshots->forVersion($official->fresh()))->toBeInstanceOf(PuCurveInputSnapshot::class)
        ->and(EmissionPuDailyCurve::query()->where('curve_version_id', $official->id)->orderBy('curve_date')->get()->map->toArray()->all())->toBe($rowsBefore)
        ->and(EmissionPuEvent::query()->whereBelongsTo($emission)->get()->map->toArray()->all())->toBe($eventsBefore)
        ->and($official->fresh()->extension_diverged_at)->toBeNull()
        // O saldo devedor das garantias segue o PU oficial, não a liquidação.
        ->and(app(OutstandingBalanceResolver::class)->resolve($emission->fresh(), '2026-03-01'))->toBe($balanceBefore);
});

it('rebuilds the reconciliation from the facts and detects a tampered summary', function () {
    [$emission, , $obligation] = p5sHundred();
    Fx::settle($obligation, '80.00', reference: 'B3-REB');

    $this->artisan('pu:obligations:reconcile', ['--emission' => [$emission->id], '--verify' => true])->assertSuccessful();

    // Alguém mexeu no resumo guardado (só a conciliação; os fatos estão intactos).
    DB::table('emission_pu_obligations')->where('id', $obligation->id)->update([
        'reconciliation_status' => PuReconciliationStatus::Matched->value,
        'latest_reconciliation_id' => null,
    ]);

    $this->artisan('pu:obligations:reconcile', ['--emission' => [$emission->id], '--verify' => true])->assertFailed();
    expect($obligation->fresh()->reconciliation_status)->toBe(PuReconciliationStatus::Matched);

    $this->artisan('pu:obligations:reconcile', ['--emission' => [$emission->id]])->assertSuccessful();

    expect($obligation->fresh()->reconciliation_status)->toBe(PuReconciliationStatus::Divergent)
        ->and((string) $obligation->fresh()->latestReconciliation->difference)->toBe('-20.00')
        ->and(EmissionPuSettlement::query()->where('obligation_id', $obligation->id)->sole()->amount)->toBe('80.00');
    $this->artisan('pu:obligations:reconcile', ['--emission' => [$emission->id], '--verify' => true])->assertSuccessful();
});

it('exposes structured statuses for the future monitoring phase without alerting', function () {
    [$emission, , $obligation] = p5sHundred();
    Fx::settle($obligation, '80.00', reference: 'B3-MON');
    Fx::settle($obligation, '70.00', reference: 'B3-MON');

    $snapshot = app(PuObligationMonitorSnapshot::class)->snapshot($emission->id);

    expect(array_keys($snapshot))->toBe([
        'business_date',
        'unsettled_past_due',
        'settlement_divergent',
        'reconciliation_indeterminate',
        'settlement_conflict_open',
        'unsupported_financial_effect',
        'reprocessing_required',
    ])
        ->and($snapshot['settlement_conflict_open'])->toHaveCount(1)
        ->and($snapshot['unsettled_past_due'])->toBe([]);
});

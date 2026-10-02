<?php

use App\DTOs\SalesBoards\SalesBoardComparableSnapshot;
use App\Enums\ContractStatus;
use App\Enums\SalesBoardApprovalOutcome;
use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardIssueCode;
use App\Enums\SalesBoardMovementTiming;
use App\Enums\SalesBoardMovementType;
use App\Enums\SalesBoardNonconformityDecision;
use App\Enums\SalesBoardNonconformityOrigin;
use App\Enums\SalesBoardStaleImpact;
use App\Enums\SalesPriceConformityStatus;
use App\Exceptions\SalesBoardManagementReviewException;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\ConstructionUnitExchange;
use App\Models\ConstructionUnitValue;
use App\Models\Contract;
use App\Models\SalesBoard;
use App\Models\SalesBoardCycleMovement;
use App\Models\SalesDiscountPolicy;
use App\Services\SalesBoards\SalesBoardCompetenceBridgeBuilder;
use App\Services\SalesBoards\SalesBoardCycleCancellationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SalesBoards\CycleFixture;
use Tests\Support\SalesBoards\DerivationFixture;
use Tests\Support\SalesBoards\ExtemporaneousFixture;
use Tests\Support\SalesBoards\GovernanceFixture;
use Tests\Support\SalesBoards\ManagementReviewFixture;

uses(RefreshDatabase::class);

/**
 * O fato lançado depois da publicação da competência dele entra como movimento
 * extemporâneo na competência seguinte, apurado contra a posição congelada da
 * anterior: a posição publicada não muda, e a venda passa pela conformidade da
 * data dela e pela Gestão.
 */

/**
 * Aplica um fato atrasado, com data em julho, depois de julho publicado -- ou,
 * no par negativo, nada.
 *
 * @param  array{construction: Construction, units: list<ConstructionUnit>, financed: Contract}  $scenario
 */
function lateFactAfterJuly(string $fact, array $scenario): void
{
    match ($fact) {
        'venda' => ExtemporaneousFixture::sale($scenario['units'][1], '2026-07-18'),
        'distrato' => $scenario['financed']->forceFill([
            'cancellation_date' => '2026-07-25',
            'status' => ContractStatus::Cancelled,
        ])->save(),
        'quitacao' => $scenario['financed']->installments()->where('number', '002')->firstOrFail()
            ->forceFill(['payment_date' => '2026-07-20', 'paid_value' => '300000.00'])->save(),
        'nada' => null,
    };
}

it('turns a sale dated in a published competence into a late sale of the next one', function () {
    $scenario = ExtemporaneousFixture::publishedJuly();
    $publishedBoard = SalesBoard::query()->findOrFail($scenario['publication']->salesBoard->getKey());
    $publishedUnits = $publishedBoard->financed_units;

    $late = ExtemporaneousFixture::sale($scenario['units'][1], '2026-07-18', '470000.00');

    $august = ExtemporaneousFixture::generateAugust($scenario['construction']);
    $sale = ExtemporaneousFixture::movements($august, SalesBoardMovementType::Sale)->sole();

    expect($sale->contract_id)->toBe($late->id)
        ->and($sale->timing)->toBe(SalesBoardMovementTiming::Extemporaneous)
        ->and($sale->sale_date->toDateString())->toBe('2026-07-18')
        // A conformidade é a da data da venda: tabela de 500.000 e 10% de desconto.
        ->and($sale->conformity_status)->toBe(SalesPriceConformityStatus::Conform)
        ->and($sale->minimum_authorized_value)->toBe(ExtemporaneousFixture::POLICY_FLOOR)
        ->and(CycleFixture::currentBaseline($august)->previous_competence_baseline_id)
        ->toBe($scenario['publication']->publication->sales_board_cycle_baseline_id)
        // O quadro publicado de julho não muda.
        ->and($publishedBoard->fresh()->financed_units)->toBe($publishedUnits)
        ->and($scenario['july']->fresh()->status)->toBe(SalesBoardCycleStatus::Approved);
});

it('makes the late sale below the floor a pending decision of the Gestão', function () {
    $scenario = ExtemporaneousFixture::publishedJuly();
    ExtemporaneousFixture::sale($scenario['units'][1], '2026-07-18', '400000.00');

    $august = ExtemporaneousFixture::generateAugust($scenario['construction']);
    $position = ExtemporaneousFixture::deriveAugust($scenario['construction']);

    expect(DerivationFixture::issueCodes($position))->toContain(SalesBoardIssueCode::LateSaleNonConform->value)
        ->not->toContain(SalesBoardIssueCode::SaleNonConform->value)
        // Aviso, nunca bloqueio: a competência foi gerada.
        ->and($august)->not->toBeNull();

    $review = ExtemporaneousFixture::analysis($august);
    $item = ManagementReviewFixture::nonconformityOf($review, SalesBoardNonconformityOrigin::SystemSaleNonConform);

    expect(fn () => ManagementReviewFixture::approve($review))
        ->toThrow(SalesBoardManagementReviewException::class, 'sem decisão da Gestão');

    expect(fn () => ManagementReviewFixture::decide($item, SalesBoardNonconformityDecision::AcceptedException, actor: GovernanceFixture::operator()))
        ->toThrow(AuthorizationException::class);

    ManagementReviewFixture::decide($item, SalesBoardNonconformityDecision::AcceptedException);

    expect(ManagementReviewFixture::approve($review)->publication->sales_board_cycle_baseline_id)
        ->toBe(CycleFixture::currentBaseline($august)->id);
});

it('turns a late distrato and a late settlement into late movements', function () {
    $scenario = ExtemporaneousFixture::publishedJuly();

    $scenario['financed']->installments()->where('number', '002')->firstOrFail()
        ->forceFill(['payment_date' => '2026-07-20', 'paid_value' => '300000.00'])->save();

    $august = ExtemporaneousFixture::generateAugust($scenario['construction']);
    $settlement = ExtemporaneousFixture::movements($august, SalesBoardMovementType::Settlement)->sole();

    expect($settlement->contract_id)->toBe($scenario['financed']->id)
        ->and($settlement->timing)->toBe(SalesBoardMovementTiming::Extemporaneous)
        // O motor não apura o dia da quitação.
        ->and($settlement->event_date)->toBeNull();

    $other = ExtemporaneousFixture::publishedJuly();
    $other['financed']->forceFill(['cancellation_date' => '2026-07-25', 'status' => ContractStatus::Cancelled])->save();

    $cancellation = ExtemporaneousFixture::movements(ExtemporaneousFixture::generateAugust($other['construction']), SalesBoardMovementType::Cancellation)->sole();

    expect($cancellation->contract_id)->toBe($other['financed']->id)
        ->and($cancellation->timing)->toBe(SalesBoardMovementTiming::Extemporaneous)
        ->and($cancellation->event_date->toDateString())->toBe('2026-07-25');
});

it('records a late cash sale as a late sale and a late settlement of the same contract', function () {
    $scenario = ExtemporaneousFixture::publishedJuly();
    $cash = ExtemporaneousFixture::cashSale($scenario['units'][1], '2026-07-10');

    $august = ExtemporaneousFixture::generateAugust($scenario['construction']);

    $byType = CycleFixture::currentBaseline($august)->movements
        ->where('contract_id', $cash->id)
        ->mapWithKeys(fn (SalesBoardCycleMovement $movement): array => [$movement->movement_type->value => $movement->timing]);

    expect($byType->all())->toBe([
        SalesBoardMovementType::Settlement->value => SalesBoardMovementTiming::Extemporaneous,
        SalesBoardMovementType::Sale->value => SalesBoardMovementTiming::Extemporaneous,
    ]);
});

it('turns a lowered sale value of a published sale into a sale revision', function () {
    $scenario = ExtemporaneousFixture::publishedJuly();

    // O mesmo valor de antes não é revisão.
    $scenario['financed']->forceFill(['sale_value' => '600000.00'])->save();
    expect(ExtemporaneousFixture::deriveAugust($scenario['construction'])->movements->sales)->toBe([]);

    $scenario['financed']->forceFill(['sale_value' => '400000.00'])->save();

    $august = ExtemporaneousFixture::generateAugust($scenario['construction']);
    $revision = ExtemporaneousFixture::movements($august, SalesBoardMovementType::Sale)->sole();

    expect($revision->timing)->toBe(SalesBoardMovementTiming::SaleRevision)
        ->and($revision->conformity_status)->toBe(SalesPriceConformityStatus::NonConform)
        ->and($revision->timingLabel(previousSaleValueCents: 60_000_000))->toContain('R$ 600.000,00 → R$ 400.000,00');

    $review = ExtemporaneousFixture::analysis($august);

    expect(ManagementReviewFixture::nonconformityOf($review, SalesBoardNonconformityOrigin::SystemSaleNonConform)->sales_board_cycle_movement_id)
        ->toBe($revision->id);
});

it('lets the Gestão accept a late sale without policy in a published month, and asks for correction without reference value', function () {
    $scenario = ExtemporaneousFixture::publishedJuly();

    // A política vigente termina em junho: a venda de julho fica sem política aplicável.
    SalesDiscountPolicy::query()->where('construction_id', $scenario['construction']->id)->update(['effective_until' => '2026-06-30']);
    SalesDiscountPolicy::factory()->forConstruction($scenario['construction'])->effectiveFrom('2026-08-01')->closedPeriod()->allowing('10.00')->create();

    ExtemporaneousFixture::sale($scenario['units'][1], '2026-07-18', '470000.00');

    $august = ExtemporaneousFixture::generateAugust($scenario['construction']);
    $review = ExtemporaneousFixture::analysis($august);
    $item = ManagementReviewFixture::nonconformityOf($review, SalesBoardNonconformityOrigin::SystemLateSaleWithoutPolicy);

    expect($item->allowedDecisions())->toContain(SalesBoardNonconformityDecision::AcceptedException);

    ManagementReviewFixture::decide($item, SalesBoardNonconformityDecision::AcceptedException);

    expect(ManagementReviewFixture::approve($review)->outcome)->toBe(SalesBoardApprovalOutcome::Approved);
});

it('keeps the late sale without reference value as undetermined, and the unit value fixes it', function () {
    $scenario = ExtemporaneousFixture::publishedJuly();
    $unit = $scenario['units'][1];

    // Sem tabela até setembro: na data da venda a unidade não tem valor.
    $unit->forceFill(['base_value' => null, 'base_value_reference_date' => null])->save();
    ConstructionUnitValue::factory()->create(['construction_unit_id' => $unit->id, 'value' => '500000.00', 'effective_from' => '2026-08-01']);
    ExtemporaneousFixture::sale($unit, '2026-07-18', '470000.00');

    $position = ExtemporaneousFixture::deriveAugust($scenario['construction']);

    expect(DerivationFixture::issueCodes($position))->toContain(SalesBoardIssueCode::LateSaleUndetermined->value)
        ->not->toContain(SalesBoardIssueCode::SaleUnitValueMissing->value);

    $august = ExtemporaneousFixture::generateAugust($scenario['construction']);
    $review = ExtemporaneousFixture::analysis($august);
    $item = ManagementReviewFixture::nonconformityOf($review, SalesBoardNonconformityOrigin::SystemSaleUndetermined);

    expect($item->allowedDecisions())->toContain(SalesBoardNonconformityDecision::CorrectionRequired)
        ->not->toContain(SalesBoardNonconformityDecision::AcceptedException);

    ConstructionUnitValue::factory()->create(['construction_unit_id' => $unit->id, 'value' => '500000.00', 'effective_from' => '2026-07-01']);

    $recalculated = CycleFixture::recalculate($august, 'Valor da unidade cadastrado.');
    $sale = $recalculated->baseline->movements()->where('movement_type', SalesBoardMovementType::Sale)->sole();

    expect($sale->conformity_status)->toBe(SalesPriceConformityStatus::Conform)
        ->and($sale->timing)->toBe(SalesBoardMovementTiming::Extemporaneous);
});

it('makes the next competence stale when a late fact arrives after it was generated', function () {
    $scenario = ExtemporaneousFixture::publishedJuly();
    $august = ExtemporaneousFixture::generateAugust($scenario['construction']);
    $review = ExtemporaneousFixture::analysis($august);
    ManagementReviewFixture::decideAll($review);

    ExtemporaneousFixture::sale($scenario['units'][1], '2026-07-18');

    expect(CycleFixture::check($august)->impact)->toBe(SalesBoardStaleImpact::Material)
        ->and(fn () => ManagementReviewFixture::approve($review))
        ->toThrow(SalesBoardManagementReviewException::class);

    $result = CycleFixture::recalculate($august, 'Venda de julho lançada depois.');

    expect($result->baseline->version)->toBe(2)
        ->and($result->baseline->movements()->where('timing', SalesBoardMovementTiming::Extemporaneous->value)->count())->toBe(1);
});

it('warns about a published sale whose date moved to the next month and lists it in the bridge', function () {
    $scenario = ExtemporaneousFixture::publishedJuly();
    $scenario['financed']->forceFill(['sale_date' => '2026-08-05'])->save();
    $scenario['financed']->installments()->update(['payment_date' => null, 'paid_value' => null]);

    $position = ExtemporaneousFixture::deriveAugust($scenario['construction']);
    $sale = collect($position->movements->sales)->sole();

    expect(DerivationFixture::issueCodes($position))->toContain(SalesBoardIssueCode::UnexplainedReclassification->value)
        ->and($sale->timing)->toBeNull()
        ->and($sale->contractId)->toBe($scenario['financed']->id);

    $august = ExtemporaneousFixture::generateAugust($scenario['construction']);
    $bridge = app(SalesBoardCompetenceBridgeBuilder::class)->forBaseline(CycleFixture::currentBaseline($august));

    expect(collect($bridge->unexplainedUnits)->pluck('constructionUnitId')->all())->toBe([$scenario['units'][0]->id]);
});

it('does not turn the contract of an initial exchange into a late sale', function () {
    $scenario = ExtemporaneousFixture::publishedJuly();
    $unit = $scenario['units'][2];

    $exchangeContract = DerivationFixture::contract($unit, '2026-07-05', '300000.00', status: ContractStatus::Exchanged);
    DerivationFixture::installment($exchangeContract, '001', '2026-07-05', '300000.00', '2026-07-05', '300000.00');
    ConstructionUnitExchange::factory()->create([
        'construction_unit_id' => $unit->id,
        'contract_id' => $exchangeContract->id,
        'exchange_value' => '300000.00',
        'effective_from' => '2026-07-05',
    ]);

    $position = ExtemporaneousFixture::deriveAugust($scenario['construction']);

    expect($position->movements->sales)->toBe([])
        ->and($position->movements->settlements)->toBe([]);
});

it('does not create movements for a sale and a distrato both inside the published month', function () {
    $scenario = ExtemporaneousFixture::publishedJuly();

    $contract = DerivationFixture::contract($scenario['units'][1], '2026-07-05', '480000.00', cancellationDate: '2026-07-25', status: ContractStatus::Cancelled);
    DerivationFixture::installment($contract, '001', '2026-12-05', '480000.00');

    $position = ExtemporaneousFixture::deriveAugust($scenario['construction']);

    expect($position->movements->lateCount())->toBe(0)
        ->and($position->movements->salesCount(true))->toBe(0)
        ->and($position->movements->cancellationsCount())->toBe(0);
});

it('absorbs the facts of a cancelled competence into the next one', function () {
    [$construction, $units] = CycleFixture::readyConstruction(3);

    $june = CycleFixture::generate($construction, '2026-06-01')->cycle;
    ExtemporaneousFixture::publish($june);

    $july = CycleFixture::generate($construction, '2026-07-01')->cycle;
    app(SalesBoardCycleCancellationService::class)->cancel($july, GovernanceFixture::approver(), 'Competência sem conferência da construtora.');

    $sale = ExtemporaneousFixture::sale($units[0], '2026-07-12');

    $august = ExtemporaneousFixture::deriveAugust($construction);
    $movement = collect($august->movements->sales)->sole();

    expect($movement->contractId)->toBe($sale->id)
        ->and($movement->timing)->toBe(SalesBoardMovementTiming::WithoutPosition)
        ->and($august->priorPosition?->referenceMonth->format('Y-m'))->toBe('2026-06')
        ->and(collect($august->priorPosition?->skippedCancelledMonths)->map->format('Y-m')->all())->toBe(['2026-07']);

    // A venda da competência cancelada sem política bloqueia, como a venda do mês.
    SalesDiscountPolicy::query()->where('construction_id', $construction->id)->update(['effective_until' => '2026-06-30']);
    SalesDiscountPolicy::factory()->forConstruction($construction)->effectiveFrom('2026-08-01')->closedPeriod()->allowing('10.00')->create();

    $blocked = ExtemporaneousFixture::deriveAugust($construction);

    expect(DerivationFixture::issueCodes($blocked))->toContain(SalesBoardIssueCode::SaleDiscountPolicyMissing->value)
        ->and($blocked->isComplete())->toBeFalse();
});

/**
 * A primeira competência automatizada cancelada não tem competência anterior
 * no ciclo: a seguinte não tem âncora, mas absorve os fatos dela do mesmo
 * jeito -- a janela começa no primeiro dia do mês cancelado, e nenhuma
 * comparação de extemporâneos acontece. A quitação conta só quando o contrato
 * não estava quitado na véspera da janela.
 */
it('absorbs a cancelled first competence into the next one without an anchor', function () {
    [$construction, $units] = CycleFixture::readyConstruction(5);

    $settledBefore = DerivationFixture::contract($units[3], '2026-03-10', '480000.00');
    DerivationFixture::installment($settledBefore, '001', '2026-05-10', '480000.00', '2026-05-10', '480000.00');

    $july = CycleFixture::generate($construction, '2026-07-01')->cycle;
    app(SalesBoardCycleCancellationService::class)->cancel($july, GovernanceFixture::approver(), 'Primeira competência cancelada no piloto.');

    $sale = ExtemporaneousFixture::sale($units[0], '2026-07-12', '400000.00');
    $cancelled = DerivationFixture::contract($units[1], '2026-03-05', '480000.00', cancellationDate: '2026-07-20', status: ContractStatus::Cancelled);
    DerivationFixture::installment($cancelled, '001', '2026-12-05', '480000.00');
    $settledInJuly = ExtemporaneousFixture::cashSale($units[2], '2026-07-08');

    $august = ExtemporaneousFixture::deriveAugust($construction);

    $absorbedSale = collect($august->movements->sales)->firstWhere('contractId', $sale->id);
    $absorbedCancellation = collect($august->movements->cancellations)->sole();
    $settlements = collect($august->movements->settlements);

    expect($august->priorPosition)->toBeNull()
        ->and(collect($august->absorbedCancelledMonths)->map->format('Y-m')->all())->toBe(['2026-07'])
        ->and($absorbedSale->timing)->toBe(SalesBoardMovementTiming::WithoutPosition)
        ->and($absorbedSale->conformity->status)->toBe(SalesPriceConformityStatus::NonConform)
        ->and($absorbedCancellation->contractId)->toBe($cancelled->id)
        ->and($absorbedCancellation->timing)->toBe(SalesBoardMovementTiming::WithoutPosition)
        ->and($settlements->pluck('contractId')->all())->toBe([$settledInJuly->id])
        ->and($settlements->sole()->timing)->toBe(SalesBoardMovementTiming::WithoutPosition)
        ->and($august->movements->countsByTiming()[SalesBoardMovementTiming::Extemporaneous->value])->toBe(0);
});

/**
 * O mês cancelado que a automação deixou de cobrir -- o início dela mudou
 * para depois dele -- é do registro manual: a competência seguinte não o
 * absorve, e a janela dela volta a ser só o mês.
 */
it('does not absorb a cancelled competence the automation no longer covers', function () {
    [$construction, $units] = CycleFixture::readyConstruction(2);

    $july = CycleFixture::generate($construction, '2026-07-01')->cycle;
    app(SalesBoardCycleCancellationService::class)->cancel($july, GovernanceFixture::approver(), 'Competência fora da automação.');
    ExtemporaneousFixture::sale($units[0], '2026-07-12');

    CycleFixture::automate($construction->emission, '2026-08-01');

    $august = ExtemporaneousFixture::deriveAugust($construction);

    expect($august->priorPosition)->toBeNull()
        ->and($august->absorbedCancelledMonths)->toBe([])
        ->and($august->movements->sales)->toBe([]);
});

it('derives the first automated competence exactly as before, with no late movements', function () {
    [$construction, $units] = CycleFixture::readyConstruction(2);
    ExtemporaneousFixture::sale($units[0], '2026-06-10');

    $position = DerivationFixture::derive($construction, '2026-07-01');

    expect($position->priorPosition)->toBeNull()
        ->and($position->movements->lateCount())->toBe(0)
        ->and($position->movements->countsByTiming()['no_mes'])->toBe(0);
});

/**
 * A linha do movimento sem timing é a de antes (golden em
 * SalesBoardFingerprintTest): a versão congelada antes dos extemporâneos
 * reconstrói o mesmo resumo do que foi gravado, e "Verificar alterações"
 * continua respondendo "Sem alterações".
 */
it('keeps the frozen fingerprint of a version without late movements', function () {
    $scenario = ExtemporaneousFixture::publishedJuly();
    $baseline = CycleFixture::currentBaseline($scenario['july']);

    expect($baseline->movements->whereNotNull('timing'))->toHaveCount(0)
        ->and(SalesBoardComparableSnapshot::fromBaseline($baseline->fresh())->snapshot->fingerprint())->toBe($baseline->snapshot_fingerprint)
        ->and(CycleFixture::check($scenario['july'])->impact)->toBe(SalesBoardStaleImpact::None);
});

it('anchors the next competence on the current publication while the previous one is under rectification', function () {
    $scenario = ExtemporaneousFixture::publishedJuly();
    $publishedBaselineId = $scenario['publication']->publication->sales_board_cycle_baseline_id;

    $scenario['financed']->forceFill(['sale_value' => '650000.00'])->save();
    ExtemporaneousFixture::rectify($scenario['july']);

    expect(CycleFixture::currentBaseline($scenario['july'])->id)->not->toBe($publishedBaselineId);

    $august = ExtemporaneousFixture::deriveAugust($scenario['construction']);

    expect($august->priorPosition?->baselineId)->toBe($publishedBaselineId)
        ->and($august->priorPosition?->isPublished)->toBeTrue();
});

it('produces late movements only when a late fact exists', function (string $fact, ?SalesBoardMovementType $type) {
    $scenario = ExtemporaneousFixture::publishedJuly();

    lateFactAfterJuly($fact, $scenario);

    $position = ExtemporaneousFixture::deriveAugust($scenario['construction']);
    $late = $position->movements->fromEarlierCompetences();

    if ($type === null) {
        expect($position->movements->lateCount())->toBe(0);

        return;
    }

    $byType = match ($type) {
        SalesBoardMovementType::Sale => $late->sales,
        SalesBoardMovementType::Settlement => $late->settlements,
        SalesBoardMovementType::Cancellation => $late->cancellations,
    };

    expect($byType)->toHaveCount(1)
        ->and($byType[0]->timing)->toBe(SalesBoardMovementTiming::Extemporaneous);
})->with([
    'venda lançada depois' => ['venda', SalesBoardMovementType::Sale],
    'distrato lançado depois' => ['distrato', SalesBoardMovementType::Cancellation],
    'pagamento que quita lançado depois' => ['quitacao', SalesBoardMovementType::Settlement],
    'sem fato atrasado' => ['nada', null],
]);

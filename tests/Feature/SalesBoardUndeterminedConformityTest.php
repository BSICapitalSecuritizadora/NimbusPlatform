<?php

use App\DTOs\SalesBoards\SalesBoardSnapshotMovement;
use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardGenerationOutcome;
use App\Enums\SalesBoardIssueCode;
use App\Enums\SalesBoardManagementReviewStatus;
use App\Enums\SalesBoardNonconformityDecision;
use App\Enums\SalesBoardNonconformityOrigin;
use App\Enums\SalesBoardRecalculationOutcome;
use App\Enums\SalesBoardStaleImpact;
use App\Enums\SalesPriceConformityStatus;
use App\Exceptions\SalesBoardManagementReviewException;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\ContractInstallment;
use App\Models\Emission;
use App\Models\SalesBoard;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardManagementNonconformity;
use App\Models\SalesBoardPublication;
use App\Models\SalesDiscountPolicy;
use App\Models\User;
use App\Services\SalesBoards\SalesBoardManagementReviewWorkspaceBuilder;
use App\Services\SalesBoards\SalesBoardStaleDetectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SalesBoards\CycleFixture;
use Tests\Support\SalesBoards\DerivationFixture;
use Tests\Support\SalesBoards\ManagementReviewFixture;

uses(RefreshDatabase::class);

/**
 * A fronteira que este hardening protege.
 *
 * A derivação já recusa congelar uma venda que ela não conseguiu avaliar -- os
 * dois testes abaixo provam isso e são a razão de o cenário só ser alcançável
 * por dado escrito fora do fluxo. O que vem depois deles é a rede de segurança:
 * se uma venda indeterminada chegar a um baseline por qualquer caminho, ela não
 * atravessa o portão sem decisão da Gestão.
 */
it('refuses to freeze a baseline when a sale has no applicable discount policy', function () {
    $construction = Construction::factory()->create([
        'emission_id' => Emission::factory()->create(['status' => 'active'])->id,
    ]);

    // Política só a partir de setembro: a venda de julho fica sem política.
    SalesDiscountPolicy::factory()->forConstruction($construction)
        ->effectiveFrom('2026-09-01')->allowing('10.00')->create();

    $unit = DerivationFixture::unit($construction, '301');
    DerivationFixture::unit($construction, '302');

    $sale = DerivationFixture::contract($unit, '2026-07-15', '900000.00');
    DerivationFixture::installment($sale, '001', '2026-08-15', '900000.00');

    $position = DerivationFixture::derive($construction);
    $result = CycleFixture::generate($construction);

    expect($position->movements->sales[0]->conformity->status)->toBe(SalesPriceConformityStatus::Undetermined)
        ->and($position->movements->sales[0]->conformity->reasonWhenUndetermined)
        ->toBe('O empreendimento não tinha política de desconto vigente na data da venda.')
        ->and(collect($position->blockingIssues())->map(fn ($issue): string => $issue->code->value)->all())
        ->toBe([SalesBoardIssueCode::SaleDiscountPolicyMissing->value])
        ->and($result->outcome)->toBe(SalesBoardGenerationOutcome::Blocked)
        ->and(SalesBoardCycle::query()->count())->toBe(0);
});

it('refuses to freeze a baseline when a sale has no unit reference value', function () {
    $construction = Construction::factory()->create([
        'emission_id' => Emission::factory()->create(['status' => 'active'])->id,
    ]);

    SalesDiscountPolicy::factory()->forConstruction($construction)
        ->effectiveFrom('2020-01-01')->allowing('10.00')->create();

    $unit = ConstructionUnit::factory()->create([
        'construction_id' => $construction->id,
        'block' => '01', 'unit' => '401',
        'base_value' => null, 'base_value_reference_date' => null,
    ]);
    DerivationFixture::unit($construction, '402');

    $sale = DerivationFixture::contract($unit, '2026-07-15', '900000.00');
    DerivationFixture::installment($sale, '001', '2026-08-15', '900000.00');

    $position = DerivationFixture::derive($construction);

    expect($position->movements->sales[0]->conformity->status)->toBe(SalesPriceConformityStatus::Undetermined)
        ->and(collect($position->blockingIssues())->map(fn ($issue): string => $issue->code->value)->all())
        ->toBe([SalesBoardIssueCode::SaleUnitValueMissing->value])
        ->and(CycleFixture::generate($construction)->outcome)->toBe(SalesBoardGenerationOutcome::Blocked)
        ->and(SalesBoardCycle::query()->count())->toBe(0);
});

it('materializes one pendency per decidable sale and none for a conform one', function () {
    $scenario = ManagementReviewFixture::submittedCycleWithUndeterminedSale();

    $review = ManagementReviewFixture::open($scenario['cycle']);
    $baseline = CycleFixture::currentBaseline($scenario['cycle']);

    $sales = $baseline->movements()->where('movement_type', 'venda')->get();

    // Duas vendas conformes na competência -- a do mês e a do contrato depois
    // distratado -- e nenhuma delas produz pendência.
    expect($sales->pluck('conformity_status')->countBy(fn ($status): string => $status->value)->all())
        ->toBe(['conforme' => 2, 'indeterminado' => 1, 'nao_conforme' => 1]);

    $byOrigin = $review->nonconformities->countBy(fn ($item): string => $item->origin->value);

    expect($review->nonconformities)->toHaveCount(2)
        ->and($byOrigin->all())->toEqualCanonicalizing([
            'venda_nao_conforme' => 1,
            'venda_indeterminada' => 1,
        ]);

    $undetermined = ManagementReviewFixture::nonconformityOf($review, SalesBoardNonconformityOrigin::SystemSaleUndetermined);

    expect($undetermined->sales_board_cycle_movement_id)->toBe($scenario['undetermined']->id)
        ->and($undetermined->sales_board_builder_divergence_id)->toBeNull()
        ->and($undetermined->decision)->toBe(SalesBoardNonconformityDecision::Pending);
});

it('never duplicates the pendency when the review is reopened', function () {
    $scenario = ManagementReviewFixture::submittedCycleWithUndeterminedSale();

    $first = ManagementReviewFixture::open($scenario['cycle']);
    $second = ManagementReviewFixture::open($scenario['cycle']);
    ManagementReviewFixture::open($scenario['cycle']);

    expect($second->id)->toBe($first->id)
        ->and(SalesBoardManagementNonconformity::query()->count())->toBe(2)
        ->and(SalesBoardManagementNonconformity::query()
            ->where('origin', SalesBoardNonconformityOrigin::SystemSaleUndetermined)
            ->count())->toBe(1);
});

it('accepts only a correction for an undetermined sale', function () {
    $scenario = ManagementReviewFixture::submittedCycleWithUndeterminedSale();
    $review = ManagementReviewFixture::open($scenario['cycle']);
    $item = ManagementReviewFixture::nonconformityOf($review, SalesBoardNonconformityOrigin::SystemSaleUndetermined);
    $actor = User::factory()->create();

    $decided = ManagementReviewFixture::decide(
        $item,
        SalesBoardNonconformityDecision::CorrectionRequired,
        'Política comercial aplicável à data da venda não está cadastrada.',
        $actor,
    );

    expect($decided->decision)->toBe(SalesBoardNonconformityDecision::CorrectionRequired)
        ->and($decided->decision_reason)->toBe('Política comercial aplicável à data da venda não está cadastrada.')
        ->and($decided->decided_by_user_id)->toBe($actor->id)
        ->and($decided->decided_at)->not->toBeNull()
        ->and($decided->blocksApproval())->toBeTrue();
});

it('refuses to accept an exception for an undetermined sale', function () {
    $scenario = ManagementReviewFixture::submittedCycleWithUndeterminedSale();
    $review = ManagementReviewFixture::open($scenario['cycle']);
    $item = ManagementReviewFixture::nonconformityOf($review, SalesBoardNonconformityOrigin::SystemSaleUndetermined);

    expect(fn () => ManagementReviewFixture::decide($item, SalesBoardNonconformityDecision::AcceptedException))
        ->toThrow(SalesBoardManagementReviewException::class, 'não determinou a conformidade');

    expect($item->fresh()->decision)->toBe(SalesBoardNonconformityDecision::Pending)
        ->and($item->fresh()->decision_reason)->toBeNull();
});

it('refuses to dismiss an undetermined sale', function () {
    $scenario = ManagementReviewFixture::submittedCycleWithUndeterminedSale();
    $review = ManagementReviewFixture::open($scenario['cycle']);
    $item = ManagementReviewFixture::nonconformityOf($review, SalesBoardNonconformityOrigin::SystemSaleUndetermined);

    expect(fn () => ManagementReviewFixture::decide($item, SalesBoardNonconformityDecision::Dismissed))
        ->toThrow(SalesBoardManagementReviewException::class, 'não determinou a conformidade');

    expect($item->fresh()->decision)->toBe(SalesBoardNonconformityDecision::Pending);
});

it('refuses a correction without a usable reason', function (string $reason) {
    $scenario = ManagementReviewFixture::submittedCycleWithUndeterminedSale();
    $review = ManagementReviewFixture::open($scenario['cycle']);
    $item = ManagementReviewFixture::nonconformityOf($review, SalesBoardNonconformityOrigin::SystemSaleUndetermined);

    expect(fn () => ManagementReviewFixture::decide($item, SalesBoardNonconformityDecision::CorrectionRequired, $reason))
        ->toThrow(SalesBoardManagementReviewException::class, 'exige um motivo');

    expect($item->fresh()->decision)->toBe(SalesBoardNonconformityDecision::Pending);
})->with(['', ' ', 'ok', '.', '-']);

it('blocks approval while the undetermined pendency is pending', function () {
    $scenario = ManagementReviewFixture::submittedCycleWithUndeterminedSale();
    $review = ManagementReviewFixture::open($scenario['cycle']);

    // A não conformidade convencional é resolvida; sobra só a indeterminada.
    ManagementReviewFixture::decide(
        ManagementReviewFixture::nonconformityOf($review, SalesBoardNonconformityOrigin::SystemSaleNonConform),
        SalesBoardNonconformityDecision::AcceptedException,
        'Desconto autorizado pela diretoria comercial em ata.',
    );

    expect(fn () => ManagementReviewFixture::approve($review))
        ->toThrow(SalesBoardManagementReviewException::class, 'sem decisão da Gestão');

    expect(SalesBoard::query()->count())->toBe(0)
        ->and(SalesBoardPublication::query()->count())->toBe(0)
        ->and($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::ManagementReview);
});

it('blocks approval once the undetermined pendency requires a correction', function () {
    $scenario = ManagementReviewFixture::submittedCycleWithUndeterminedSale();
    $review = ManagementReviewFixture::open($scenario['cycle']);

    ManagementReviewFixture::decide(
        ManagementReviewFixture::nonconformityOf($review, SalesBoardNonconformityOrigin::SystemSaleNonConform),
        SalesBoardNonconformityDecision::AcceptedException,
        'Desconto autorizado pela diretoria comercial em ata.',
    );

    ManagementReviewFixture::decide(
        ManagementReviewFixture::nonconformityOf($review, SalesBoardNonconformityOrigin::SystemSaleUndetermined),
        SalesBoardNonconformityDecision::CorrectionRequired,
        'Política comercial aplicável à data da venda não está cadastrada.',
    );

    expect(fn () => ManagementReviewFixture::approve($review))
        ->toThrow(SalesBoardManagementReviewException::class, 'exigem correção da fonte');

    expect(SalesBoard::query()->count())->toBe(0)
        ->and(SalesBoardPublication::query()->count())->toBe(0)
        ->and($review->fresh()->status)->toBe(SalesBoardManagementReviewStatus::Draft);
});

it('refuses to publish when a decidable sale has no pendency at all', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $review = ManagementReviewFixture::open($scenario['cycle']);
    $baseline = CycleFixture::currentBaseline($scenario['cycle']);

    // Inconsistência controlada: a venda entra na versão vigente depois de a
    // análise ter sido materializada, exatamente como um dado escrito fora do
    // fluxo apareceria.
    ManagementReviewFixture::writeSaleMovement(
        $baseline,
        '903',
        SalesPriceConformityStatus::Undetermined,
        'A unidade não tinha valor de referência conhecido na data da venda.',
    );

    expect($review->fresh()->nonconformities)->toHaveCount(0);

    expect(fn () => ManagementReviewFixture::approve($review))
        ->toThrow(SalesBoardManagementReviewException::class, 'não cobre todas as vendas apontadas');

    expect(SalesBoard::query()->count())->toBe(0)
        ->and(SalesBoardPublication::query()->count())->toBe(0);
});

it('leaves the frozen snapshot untouched through the whole undetermined flow', function () {
    $scenario = ManagementReviewFixture::submittedCycleWithUndeterminedSale();
    $baseline = CycleFixture::currentBaseline($scenario['cycle']);

    $before = [
        'source' => $baseline->source_fingerprint,
        'snapshot' => $baseline->snapshot_fingerprint,
        'movement' => $scenario['undetermined']->snapshot_fingerprint,
        'conformity' => $scenario['undetermined']->conformity_status,
        'reason' => $scenario['undetermined']->conformity_reason,
    ];

    $review = ManagementReviewFixture::open($scenario['cycle']);

    ManagementReviewFixture::decide(
        ManagementReviewFixture::nonconformityOf($review, SalesBoardNonconformityOrigin::SystemSaleUndetermined),
        SalesBoardNonconformityDecision::CorrectionRequired,
        'Política comercial aplicável à data da venda não está cadastrada.',
    );

    $after = $baseline->fresh();
    $movement = $scenario['undetermined']->fresh();

    expect($after->source_fingerprint)->toBe($before['source'])
        ->and($after->snapshot_fingerprint)->toBe($before['snapshot'])
        ->and($movement->snapshot_fingerprint)->toBe($before['movement'])
        ->and($movement->conformity_status)->toBe($before['conformity'])
        ->and($movement->conformity_reason)->toBe($before['reason']);
});

it('shows the undetermined pendency with its frozen cause and only one allowed decision', function () {
    $scenario = ManagementReviewFixture::submittedCycleWithUndeterminedSale();
    $review = ManagementReviewFixture::open($scenario['cycle']);

    $workspace = app(SalesBoardManagementReviewWorkspaceBuilder::class)->build($review);
    $row = $workspace->nonconformitiesOf(SalesBoardNonconformityOrigin::SystemSaleUndetermined)[0];

    expect($row->typeLabel)->toBe('Conformidade não determinada pelo Nimbus')
        // A causa exibida é a congelada no movimento, não uma releitura da fonte.
        ->and($row->systemStatement)->toBe('O empreendimento não tinha política de desconto vigente na data da venda.')
        ->and($row->builderStatement)->toBeNull()
        ->and($row->callout())->toContain('não existe limite conhecido')
        ->and($row->allowedDecisions())->toBe([SalesBoardNonconformityDecision::CorrectionRequired])
        // O que falta aparece vazio -- é a ausência que impede o veredito.
        ->and(collect($row->facts)->firstWhere('label', 'Preço mínimo')['value'])->toBe('—')
        ->and(collect($row->facts)->firstWhere('label', 'Valor de referência')['value'])->toBe('—')
        ->and(collect($row->facts)->firstWhere('label', 'Valor da venda')['value'])->toBe('R$ 400.000,00')
        ->and(collect($row->facts)->firstWhere('label', 'Conformidade')['value'])->toBe('Indeterminado');
});

it('counts the undetermined pendency among the ones that still need a decision', function () {
    $scenario = ManagementReviewFixture::submittedCycleWithUndeterminedSale();
    $review = ManagementReviewFixture::open($scenario['cycle']);

    $workspace = app(SalesBoardManagementReviewWorkspaceBuilder::class)->build($review);

    expect($workspace->pendingCount())->toBe(2)
        ->and($workspace->blockingCount())->toBe(2)
        ->and($workspace->nonconformities)->toHaveCount(2)
        ->and($workspace->isReadyToPublish())->toBeFalse()
        ->and($workspace->progressLabel())->toBe('0 de 2 não conformidade(s) decidida(s)');
});

it('changes the snapshot fingerprint when a sale conformity changes materially', function () {
    $scenario = ManagementReviewFixture::submittedCycleWithUndeterminedSale();
    $baseline = CycleFixture::currentBaseline($scenario['cycle']);

    // O resumo do movimento carrega status e motivo da conformidade, então a
    // mesma venda com veredito diferente produz outro resumo -- que é o que
    // torna a correção uma mudança material, e não um detalhe invisível.
    $frozen = SalesBoardSnapshotMovement::fromPersisted($scenario['undetermined']->fresh());

    $corrected = new SalesBoardSnapshotMovement(
        type: $frozen->type,
        constructionUnitId: $frozen->constructionUnitId,
        block: $frozen->block,
        unit: $frozen->unit,
        contractId: $frozen->contractId,
        contractCode: $frozen->contractCode,
        eventDate: $frozen->eventDate,
        saleDate: $frozen->saleDate,
        saleValueCents: $frozen->saleValueCents,
        cancellationDate: $frozen->cancellationDate,
        settlementInstallmentsTotal: $frozen->settlementInstallmentsTotal,
        unitReferenceValueCents: 50_000_000,
        unitReferenceValueSource: $frozen->unitReferenceValueSource,
        unitReferenceEffectiveFrom: $frozen->unitReferenceEffectiveFrom,
        salesDiscountPolicyId: $frozen->salesDiscountPolicyId,
        authorizedDiscountBasisPoints: 2000,
        minimumAuthorizedValueCents: 40_000_000,
        effectiveDiscountBasisPoints: 2000,
        differenceCents: 0,
        conformityStatus: SalesPriceConformityStatus::Conform,
        conformityReason: null,
    );

    expect($frozen->conformityStatus)->toBe(SalesPriceConformityStatus::Undetermined)
        ->and($corrected->fingerprint())->not->toBe($frozen->fingerprint())
        ->and($baseline->snapshot_fingerprint)->not->toBeNull();
});

it('carries no undetermined pendency into a new round once the source is corrected', function () {
    // V1 congela a venda como indeterminada e a Gestão exige correção.
    $scenario = ManagementReviewFixture::submittedCycleWithUndeterminedSale();
    $first = ManagementReviewFixture::open($scenario['cycle']);

    ManagementReviewFixture::decide(
        ManagementReviewFixture::nonconformityOf($first, SalesBoardNonconformityOrigin::SystemSaleUndetermined),
        SalesBoardNonconformityDecision::CorrectionRequired,
        'Política comercial aplicável à data da venda não está cadastrada.',
    );

    // A correção real da fonte muda a posição: nova versão material.
    $scenario['contracts']['financed']->update(['sale_value' => '910000.00']);
    $result = CycleFixture::recalculate($scenario['cycle'], 'Correção do valor de venda do contrato.');

    expect($result->outcome)->toBe(SalesBoardRecalculationOutcome::Recalculated)
        ->and($first->fresh()->status)->toBe(SalesBoardManagementReviewStatus::Superseded)
        // A decisão da rodada substituída continua consultável.
        ->and($first->fresh()->nonconformities
            ->firstWhere('origin', SalesBoardNonconformityOrigin::SystemSaleUndetermined)
            ->decision)->toBe(SalesBoardNonconformityDecision::CorrectionRequired)
        // E a versão nova, derivada de uma fonte que passa no readiness, não
        // carrega nenhuma venda indeterminada.
        ->and(CycleFixture::currentBaseline($scenario['cycle'])
            ->movements()
            ->where('conformity_status', SalesPriceConformityStatus::Undetermined)
            ->count())->toBe(0);
});

it('turns the pendency into the ordinary kind when the corrected sale is merely non conform', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $baseline = CycleFixture::currentBaseline($scenario['cycle']);

    // A mesma venda, agora com veredito: fora da política, mas apurável.
    ManagementReviewFixture::writeSaleMovement(
        $baseline,
        '904',
        SalesPriceConformityStatus::NonConform,
        null,
    );

    $review = ManagementReviewFixture::open($scenario['cycle']);
    $item = $review->nonconformities->sole();

    expect($item->origin)->toBe(SalesBoardNonconformityOrigin::SystemSaleNonConform)
        ->and($item->allowedDecisions())->toBe([
            SalesBoardNonconformityDecision::Pending,
            SalesBoardNonconformityDecision::AcceptedException,
            SalesBoardNonconformityDecision::CorrectionRequired,
        ]);

    // E aí a exceção volta a ser possível, porque o limite é conhecido.
    ManagementReviewFixture::decide(
        $item,
        SalesBoardNonconformityDecision::AcceptedException,
        'Desconto autorizado pela diretoria comercial em ata.',
    );

    expect($item->fresh()->decision)->toBe(SalesBoardNonconformityDecision::AcceptedException);
});

it('keeps the undetermined pendency blocking through a source-only change', function () {
    $scenario = ManagementReviewFixture::submittedCycleWithUndeterminedSale();
    $review = ManagementReviewFixture::open($scenario['cycle']);

    ManagementReviewFixture::decide(
        ManagementReviewFixture::nonconformityOf($review, SalesBoardNonconformityOrigin::SystemSaleNonConform),
        SalesBoardNonconformityDecision::AcceptedException,
        'Desconto autorizado pela diretoria comercial em ata.',
    );

    ManagementReviewFixture::decide(
        ManagementReviewFixture::nonconformityOf($review, SalesBoardNonconformityOrigin::SystemSaleUndetermined),
        SalesBoardNonconformityDecision::CorrectionRequired,
        'Política comercial aplicável à data da venda não está cadastrada.',
    );

    // Mexer no valor esperado de uma parcela de contrato distratado muda a fonte
    // material e não move nenhum número congelado.
    ContractInstallment::query()
        ->where('contract_id', $scenario['contracts']['cancelled']->id)
        ->sole()
        ->update(['expected_value' => '469000.00']);

    $assessment = app(SalesBoardStaleDetectionService::class)->assessWithoutPersisting(
        $scenario['cycle']->fresh(),
        CycleFixture::currentBaseline($scenario['cycle']),
    );

    // A análise continua aplicável -- e continua impossível publicar, porque a
    // fonte que faltava não foi regularizada por essa mudança.
    expect($assessment->impact)->toBe(SalesBoardStaleImpact::SourceOnly)
        ->and($review->fresh()->appliesTo(CycleFixture::currentBaseline($scenario['cycle'])))->toBeTrue()
        ->and(fn () => ManagementReviewFixture::approve($review, null, 'Ajuste de parcela de contrato distratado.'))
        ->toThrow(SalesBoardManagementReviewException::class, 'exigem correção da fonte');

    expect(SalesBoard::query()->count())->toBe(0);
});

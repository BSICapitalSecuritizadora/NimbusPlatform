<?php

use App\Enums\AccessPermission;
use App\Enums\ContractStatus;
use App\Enums\SalesBoardApprovalOutcome;
use App\Enums\SalesBoardBuilderReviewStatus;
use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardManagementReviewStatus;
use App\Enums\SalesBoardMovementTiming;
use App\Enums\SalesBoardMovementType;
use App\Enums\SalesBoardPositionStatus;
use App\Enums\SalesBoardRectificationStatus;
use App\Enums\SalesBoardStaleImpact;
use App\Exceptions\ConstructionUnitExchangeException;
use App\Exceptions\SalesBoardMakerCheckerException;
use App\Exceptions\SalesBoardManagementReviewException;
use App\Exceptions\SalesBoardRectificationException;
use App\Exceptions\SalesBoardRolloutException;
use App\Exceptions\SalesDiscountPolicyPeriodException;
use App\Models\ConstructionUnitExchange;
use App\Models\ContractInstallment;
use App\Models\GuaranteeSnapshot;
use App\Models\SalesBoard;
use App\Models\SalesBoardBuilderReview;
use App\Models\SalesBoardCycleBaseline;
use App\Models\SalesBoardCycleRectification;
use App\Models\SalesBoardPublication;
use App\Services\SalesBoards\ConstructionUnitExchangeService;
use App\Services\SalesBoards\SalesBoardCycleRectificationService;
use App\Services\SalesBoards\SalesBoardManagementApprovalService;
use App\Services\SalesBoards\SalesBoardPositionReader;
use App\Services\SalesBoards\SalesBoardStaleDetectionService;
use App\Services\SalesBoards\SalesDiscountPolicyRegistrar;
use App\Support\Money\IntegerMoney;
use App\Support\SalesBoards\SalesBoardWriteContext;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Exceptions;
use Mockery\MockInterface;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SalesBoards\BuilderReviewFixture;
use Tests\Support\SalesBoards\CycleFixture;
use Tests\Support\SalesBoards\DerivationFixture;
use Tests\Support\SalesBoards\ExtemporaneousFixture;
use Tests\Support\SalesBoards\GovernanceFixture;
use Tests\Support\SalesBoards\ManagementReviewFixture;
use Tests\Support\SalesBoards\RolloutFixture;

uses(RefreshDatabase::class);

/**
 * "Retificar competência": a Gestão corrige a posição publicada da última
 * competência publicada do empreendimento, pelo fluxo de sempre -- validação da
 * construtora, análise e aprovação por outra pessoa --, e a aprovação publica de
 * novo o mesmo quadro, encadeando a publicação. Enquanto isso, a posição
 * publicada continua valendo para todos.
 */

/**
 * Os valores do quadro como estão gravados, para comparar sem os casts.
 *
 * @return array<string, mixed>
 */
function rectificationBoardValues(SalesBoard $board): array
{
    return Arr::only($board->fresh()->getAttributes(), SalesBoard::versionedFields());
}

it('rectifies only the last published competence, approved and without another rectification open', function () {
    $scenario = ExtemporaneousFixture::rectifiableJuly();
    $august = ExtemporaneousFixture::generateAugust($scenario['construction']);
    ExtemporaneousFixture::publish($august);

    expect(fn () => ExtemporaneousFixture::rectify($scenario['july']))
        ->toThrow(SalesBoardRectificationException::class, 'Só a última competência publicada do empreendimento pode ser retificada, e ela é 08/2026');

    $scenario['financed']->forceFill(['sale_value' => '660000.00'])->save();
    ExtemporaneousFixture::rectify($august);

    expect(fn () => ExtemporaneousFixture::rectify($august))
        ->toThrow(SalesBoardRectificationException::class, 'A competência 08/2026 já está em retificação');

    [$other] = CycleFixture::readyConstruction(2);
    $generated = CycleFixture::generate($other, '2026-07-01')->cycle;

    expect(fn () => ExtemporaneousFixture::rectify($generated))
        ->toThrow(SalesBoardRectificationException::class, 'Só se retifica competência aprovada e publicada; esta está em "Gerado"')
        ->and(SalesBoardCycleRectification::query()->where('sales_board_cycle_id', $scenario['july']->id)->exists())->toBeFalse()
        ->and(SalesBoardCycleRectification::query()->where('sales_board_cycle_id', $august->id)->count())->toBe(1)
        ->and(SalesBoardCycleRectification::query()->where('sales_board_cycle_id', $generated->id)->exists())->toBeFalse();
});

it('requires the Gestão authority, an identified actor and a reason of at least ten characters', function () {
    $scenario = ExtemporaneousFixture::rectifiableJuly();

    expect(fn () => ExtemporaneousFixture::rectify($scenario['july'], GovernanceFixture::operator()))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => ExtemporaneousFixture::rectify($scenario['july'], reason: '<b>Errado.</b>'))
        ->toThrow(SalesBoardRectificationException::class, 'Informe o motivo, com pelo menos 10 caracteres')
        ->and(fn () => app(SalesBoardCycleRectificationService::class)->open($scenario['july'], null, 'Venda da unidade lançada com valor errado.'))
        ->toThrow(SalesBoardRectificationException::class, 'A retificação da competência exige um usuário identificado')
        ->and(SalesBoardCycleRectification::query()->exists())->toBeFalse()
        ->and($scenario['july']->fresh()->status)->toBe(SalesBoardCycleStatus::Approved);
});

it('refuses to open a rectification when the position today is the published one', function () {
    $scenario = ExtemporaneousFixture::publishedJuly();

    expect(fn () => ExtemporaneousFixture::rectify($scenario['july']))
        ->toThrow(SalesBoardRectificationException::class, 'A posição apurada hoje para 07/2026 é igual à publicada: não há o que retificar.')
        ->and(CycleFixture::currentBaseline($scenario['july'])->version)->toBe(1);
});

it('says so when only the source changed after the publication', function () {
    [$construction, $units] = CycleFixture::readyConstruction(2);
    $cancelled = DerivationFixture::contract($units[0], '2026-03-01', '500000.00', cancellationDate: '2026-05-10', status: ContractStatus::Cancelled);
    $installment = DerivationFixture::installment($cancelled, '001', '2026-04-10', '500000.00');

    $july = CycleFixture::generate($construction, '2026-07-01')->cycle;
    ExtemporaneousFixture::publish($july);

    // O valor previsto da parcela de um contrato distratado: muda a fonte, não a posição.
    $installment->update(['expected_value' => '499000.00']);

    expect(fn () => ExtemporaneousFixture::rectify($july))
        ->toThrow(SalesBoardRectificationException::class, 'A fonte de 07/2026 mudou depois da publicação, mas a posição apurada continua igual à publicada');
});

it('refuses to open a rectification over an incomplete source, naming what blocks it', function () {
    $scenario = ExtemporaneousFixture::publishedJuly();

    // O contrato financiado fica sem cronograma: a unidade não tem posição decidível.
    ContractInstallment::query()->where('contract_id', $scenario['financed']->id)->delete();

    expect(fn () => ExtemporaneousFixture::rectify($scenario['july']))
        ->toThrow(SalesBoardRectificationException::class, 'A fonte da competência está incompleta e a posição retificada não pode ser apurada')
        ->and(CycleFixture::currentBaseline($scenario['july'])->version)->toBe(1)
        ->and(SalesBoardCycleRectification::query()->exists())->toBeFalse();
});

it('opens a new version with the reason and leaves the published position untouched', function () {
    $scenario = ExtemporaneousFixture::rectifiableJuly();
    $actor = GovernanceFixture::approver();
    $publication = $scenario['publication']->publication;
    $board = SalesBoard::query()->findOrFail($scenario['publication']->salesBoard->id);
    $boardValues = rectificationBoardValues($board);
    $read = fn () => app(SalesBoardPositionReader::class)
        ->forConstruction($scenario['construction']->fresh(), CarbonImmutable::parse('2026-07-31'));
    $before = $read();

    $rectification = ExtemporaneousFixture::rectify($scenario['july'], $actor, 'Venda da unidade 101 lançada com valor errado.');

    $july = $scenario['july']->fresh();
    $baseline = CycleFixture::currentBaseline($july);
    $after = $read();

    expect($rectification->status)->toBe(SalesBoardRectificationStatus::Open)
        ->and($rectification->sequence_number)->toBe(1)
        ->and($rectification->reason)->toBe('Venda da unidade 101 lançada com valor errado.')
        ->and($rectification->requested_by_user_id)->toBe($actor->id)
        ->and($rectification->rectified_publication_id)->toBe($publication->id)
        ->and($rectification->opening_baseline_id)->toBe($baseline->id)
        ->and($baseline->version)->toBe(2)
        ->and($baseline->reason)->toBe('Retificação: Venda da unidade 101 lançada com valor errado.')
        ->and($baseline->snapshot_fingerprint)->not->toBe($publication->snapshot_fingerprint)
        ->and($july->status)->toBe(SalesBoardCycleStatus::Generated)
        ->and($july->isUnderRectification())->toBeTrue()
        // O quadro, a publicação e o leitor continuam na posição publicada.
        ->and(rectificationBoardValues($board))->toBe($boardValues)
        ->and(SalesBoardPublication::query()->where('sales_board_cycle_id', $july->id)->count())->toBe(1)
        ->and($publication->fresh()->sales_board_cycle_baseline_id)->toBe($publication->sales_board_cycle_baseline_id)
        ->and($after->status)->toBe(SalesBoardPositionStatus::Current)
        ->and($after->salesBoard?->id)->toBe($board->id)
        ->and($after->financedValue)->toBe($before->financedValue);
});

it('publishes the rectified position over the same board, chained to the first publication', function () {
    $scenario = ExtemporaneousFixture::rectifiableJuly();
    $requester = GovernanceFixture::approver();
    $approver = GovernanceFixture::approver();
    $board = SalesBoard::query()->findOrFail($scenario['publication']->salesBoard->id);
    $first = $scenario['publication']->publication;

    $rectification = ExtemporaneousFixture::rectify($scenario['july'], $requester, 'Venda da unidade 101 lançada com valor errado.');
    $result = ExtemporaneousFixture::publish($scenario['july'], $approver);

    $july = $scenario['july']->fresh();
    $second = $result->publication;
    $history = $board->valueHistories()->orderByDesc('id')->first();

    expect($result->outcome)->toBe(SalesBoardApprovalOutcome::Approved)
        ->and($result->salesBoard->id)->toBe($board->id)
        ->and(SalesBoard::query()->where('construction_id', $scenario['construction']->id)->count())->toBe(1)
        ->and(IntegerMoney::cents((string) $board->fresh()->financed_value))
        ->toBe(IntegerMoney::cents(CycleFixture::currentBaseline($july)->financed_value))
        ->and(IntegerMoney::cents((string) $board->fresh()->financed_value))
        ->not->toBe(IntegerMoney::cents((string) $scenario['publication']->salesBoard->financed_value))
        // A mudança fica no histórico de versões, com o motivo e quem aprovou.
        ->and($history->change_reason)->toBe('Retificação aprovada da competência 07/2026: Venda da unidade 101 lançada com valor errado.')
        ->and($history->changed_by_id)->toBe($approver->id)
        ->and($second->sequence_number)->toBe(2)
        ->and($second->supersedes_publication_id)->toBe($first->id)
        ->and($second->sales_board_cycle_rectification_id)->toBe($rectification->id)
        ->and($second->sales_board_id)->toBe($board->id)
        ->and($second->published_by_user_id)->toBe($approver->id)
        ->and($rectification->fresh()->status)->toBe(SalesBoardRectificationStatus::Published)
        ->and($rectification->fresh()->closed_by_user_id)->toBe($approver->id)
        ->and($july->status)->toBe(SalesBoardCycleStatus::Approved)
        ->and($july->isUnderRectification())->toBeFalse()
        ->and($result->review->approval_declaration_version)->toBe(SalesBoardManagementApprovalService::DECLARATION_VERSION)
        // A primeira publicação não é reescrita.
        ->and($first->fresh()->only(['sequence_number', 'sales_board_cycle_baseline_id', 'snapshot_fingerprint', 'supersedes_publication_id']))
        ->toBe([
            'sequence_number' => 1,
            'sales_board_cycle_baseline_id' => $first->sales_board_cycle_baseline_id,
            'snapshot_fingerprint' => $first->snapshot_fingerprint,
            'supersedes_publication_id' => null,
        ]);
});

it('keeps the requester and the submitter of the round from approving the rectification', function () {
    $scenario = ExtemporaneousFixture::rectifiableJuly();
    $requester = GovernanceFixture::approver();
    ExtemporaneousFixture::rectify($scenario['july'], $requester);

    $submitter = GovernanceFixture::approver();
    $submitter->givePermissionTo([
        AccessPermission::SalesBoardsView->value,
        AccessPermission::SalesBoardsUpdate->value,
        AccessPermission::EmissionsView->value,
    ]);

    $builderReview = BuilderReviewFixture::open($scenario['july']->fresh());
    BuilderReviewFixture::confirmAll($builderReview);
    BuilderReviewFixture::submit($builderReview, $submitter);
    $review = ManagementReviewFixture::open($scenario['july']->fresh());

    expect(fn () => ManagementReviewFixture::approve($review, $requester))
        ->toThrow(SalesBoardMakerCheckerException::class, 'quem abriu a retificação não pode aprová-la')
        ->and(fn () => ManagementReviewFixture::approve($review, $submitter))
        ->toThrow(SalesBoardMakerCheckerException::class, 'quem enviou a validação da construtora')
        ->and($review->fresh()->status)->toBe(SalesBoardManagementReviewStatus::Draft)
        ->and(ManagementReviewFixture::approve($review)->outcome)->toBe(SalesBoardApprovalOutcome::Approved);
});

it('lets a super admin publish the rectification they opened', function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);

    $scenario = ExtemporaneousFixture::rectifiableJuly();
    $superAdmin = GovernanceFixture::superAdmin();

    ExtemporaneousFixture::rectify($scenario['july'], $superAdmin);
    $result = ExtemporaneousFixture::publish($scenario['july'], $superAdmin);

    expect($result->outcome)->toBe(SalesBoardApprovalOutcome::Approved)
        ->and($result->publication->sequence_number)->toBe(2)
        ->and($result->publication->published_by_user_id)->toBe($superAdmin->id);
});

it('marks the guarantee competences that depended on the published board as outdated on republication', function () {
    $scenario = ExtemporaneousFixture::rectifiableJuly();
    $construction = $scenario['construction'];
    $closedCompetence = fn (string $month, string $boardUsed, SalesBoardPositionStatus $status): GuaranteeSnapshot => GuaranteeSnapshot::factory()->create([
        'emission_id' => $construction->emission_id,
        'reference_month' => $month,
        'closed_at' => now(),
        'sales_board_coverage' => [
            'emission_wide' => false,
            'constructions' => [[
                'construction_id' => $construction->id,
                'construction_name' => $construction->development_name,
                'status' => $status->value,
                'reference_month_used' => $boardUsed,
            ]],
        ],
    ]);

    $june = $closedCompetence('2026-06-01', '2026-06-01', SalesBoardPositionStatus::Current);
    $july = $closedCompetence('2026-07-01', '2026-07-01', SalesBoardPositionStatus::Current);
    $august = $closedCompetence('2026-08-01', '2026-07-01', SalesBoardPositionStatus::CarriedForward);

    ExtemporaneousFixture::rectify($scenario['july']);

    // Abrir a retificação não toca o quadro publicado: nada desatualiza ainda.
    expect($july->fresh()->sales_board_outdated_at)->toBeNull()
        ->and($august->fresh()->sales_board_outdated_at)->toBeNull();

    ExtemporaneousFixture::publish($scenario['july']);

    expect($july->fresh()->sales_board_outdated_at)->not->toBeNull()
        ->and($august->fresh()->sales_board_outdated_at)->not->toBeNull()
        ->and($june->fresh()->sales_board_outdated_at)->toBeNull();
});

it('abandons the rectification back to the published version, superseding the open rounds', function () {
    $scenario = ExtemporaneousFixture::rectifiableJuly();
    $publishedBaselineId = $scenario['publication']->publication->sales_board_cycle_baseline_id;
    $publishedRoundId = $scenario['publication']->publication->sales_board_builder_review_id;
    $board = SalesBoard::query()->findOrFail($scenario['publication']->salesBoard->id);
    $boardValues = rectificationBoardValues($board);

    $rectification = ExtemporaneousFixture::rectify($scenario['july']);
    $review = ExtemporaneousFixture::analysis($scenario['july']);
    $closer = GovernanceFixture::approver();
    $service = app(SalesBoardCycleRectificationService::class);

    expect(fn () => $service->abandon($rectification, GovernanceFixture::operator(), 'A construtora confirmou o valor publicado.'))
        ->toThrow(AuthorizationException::class);

    $abandoned = $service->abandon($rectification, $closer, 'A construtora confirmou o valor publicado.');
    $july = $scenario['july']->fresh();

    expect($abandoned->status)->toBe(SalesBoardRectificationStatus::Abandoned)
        ->and($abandoned->closed_by_user_id)->toBe($closer->id)
        ->and($abandoned->closing_reason)->toBe('A construtora confirmou o valor publicado.')
        ->and($july->status)->toBe(SalesBoardCycleStatus::Approved)
        ->and($july->current_baseline_id)->toBe($publishedBaselineId)
        ->and($review->fresh()->status)->toBe(SalesBoardManagementReviewStatus::Superseded)
        ->and($review->fresh()->superseded_reason)->toBe(SalesBoardCycleRectificationService::SUPERSEDED_REASON)
        ->and(SalesBoardBuilderReview::query()->findOrFail($review->sales_board_builder_review_id)->superseded_reason)
        ->toBe('retificacao_desistida')
        // A validação que sustenta a publicação é rodada encerrada.
        ->and(SalesBoardBuilderReview::query()->findOrFail($publishedRoundId)->status)->toBe(SalesBoardBuilderReviewStatus::Submitted)
        ->and(rectificationBoardValues($board))->toBe($boardValues)
        ->and(SalesBoardPublication::query()->where('sales_board_cycle_id', $july->id)->count())->toBe(1)
        // A versão da retificação fica no histórico.
        ->and(SalesBoardCycleBaseline::query()->where('sales_board_cycle_id', $july->id)->count())->toBe(2)
        ->and(fn () => $service->abandon($rectification, $closer, 'Desistência repetida por engano.'))
        ->toThrow(SalesBoardRectificationException::class, 'Esta retificação já foi encerrada');

    expect(ExtemporaneousFixture::rectify($july)->sequence_number)->toBe(2);
});

/**
 * A constatação gravada na versão publicada é de antes da retificação. Sem a
 * conferência, a competência voltava a "Sem alterações" sobre uma fonte que já
 * não confere com a posição publicada -- a mesma que motivou retificar.
 */
it('checks the source again on the restored published version once the rectification is abandoned', function () {
    $scenario = ExtemporaneousFixture::rectifiableJuly();
    $published = SalesBoardCycleBaseline::query()->findOrFail($scenario['publication']->publication->sales_board_cycle_baseline_id);
    $rectification = ExtemporaneousFixture::rectify($scenario['july']);

    expect($published->fresh()->is_stale)->toBeFalse();

    $this->travel(10)->minutes();
    $checkedAt = now()->toDateTimeString();

    app(SalesBoardCycleRectificationService::class)
        ->abandon($rectification, GovernanceFixture::approver(), 'A construtora confirmou o valor publicado.');

    $published->refresh();

    expect($scenario['july']->fresh()->current_baseline_id)->toBe($published->id)
        ->and($published->is_stale)->toBeTrue()
        ->and($published->stale_impact)->toBe(SalesBoardStaleImpact::Material)
        ->and($published->last_checked_at?->toDateTimeString())->toBe($checkedAt);
});

it('keeps the abandonment when the source check after it fails', function () {
    $scenario = ExtemporaneousFixture::rectifiableJuly();
    $rectification = ExtemporaneousFixture::rectify($scenario['july']);

    Exceptions::fake();

    $this->mock(SalesBoardStaleDetectionService::class, fn (MockInterface $mock) => $mock
        ->shouldReceive('check')
        ->once()
        ->andThrow(new RuntimeException('Fonte indisponível no momento.')));

    $abandoned = app(SalesBoardCycleRectificationService::class)
        ->abandon($rectification, GovernanceFixture::approver(), 'A construtora confirmou o valor publicado.');

    expect($abandoned->status)->toBe(SalesBoardRectificationStatus::Abandoned)
        ->and($scenario['july']->fresh()->status)->toBe(SalesBoardCycleStatus::Approved);

    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'Fonte indisponível no momento.');
});

it('keeps the round that sustains the publication when the rectification opens and recalculates', function () {
    $scenario = ExtemporaneousFixture::rectifiableJuly();
    $publishedRound = SalesBoardBuilderReview::query()->findOrFail($scenario['publication']->publication->sales_board_builder_review_id);

    ExtemporaneousFixture::rectify($scenario['july']);

    expect($publishedRound->fresh()->status)->toBe(SalesBoardBuilderReviewStatus::Submitted);

    $scenario['financed']->forceFill(['sale_value' => '660000.00'])->save();
    CycleFixture::recalculate($scenario['july'], 'Valor de venda corrigido de novo pela construtora.');

    expect(CycleFixture::currentBaseline($scenario['july'])->version)->toBe(3)
        ->and($publishedRound->fresh()->status)->toBe(SalesBoardBuilderReviewStatus::Submitted)
        ->and($publishedRound->fresh()->superseded_at)->toBeNull();
});

it('rechecks the next competence in progress once the rectification is published', function () {
    $scenario = ExtemporaneousFixture::rectifiableJuly();
    $august = ExtemporaneousFixture::generateAugust($scenario['construction']);
    $augustReview = ExtemporaneousFixture::analysis($august);

    // Agosto ancorou na publicação de julho e trouxe a revisão da venda.
    expect(ExtemporaneousFixture::movements($august, SalesBoardMovementType::Sale, SalesBoardMovementTiming::SaleRevision))->toHaveCount(1);

    ExtemporaneousFixture::rectify($scenario['july']);
    ExtemporaneousFixture::publish($scenario['july']);

    $baseline = CycleFixture::currentBaseline($august);

    expect($baseline->is_stale)->toBeTrue()
        ->and($baseline->stale_impact)->toBe(SalesBoardStaleImpact::Material)
        ->and(fn () => ManagementReviewFixture::approve($augustReview))
        ->toThrow(SalesBoardManagementReviewException::class, 'Recalcule antes da aprovação');

    CycleFixture::recalculate($august, 'Julho retificado: a venda revista entrou na posição publicada.');

    expect(ExtemporaneousFixture::movements($august, SalesBoardMovementType::Sale, SalesBoardMovementTiming::SaleRevision))->toHaveCount(0)
        ->and(CycleFixture::currentBaseline($august)->previous_competence_baseline_id)
        ->toBe(CycleFixture::currentBaseline($scenario['july'])->id);
});

it('keeps the published board closed to manual writes during the rectification', function () {
    $scenario = ExtemporaneousFixture::rectifiableJuly();
    $board = SalesBoard::query()->findOrFail($scenario['publication']->salesBoard->id);
    $boardValues = rectificationBoardValues($board);
    $context = app(SalesBoardWriteContext::class);

    ExtemporaneousFixture::rectify($scenario['july']);

    $board->financed_value = '650000.00';
    $board->changeReason = 'Ajuste manual durante a retificação.';

    expect(fn () => $board->save())
        ->toThrow(SalesBoardRolloutException::class, 'Este Quadro de Vendas foi publicado')
        // O contexto da republicação vale só para o quadro que ele abriu.
        ->and(fn () => $context->asRepublication((int) $board->id + 1, fn (): bool => $board->save()))
        ->toThrow(SalesBoardRolloutException::class, 'Este Quadro de Vendas foi publicado')
        // E nunca libera a exclusão.
        ->and(fn () => $context->asRepublication((int) $board->id, fn (): ?bool => $board->fresh()->delete()))
        ->toThrow(SalesBoardRolloutException::class, 'Este Quadro de Vendas foi publicado')
        ->and(rectificationBoardValues($board))->toBe($boardValues);
});

it('keeps exchanges and discount policies out of the competence under rectification', function () {
    $scenario = ExtemporaneousFixture::rectifiableJuly();
    ExtemporaneousFixture::rectify($scenario['july']);

    $approver = GovernanceFixture::approver();
    $approver->givePermissionTo(AccessPermission::EmissionsUpdate->value);
    $exchanges = app(ConstructionUnitExchangeService::class);

    // Uma permuta que já ocupava a unidade, gravada por fora do serviço.
    $exchange = ConstructionUnitExchange::factory()->forUnit($scenario['units'][3])->effectiveFrom('2026-01-01')->worth('450000.00')->create();

    expect(fn () => $exchanges->registerExtraordinary(
        $scenario['units'][2],
        $approver,
        '450000.00',
        CarbonImmutable::parse('2026-07-20'),
        null,
        'Permuta acertada com a construtora em aditivo ao contrato de obra.',
    ))->toThrow(ConstructionUnitExchangeException::class, 'posterior a 31/07/2026')
        ->and(fn () => $exchanges->end(
            $exchange,
            $approver,
            CarbonImmutable::parse('2026-07-20'),
            'Permuta encerrada no aditivo de julho.',
        ))->toThrow(ConstructionUnitExchangeException::class, 'posterior a 31/07/2026')
        ->and(fn () => app(SalesDiscountPolicyRegistrar::class)->register(
            $scenario['construction'],
            [
                'maximum_discount_percent' => '15.00',
                'effective_from' => '2026-07-01',
                'effective_until' => '2026-07-31',
            ],
            null,
            $approver,
        ))->toThrow(SalesDiscountPolicyPeriodException::class, 'competência já aprovada e publicada desta obra (07/2026)');
});

it('rectifies a competence after the emission went back to legacy, without the automation coverage check', function () {
    $scenario = ExtemporaneousFixture::rectifiableJuly();
    RolloutFixture::returnToLegacy($scenario['construction']->emission);

    ExtemporaneousFixture::rectify($scenario['july']);
    $result = ExtemporaneousFixture::publish($scenario['july']);

    expect($result->outcome)->toBe(SalesBoardApprovalOutcome::Approved)
        ->and($result->publication->sequence_number)->toBe(2)
        ->and($scenario['construction']->emission->fresh()->usesAutomatedSalesBoard())->toBeFalse();
});

it('refuses to publish the rectification once a later competence was published meanwhile', function () {
    $scenario = ExtemporaneousFixture::rectifiableJuly();
    ExtemporaneousFixture::rectify($scenario['july']);
    $review = ExtemporaneousFixture::analysis($scenario['july']);
    ManagementReviewFixture::decideAll($review);

    // Setembro sem agosto no ciclo: a regra de ordem não o segura.
    $september = CycleFixture::generate($scenario['construction'], '2026-09-01')->cycle;
    ExtemporaneousFixture::publish($september);

    expect(fn () => ManagementReviewFixture::approve($review))
        ->toThrow(SalesBoardRectificationException::class, 'A competência 07/2026 deixou de ser a última publicada do empreendimento: 09/2026 foi publicada depois')
        ->and(SalesBoardPublication::query()->where('sales_board_cycle_id', $scenario['july']->id)->count())->toBe(1)
        ->and($review->fresh()->status)->toBe(SalesBoardManagementReviewStatus::Draft);
});

it('refuses to publish a rectification whose version went back to the published position', function () {
    $scenario = ExtemporaneousFixture::rectifiableJuly();
    ExtemporaneousFixture::rectify($scenario['july']);

    $scenario['financed']->forceFill(['sale_value' => '600000.00'])->save();
    CycleFixture::recalculate($scenario['july'], 'A construtora voltou o valor de venda ao publicado.');
    $review = ExtemporaneousFixture::analysis($scenario['july']);
    ManagementReviewFixture::decideAll($review);

    $check = collect(app(SalesBoardManagementApprovalService::class)->gate($review->fresh())['checks'])
        ->firstWhere('label', 'A retificação muda a posição publicada');

    expect($check['passed'])->toBeFalse()
        ->and(fn () => ManagementReviewFixture::approve($review))
        ->toThrow(SalesBoardRectificationException::class, 'A versão em análise de 07/2026 é igual à posição publicada: não há o que publicar.')
        ->and(SalesBoardPublication::query()->where('sales_board_cycle_id', $scenario['july']->id)->count())->toBe(1);
});

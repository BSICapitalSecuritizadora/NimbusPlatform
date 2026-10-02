<?php

use App\Enums\SalesBoardApprovalOutcome;
use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardMovementTiming;
use App\Enums\SalesBoardMovementType;
use App\Enums\SalesBoardStaleImpact;
use App\Exceptions\SalesBoardManagementReviewException;
use App\Models\SalesBoardCycle;
use App\Services\SalesBoards\SalesBoardCycleCancellationService;
use App\Services\SalesBoards\SalesBoardCycleReopeningService;
use App\Services\SalesBoards\SalesBoardManagementApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SalesBoards\BuilderReviewFixture;
use Tests\Support\SalesBoards\CycleFixture;
use Tests\Support\SalesBoards\ExtemporaneousFixture;
use Tests\Support\SalesBoards\GovernanceFixture;
use Tests\Support\SalesBoards\ManagementReviewFixture;

uses(RefreshDatabase::class);

/**
 * A regra de ordem da aprovação: os movimentos de M partem da posição da
 * âncora -- M-1 ou, com M-1 cancelada, a primeira competência não cancelada
 * antes dela --, então M só é publicada com a âncora aprovada. Sem ciclo antes
 * da cadeia de canceladas não há exigência.
 */

/**
 * Deixa a competência no estado pedido: gerada, em validação, em análise,
 * aprovada ou cancelada.
 */
function priorCompetenceInState(SalesBoardCycle $cycle, string $state): void
{
    match ($state) {
        'gerada' => null,
        'em validação' => BuilderReviewFixture::open($cycle),
        'em análise' => ExtemporaneousFixture::analysis($cycle),
        'aprovada' => ExtemporaneousFixture::publish($cycle),
        'cancelada' => app(SalesBoardCycleCancellationService::class)->cancel($cycle->fresh(), GovernanceFixture::approver(), 'Competência encerrada sem posição.'),
    };
}

it('refuses to approve a competence while the previous one is still open', function (string $state, string $situation) {
    [$construction] = CycleFixture::readyConstruction(2);
    $july = CycleFixture::generate($construction, '2026-07-01')->cycle;
    $august = ExtemporaneousFixture::generateAugust($construction);

    priorCompetenceInState($july, $state);

    $review = ExtemporaneousFixture::analysis($august);
    $gate = app(SalesBoardManagementApprovalService::class)->gate($review->fresh());
    $check = collect($gate['checks'])->firstWhere('label', 'Competência anterior encerrada');

    expect($gate['ready'])->toBeFalse()
        ->and($check['passed'])->toBeFalse()
        ->and($check['detail'])->toContain(sprintf('situação: %s', $situation))
        ->and(fn () => ManagementReviewFixture::approve($review))
        ->toThrow(SalesBoardManagementReviewException::class, 'A competência anterior (07/2026) ainda não foi aprovada nem cancelada');
})->with([
    'gerada' => ['gerada', 'Gerado'],
    'em validação' => ['em validação', 'Em validação da construtora'],
    'em análise' => ['em análise', 'Em análise da Gestão'],
]);

it('approves a competence whose previous one is approved or cancelled', function (string $state) {
    [$construction] = CycleFixture::readyConstruction(2);
    $july = CycleFixture::generate($construction, '2026-07-01')->cycle;

    priorCompetenceInState($july, $state);

    $august = ExtemporaneousFixture::generateAugust($construction);
    $review = ExtemporaneousFixture::analysis($august);
    $check = collect(app(SalesBoardManagementApprovalService::class)->gate($review->fresh())['checks'])
        ->firstWhere('label', 'Competência anterior encerrada');

    expect($check['passed'])->toBeTrue()
        ->and(ManagementReviewFixture::approve($review)->outcome)->toBe(SalesBoardApprovalOutcome::Approved);
})->with([
    'aprovada' => ['aprovada'],
    'cancelada' => ['cancelada'],
]);

it('does not hold a competence whose previous month has no cycle', function () {
    [$construction] = CycleFixture::readyConstruction(2);

    $august = ExtemporaneousFixture::generateAugust($construction);
    $review = ExtemporaneousFixture::analysis($august);

    expect(ManagementReviewFixture::approve($review)->outcome)->toBe(SalesBoardApprovalOutcome::Approved);
});

it('holds the next competence while the previous one is under rectification', function () {
    $scenario = ExtemporaneousFixture::publishedJuly();
    $august = ExtemporaneousFixture::generateAugust($scenario['construction']);
    $review = ExtemporaneousFixture::analysis($august);

    $scenario['financed']->forceFill(['sale_value' => '650000.00'])->save();
    ExtemporaneousFixture::rectify($scenario['july']);

    $check = collect(app(SalesBoardManagementApprovalService::class)->gate($review->fresh())['checks'])
        ->firstWhere('label', 'Competência anterior encerrada');

    expect($check['passed'])->toBeFalse()
        ->and($check['detail'])->toContain('está em retificação')
        ->and(fn () => ManagementReviewFixture::approve($review))
        ->toThrow(SalesBoardManagementReviewException::class, 'A competência anterior (07/2026) está em retificação');
});

it('applies the order rule to the approval of a rectification too', function () {
    [$construction, $units] = CycleFixture::readyConstruction(2);

    // Agosto publicado antes de julho existir no ciclo. A geração de julho
    // depois disso é recusada; o ciclo aberto de julho é o estado que uma carga
    // ou uma geração anterior à recusa deixariam.
    $august = ExtemporaneousFixture::generateAugust($construction);
    ExtemporaneousFixture::publish($august);
    SalesBoardCycle::factory()->forConstruction($construction)->referenceMonth('2026-07-01')->create();

    ExtemporaneousFixture::sale($units[1], '2026-08-10');
    ExtemporaneousFixture::rectify($august);

    $review = ExtemporaneousFixture::analysis($august);

    expect(fn () => ManagementReviewFixture::approve($review))
        ->toThrow(SalesBoardManagementReviewException::class, 'A competência anterior (07/2026) ainda não foi aprovada nem cancelada');
});

/**
 * Reabrir a anterior cancelada depois que a seguinte só foi gerada: a seguinte,
 * que absorvia os fatos do mês cancelado, volta a ancorar nele -- a conferência
 * dela é antecipada pelo evento da reabertura e acusa "Alterações materiais" --,
 * e a regra de ordem a segura até a anterior ser aprovada ou cancelada de novo.
 */
it('rechecks and holds the next competence when the cancelled previous one is reopened', function () {
    [$construction, $units] = CycleFixture::readyConstruction(3);
    ExtemporaneousFixture::publish(CycleFixture::generate($construction, '2026-06-01')->cycle);
    $july = CycleFixture::generate($construction, '2026-07-01')->cycle;
    priorCompetenceInState($july, 'cancelada');

    ExtemporaneousFixture::sale($units[0], '2026-07-12');

    $august = ExtemporaneousFixture::generateAugust($construction);
    $review = ExtemporaneousFixture::analysis($august);

    expect(ExtemporaneousFixture::movements($august, SalesBoardMovementType::Sale, SalesBoardMovementTiming::WithoutPosition))->toHaveCount(1);

    app(SalesBoardCycleReopeningService::class)->reopen($july->fresh(), GovernanceFixture::approver(), 'O cancelamento foi um engano: a fonte já estava correta.');

    $baseline = CycleFixture::currentBaseline($august);

    expect($july->fresh()->status)->toBe(SalesBoardCycleStatus::Generated)
        ->and($baseline->is_stale)->toBeTrue()
        ->and($baseline->stale_impact)->toBe(SalesBoardStaleImpact::Material)
        ->and(fn () => ManagementReviewFixture::approve($review))
        ->toThrow(SalesBoardManagementReviewException::class, 'A competência anterior (07/2026) ainda não foi aprovada nem cancelada');
});

/**
 * Com M-1 cancelada, a âncora de M é a competência não cancelada antes dela, e
 * é ela que precisa estar aprovada -- a mesma cadeia que a derivação anda.
 */
it('holds a competence whose anchor across a cancelled month is still open', function (string $state, string $situation) {
    [$construction] = CycleFixture::readyConstruction(2);
    $june = CycleFixture::generate($construction, '2026-06-01')->cycle;
    $july = CycleFixture::generate($construction, '2026-07-01')->cycle;

    priorCompetenceInState($july, 'cancelada');
    priorCompetenceInState($june, $state);

    $review = ExtemporaneousFixture::analysis(ExtemporaneousFixture::generateAugust($construction));
    $gate = app(SalesBoardManagementApprovalService::class)->gate($review->fresh());
    $check = collect($gate['checks'])->firstWhere('label', 'Competência anterior encerrada');

    expect($gate['ready'])->toBeFalse()
        ->and($check['passed'])->toBeFalse()
        ->and($check['detail'])->toBe(sprintf(
            'A competência 06/2026 ainda não foi aprovada nem cancelada (situação: %s). '
                .'Como 07/2026 foi cancelada, os movimentos de 08/2026 partem da posição de 06/2026: aprove ou cancele 06/2026 antes de aprovar 08/2026.',
            $situation,
        ))
        ->and(fn () => ManagementReviewFixture::approve($review))
        ->toThrow(SalesBoardManagementReviewException::class, 'A competência 06/2026 ainda não foi aprovada nem cancelada');
})->with([
    'gerada' => ['gerada', 'Gerado'],
    'em validação' => ['em validação', 'Em validação da construtora'],
    'em análise' => ['em análise', 'Em análise da Gestão'],
]);

it('holds a competence whose anchor across a cancelled month is under rectification', function () {
    $scenario = ExtemporaneousFixture::publishedJuly();
    $construction = $scenario['construction'];

    priorCompetenceInState(ExtemporaneousFixture::generateAugust($construction), 'cancelada');

    $scenario['financed']->forceFill(['sale_value' => '650000.00'])->save();
    ExtemporaneousFixture::rectify($scenario['july']);

    $review = ExtemporaneousFixture::analysis(CycleFixture::generate($construction, '2026-09-01')->cycle);
    $check = collect(app(SalesBoardManagementApprovalService::class)->gate($review->fresh())['checks'])
        ->firstWhere('label', 'Competência anterior encerrada');

    expect($check['passed'])->toBeFalse()
        ->and($check['detail'])->toBe('A competência 07/2026 está em retificação. Como 08/2026 foi cancelada, os movimentos de 09/2026 partem da posição de 07/2026: conclua ou desista da retificação antes de aprovar 09/2026.')
        ->and(fn () => ManagementReviewFixture::approve($review))
        ->toThrow(SalesBoardManagementReviewException::class, 'A competência 07/2026 está em retificação');
});

it('approves a competence whose cancelled chain ends at an approved competence or at a month without cycle', function (string $chain) {
    [$construction] = CycleFixture::readyConstruction(2);

    match ($chain) {
        'junho aprovada, julho cancelada' => (function () use ($construction): void {
            priorCompetenceInState(CycleFixture::generate($construction, '2026-06-01')->cycle, 'aprovada');
            priorCompetenceInState(CycleFixture::generate($construction, '2026-07-01')->cycle, 'cancelada');
        })(),
        'maio aprovada, junho e julho canceladas' => (function () use ($construction): void {
            priorCompetenceInState(CycleFixture::generate($construction, '2026-05-01')->cycle, 'aprovada');
            priorCompetenceInState(CycleFixture::generate($construction, '2026-06-01')->cycle, 'cancelada');
            priorCompetenceInState(CycleFixture::generate($construction, '2026-07-01')->cycle, 'cancelada');
        })(),
        'junho sem ciclo, julho cancelada' => priorCompetenceInState(CycleFixture::generate($construction, '2026-07-01')->cycle, 'cancelada'),
    };

    $review = ExtemporaneousFixture::analysis(ExtemporaneousFixture::generateAugust($construction));
    $check = collect(app(SalesBoardManagementApprovalService::class)->gate($review->fresh())['checks'])
        ->firstWhere('label', 'Competência anterior encerrada');

    expect($check['passed'])->toBeTrue()
        ->and(ManagementReviewFixture::approve($review)->outcome)->toBe(SalesBoardApprovalOutcome::Approved);
})->with([
    'junho aprovada, julho cancelada',
    'maio aprovada, junho e julho canceladas',
    'junho sem ciclo, julho cancelada',
]);

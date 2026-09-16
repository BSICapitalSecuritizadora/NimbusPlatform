<?php

use App\Enums\SalesBoardBuilderReviewStatus;
use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardManagementReviewStatus;
use App\Events\SalesBoards\SalesBoardCurrentBaselineChanged;
use App\Listeners\SalesBoards\SupersedeBuilderReviewOnBaselineChange;
use App\Listeners\SalesBoards\SupersedeManagementReviewOnBaselineChange;
use App\Models\SalesBoardBuilderReview;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleBaseline;
use App\Models\SalesBoardManagementReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\SalesBoards\CycleFixture;
use Tests\Support\SalesBoards\DerivationFixture;
use Tests\Support\SalesBoards\ManagementReviewFixture;

uses(RefreshDatabase::class);

/**
 * Quem reage a uma nova versão material do Quadro, e quando.
 *
 * Os dois ouvintes moram em `app/Listeners` e são registrados pelo event
 * discovery do Laravel -- só por ele. Um registro manual a mais fazia cada um
 * rodar duas vezes; a idempotência escondia o efeito, mas não a duplicação.
 *
 * O anúncio da troca sai depois do commit do recálculo. Um recálculo desfeito
 * não pode ter substituído revisão nenhuma: a versão que justificaria isso
 * nunca existiu.
 */
pest()->group('parity');

/**
 * Uma competência com a validação da construtora enviada e a análise da Gestão
 * aberta -- as duas revisões que uma mudança material invalida.
 *
 * @return array{cycle: SalesBoardCycle, builderReview: SalesBoardBuilderReview, managementReview: SalesBoardManagementReview}
 */
function cycleWithBothReviewsOpen(): array
{
    $scenario = ManagementReviewFixture::submittedCycle();
    $managementReview = ManagementReviewFixture::open($scenario['cycle']);

    // Mudança material na fonte: uma unidade nova em estoque.
    DerivationFixture::unit($scenario['construction'], '777');

    return [
        'cycle' => $scenario['cycle']->fresh(),
        'builderReview' => $scenario['builderReview']->fresh(),
        'managementReview' => $managementReview->fresh(),
    ];
}

it('registers each baseline change listener exactly once', function () {
    $listeners = collect(Event::getRawListeners()[SalesBoardCurrentBaselineChanged::class] ?? [])
        ->map(fn (mixed $listener): string => is_array($listener)
            ? (is_object($listener[0]) ? $listener[0]::class : (string) $listener[0])
            : (is_string($listener) ? explode('@', $listener)[0] : 'closure'))
        ->sort()
        ->values()
        ->all();

    expect($listeners)->toBe([
        SupersedeBuilderReviewOnBaselineChange::class,
        SupersedeManagementReviewOnBaselineChange::class,
    ]);
});

it('supersedes nothing when the recalculation is rolled back', function () {
    $scenario = cycleWithBothReviewsOpen();
    $baselines = SalesBoardCycleBaseline::query()->count();

    expect(fn () => DB::transaction(function () use ($scenario): void {
        CycleFixture::recalculate($scenario['cycle'], 'Recálculo que será desfeito.');

        throw new RuntimeException('Desfaz o recálculo depois de ele ter anunciado a troca.');
    }))->toThrow(RuntimeException::class, 'Desfaz o recálculo');

    expect($scenario['builderReview']->fresh()->status)->toBe(SalesBoardBuilderReviewStatus::Submitted)
        ->and($scenario['managementReview']->fresh()->status)->toBe(SalesBoardManagementReviewStatus::Draft)
        ->and($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::ManagementReview)
        ->and($scenario['cycle']->fresh()->current_baseline_id)->toBe($scenario['cycle']->current_baseline_id)
        ->and(SalesBoardCycleBaseline::query()->count())->toBe($baselines);
});

it('supersedes each outdated review exactly once when the recalculation commits', function () {
    $scenario = cycleWithBothReviewsOpen();
    $builderReviews = SalesBoardBuilderReview::query()->count();
    $managementReviews = SalesBoardManagementReview::query()->count();

    CycleFixture::recalculate($scenario['cycle'], 'Recálculo confirmado.');

    $builderReview = $scenario['builderReview']->fresh();
    $managementReview = $scenario['managementReview']->fresh();

    expect($builderReview->status)->toBe(SalesBoardBuilderReviewStatus::Superseded)
        ->and($builderReview->superseded_at)->not->toBeNull()
        ->and($managementReview->status)->toBe(SalesBoardManagementReviewStatus::Superseded)
        ->and($managementReview->superseded_at)->not->toBeNull()
        ->and(SalesBoardBuilderReview::query()->where('status', SalesBoardBuilderReviewStatus::Superseded)->count())->toBe(1)
        ->and(SalesBoardManagementReview::query()->where('status', SalesBoardManagementReviewStatus::Superseded)->count())->toBe(1)
        // Ninguém abre revisão nova sozinho: a próxima tentativa é ato humano.
        ->and(SalesBoardBuilderReview::query()->count())->toBe($builderReviews)
        ->and(SalesBoardManagementReview::query()->count())->toBe($managementReviews)
        ->and($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::BuilderReview)
        ->and($scenario['cycle']->fresh()->current_baseline_id)->not->toBe($scenario['cycle']->current_baseline_id);
});

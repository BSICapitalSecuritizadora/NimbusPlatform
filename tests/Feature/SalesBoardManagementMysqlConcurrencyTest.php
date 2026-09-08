<?php

use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardManagementReviewStatus;
use App\Enums\SalesBoardNonconformityDecision;
use App\Exceptions\SalesBoardManagementReviewException;
use App\Models\SalesBoard;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardManagementNonconformity;
use App\Models\SalesBoardManagementReview;
use App\Models\SalesBoardPublication;
use App\Models\User;
use App\Services\SalesBoards\SalesBoardManagementApprovalService;
use App\Services\SalesBoards\SalesBoardManagementDecisionService;
use App\Services\SalesBoards\SalesBoardManagementReturnService;
use App\Services\SalesBoards\SalesBoardManagementReviewOpeningService;
use App\Services\SalesBoards\SalesBoardRecalculationService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Tests\Support\SalesBoards\ManagementReviewFixture;

/**
 * As corridas da análise e da publicação, em conexões reais.
 *
 * Dois gestores abrindo a mesma análise, dois cliques em "aprovar e publicar",
 * um recálculo material nascendo no meio de uma aprovação e uma devolução
 * disputando com ela. Nenhuma delas o SQLite consegue mostrar, porque ele
 * serializa escritores -- e todas são exatamente o caminho pelo qual um Quadro
 * de Vendas errado seria publicado.
 */
beforeEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requer MySQL para observar duas conexões disputando o mesmo ciclo.');
    }

    Artisan::call('migrate:fresh', ['--no-interaction' => true]);
});

/**
 * Os processos filhos commitam fora de qualquer transação de teste. Sem esta
 * limpeza, o arquivo seguinte da suíte herdaria ciclos, análises e quadros
 * alheios.
 */
afterEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        return;
    }

    DB::table('sales_board_publications')->delete();
    DB::table('sales_board_management_nonconformities')->delete();
    DB::table('sales_board_management_reviews')->delete();
    DB::table('sales_board_builder_divergences')->delete();
    DB::table('sales_board_builder_review_sections')->delete();
    DB::table('sales_board_builder_reviews')->delete();
    DB::table('sales_board_histories')->delete();
    DB::table('sales_boards')->delete();
    DB::table('sales_board_cycles')->update(['current_baseline_id' => null]);
    DB::table('sales_board_cycle_movements')->delete();
    DB::table('sales_board_cycle_lines')->delete();
    DB::table('sales_board_cycle_baselines')->delete();
    DB::table('sales_board_cycles')->delete();
});

/**
 * @param  array{action: string, cycle_id?: int, review_id?: int, nonconformity_id?: int, decision?: string, actor_id: int, reason?: string, lock_marker?: string, wait_for_marker?: string, hold_after_lock_ms?: int}  $instruction
 */
function managementTask(array $instruction): Closure
{
    return static function () use ($instruction): array {
        if (isset($instruction['lock_marker'])) {
            DB::listen(static function (QueryExecuted $query) use ($instruction): void {
                $sql = strtolower($query->sql);

                if (! str_contains($sql, 'sales_board_cycles') || ! str_contains($sql, 'for update')) {
                    return;
                }

                file_put_contents($instruction['lock_marker'], 'locked');
                usleep(((int) ($instruction['hold_after_lock_ms'] ?? 0)) * 1000);
            });
        }

        try {
            if (isset($instruction['wait_for_marker'])) {
                $deadline = microtime(true) + 10;

                while (! is_file($instruction['wait_for_marker']) && microtime(true) < $deadline) {
                    usleep(10_000);
                }

                if (! is_file($instruction['wait_for_marker'])) {
                    throw new RuntimeException('O processo concorrente não confirmou a aquisição do lock.');
                }
            }

            $actor = User::query()->findOrFail($instruction['actor_id']);

            $outcome = match ($instruction['action']) {
                'open' => (string) app(SalesBoardManagementReviewOpeningService::class)
                    ->open(SalesBoardCycle::query()->findOrFail($instruction['cycle_id']), $actor)
                    ->getKey(),
                'approve' => app(SalesBoardManagementApprovalService::class)
                    ->approve(
                        SalesBoardManagementReview::query()->findOrFail($instruction['review_id']),
                        $actor,
                        true,
                    )->outcome->value,
                'return' => app(SalesBoardManagementReturnService::class)
                    ->returnToBuilder(
                        SalesBoardManagementReview::query()->findOrFail($instruction['review_id']),
                        $actor,
                        $instruction['reason'] ?? 'Devolução concorrente para conferência adicional.',
                    )['review']->status->value,
                'decide' => app(SalesBoardManagementDecisionService::class)
                    ->decide(
                        SalesBoardManagementNonconformity::query()->findOrFail($instruction['nonconformity_id']),
                        SalesBoardNonconformityDecision::from((string) $instruction['decision']),
                        $instruction['reason'] ?? null,
                        $actor,
                    )->decision->value,
                'recalculate' => app(SalesBoardRecalculationService::class)
                    ->recalculate(
                        SalesBoardCycle::query()->findOrFail($instruction['cycle_id']),
                        $actor,
                        'Recálculo concorrente.',
                    )->outcome->value,
            };

            return ['success' => true, 'outcome' => $outcome, 'exception' => null];
        } catch (Throwable $exception) {
            return ['success' => false, 'outcome' => null, 'exception' => $exception::class];
        }
    };
}

it('never opens two management reviews for the same cycle', function () {
    $scenario = ManagementReviewFixture::submittedCycleWithNonConformSale();
    $actor = User::factory()->create();

    $results = Concurrency::driver('process')->run([
        managementTask(['action' => 'open', 'cycle_id' => $scenario['cycle']->id, 'actor_id' => $actor->id]),
        managementTask(['action' => 'open', 'cycle_id' => $scenario['cycle']->id, 'actor_id' => $actor->id]),
    ]);

    expect(collect($results)->where('success', true))->toHaveCount(2)
        ->and(collect($results)->pluck('outcome')->unique())->toHaveCount(1)
        ->and(SalesBoardManagementReview::query()->count())->toBe(1)
        // E as pendências também nascem uma vez só.
        ->and(SalesBoardManagementNonconformity::query()->count())->toBe(1);
})->group('mysql');

it('never publishes two boards for the same cycle', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $actor = User::factory()->create();
    $review = ManagementReviewFixture::open($scenario['cycle'], $actor);

    $marker = temporaryTestFilePath('management-approve-lock', 'lock');
    @unlink($marker);

    $results = Concurrency::driver('process')->run([
        managementTask([
            'action' => 'approve',
            'review_id' => $review->id,
            'actor_id' => $actor->id,
            'lock_marker' => $marker,
            'hold_after_lock_ms' => 400,
        ]),
        managementTask([
            'action' => 'approve',
            'review_id' => $review->id,
            'actor_id' => $actor->id,
            'wait_for_marker' => $marker,
        ]),
    ]);

    @unlink($marker);

    // Os dois terminam bem: um publica, o outro encontra publicado. Nenhum
    // SQLSTATE chega à superfície.
    expect(collect($results)->where('success', true))->toHaveCount(2)
        ->and(collect($results)->pluck('outcome')->sort()->values()->all())
        ->toBe(['aprovado', 'ja_aprovado'])
        ->and(SalesBoard::query()->count())->toBe(1)
        ->and(SalesBoardPublication::query()->count())->toBe(1)
        ->and($review->fresh()->status)->toBe(SalesBoardManagementReviewStatus::Approved)
        ->and($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::Approved);
})->group('mysql');

it('never publishes a baseline other than the approved one when a recalculation races it', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $actor = User::factory()->create();
    $review = ManagementReviewFixture::open($scenario['cycle'], $actor);

    // A fonte muda: o recálculo concorrente vai produzir uma V2 material.
    $scenario['contracts']['financed']->update(['sale_value' => '910000.00']);

    $marker = temporaryTestFilePath('management-recalc-lock', 'lock');
    @unlink($marker);

    $results = Concurrency::driver('process')->run([
        managementTask([
            'action' => 'recalculate',
            'cycle_id' => $scenario['cycle']->id,
            'actor_id' => $actor->id,
            'lock_marker' => $marker,
            'hold_after_lock_ms' => 400,
        ]),
        managementTask([
            'action' => 'approve',
            'review_id' => $review->id,
            'actor_id' => $actor->id,
            'wait_for_marker' => $marker,
        ]),
    ]);

    @unlink($marker);

    // Cenário B: o recálculo trava primeiro, a análise antiga é substituída e a
    // aprovação da versão anterior não atravessa.
    expect(collect($results)->firstWhere('outcome', 'recalculado'))->not->toBeNull()
        ->and(collect($results)->where('success', false)->pluck('exception')->all())
        ->toBe([SalesBoardManagementReviewException::class])
        ->and(SalesBoard::query()->count())->toBe(0)
        ->and(SalesBoardPublication::query()->count())->toBe(0)
        ->and($review->fresh()->status)->not->toBe(SalesBoardManagementReviewStatus::Approved)
        ->and($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::BuilderReview);
})->group('mysql');

it('refuses a recalculation that races an approval and loses', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $actor = User::factory()->create();
    $review = ManagementReviewFixture::open($scenario['cycle'], $actor);

    $scenario['contracts']['financed']->update(['sale_value' => '910000.00']);

    $marker = temporaryTestFilePath('management-approve-first-lock', 'lock');
    @unlink($marker);

    $results = Concurrency::driver('process')->run([
        managementTask([
            'action' => 'approve',
            'review_id' => $review->id,
            'actor_id' => $actor->id,
            'lock_marker' => $marker,
            'hold_after_lock_ms' => 400,
        ]),
        managementTask([
            'action' => 'recalculate',
            'cycle_id' => $scenario['cycle']->id,
            'actor_id' => $actor->id,
            'wait_for_marker' => $marker,
        ]),
    ]);

    @unlink($marker);

    // Cenário A: a aprovação trava primeiro. Ela mesma recusa -- a fonte mudou
    // materialmente -- e o recálculo seguinte passa. O que nunca acontece é
    // publicar a posição que a fonte já contradiz.
    expect(SalesBoard::query()->count())->toBe(0)
        ->and(SalesBoardPublication::query()->count())->toBe(0)
        ->and(collect($results)->where('success', false)->pluck('exception')->all())
        ->toBe([SalesBoardManagementReviewException::class]);
})->group('mysql');

it('never ends with a returned review next to an approved cycle', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $actor = User::factory()->create();
    $review = ManagementReviewFixture::open($scenario['cycle'], $actor);

    $marker = temporaryTestFilePath('management-return-lock', 'lock');
    @unlink($marker);

    $results = Concurrency::driver('process')->run([
        managementTask([
            'action' => 'return',
            'review_id' => $review->id,
            'actor_id' => $actor->id,
            'lock_marker' => $marker,
            'hold_after_lock_ms' => 400,
        ]),
        managementTask([
            'action' => 'approve',
            'review_id' => $review->id,
            'actor_id' => $actor->id,
            'wait_for_marker' => $marker,
        ]),
    ]);

    @unlink($marker);

    $finalReview = $review->fresh();
    $finalCycle = $scenario['cycle']->fresh();

    expect(collect($results)->where('success', true))->toHaveCount(1)
        ->and(collect($results)->where('success', false)->pluck('exception')->all())
        ->toBe([SalesBoardManagementReviewException::class])
        ->and($finalReview->status)->toBe(SalesBoardManagementReviewStatus::Returned)
        ->and($finalCycle->status)->toBe(SalesBoardCycleStatus::BuilderReview)
        ->and(SalesBoard::query()->count())->toBe(0)
        ->and(SalesBoardPublication::query()->count())->toBe(0);
})->group('mysql');

it('never lets two managers decide the same nonconformity into different conclusions', function () {
    $scenario = ManagementReviewFixture::submittedCycleWithNonConformSale();
    $actor = User::factory()->create();
    $review = ManagementReviewFixture::open($scenario['cycle'], $actor);
    $item = $review->nonconformities->sole();

    $exception = 'Exceção autorizada pela diretoria comercial em ata.';
    $correction = 'A tabela de preços da unidade precisa ser corrigida.';

    $results = Concurrency::driver('process')->run([
        managementTask([
            'action' => 'decide',
            'nonconformity_id' => $item->id,
            'decision' => SalesBoardNonconformityDecision::AcceptedException->value,
            'reason' => $exception,
            'actor_id' => $actor->id,
        ]),
        managementTask([
            'action' => 'decide',
            'nonconformity_id' => $item->id,
            'decision' => SalesBoardNonconformityDecision::CorrectionRequired->value,
            'reason' => $correction,
            'actor_id' => $actor->id,
        ]),
    ]);

    $final = $item->fresh();

    /**
     * O lock da pendência serializa as duas: a última vence, inteira. O que não
     * pode acontecer é o resultado misturado -- uma conclusão com o motivo da
     * outra -- que é exatamente o que uma escrita campo a campo sem lock
     * produziria.
     */
    expect(collect($results)->where('success', true))->toHaveCount(2)
        ->and([$final->decision->value => $final->decision_reason])
        ->toBeIn([
            [SalesBoardNonconformityDecision::AcceptedException->value => $exception],
            [SalesBoardNonconformityDecision::CorrectionRequired->value => $correction],
        ]);
})->group('mysql');

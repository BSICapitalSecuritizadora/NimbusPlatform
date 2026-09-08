<?php

use App\Enums\SalesBoardManagementReviewStatus;
use App\Enums\SalesBoardNonconformityDecision;
use App\Enums\SalesBoardNonconformityOrigin;
use App\Models\SalesBoard;
use App\Models\SalesBoardCycleMovement;
use App\Models\SalesBoardManagementNonconformity;
use App\Models\SalesBoardManagementReview;
use App\Models\SalesBoardPublication;
use App\Models\User;
use App\Support\Money\IntegerMoney;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SalesBoards\CycleFixture;
use Tests\Support\SalesBoards\ManagementReviewFixture;

uses(RefreshDatabase::class);

/**
 * As garantias da análise e da publicação que dependem de o banco ser um banco
 * específico: unique, RESTRICT, e o round-trip exato dos valores publicados.
 */
pest()->group('parity');

it('allows a single management attempt per cycle', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $review = ManagementReviewFixture::open($scenario['cycle']);

    SalesBoardManagementReview::factory()->create([
        'sales_board_cycle_id' => $review->sales_board_cycle_id,
        'sales_board_cycle_baseline_id' => $review->sales_board_cycle_baseline_id,
        'sales_board_builder_review_id' => $review->sales_board_builder_review_id,
        'attempt' => 1,
    ]);
})->throws(UniqueConstraintViolationException::class);

it('allows a single nonconformity per builder divergence of a review', function () {
    $scenario = ManagementReviewFixture::submittedCycleWithNonConformSale();
    $review = ManagementReviewFixture::open($scenario['cycle']);
    $movement = $review->nonconformities->sole()->movement;

    SalesBoardManagementNonconformity::query()->create([
        'sales_board_management_review_id' => $review->id,
        'origin' => SalesBoardNonconformityOrigin::SystemSaleNonConform,
        'sales_board_cycle_movement_id' => $movement->id,
        'decision' => SalesBoardNonconformityDecision::Pending,
    ]);
})->throws(UniqueConstraintViolationException::class);

it('allows many nonconformities of the same review to leave the other anchor null', function () {
    $scenario = ManagementReviewFixture::submittedCycleWithNonConformSale();
    $review = ManagementReviewFixture::open($scenario['cycle']);
    $baseline = CycleFixture::currentBaseline($scenario['cycle']);

    // Índice único com nulos admite vários nulos nos dois bancos: é o que faz
    // as duas uniques restringirem só as linhas da própria origem.
    $other = SalesBoardCycleMovement::query()
        ->where('sales_board_cycle_baseline_id', $baseline->id)
        ->whereKeyNot($review->nonconformities->sole()->sales_board_cycle_movement_id)
        ->firstOrFail();

    SalesBoardManagementNonconformity::query()->create([
        'sales_board_management_review_id' => $review->id,
        'origin' => SalesBoardNonconformityOrigin::SystemSaleNonConform,
        'sales_board_cycle_movement_id' => $other->id,
        'decision' => SalesBoardNonconformityDecision::Pending,
    ]);

    expect(SalesBoardManagementNonconformity::query()
        ->where('sales_board_management_review_id', $review->id)
        ->whereNull('sales_board_builder_divergence_id')
        ->count())->toBe(2);
});

it('allows a single publication per cycle', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $review = ManagementReviewFixture::open($scenario['cycle']);
    ManagementReviewFixture::approve($review);

    $publication = SalesBoardPublication::query()->sole();

    SalesBoardPublication::factory()->create([
        'sales_board_cycle_id' => $publication->sales_board_cycle_id,
        'sales_board_cycle_baseline_id' => $publication->sales_board_cycle_baseline_id,
        'sales_board_builder_review_id' => $publication->sales_board_builder_review_id,
        'sales_board_management_review_id' => $publication->sales_board_management_review_id,
        'sales_board_id' => SalesBoard::factory()->create()->id,
    ]);
})->throws(UniqueConstraintViolationException::class);

it('allows a single publication per published board', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $review = ManagementReviewFixture::open($scenario['cycle']);
    ManagementReviewFixture::approve($review);

    $publication = SalesBoardPublication::query()->sole();

    SalesBoardPublication::factory()->create(['sales_board_id' => $publication->sales_board_id]);
})->throws(UniqueConstraintViolationException::class);

it('refuses to delete a cycle that has a management review', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    ManagementReviewFixture::open($scenario['cycle']);

    DB::table('sales_board_cycles')->where('id', $scenario['cycle']->id)->delete();
})->throws(QueryException::class);

it('refuses to delete a builder review that a management review points at', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    ManagementReviewFixture::open($scenario['cycle']);

    DB::table('sales_board_builder_reviews')->where('id', $scenario['builderReview']->id)->delete();
})->throws(QueryException::class);

it('refuses to delete a movement that a nonconformity points at', function () {
    $scenario = ManagementReviewFixture::submittedCycleWithNonConformSale();
    $review = ManagementReviewFixture::open($scenario['cycle']);

    DB::table('sales_board_cycle_movements')
        ->where('id', $review->nonconformities->sole()->sales_board_cycle_movement_id)
        ->delete();
})->throws(QueryException::class);

it('refuses to delete a published board that a publication points at', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $review = ManagementReviewFixture::open($scenario['cycle']);
    ManagementReviewFixture::approve($review);

    DB::table('sales_boards')->where('id', SalesBoardPublication::query()->sole()->sales_board_id)->delete();
})->throws(QueryException::class);

it('round-trips every published value to the exact cent', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $baseline = CycleFixture::currentBaseline($scenario['cycle']);
    $review = ManagementReviewFixture::open($scenario['cycle']);

    $result = ManagementReviewFixture::approve($review);

    $persisted = DB::table('sales_boards')->where('id', $result->salesBoard->id)->first();

    expect(IntegerMoney::cents($persisted->stock_value))->toBe(IntegerMoney::cents($baseline->stock_value))
        ->and(IntegerMoney::cents($persisted->financed_value))->toBe(IntegerMoney::cents($baseline->financed_value))
        ->and(IntegerMoney::cents($persisted->paid_value))->toBe(IntegerMoney::cents($baseline->settled_value))
        ->and(IntegerMoney::cents($persisted->exchanged_value))->toBe(IntegerMoney::cents($baseline->exchanged_value))
        ->and((int) $persisted->stock_units)->toBe((int) $baseline->stock_units)
        ->and((int) $persisted->financed_units)->toBe((int) $baseline->financed_units)
        ->and((int) $persisted->paid_units)->toBe((int) $baseline->settled_units)
        ->and((int) $persisted->exchanged_units)->toBe((int) $baseline->exchanged_units)
        ->and((int) $persisted->total_units)->toBe((int) $baseline->units_total)
        ->and(substr((string) $persisted->reference_month, 0, 10))
        ->toBe($scenario['cycle']->fresh()->reference_month->toDateString());
});

/**
 * A largura das colunas de enum é limite real no MySQL e ficção no SQLite: um
 * valor longo demais passa na suíte padrão e é truncado em produção. Foi assim
 * que `venda_conformidade_indeterminada` (32 caracteres) tentou entrar numa
 * `varchar(30)`.
 */
it('keeps every persisted enum value within its column width', function () {
    // Um laço por enum, para a falha dizer qual caso estourou.
    foreach (SalesBoardNonconformityOrigin::cases() as $case) {
        expect(strlen($case->value))->toBeLessThanOrEqual(30, "origin {$case->name}");
    }

    foreach (SalesBoardNonconformityDecision::cases() as $case) {
        expect(strlen($case->value))->toBeLessThanOrEqual(30, "decision {$case->name}");
    }

    foreach (SalesBoardManagementReviewStatus::cases() as $case) {
        expect(strlen($case->value))->toBeLessThanOrEqual(30, "status {$case->name}");
    }
});

/**
 * E a prova que só o banco real dá: cada origem realmente entra na coluna.
 */
it('persists every nonconformity origin without truncation', function () {
    $scenario = ManagementReviewFixture::submittedCycleWithUndeterminedSale();
    $review = ManagementReviewFixture::open($scenario['cycle']);

    $persisted = DB::table('sales_board_management_nonconformities')
        ->where('sales_board_management_review_id', $review->id)
        ->pluck('origin')
        ->all();

    expect($persisted)->toContain(SalesBoardNonconformityOrigin::SystemSaleUndetermined->value)
        ->and($persisted)->toContain(SalesBoardNonconformityOrigin::SystemSaleNonConform->value)
        // Recarregar pelo model prova que o valor gravado ainda resolve o enum:
        // um truncamento silencioso quebraria exatamente aqui.
        ->and($review->fresh()->nonconformities->pluck('origin')->pluck('value')->all())
        ->toEqualCanonicalizing($persisted);
});

it('round-trips the enums, fingerprints and decision text of the management trail', function () {
    $scenario = ManagementReviewFixture::submittedCycleWithNonConformSale();
    $review = ManagementReviewFixture::open($scenario['cycle']);
    $manager = User::factory()->create();

    ManagementReviewFixture::decide(
        $review->nonconformities->sole(),
        SalesBoardNonconformityDecision::AcceptedException,
        'Exceção autorizada pela diretoria comercial em ata.',
        $manager,
    );

    ManagementReviewFixture::approve($review, $manager);

    $persistedReview = DB::table('sales_board_management_reviews')->where('id', $review->id)->first();
    $persistedItem = DB::table('sales_board_management_nonconformities')
        ->where('sales_board_management_review_id', $review->id)
        ->first();

    expect($persistedReview->status)->toBe('aprovada')
        ->and(strlen((string) $persistedReview->snapshot_fingerprint))->toBe(64)
        ->and(strlen((string) $persistedReview->approved_source_fingerprint))->toBe(64)
        ->and((int) $persistedReview->source_changed)->toBe(0)
        ->and($persistedItem->origin)->toBe('venda_nao_conforme')
        ->and($persistedItem->decision)->toBe('excecao_aprovada')
        ->and($persistedItem->decision_reason)->toBe('Exceção autorizada pela diretoria comercial em ata.')
        ->and((int) $persistedItem->decided_by_user_id)->toBe($manager->id)
        ->and($persistedItem->sales_board_builder_divergence_id)->toBeNull();
});

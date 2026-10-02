<?php

use App\Enums\SalesBoardRectificationStatus;
use App\Models\SalesBoard;
use App\Models\SalesBoardCycleRectification;
use App\Models\SalesBoardPublication;
use App\Support\Money\IntegerMoney;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\SalesBoards\CycleFixture;
use Tests\Support\SalesBoards\ExtemporaneousFixture;

uses(RefreshDatabase::class);

/**
 * A cadeia de publicações e a retificação contra o banco: uniques, FKs e o
 * round-trip dos valores republicados. Roda também no MySQL (job parity), onde
 * as FKs dependem de índice e a coluna gerada da trava de retificação aberta é
 * de verdade.
 */
pest()->group('parity');

/**
 * @return array<string, array{columns: list<string>, on_delete: string}>
 */
function rectificationParityForeignKeys(string $table): array
{
    return collect(Schema::getForeignKeys($table))
        ->mapWithKeys(fn (array $foreignKey): array => [implode(',', $foreignKey['columns']) => [
            'columns' => $foreignKey['columns'],
            'on_delete' => strtolower((string) $foreignKey['on_delete']),
        ]])
        ->all();
}

it('indexes the publication chain and the rectifications as the contract says', function () {
    $publications = collect(Schema::getIndexes('sales_board_publications'))->keyBy('name');
    $rectifications = collect(Schema::getIndexes('sales_board_cycle_rectifications'))->keyBy('name');
    $publicationKeys = rectificationParityForeignKeys('sales_board_publications');
    $rectificationKeys = rectificationParityForeignKeys('sales_board_cycle_rectifications');

    expect(Arr::only($publications['sales_board_publications_cycle_sequence_unique'], ['columns', 'unique']))
        ->toBe(['columns' => ['sales_board_cycle_id', 'sequence_number'], 'unique' => true])
        ->and(Arr::only($publications['sales_board_publications_supersedes_unique'], ['columns', 'unique']))
        ->toBe(['columns' => ['supersedes_publication_id'], 'unique' => true])
        ->and(Arr::only($publications['sales_board_publications_board_index'], ['columns', 'unique']))
        ->toBe(['columns' => ['sales_board_id'], 'unique' => false])
        // As uniques antigas -- uma publicação por ciclo e por quadro -- saíram.
        ->and($publications->keys()->all())->not->toContain('sales_board_publications_cycle_unique')
        ->and($publications->keys()->all())->not->toContain('sales_board_publications_board_unique')
        ->and($publicationKeys['supersedes_publication_id']['on_delete'])->toBe('restrict')
        ->and($publicationKeys['sales_board_cycle_rectification_id']['on_delete'])->toBe('restrict')
        ->and(array_keys($publicationKeys))->toContain('sales_board_cycle_id', 'sales_board_id')
        ->and(Arr::only($rectifications['sb_rectifications_cycle_sequence_unique'], ['columns', 'unique']))
        ->toBe(['columns' => ['sales_board_cycle_id', 'sequence_number'], 'unique' => true])
        ->and(Arr::only($rectifications['sb_rectifications_open_cycle_unique'], ['columns', 'unique']))
        ->toBe(['columns' => ['open_cycle_lock'], 'unique' => true])
        ->and($rectificationKeys['sales_board_cycle_id']['on_delete'])->toBe('restrict')
        ->and($rectificationKeys['rectified_publication_id']['on_delete'])->toBe('restrict')
        ->and($rectificationKeys['opening_baseline_id']['on_delete'])->toBe('restrict')
        ->and($rectificationKeys['requested_by_user_id']['on_delete'])->toBe('set null')
        ->and($rectificationKeys['closed_by_user_id']['on_delete'])->toBe('set null')
        ->and(rectificationParityForeignKeys('sales_board_cycle_baselines')['previous_competence_baseline_id']['on_delete'])->toBe('restrict')
        ->and(Schema::hasColumn('sales_board_cycle_movements', 'timing'))->toBeTrue();
});

it('starts a publication at sequence one, takes the next one in the chain and refuses the same sequence twice', function () {
    $scenario = ExtemporaneousFixture::publishedJuly();
    $first = SalesBoardPublication::query()->findOrFail($scenario['publication']->publication->id);
    $row = Arr::except($first->getAttributes(), ['id']);
    $insert = fn (array $attributes): bool => DB::table('sales_board_publications')->insert([...$row, ...$attributes]);

    expect($first->sequence_number)->toBe(1)
        ->and($first->supersedes_publication_id)->toBeNull()
        ->and($first->sales_board_cycle_rectification_id)->toBeNull();

    $insert(['sequence_number' => 2, 'supersedes_publication_id' => $first->id]);

    expect(SalesBoardPublication::query()->where('sales_board_cycle_id', $first->sales_board_cycle_id)->count())->toBe(2)
        ->and(fn () => $insert(['sequence_number' => 2, 'supersedes_publication_id' => null]))
        ->toThrow(UniqueConstraintViolationException::class)
        // O model recusa antes do banco a publicação fora da cadeia.
        ->and(fn () => SalesBoardPublication::query()->create([...$row, 'sequence_number' => 4, 'supersedes_publication_id' => $first->id]))
        ->toThrow(LogicException::class, 'must supersede the previous publication of the same cycle and board');
});

it('allows a single open rectification per cycle in the database', function () {
    $scenario = ExtemporaneousFixture::rectifiableJuly();
    $open = ExtemporaneousFixture::rectify($scenario['july']);
    $row = Arr::except((array) DB::table('sales_board_cycle_rectifications')->where('id', $open->id)->first(), ['id', 'open_cycle_lock']);
    $insert = fn (array $attributes): bool => DB::table('sales_board_cycle_rectifications')->insert([...$row, ...$attributes]);

    expect(fn () => $insert(['sequence_number' => 2]))->toThrow(UniqueConstraintViolationException::class);

    // Encerrada, a trava sai junto, e a próxima cabe.
    DB::table('sales_board_cycle_rectifications')->where('id', $open->id)->update([
        'status' => SalesBoardRectificationStatus::Abandoned->value,
        'closed_at' => now(),
    ]);

    $insert(['sequence_number' => 2]);

    expect(SalesBoardCycleRectification::query()->where('sales_board_cycle_id', $scenario['july']->id)->count())->toBe(2)
        ->and(SalesBoardCycleRectification::query()->where('sales_board_cycle_id', $scenario['july']->id)->where('status', SalesBoardRectificationStatus::Open->value)->count())->toBe(1);
});

it('republishes the board values without losing a cent', function () {
    $scenario = ExtemporaneousFixture::publishedJuly();
    $scenario['financed']->forceFill(['sale_value' => '654321.99'])->save();

    ExtemporaneousFixture::rectify($scenario['july']);
    $result = ExtemporaneousFixture::publish($scenario['july']);

    $board = SalesBoard::query()->findOrFail($result->salesBoard->id);
    $baseline = CycleFixture::currentBaseline($scenario['july']);
    $publication = SalesBoardPublication::query()->findOrFail($result->publication->id);

    expect($publication->sales_board_cycle_baseline_id)->toBe($baseline->id)
        ->and(IntegerMoney::cents((string) $board->financed_value))->toBe(IntegerMoney::cents($baseline->financed_value))
        ->and(IntegerMoney::cents((string) $board->stock_value))->toBe(IntegerMoney::cents($baseline->stock_value))
        ->and((string) $board->getRawOriginal('financed_value'))->toStartWith('654321.99');
});

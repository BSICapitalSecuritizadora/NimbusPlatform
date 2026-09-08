<?php

use App\Enums\SalesBoardSource;
use App\Exceptions\SalesBoardRolloutException;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\SalesBoard;
use App\Models\SalesBoardHistory;
use App\Models\SalesBoardPublication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SalesBoards\ManagementReviewFixture;
use Tests\Support\SalesBoards\RolloutFixture;

uses(RefreshDatabase::class);

/**
 * @return array{emission: Emission, constructions: list<Construction>}
 */
function automatedEmission(int $constructions = 1): array
{
    $scenario = RolloutFixture::emission($constructions);

    foreach ($scenario['constructions'] as $construction) {
        RolloutFixture::legacyBoard($construction);
    }

    $homologation = RolloutFixture::approvedHomologation($scenario['emission']);
    RolloutFixture::activate($scenario['emission'], $homologation);

    return $scenario;
}

it('keeps manual writes working while the emission is legacy', function () {
    $scenario = RolloutFixture::emission(1);
    $construction = $scenario['constructions'][0];

    $board = RolloutFixture::legacyBoard($construction, '2026-08-01');

    expect($board->exists)->toBeTrue()
        ->and(SalesBoardHistory::query()->where('sales_board_id', $board->id)->count())->toBe(1);

    // E a atualização legada continua registrando versão, como sempre.
    $board->changeReason = 'Correção acordada com a construtora.';
    $board->update(['stock_units' => 1]);

    expect(SalesBoardHistory::query()->where('sales_board_id', $board->id)->count())->toBe(2);
});

it('blocks a manual board on a competence the automation already owns', function () {
    $scenario = automatedEmission();
    $construction = $scenario['constructions'][0];

    expect(fn () => SalesBoard::factory()->create([
        'emission_id' => $scenario['emission']->id,
        'construction_id' => $construction->id,
        'reference_month' => '2026-08-01',
    ]))->toThrow(SalesBoardRolloutException::class, 'produzido pelo ciclo mensal');

    expect(SalesBoard::query()->where('construction_id', $construction->id)
        ->whereDate('reference_month', '2026-08-01')->count())->toBe(0);
});

it('blocks a manual board after the start month too', function () {
    $scenario = automatedEmission();

    expect(fn () => SalesBoard::factory()->create([
        'emission_id' => $scenario['emission']->id,
        'construction_id' => $scenario['constructions'][0]->id,
        'reference_month' => '2026-12-01',
    ]))->toThrow(SalesBoardRolloutException::class, 'produzido pelo ciclo mensal');
});

it('still allows maintaining history before the start month', function () {
    $scenario = automatedEmission();
    $construction = $scenario['constructions'][0];

    // 06/2026 é anterior ao corte: manutenção do passado continua possível.
    $board = SalesBoard::factory()->create([
        'emission_id' => $scenario['emission']->id,
        'construction_id' => $construction->id,
        'reference_month' => '2026-06-01',
        'stock_units' => 2, 'financed_units' => 0, 'paid_units' => 0, 'exchanged_units' => 0,
        'stock_value' => '1000000.00', 'financed_value' => '0.00',
        'paid_value' => '0.00', 'exchanged_value' => '0.00',
    ]);

    expect($board->exists)->toBeTrue()
        ->and($board->reference_month->format('Y-m'))->toBe('2026-06');
});

it('lets the publication service write in automated mode', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $emission = $scenario['construction']->emission;

    // A Emissão do cenário passa a automatizada a partir de 07/2026 -- a mesma
    // competência do ciclo, ou seja, a competência que a publicação vai gravar.
    $emission->forceFill([
        'sales_board_source' => SalesBoardSource::Automated,
        'sales_board_automation_start_reference_month' => '2026-07-01',
    ])->save();

    $review = ManagementReviewFixture::open($scenario['cycle']);
    $result = ManagementReviewFixture::approve($review, User::factory()->create());

    $board = $result->salesBoard;

    expect($board->exists)->toBeTrue()
        ->and($board->reference_month->format('Y-m'))->toBe('2026-07')
        ->and(SalesBoardPublication::query()->count())->toBe(1)
        // O observer não foi silenciado: a versão inicial do histórico existe.
        ->and(SalesBoardHistory::query()->where('sales_board_id', $board->id)->count())->toBe(1);
});

it('never lets a published board be edited', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $review = ManagementReviewFixture::open($scenario['cycle']);
    $board = ManagementReviewFixture::approve($review)->salesBoard;

    expect(fn () => $board->update(['stock_units' => 99]))
        ->toThrow(SalesBoardRolloutException::class, 'não pode ser alterado nem removido');

    expect($board->fresh()->stock_units)->not->toBe(99);
});

it('never lets a published board be deleted', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $review = ManagementReviewFixture::open($scenario['cycle']);
    $board = ManagementReviewFixture::approve($review)->salesBoard;

    expect(fn () => $board->delete())
        ->toThrow(SalesBoardRolloutException::class, 'não pode ser alterado nem removido');

    expect($board->fresh()->exists)->toBeTrue();
});

it('leaves an unpublished legacy board editable and deletable', function () {
    $scenario = RolloutFixture::emission(1);
    $board = RolloutFixture::legacyBoard($scenario['constructions'][0], '2026-08-01');

    $board->changeReason = 'Ajuste combinado com a operação.';
    $board->update(['stock_units' => 3]);

    expect($board->fresh()->stock_units)->toBe(3);

    $board->delete();

    expect(SalesBoard::query()->whereKey($board->id)->exists())->toBeFalse();
});

it('reopens manual writes after the emission returns to legacy', function () {
    $scenario = automatedEmission();
    $construction = $scenario['constructions'][0];

    expect(fn () => SalesBoard::factory()->create([
        'emission_id' => $scenario['emission']->id,
        'construction_id' => $construction->id,
        'reference_month' => '2026-08-01',
    ]))->toThrow(SalesBoardRolloutException::class);

    RolloutFixture::returnToLegacy($scenario['emission']);

    $board = SalesBoard::factory()->create([
        'emission_id' => $scenario['emission']->id,
        'construction_id' => $construction->id,
        'reference_month' => '2026-08-01',
        'stock_units' => 2, 'financed_units' => 0, 'paid_units' => 0, 'exchanged_units' => 0,
        'stock_value' => '1000000.00', 'financed_value' => '0.00',
        'paid_value' => '0.00', 'exchanged_value' => '0.00',
    ]);

    expect($board->exists)->toBeTrue();
});

it('leaves another emission untouched by the guard', function () {
    $automated = automatedEmission();
    $legacy = RolloutFixture::emission(1, 'B');

    $board = RolloutFixture::legacyBoard($legacy['constructions'][0], '2026-08-01');

    expect($board->exists)->toBeTrue()
        ->and($legacy['emission']->fresh()->usesAutomatedSalesBoard())->toBeFalse();
});

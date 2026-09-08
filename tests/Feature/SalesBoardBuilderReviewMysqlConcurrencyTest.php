<?php

use App\DTOs\SalesBoards\BuilderReviewerIdentity;
use App\Enums\SalesBoardBuilderReviewSection as SectionEnum;
use App\Enums\SalesBoardBuilderReviewStatus;
use App\Enums\SalesBoardCycleStatus;
use App\Exceptions\SalesBoardBuilderReviewException;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\Contract;
use App\Models\ContractInstallment;
use App\Models\Emission;
use App\Models\SalesBoardBuilderReview;
use App\Models\SalesBoardBuilderReviewSection;
use App\Models\SalesBoardCycle;
use App\Models\SalesDiscountPolicy;
use App\Models\User;
use App\Services\SalesBoards\SalesBoardBuilderReviewEditor;
use App\Services\SalesBoards\SalesBoardBuilderReviewOpeningService;
use App\Services\SalesBoards\SalesBoardBuilderReviewSubmissionService;
use App\Services\SalesBoards\SalesBoardGenerationService;
use App\Services\SalesBoards\SalesBoardRecalculationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;

/**
 * As corridas da validação, em conexões reais.
 *
 * Duas pessoas abrindo a mesma revisão, dois envios simultâneos e uma nova
 * versão da posição nascendo no meio de um envio. Nenhuma delas o SQLite
 * consegue mostrar, porque ele serializa escritores.
 */
beforeEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requer MySQL para observar duas conexões disputando a mesma revisão.');
    }

    Artisan::call('migrate:fresh', ['--no-interaction' => true]);
});

/**
 * Os processos filhos commitam fora de qualquer transação de teste. Sem esta
 * limpeza, o arquivo seguinte da suíte herdaria ciclos e revisões alheios.
 */
afterEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        return;
    }

    DB::table('sales_board_builder_divergences')->delete();
    DB::table('sales_board_builder_review_sections')->delete();
    DB::table('sales_board_builder_reviews')->delete();
    DB::table('sales_board_cycles')->update(['current_baseline_id' => null]);
    DB::table('sales_board_cycle_movements')->delete();
    DB::table('sales_board_cycle_lines')->delete();
    DB::table('sales_board_cycle_baselines')->delete();
    DB::table('sales_board_cycles')->delete();
});

/**
 * @return array{cycle: SalesBoardCycle, actor: User, contract: Contract}
 */
function builderReviewRaceScenario(): array
{
    $emission = Emission::factory()->create(['status' => 'active']);
    $construction = Construction::factory()->create(['emission_id' => $emission->id]);

    SalesDiscountPolicy::factory()->forConstruction($construction)->effectiveFrom('2020-01-01')->allowing('20.00')->create();

    $units = collect(range(1, 3))->map(fn (int $n): ConstructionUnit => ConstructionUnit::factory()->create([
        'construction_id' => $construction->id,
        'block' => 'A',
        'unit' => (string) (100 + $n),
        'base_value' => '500000.00',
        'base_value_reference_date' => '2026-01-01',
    ]));

    $contract = Contract::factory()->create([
        'construction_unit_id' => $units[0]->id,
        'sale_date' => '2026-03-10',
        'sale_value' => '600000.00',
    ]);

    ContractInstallment::factory()->create([
        'contract_id' => $contract->id,
        'number' => '001',
        'due_date' => '2026-10-10',
        'expected_value' => '600000.00',
    ]);

    app(SalesBoardGenerationService::class)->generateForConstruction($construction, CarbonImmutable::parse('2026-07-01'));

    return [
        'cycle' => SalesBoardCycle::query()->sole(),
        'actor' => User::factory()->create(),
        'contract' => $contract,
    ];
}

/**
 * @param  array{action: string, cycle_id?: int, review_id?: int, actor_id: int, lock_marker?: string, wait_for_marker?: string, hold_after_lock_ms?: int}  $instruction
 */
function builderReviewTask(array $instruction): Closure
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
                'open' => (string) app(SalesBoardBuilderReviewOpeningService::class)
                    ->open(SalesBoardCycle::query()->findOrFail($instruction['cycle_id']), $actor)
                    ->getKey(),
                'submit' => app(SalesBoardBuilderReviewSubmissionService::class)
                    ->submit(
                        SalesBoardBuilderReview::query()->findOrFail($instruction['review_id']),
                        BuilderReviewerIdentity::forInternalUser($actor),
                    )->status->value,
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

it('never opens two reviews for the same cycle', function () {
    $scenario = builderReviewRaceScenario();

    $results = Concurrency::driver('process')->run([
        builderReviewTask(['action' => 'open', 'cycle_id' => $scenario['cycle']->id, 'actor_id' => $scenario['actor']->id]),
        builderReviewTask(['action' => 'open', 'cycle_id' => $scenario['cycle']->id, 'actor_id' => $scenario['actor']->id]),
    ]);

    expect(collect($results)->where('success', true))->toHaveCount(2)
        ->and(collect($results)->pluck('outcome')->unique())->toHaveCount(1)
        ->and(SalesBoardBuilderReview::query()->count())->toBe(1)
        ->and(SalesBoardBuilderReviewSection::query()->count())->toBe(7)
        ->and($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::BuilderReview);
})->group('mysql');

it('never submits the same review twice', function () {
    $scenario = builderReviewRaceScenario();

    $review = app(SalesBoardBuilderReviewOpeningService::class)->open($scenario['cycle'], $scenario['actor']);
    $editor = app(SalesBoardBuilderReviewEditor::class);

    foreach (SectionEnum::ordered() as $section) {
        $editor->confirmSection($review->sections()->where('section', $section)->sole());
    }

    $marker = temporaryTestFilePath('builder-review-submit-lock', 'lock');
    @unlink($marker);

    $results = Concurrency::driver('process')->run([
        builderReviewTask([
            'action' => 'submit',
            'review_id' => $review->id,
            'actor_id' => $scenario['actor']->id,
            'lock_marker' => $marker,
            'hold_after_lock_ms' => 400,
        ]),
        builderReviewTask([
            'action' => 'submit',
            'review_id' => $review->id,
            'actor_id' => $scenario['actor']->id,
            'wait_for_marker' => $marker,
        ]),
    ]);

    @unlink($marker);

    // Um envia; o outro encontra a revisão já enviada e recusa como domínio.
    expect(collect($results)->where('success', true))->toHaveCount(1)
        ->and(collect($results)->where('success', false)->pluck('exception')->all())
        ->toBe([SalesBoardBuilderReviewException::class])
        ->and($review->fresh()->status)->toBe(SalesBoardBuilderReviewStatus::Submitted)
        ->and($review->fresh()->submitted_at)->not->toBeNull()
        ->and($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::ManagementReview);
})->group('mysql');

it('never lets a review cross into management while a new version is being created', function () {
    $scenario = builderReviewRaceScenario();

    $review = app(SalesBoardBuilderReviewOpeningService::class)->open($scenario['cycle'], $scenario['actor']);
    $editor = app(SalesBoardBuilderReviewEditor::class);

    foreach (SectionEnum::ordered() as $section) {
        $editor->confirmSection($review->sections()->where('section', $section)->sole());
    }

    // A fonte muda: o recálculo concorrente vai produzir uma V2 material.
    $scenario['contract']->update(['sale_value' => '610000.00']);

    $marker = temporaryTestFilePath('builder-review-recalc-lock', 'lock');
    @unlink($marker);

    $results = Concurrency::driver('process')->run([
        builderReviewTask([
            'action' => 'recalculate',
            'cycle_id' => $scenario['cycle']->id,
            'actor_id' => $scenario['actor']->id,
            'lock_marker' => $marker,
            'hold_after_lock_ms' => 400,
        ]),
        builderReviewTask([
            'action' => 'submit',
            'review_id' => $review->id,
            'actor_id' => $scenario['actor']->id,
            'wait_for_marker' => $marker,
        ]),
    ]);

    @unlink($marker);

    $cycle = $scenario['cycle']->fresh();

    // O recálculo passa; o envio da versão anterior não atravessa.
    expect(collect($results)->firstWhere('outcome', 'recalculado'))->not->toBeNull()
        ->and($review->fresh()->status)->not->toBe(SalesBoardBuilderReviewStatus::Submitted)
        ->and($cycle->status)->toBe(SalesBoardCycleStatus::BuilderReview);
})->group('mysql');

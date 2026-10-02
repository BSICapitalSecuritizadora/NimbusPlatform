<?php

use App\Exceptions\ConstructionUnitRetirementException;
use App\Exceptions\SalesBoardManagementReviewException;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\ConstructionUnitRetirement;
use App\Models\Emission;
use App\Models\SalesBoard;
use App\Models\SalesBoardManagementReview;
use App\Models\SalesBoardPublication;
use App\Models\User;
use App\Services\SalesBoards\ConstructionUnitRetirementService;
use App\Services\SalesBoards\SalesBoardManagementApprovalService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Tests\Support\CommittedRowsSweeper;
use Tests\Support\SalesBoards\CycleFixture;
use Tests\Support\SalesBoards\DerivationFixture;
use Tests\Support\SalesBoards\ExtemporaneousFixture;
use Tests\Support\SalesBoards\GovernanceFixture;
use Tests\Support\SalesBoards\ManagementReviewFixture;

/**
 * As corridas da baixa de unidade, em conexões reais.
 *
 * A baixa trava os ciclos em aberto da obra antes da unidade -- a ordem de locks
 * do Quadro, do mês mais recente para o mais antigo -- e lê a última competência
 * publicada com `sharedLock()`. É o que impede uma baixa commitada no meio da
 * aprovação de deixar a data dentro de uma competência publicada. O SQLite
 * serializa escritores e não mostra nada disso.
 */
beforeEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requer MySQL para observar duas conexões disputando o mesmo ciclo e a mesma unidade.');
    }

    Artisan::call('migrate:fresh', ['--no-interaction' => true]);

    $this->committedRows = CommittedRowsSweeper::afterFreshMigration();
});

/**
 * O cenário e os processos filhos commitam fora de qualquer transação de teste:
 * a limpeza devolve o banco ao estado recém-migrado, e a verificação garante que
 * o arquivo seguinte da suíte não herda nada daqui.
 */
afterEach(function () {
    if (DB::getDriverName() !== 'mysql' || ! isset($this->committedRows)) {
        return;
    }

    $this->committedRows->sweep();

    expect($this->committedRows->leftovers())->toBe([]);
});

/**
 * @param  array{action: string, review_id?: int, unit_id?: int, retired_on?: string, actor_id: int, lock_marker?: string, wait_for_marker?: string, hold_after_lock_ms?: int}  $instruction
 */
function retirementConcurrencyTask(array $instruction): Closure
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
                'approve' => app(SalesBoardManagementApprovalService::class)
                    ->approve(SalesBoardManagementReview::query()->findOrFail($instruction['review_id']), $actor, true)
                    ->outcome->value,
                'retire' => (string) app(ConstructionUnitRetirementService::class)
                    ->retire(
                        ConstructionUnit::query()->findOrFail($instruction['unit_id']),
                        $actor,
                        CarbonImmutable::parse((string) $instruction['retired_on']),
                        'Unidade cadastrada em duplicidade na carga inicial.',
                    )
                    ->getKey(),
            };

            return ['success' => true, 'outcome' => $outcome, 'exception' => null, 'message' => null];
        } catch (Throwable $exception) {
            return ['success' => false, 'outcome' => null, 'exception' => $exception::class, 'message' => $exception->getMessage()];
        }
    };
}

it('never retires a unit inside the competence a concurrent approval publishes', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $approver = GovernanceFixture::approver();
    $review = ManagementReviewFixture::open($scenario['cycle'], $approver);
    $unit = $scenario['units']['stock'];
    $stockUnitsFrozen = (int) CycleFixture::currentBaseline($scenario['cycle'])->stock_units;

    $marker = temporaryTestFilePath('retirement-approval-lock', 'lock');
    @unlink($marker);

    $results = Concurrency::driver('process')->run([
        retirementConcurrencyTask([
            'action' => 'approve',
            'review_id' => $review->id,
            'actor_id' => $approver->id,
            'lock_marker' => $marker,
            'hold_after_lock_ms' => 600,
        ]),
        retirementConcurrencyTask([
            'action' => 'retire',
            'unit_id' => $unit->id,
            'retired_on' => '2026-07-25',
            'actor_id' => GovernanceFixture::approver()->id,
            'wait_for_marker' => $marker,
        ]),
    ]);

    @unlink($marker);

    [$approval, $retirement] = $results;

    $publication = SalesBoardPublication::query()->where('sales_board_cycle_id', $scenario['cycle']->id)->first();

    // A baixa esperou o lock do ciclo e, com a aprovação commitada, viu a
    // competência publicada: é recusada, e o quadro publicado conta a unidade.
    expect($approval['success'])->toBeTrue()
        ->and($approval['outcome'])->toBe('aprovado')
        ->and($retirement['success'])->toBeFalse()
        ->and($retirement['exception'])->toBe(ConstructionUnitRetirementException::class)
        ->and($retirement['message'])->toContain('posterior a 31/07/2026')
        ->and(ConstructionUnitRetirement::query()->where('construction_unit_id', $unit->id)->exists())->toBeFalse()
        ->and($publication)->not->toBeNull()
        ->and((int) SalesBoard::query()->findOrFail($publication->sales_board_id)->stock_units)->toBe($stockUnitsFrozen);
})->group('mysql');

it('ends two simultaneous retirements of the same unit with exactly one open retirement', function () {
    $construction = Construction::factory()->create([
        'emission_id' => Emission::factory()->create(['status' => 'active'])->id,
    ]);
    $unit = DerivationFixture::unit($construction, '101');
    $first = GovernanceFixture::approver();
    $second = GovernanceFixture::approver();

    $results = Concurrency::driver('process')->run([
        retirementConcurrencyTask(['action' => 'retire', 'unit_id' => $unit->id, 'retired_on' => '2026-08-10', 'actor_id' => $first->id]),
        retirementConcurrencyTask(['action' => 'retire', 'unit_id' => $unit->id, 'retired_on' => '2026-08-12', 'actor_id' => $second->id]),
    ]);

    $winners = collect($results)->where('success', true);
    $losers = collect($results)->where('success', false);

    expect($winners)->toHaveCount(1)
        ->and($losers)->toHaveCount(1)
        ->and([ConstructionUnitRetirementException::class, UniqueConstraintViolationException::class])->toContain($losers->first()['exception'])
        ->and(ConstructionUnitRetirement::query()->where('construction_unit_id', $unit->id)->whereNull('reactivated_on')->count())->toBe(1)
        ->and((string) ConstructionUnitRetirement::query()->where('construction_unit_id', $unit->id)->value('id'))->toBe($winners->first()['outcome']);
})->group('mysql');

/**
 * A regra única de lock do Quadro: os ciclos de um empreendimento são travados
 * do mês mais recente para o mais antigo. A aprovação de agosto trava agosto e
 * lê julho em modo compartilhado; a baixa datada em julho trava os ciclos
 * abertos de agosto para trás e só depois a unidade. Com as duas ordens
 * opostas, a disputa terminava em deadlock 1213; na mesma ordem, uma espera a
 * outra, e a regra de ordem da aprovação recusa agosto com julho em aberto.
 */
it('ends a retirement dated in the previous month and the approval of the next one without deadlock', function () {
    [$construction, $units] = CycleFixture::readyConstruction(3);
    CycleFixture::generate($construction, '2026-07-01');
    $august = ExtemporaneousFixture::generateAugust($construction);
    $review = ExtemporaneousFixture::analysis($august);
    $approver = GovernanceFixture::approver();
    $unit = $units[2];

    $marker = temporaryTestFilePath('retirement-previous-month-approval', 'lock');
    @unlink($marker);

    $results = Concurrency::driver('process')->run([
        retirementConcurrencyTask([
            'action' => 'approve',
            'review_id' => $review->id,
            'actor_id' => $approver->id,
            'lock_marker' => $marker,
            'hold_after_lock_ms' => 600,
        ]),
        retirementConcurrencyTask([
            'action' => 'retire',
            'unit_id' => $unit->id,
            'retired_on' => '2026-07-25',
            'actor_id' => GovernanceFixture::approver()->id,
            'wait_for_marker' => $marker,
        ]),
    ]);

    @unlink($marker);

    [$approval, $retirement] = $results;

    expect($approval['success'])->toBeFalse()
        ->and($approval['exception'])->toBe(SalesBoardManagementReviewException::class)
        ->and($approval['message'])->toContain('A competência anterior (07/2026) ainda não foi aprovada nem cancelada')
        ->and($retirement['success'])->toBeTrue()
        ->and(SalesBoardPublication::query()->where('sales_board_cycle_id', $august->id)->exists())->toBeFalse()
        ->and(ConstructionUnitRetirement::query()->where('construction_unit_id', $unit->id)->whereNull('reactivated_on')->count())->toBe(1);
})->group('mysql');

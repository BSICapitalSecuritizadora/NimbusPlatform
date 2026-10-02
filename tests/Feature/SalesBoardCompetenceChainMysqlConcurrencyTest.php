<?php

use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardMovementTiming;
use App\Enums\SalesBoardMovementType;
use App\Enums\SalesBoardRectificationStatus;
use App\Exceptions\SalesBoardCycleReopeningException;
use App\Exceptions\SalesBoardManagementReviewException;
use App\Exceptions\SalesBoardRectificationException;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleMovement;
use App\Models\SalesBoardCycleRectification;
use App\Models\SalesBoardManagementReview;
use App\Models\SalesBoardPublication;
use App\Models\User;
use App\Services\SalesBoards\SalesBoardCycleCancellationService;
use App\Services\SalesBoards\SalesBoardCycleRectificationService;
use App\Services\SalesBoards\SalesBoardCycleReopeningService;
use App\Services\SalesBoards\SalesBoardManagementApprovalService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Tests\Support\CommittedRowsSweeper;
use Tests\Support\SalesBoards\CycleFixture;
use Tests\Support\SalesBoards\ExtemporaneousFixture;
use Tests\Support\SalesBoards\GovernanceFixture;
use Tests\Support\SalesBoards\ManagementReviewFixture;

/**
 * A cadeia de competências em conexões reais: aprovar uma competência contra
 * retificar, reabrir ou cancelar uma competência da cadeia de onde ela parte.
 *
 * A aprovação de M trava M e lê a cadeia até a âncora com lock compartilhado,
 * do mês mais recente para o mais antigo; retificar, cancelar e reabrir travam
 * o próprio ciclo com FOR UPDATE, e a reabertura trava antes os ciclos
 * posteriores em modo compartilhado, pela chave primária. Quem pega o lock
 * primeiro termina; o outro espera e decide pelo que encontrou. O que nunca
 * acontece é a mesma venda em duas publicações, uma competência publicada sobre
 * uma âncora que mudou enquanto ela esperava, ou uma competência reaberta
 * depois de uma posterior publicada. O SQLite serializa escritores e não mostra
 * nada disso.
 */
beforeEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requer MySQL para observar duas conexões disputando a cadeia de competências.');
    }

    Artisan::call('migrate:fresh', ['--no-interaction' => true]);

    $this->committedRows = CommittedRowsSweeper::afterFreshMigration();
});

/**
 * O cenário e os processos filhos commitam fora de qualquer transação de teste.
 * A limpeza devolve o banco ao estado recém-migrado, e a verificação garante que
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
 * Um ato sobre a cadeia num processo filho.
 *
 * Com `hold_on_table`, o processo grava o marcador e segura a transação logo
 * depois da primeira consulta **dentro da transação** que toca a tabela -- com
 * os locks que tiver adquirido até ali; com `hold_on_sql`, logo depois da
 * primeira cujo SQL contém o trecho (um `insert into` específico, por
 * exemplo). Com `wait_for_marker`, o processo só começa depois disso.
 *
 * @param  array{action: string, review_id?: int, cycle_id?: int, actor_id: int, hold_on_table?: string, hold_on_sql?: string, marker?: string, hold_ms?: int, wait_for_marker?: string}  $instruction
 */
function competenceChainTask(array $instruction): Closure
{
    return static function () use ($instruction): array {
        if (isset($instruction['hold_on_table']) || isset($instruction['hold_on_sql'])) {
            $held = false;
            $fragment = isset($instruction['hold_on_sql'])
                ? strtolower($instruction['hold_on_sql'])
                : '`'.$instruction['hold_on_table'].'`';

            DB::listen(static function (QueryExecuted $query) use ($instruction, $fragment, &$held): void {
                if ($held
                    || ($query->connection->transactionLevel() === 0)
                    || ! str_contains(strtolower($query->sql), $fragment)) {
                    return;
                }

                $held = true;
                file_put_contents($instruction['marker'], 'held');
                usleep(((int) ($instruction['hold_ms'] ?? 0)) * 1000);
            });
        }

        try {
            if (isset($instruction['wait_for_marker'])) {
                $deadline = microtime(true) + 15;

                while (! is_file($instruction['wait_for_marker']) && microtime(true) < $deadline) {
                    usleep(10_000);
                }

                if (! is_file($instruction['wait_for_marker'])) {
                    throw new RuntimeException('O processo concorrente não sinalizou o ponto de espera.');
                }
            }

            $actor = User::query()->findOrFail($instruction['actor_id']);

            $outcome = match ($instruction['action']) {
                'approve' => app(SalesBoardManagementApprovalService::class)
                    ->approve(SalesBoardManagementReview::query()->findOrFail($instruction['review_id']), $actor, true)
                    ->outcome->value,
                'rectify' => app(SalesBoardCycleRectificationService::class)
                    ->open(SalesBoardCycle::query()->findOrFail($instruction['cycle_id']), $actor, 'Venda da unidade lançada com valor errado.')
                    ->status->value,
                'reopen' => app(SalesBoardCycleReopeningService::class)
                    ->reopen(SalesBoardCycle::query()->findOrFail($instruction['cycle_id']), $actor, 'O cancelamento foi um engano: a fonte já estava correta.')
                    ->status->value,
                'cancel' => app(SalesBoardCycleCancellationService::class)
                    ->cancel(SalesBoardCycle::query()->findOrFail($instruction['cycle_id']), $actor, 'Competência encerrada sem posição no piloto.')
                    ->status->value,
            };

            return ['success' => true, 'outcome' => $outcome, 'exception' => null, 'message' => null];
        } catch (Throwable $exception) {
            return ['success' => false, 'outcome' => null, 'exception' => $exception::class, 'message' => $exception->getMessage()];
        }
    };
}

/**
 * As duas operações, uma segurando a transação e a outra esperando o marcador.
 *
 * @param  array<string, mixed>  $holding
 * @param  array<string, mixed>  $waiting
 * @return array{0: array{success: bool, outcome: string|null, exception: string|null, message: string|null}, 1: array{success: bool, outcome: string|null, exception: string|null, message: string|null}}
 */
function competenceChainRace(string $name, array $holding, array $waiting): array
{
    $marker = temporaryTestFilePath($name, 'lock');
    @unlink($marker);

    $results = Concurrency::driver('process')->run([
        competenceChainTask([...$holding, 'marker' => $marker, 'hold_ms' => 1500]),
        competenceChainTask([...$waiting, 'wait_for_marker' => $marker]),
    ]);

    @unlink($marker);

    return $results;
}

/**
 * Julho publicado e corrigido na fonte depois; agosto em análise, pronto para
 * aprovar, e julho retificável.
 *
 * @return array{july: SalesBoardCycle, august: SalesBoardCycle, review: SalesBoardManagementReview}
 */
function rectifyRaceScenario(): array
{
    $scenario = ExtemporaneousFixture::rectifiableJuly();
    $august = ExtemporaneousFixture::generateAugust($scenario['construction']);
    $review = ExtemporaneousFixture::analysis($august);
    ManagementReviewFixture::decideAll($review);

    return ['july' => $scenario['july'], 'august' => $august, 'review' => $review->fresh()];
}

/**
 * Junho publicado; julho cancelado com uma venda; agosto absorvendo julho, em
 * análise e pronto para aprovar.
 *
 * @return array{july: SalesBoardCycle, august: SalesBoardCycle, review: SalesBoardManagementReview}
 */
function reopenAdjacentRaceScenario(): array
{
    [$construction, $units] = CycleFixture::readyConstruction(3);
    ExtemporaneousFixture::publish(CycleFixture::generate($construction, '2026-06-01')->cycle);

    ExtemporaneousFixture::sale($units[0], '2026-07-12');

    $july = CycleFixture::generate($construction, '2026-07-01')->cycle;
    app(SalesBoardCycleCancellationService::class)->cancel($july->fresh(), GovernanceFixture::approver(), 'Competência encerrada sem posição no piloto.');

    $august = ExtemporaneousFixture::generateAugust($construction);
    $review = ExtemporaneousFixture::analysis($august);
    ManagementReviewFixture::decideAll($review);

    return ['july' => $july, 'august' => $august, 'review' => $review->fresh()];
}

/**
 * Junho publicado; a venda de 15/07; julho e agosto cancelados; setembro, que
 * absorve julho e agosto, em análise e pronto para aprovar.
 *
 * @return array{july: SalesBoardCycle, september: SalesBoardCycle, review: SalesBoardManagementReview, saleId: int}
 */
function reopenSecondNextRaceScenario(): array
{
    [$construction, $units] = CycleFixture::readyConstruction(3);
    ExtemporaneousFixture::publish(CycleFixture::generate($construction, '2026-06-01')->cycle);

    $sale = ExtemporaneousFixture::sale($units[1], '2026-07-15');

    $july = CycleFixture::generate($construction, '2026-07-01')->cycle;
    app(SalesBoardCycleCancellationService::class)->cancel($july->fresh(), GovernanceFixture::approver(), 'Competência encerrada sem posição no piloto.');
    $august = CycleFixture::generate($construction, '2026-08-01')->cycle;
    app(SalesBoardCycleCancellationService::class)->cancel($august->fresh(), GovernanceFixture::approver(), 'Competência encerrada sem posição no piloto.');

    $september = CycleFixture::generate($construction, '2026-09-01')->cycle;
    $review = ExtemporaneousFixture::analysis($september);
    ManagementReviewFixture::decideAll($review);

    return ['july' => $july, 'september' => $september, 'review' => $review->fresh(), 'saleId' => $sale->id];
}

/**
 * Junho publicado; julho cancelado; agosto sem ciclo; setembro em análise,
 * pronto para aprovar. Setembro não tem âncora nem absorve julho -- a cadeia
 * dele para em agosto --, mas a reabertura de julho continua recusada depois
 * de setembro publicado.
 *
 * @return array{july: SalesBoardCycle, september: SalesBoardCycle, review: SalesBoardManagementReview}
 */
function reopenAcrossMonthWithoutCycleRaceScenario(): array
{
    [$construction] = CycleFixture::readyConstruction(3);
    ExtemporaneousFixture::publish(CycleFixture::generate($construction, '2026-06-01')->cycle);

    $july = CycleFixture::generate($construction, '2026-07-01')->cycle;
    app(SalesBoardCycleCancellationService::class)->cancel($july->fresh(), GovernanceFixture::approver(), 'Competência encerrada sem posição no piloto.');

    $september = CycleFixture::generate($construction, '2026-09-01')->cycle;
    $review = ExtemporaneousFixture::analysis($september);
    ManagementReviewFixture::decideAll($review);

    return ['july' => $july, 'september' => $september, 'review' => $review->fresh()];
}

/**
 * As publicações vigentes em que a venda é movimento, como `aaaa-mm:timing`.
 *
 * @return list<string>
 */
function competenceChainSaleOwners(int $contractId): array
{
    return SalesBoardPublication::query()
        ->whereDoesntHave('supersededBy')
        ->with('cycle')
        ->get()
        ->flatMap(fn (SalesBoardPublication $publication): array => SalesBoardCycleMovement::query()
            ->where('sales_board_cycle_baseline_id', $publication->sales_board_cycle_baseline_id)
            ->where('contract_id', $contractId)
            ->where('movement_type', SalesBoardMovementType::Sale->value)
            ->get()
            ->map(fn (SalesBoardCycleMovement $movement): string => $publication->cycle->reference_month->format('Y-m').':'.($movement->timing?->value ?? 'mes'))
            ->all())
        ->sort()
        ->values()
        ->all();
}

it('refuses the rectification of July that waited for the approval of August', function () {
    $scenario = rectifyRaceScenario();

    // A aprovação segura a transação na conferência da retificação de julho,
    // com agosto travado e julho lido em modo compartilhado.
    [$approval, $rectification] = competenceChainRace('rectify-after-approval', [
        'action' => 'approve',
        'review_id' => $scenario['review']->id,
        'actor_id' => GovernanceFixture::approver()->id,
        'hold_on_table' => 'sales_board_cycle_rectifications',
    ], [
        'action' => 'rectify',
        'cycle_id' => $scenario['july']->id,
        'actor_id' => GovernanceFixture::approver()->id,
    ]);

    expect($approval)->toMatchArray(['success' => true, 'outcome' => 'aprovado'])
        ->and($rectification['exception'])->toBe(SalesBoardRectificationException::class)
        ->and(SalesBoardCycleRectification::query()->where('sales_board_cycle_id', $scenario['july']->id)->exists())->toBeFalse()
        ->and($scenario['july']->fresh()->status)->toBe(SalesBoardCycleStatus::Approved)
        ->and(SalesBoardPublication::query()->where('sales_board_cycle_id', $scenario['august']->id)->count())->toBe(1);
})->group('mysql');

it('refuses the approval of August that waited for the rectification of July', function () {
    $scenario = rectifyRaceScenario();

    // A retificação segura a transação logo depois de travar julho.
    [$rectification, $approval] = competenceChainRace('approval-after-rectify', [
        'action' => 'rectify',
        'cycle_id' => $scenario['july']->id,
        'actor_id' => GovernanceFixture::approver()->id,
        'hold_on_table' => 'sales_board_cycles',
    ], [
        'action' => 'approve',
        'review_id' => $scenario['review']->id,
        'actor_id' => GovernanceFixture::approver()->id,
    ]);

    expect($rectification)->toMatchArray(['success' => true, 'outcome' => SalesBoardRectificationStatus::Open->value])
        ->and($approval['exception'])->toBe(SalesBoardManagementReviewException::class)
        ->and($approval['message'])->toContain('A competência anterior (07/2026) está em retificação')
        ->and(SalesBoardPublication::query()->where('sales_board_cycle_id', $scenario['august']->id)->exists())->toBeFalse();
})->group('mysql');

it('refuses the reopening of July that waited for the approval of August', function () {
    $scenario = reopenAdjacentRaceScenario();

    // A aprovação segura a transação na conferência da retificação da âncora
    // (junho), com agosto travado e julho e junho lidos em modo compartilhado.
    [$approval, $reopening] = competenceChainRace('reopen-after-approval', [
        'action' => 'approve',
        'review_id' => $scenario['review']->id,
        'actor_id' => GovernanceFixture::approver()->id,
        'hold_on_table' => 'sales_board_cycle_rectifications',
    ], [
        'action' => 'reopen',
        'cycle_id' => $scenario['july']->id,
        'actor_id' => GovernanceFixture::approver()->id,
    ]);

    expect($approval)->toMatchArray(['success' => true, 'outcome' => 'aprovado'])
        ->and($reopening['exception'])->toBe(SalesBoardCycleReopeningException::class)
        ->and($reopening['message'])->toContain('08/2026 já foi publicada e absorveu os fatos dela')
        ->and($scenario['july']->fresh()->status)->toBe(SalesBoardCycleStatus::Cancelled);
})->group('mysql');

it('refuses the approval of August that waited for the reopening of July', function () {
    $scenario = reopenAdjacentRaceScenario();

    // A reabertura segura a transação ao ler a Emissão: já travou agosto em
    // modo compartilhado e julho com FOR UPDATE.
    [$reopening, $approval] = competenceChainRace('approval-after-reopen', [
        'action' => 'reopen',
        'cycle_id' => $scenario['july']->id,
        'actor_id' => GovernanceFixture::approver()->id,
        'hold_on_table' => 'emissions',
    ], [
        'action' => 'approve',
        'review_id' => $scenario['review']->id,
        'actor_id' => GovernanceFixture::approver()->id,
    ]);

    expect($reopening)->toMatchArray(['success' => true, 'outcome' => SalesBoardCycleStatus::Generated->value])
        ->and($approval['exception'])->toBe(SalesBoardManagementReviewException::class)
        ->and($approval['message'])->toContain('A competência anterior (07/2026) ainda não foi aprovada nem cancelada')
        ->and(SalesBoardPublication::query()->where('sales_board_cycle_id', $scenario['august']->id)->exists())->toBeFalse();
})->group('mysql');

it('refuses the reopening of July that waited for the approval of September, with August cancelled', function () {
    $scenario = reopenSecondNextRaceScenario();

    // Setembro absorve julho e agosto: a aprovação lê agosto, julho e a âncora
    // (junho) em modo compartilhado e segura na conferência da retificação.
    [$approval, $reopening] = competenceChainRace('reopen-after-second-next-approval', [
        'action' => 'approve',
        'review_id' => $scenario['review']->id,
        'actor_id' => GovernanceFixture::approver()->id,
        'hold_on_table' => 'sales_board_cycle_rectifications',
    ], [
        'action' => 'reopen',
        'cycle_id' => $scenario['july']->id,
        'actor_id' => GovernanceFixture::approver()->id,
    ]);

    expect($approval)->toMatchArray(['success' => true, 'outcome' => 'aprovado'])
        ->and($reopening['exception'])->toBe(SalesBoardCycleReopeningException::class)
        ->and($reopening['message'])->toContain('09/2026 já foi publicada e absorveu os fatos dela')
        ->and($scenario['july']->fresh()->status)->toBe(SalesBoardCycleStatus::Cancelled)
        ->and(competenceChainSaleOwners($scenario['saleId']))->toBe(['2026-09:'.SalesBoardMovementTiming::WithoutPosition->value]);
})->group('mysql');

/**
 * Setembro é aprovado com agosto sem ciclo: o portão de ordem para em agosto e
 * não lê julho, e o único lock em comum com a reabertura de julho é o do ciclo
 * de setembro, travado pela aprovação com FOR UPDATE. A aprovação segura a
 * transação logo depois de gravar a publicação; a reabertura, que começa ali,
 * precisa esperar no ciclo de setembro e ser recusada. Com o lock compartilhado
 * só no índice secundário, ela não esperava, não via a publicação ainda não
 * commitada e reabria julho com setembro publicado.
 */
it('refuses the reopening of July that waited for the approval of September, with no cycle in August', function () {
    $scenario = reopenAcrossMonthWithoutCycleRaceScenario();

    [$approval, $reopening] = competenceChainRace('reopen-after-approval-across-month-without-cycle', [
        'action' => 'approve',
        'review_id' => $scenario['review']->id,
        'actor_id' => GovernanceFixture::approver()->id,
        'hold_on_sql' => 'insert into `sales_board_publications`',
    ], [
        'action' => 'reopen',
        'cycle_id' => $scenario['july']->id,
        'actor_id' => GovernanceFixture::approver()->id,
    ]);

    expect($approval)->toMatchArray(['success' => true, 'outcome' => 'aprovado'])
        ->and($reopening['exception'])->toBe(SalesBoardCycleReopeningException::class)
        ->and($reopening['message'])->toContain('09/2026 já foi publicada')
        ->and($scenario['july']->fresh()->status)->toBe(SalesBoardCycleStatus::Cancelled)
        ->and(SalesBoardPublication::query()->where('sales_board_cycle_id', $scenario['september']->id)->count())->toBe(1);
})->group('mysql');

it('refuses the approval of September that waited for the reopening of July, with August cancelled', function () {
    $scenario = reopenSecondNextRaceScenario();

    // A reabertura trava setembro e agosto em modo compartilhado antes de
    // julho, e segura ao ler a Emissão.
    [$reopening, $approval] = competenceChainRace('second-next-approval-after-reopen', [
        'action' => 'reopen',
        'cycle_id' => $scenario['july']->id,
        'actor_id' => GovernanceFixture::approver()->id,
        'hold_on_table' => 'emissions',
    ], [
        'action' => 'approve',
        'review_id' => $scenario['review']->id,
        'actor_id' => GovernanceFixture::approver()->id,
    ]);

    expect($reopening)->toMatchArray(['success' => true, 'outcome' => SalesBoardCycleStatus::Generated->value])
        ->and($approval['exception'])->toBe(SalesBoardManagementReviewException::class)
        ->and($approval['message'])->toContain('A competência 07/2026 ainda não foi aprovada nem cancelada')
        ->and($approval['message'])->toContain('Como 08/2026 foi cancelada')
        ->and(SalesBoardPublication::query()->where('sales_board_cycle_id', $scenario['september']->id)->exists())->toBeFalse()
        ->and(competenceChainSaleOwners($scenario['saleId']))->toBe([]);
})->group('mysql');

/**
 * Agosto foi apurado contra julho, ainda aberto. Julho é cancelado enquanto a
 * aprovação de agosto espera o lock dele: a aprovação passa pela regra de ordem
 * (julho cancelado, junho publicado), mas a fonte é derivada depois da espera e
 * já absorve julho -- a versão em análise ficou desatualizada, e a aprovação é
 * recusada em vez de publicar agosto sem a venda de julho.
 */
it('refuses the approval of August that waited for July to be cancelled, because August now absorbs July', function () {
    [$construction, $units] = CycleFixture::readyConstruction(3);
    ExtemporaneousFixture::publish(CycleFixture::generate($construction, '2026-06-01')->cycle);

    $sale = ExtemporaneousFixture::sale($units[0], '2026-07-12');
    $july = CycleFixture::generate($construction, '2026-07-01')->cycle;

    $august = ExtemporaneousFixture::generateAugust($construction);
    $review = ExtemporaneousFixture::analysis($august);
    ManagementReviewFixture::decideAll($review);

    // O cancelamento segura a transação logo depois de travar julho.
    [$cancellation, $approval] = competenceChainRace('approval-after-cancel', [
        'action' => 'cancel',
        'cycle_id' => $july->id,
        'actor_id' => GovernanceFixture::approver()->id,
        'hold_on_table' => 'sales_board_cycles',
    ], [
        'action' => 'approve',
        'review_id' => $review->id,
        'actor_id' => GovernanceFixture::approver()->id,
    ]);

    expect($cancellation)->toMatchArray(['success' => true, 'outcome' => SalesBoardCycleStatus::Cancelled->value])
        ->and($approval['exception'])->toBe(SalesBoardManagementReviewException::class)
        ->and(SalesBoardPublication::query()->where('sales_board_cycle_id', $august->id)->exists())->toBeFalse()
        ->and(competenceChainSaleOwners($sale->id))->toBe([]);
})->group('mysql');

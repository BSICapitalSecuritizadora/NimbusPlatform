<?php

use App\DTOs\SalesBoards\BuilderReviewerIdentity;
use App\Enums\SalesBoardBuilderReviewSectionStatus;
use App\Enums\SalesBoardBuilderReviewStatus;
use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardManagementReviewStatus;
use App\Enums\SalesBoardNonconformityDecision;
use App\Enums\SalesBoardNonconformityOrigin;
use App\Exceptions\SalesBoardBuilderReviewException;
use App\Exceptions\SalesBoardCycleCancellationException;
use App\Exceptions\SalesBoardManagementReviewException;
use App\Models\SalesBoardBuilderReview;
use App\Models\SalesBoardBuilderReviewSection;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardManagementNonconformity;
use App\Models\SalesBoardManagementReview;
use App\Models\SalesBoardPublication;
use App\Models\User;
use App\Services\SalesBoards\SalesBoardBuilderReviewEditor;
use App\Services\SalesBoards\SalesBoardBuilderReviewSubmissionService;
use App\Services\SalesBoards\SalesBoardCycleCancellationService;
use App\Services\SalesBoards\SalesBoardManagementApprovalService;
use App\Services\SalesBoards\SalesBoardManagementDecisionService;
use App\Services\SalesBoards\SalesBoardManagementReturnService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Tests\Support\CommittedRowsSweeper;
use Tests\Support\SalesBoards\BuilderReviewFixture;
use Tests\Support\SalesBoards\GovernanceFixture;
use Tests\Support\SalesBoards\ManagementReviewFixture;

/**
 * Decidir contra aprovar, e editar contra enviar, em conexões reais.
 *
 * A decisão e a edição travavam só a linha filha, e liam a revisão com leitura
 * simples; a aprovação e o envio travavam ciclo e revisão, e liam as linhas
 * filhas sem lock. No `REPEATABLE READ` do MySQL uma operação não esperava a
 * outra: uma "correção necessária" entrava enquanto a aprovação derivava a
 * fonte, e uma seção voltava a "pendente" numa declaração já enviada. O SQLite
 * serializa escritores e não mostra nada disso.
 */
beforeEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requer MySQL para observar duas conexões disputando a mesma revisão.');
    }

    Artisan::call('migrate:fresh', ['--no-interaction' => true]);

    $this->committedRows = CommittedRowsSweeper::afterFreshMigration();
});

/**
 * O cenário e os processos filhos commitam fora de qualquer transação de teste.
 * A limpeza devolve o banco ao estado recém-migrado -- tudo o que entrou depois
 * da foto do `beforeEach`, em qualquer tabela -- e a verificação garante que o
 * arquivo seguinte da suíte não herda nada daqui.
 */
afterEach(function () {
    if (DB::getDriverName() !== 'mysql' || ! isset($this->committedRows)) {
        return;
    }

    $this->committedRows->sweep();

    expect($this->committedRows->leftovers())->toBe([]);
});

/**
 * Uma operação da revisão num processo filho.
 *
 * Com `hold_on_table`, o processo grava o marcador e segura a transação logo
 * depois da primeira consulta **dentro da transação** que toca a tabela -- com
 * os locks que tiver adquirido até ali. As leituras de âncora rodam antes, fora
 * da transação, e segurar numa delas deixaria o processo dormindo sem lock
 * nenhum: a disputa viraria sequencial e o teste não provaria a serialização.
 * Com `wait_for_marker`, o processo só começa depois disso.
 *
 * @param  array{action: string, review_id?: int, cycle_id?: int, nonconformity_id?: int, section_id?: int, decision?: string, actor_id: int, reason?: string, hold_on_table?: string, marker?: string, hold_ms?: int, wait_for_marker?: string}  $instruction
 */
function reviewWorkflowTask(array $instruction): Closure
{
    return static function () use ($instruction): array {
        if (isset($instruction['hold_on_table'])) {
            $held = false;

            DB::listen(static function (QueryExecuted $query) use ($instruction, &$held): void {
                if ($held
                    || ($query->connection->transactionLevel() === 0)
                    || ! str_contains(strtolower($query->sql), '`'.$instruction['hold_on_table'].'`')) {
                    return;
                }

                $held = true;
                file_put_contents($instruction['marker'], 'held');
                usleep(((int) ($instruction['hold_ms'] ?? 0)) * 1000);
            });
        }

        try {
            if (isset($instruction['wait_for_marker'])) {
                $deadline = microtime(true) + 10;

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
                    ->approve(
                        SalesBoardManagementReview::query()->findOrFail($instruction['review_id']),
                        $actor,
                        true,
                    )->outcome->value,
                'decide' => app(SalesBoardManagementDecisionService::class)
                    ->decide(
                        SalesBoardManagementNonconformity::query()->findOrFail($instruction['nonconformity_id']),
                        SalesBoardNonconformityDecision::from((string) $instruction['decision']),
                        $instruction['reason'] ?? null,
                        $actor,
                    )->decision->value,
                /**
                 * O processo filho commita: a resposta da construtora vai para
                 * o armazenamento em memória, e nenhum arquivo fica no disco
                 * privado sem que alguém o apague.
                 */
                'submit' => (static function () use ($instruction, $actor): string {
                    BuilderReviewFixture::useInMemoryEvidenceStore();

                    $review = SalesBoardBuilderReview::query()->findOrFail($instruction['review_id']);

                    return app(SalesBoardBuilderReviewSubmissionService::class)
                        ->submit(
                            $review,
                            BuilderReviewerIdentity::forInternalUser($actor),
                            null,
                            BuilderReviewFixture::evidence($review),
                        )->status->value;
                })(),
                'reopen_section' => app(SalesBoardBuilderReviewEditor::class)
                    ->reopenSection(
                        SalesBoardBuilderReviewSection::query()->findOrFail($instruction['section_id']),
                        BuilderReviewerIdentity::forInternalUser($actor),
                    )
                    ->status->value,
                'cancel' => app(SalesBoardCycleCancellationService::class)
                    ->cancel(
                        SalesBoardCycle::query()->findOrFail($instruction['cycle_id']),
                        $actor,
                        'A competência não será publicada: a Emissão voltou ao registro manual.',
                    )
                    ->status->value,
                'return' => app(SalesBoardManagementReturnService::class)
                    ->returnToBuilder(
                        SalesBoardManagementReview::query()->findOrFail($instruction['review_id']),
                        $actor,
                        'Precisamos da confirmação do contrato da unidade 102.',
                    )['review']
                    ->status->value,
            };

            return ['success' => true, 'outcome' => $outcome, 'exception' => null];
        } catch (Throwable $exception) {
            return ['success' => false, 'outcome' => null, 'exception' => $exception::class];
        }
    };
}

it('never lets a decision land after the approval checked the gate', function () {
    $scenario = ManagementReviewFixture::submittedCycleWithNonConformSale();
    $actor = GovernanceFixture::approver();
    $review = ManagementReviewFixture::open($scenario['cycle'], $actor);
    ManagementReviewFixture::decideAll($review, $actor);

    $item = ManagementReviewFixture::nonconformityOf($review, SalesBoardNonconformityOrigin::SystemSaleNonConform);

    $marker = temporaryTestFilePath('decide-approve-hold', 'lock');
    @unlink($marker);

    $results = Concurrency::driver('process')->run([
        // A aprovação segura a transação logo depois de ler as pendências --
        // o ponto em que o portão já foi conferido e falta publicar.
        reviewWorkflowTask([
            'action' => 'approve',
            'review_id' => $review->id,
            'actor_id' => $actor->id,
            'hold_on_table' => 'sales_board_management_nonconformities',
            'marker' => $marker,
            'hold_ms' => 600,
        ]),
        reviewWorkflowTask([
            'action' => 'decide',
            'nonconformity_id' => $item->id,
            'decision' => SalesBoardNonconformityDecision::CorrectionRequired->value,
            'reason' => 'A tabela de preços da unidade precisa ser corrigida.',
            'actor_id' => $actor->id,
            'wait_for_marker' => $marker,
        ]),
    ]);

    @unlink($marker);

    $finalReview = $review->fresh();
    $finalItem = $item->fresh();

    /**
     * A decisão espera a aprovação e encontra a análise encerrada. O que nunca
     * acontece é o Quadro publicado ao lado de uma pendência que exige corrigir
     * a fonte.
     */
    expect($results[0])->toBe(['success' => true, 'outcome' => 'aprovado', 'exception' => null])
        ->and($results[1]['exception'])->toBe(SalesBoardManagementReviewException::class)
        ->and($finalReview->status)->toBe(SalesBoardManagementReviewStatus::Approved)
        ->and($finalItem->decision)->toBe(SalesBoardNonconformityDecision::AcceptedException)
        ->and(SalesBoardPublication::query()->count())->toBe(1);
})->group('mysql');

it('never reopens a section of a review the submission already checked', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    $actor = GovernanceFixture::operator();
    $review = BuilderReviewFixture::open($scenario['cycle'], $actor);
    BuilderReviewFixture::confirmAll($review);

    $section = $review->sections()->firstOrFail();

    $marker = temporaryTestFilePath('reopen-submit-hold', 'lock');
    @unlink($marker);

    $results = Concurrency::driver('process')->run([
        // A edição segura a transação logo depois de ler a revisão -- o ponto
        // em que ela já conferiu que a declaração está aberta e falta gravar.
        reviewWorkflowTask([
            'action' => 'reopen_section',
            'section_id' => $section->id,
            'actor_id' => $actor->id,
            'hold_on_table' => 'sales_board_builder_reviews',
            'marker' => $marker,
            'hold_ms' => 600,
        ]),
        reviewWorkflowTask([
            'action' => 'submit',
            'review_id' => $review->id,
            'actor_id' => $actor->id,
            'wait_for_marker' => $marker,
        ]),
    ]);

    @unlink($marker);

    $finalReview = $review->fresh();
    $statuses = $finalReview->sections()->pluck('status')->all();

    /**
     * A reabertura tem ciclo e revisão travados quando o envio começa. O envio
     * espera o ciclo, encontra a seção pendente e é recusado. O que nunca
     * acontece é uma declaração enviada com seção pendente.
     */
    expect($results[0])->toBe(['success' => true, 'outcome' => SalesBoardBuilderReviewSectionStatus::Pending->value, 'exception' => null])
        ->and($results[1]['exception'])->toBe(SalesBoardBuilderReviewException::class)
        ->and($finalReview->status)->toBe(SalesBoardBuilderReviewStatus::Draft)
        ->and($section->fresh()->status)->toBe(SalesBoardBuilderReviewSectionStatus::Pending)
        ->and(collect($statuses)->contains(SalesBoardBuilderReviewSectionStatus::Pending))->toBeTrue();
})->group('mysql');

/*
|--------------------------------------------------------------------------
| Cancelar competência contra aprovar, enviar, devolver e decidir
|--------------------------------------------------------------------------
|
| O cancelamento trava o ciclo antes de tudo, como as quatro operações. Quem
| pega o lock primeiro termina; o outro espera, relê sob lock e decide pelo que
| encontrou. Nunca um quadro publicado num ciclo cancelado, nem uma rodada
| aberta num ciclo cancelado.
*/

it('refuses the cancellation that waited for an approval to publish', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $review = ManagementReviewFixture::open($scenario['cycle']);
    $approver = GovernanceFixture::approver();

    $marker = temporaryTestFilePath('approve-cancel-hold', 'lock');
    @unlink($marker);

    $results = Concurrency::driver('process')->run([
        reviewWorkflowTask([
            'action' => 'approve',
            'review_id' => $review->id,
            'actor_id' => $approver->id,
            'hold_on_table' => 'sales_board_cycles',
            'marker' => $marker,
            'hold_ms' => 600,
        ]),
        reviewWorkflowTask([
            'action' => 'cancel',
            'cycle_id' => $scenario['cycle']->id,
            'actor_id' => GovernanceFixture::approver()->id,
            'wait_for_marker' => $marker,
        ]),
    ]);

    @unlink($marker);

    expect($results[0])->toBe(['success' => true, 'outcome' => 'aprovado', 'exception' => null])
        ->and($results[1]['exception'])->toBe(SalesBoardCycleCancellationException::class)
        ->and($scenario['cycle']->fresh()->status)->toBe(SalesBoardCycleStatus::Approved)
        ->and(SalesBoardPublication::query()->where('sales_board_cycle_id', $scenario['cycle']->id)->count())->toBe(1);
})->group('mysql');

it('refuses the approval that waited for a cancellation', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $review = ManagementReviewFixture::open($scenario['cycle']);

    $marker = temporaryTestFilePath('cancel-approve-hold', 'lock');
    @unlink($marker);

    $results = Concurrency::driver('process')->run([
        reviewWorkflowTask([
            'action' => 'cancel',
            'cycle_id' => $scenario['cycle']->id,
            'actor_id' => GovernanceFixture::approver()->id,
            'hold_on_table' => 'sales_board_cycles',
            'marker' => $marker,
            'hold_ms' => 600,
        ]),
        reviewWorkflowTask([
            'action' => 'approve',
            'review_id' => $review->id,
            'actor_id' => GovernanceFixture::approver()->id,
            'wait_for_marker' => $marker,
        ]),
    ]);

    @unlink($marker);

    expect($results[0])->toBe(['success' => true, 'outcome' => SalesBoardCycleStatus::Cancelled->value, 'exception' => null])
        ->and($results[1]['exception'])->toBe(SalesBoardManagementReviewException::class)
        ->and($review->fresh()->status)->toBe(SalesBoardManagementReviewStatus::Superseded)
        ->and(SalesBoardPublication::query()->where('sales_board_cycle_id', $scenario['cycle']->id)->exists())->toBeFalse();
})->group('mysql');

it('supersedes the round submitted while the cancellation waited', function () {
    $scenario = BuilderReviewFixture::generatedCycle();
    $operator = GovernanceFixture::operator();
    $review = BuilderReviewFixture::open($scenario['cycle'], $operator);
    BuilderReviewFixture::confirmAll($review);

    $marker = temporaryTestFilePath('submit-cancel-hold', 'lock');
    @unlink($marker);

    $results = Concurrency::driver('process')->run([
        // O envio segura a transação nas seções, já com o ciclo travado. Segurar
        // na primeira consulta a `sales_board_cycles` pararia antes, na
        // conferência de desatualização, que roda em transação própria: o
        // cancelamento passaria no meio e o envio nem chegaria a acontecer.
        reviewWorkflowTask([
            'action' => 'submit',
            'review_id' => $review->id,
            'actor_id' => $operator->id,
            'hold_on_table' => 'sales_board_builder_review_sections',
            'marker' => $marker,
            'hold_ms' => 600,
        ]),
        reviewWorkflowTask([
            'action' => 'cancel',
            'cycle_id' => $scenario['cycle']->id,
            'actor_id' => GovernanceFixture::approver()->id,
            'wait_for_marker' => $marker,
        ]),
    ]);

    @unlink($marker);

    $final = $review->fresh();

    expect($results[0]['success'])->toBeTrue()
        ->and($results[1])->toBe(['success' => true, 'outcome' => SalesBoardCycleStatus::Cancelled->value, 'exception' => null])
        ->and($final->status)->toBe(SalesBoardBuilderReviewStatus::Superseded)
        ->and($final->superseded_reason)->toBe(SalesBoardCycleCancellationService::SUPERSEDED_REASON);
})->group('mysql');

it('cancels after a return without leaving the new round open', function () {
    $scenario = ManagementReviewFixture::submittedCycle();
    $review = ManagementReviewFixture::open($scenario['cycle']);

    $marker = temporaryTestFilePath('return-cancel-hold', 'lock');
    @unlink($marker);

    $results = Concurrency::driver('process')->run([
        reviewWorkflowTask([
            'action' => 'return',
            'review_id' => $review->id,
            'actor_id' => GovernanceFixture::approver()->id,
            'hold_on_table' => 'sales_board_cycles',
            'marker' => $marker,
            'hold_ms' => 600,
        ]),
        reviewWorkflowTask([
            'action' => 'cancel',
            'cycle_id' => $scenario['cycle']->id,
            'actor_id' => GovernanceFixture::approver()->id,
            'wait_for_marker' => $marker,
        ]),
    ]);

    @unlink($marker);

    $rounds = SalesBoardBuilderReview::query()->where('sales_board_cycle_id', $scenario['cycle']->id)->orderBy('id')->get();

    /**
     * A devolução é rodada encerrada e fica como está; a rodada nova que ela
     * abriu é substituída pelo cancelamento.
     */
    expect($results[0])->toBe(['success' => true, 'outcome' => SalesBoardManagementReviewStatus::Returned->value, 'exception' => null])
        ->and($results[1])->toBe(['success' => true, 'outcome' => SalesBoardCycleStatus::Cancelled->value, 'exception' => null])
        ->and($review->fresh()->status)->toBe(SalesBoardManagementReviewStatus::Returned)
        ->and($rounds)->toHaveCount(2)
        ->and($rounds->first()->status)->toBe(SalesBoardBuilderReviewStatus::Submitted)
        ->and($rounds->last()->status)->toBe(SalesBoardBuilderReviewStatus::Superseded)
        ->and($rounds->last()->superseded_reason)->toBe(SalesBoardCycleCancellationService::SUPERSEDED_REASON);
})->group('mysql');

it('refuses the decision that waited for a cancellation', function () {
    $scenario = ManagementReviewFixture::submittedCycleWithNonConformSale();
    $review = ManagementReviewFixture::open($scenario['cycle']);
    $item = ManagementReviewFixture::nonconformityOf($review, SalesBoardNonconformityOrigin::SystemSaleNonConform);

    $marker = temporaryTestFilePath('cancel-decide-hold', 'lock');
    @unlink($marker);

    $results = Concurrency::driver('process')->run([
        reviewWorkflowTask([
            'action' => 'cancel',
            'cycle_id' => $scenario['cycle']->id,
            'actor_id' => GovernanceFixture::approver()->id,
            'hold_on_table' => 'sales_board_cycles',
            'marker' => $marker,
            'hold_ms' => 600,
        ]),
        reviewWorkflowTask([
            'action' => 'decide',
            'nonconformity_id' => $item->id,
            'decision' => SalesBoardNonconformityDecision::AcceptedException->value,
            'reason' => 'Venda autorizada pela diretoria comercial.',
            'actor_id' => GovernanceFixture::approver()->id,
            'wait_for_marker' => $marker,
        ]),
    ]);

    @unlink($marker);

    expect($results[0])->toBe(['success' => true, 'outcome' => SalesBoardCycleStatus::Cancelled->value, 'exception' => null])
        ->and($results[1]['exception'])->toBe(SalesBoardManagementReviewException::class)
        ->and($item->fresh()->decision)->toBe(SalesBoardNonconformityDecision::Pending)
        ->and($review->fresh()->status)->toBe(SalesBoardManagementReviewStatus::Superseded);
})->group('mysql');

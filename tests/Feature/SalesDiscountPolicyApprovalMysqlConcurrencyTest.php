<?php

use App\Exceptions\SalesBoardManagementReviewException;
use App\Exceptions\SalesDiscountPolicyPeriodException;
use App\Models\Construction;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardManagementReview;
use App\Models\SalesBoardPublication;
use App\Models\SalesDiscountPolicy;
use App\Models\User;
use App\Services\SalesBoards\SalesBoardGenerationService;
use App\Services\SalesBoards\SalesBoardManagementApprovalService;
use App\Services\SalesBoards\SalesDiscountPolicyRegistrar;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Tests\Support\CommittedRowsSweeper;
use Tests\Support\SalesBoards\CycleFixture;
use Tests\Support\SalesBoards\GovernanceFixture;
use Tests\Support\SalesBoards\ManagementReviewFixture;

/**
 * A política de desconto retroativa contra a aprovação da competência que ela
 * alcança, em conexões reais.
 *
 * O registro trava a obra em modo exclusivo antes de ler a fronteira publicada,
 * e a aprovação trava a obra em modo compartilhado logo depois do ciclo, antes
 * de qualquer leitura comum. Com a obra lida só na publicação, uma política
 * commitada durante a derivação passava pelas duas checagens: a competência
 * saía publicada com a régua antiga e a política nova valia sobre ela. Agora
 * uma das duas espera a outra e é recusada pela regra -- sem deadlock, nas duas
 * ordens. O SQLite serializa escritores e não mostra nada disso.
 *
 * A venda de julho sai por 480.000 contra a tabela de 500.000: conforme com os
 * 10% da política vigente e fora dos 3% da política nova. A política nova muda o
 * veredito -- é isso que torna a corrida observável.
 */
beforeEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requer MySQL para observar duas conexões disputando a mesma obra.');
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
 * O processo que segura o lock para depois da primeira consulta, dentro da
 * transação, cujo SQL tem todos os fragmentos de `hold_after` e pelo menos um
 * dos de `hold_after_any`; o outro espera o marcador antes de começar.
 *
 * @param  array{action: string, actor_id: int, review_id?: int, construction_id?: int, month?: string, from?: string, until?: string, percent?: string, hold_after?: list<string>, hold_after_any?: list<string>, lock_marker?: string, wait_for_marker?: string, hold_after_lock_ms?: int}  $instruction
 */
function policyApprovalRaceTask(array $instruction): Closure
{
    return static function () use ($instruction): array {
        if (isset($instruction['lock_marker'])) {
            $held = false;

            DB::listen(static function (QueryExecuted $query) use ($instruction, &$held): void {
                if ($held || ($query->connection->transactionLevel() === 0)) {
                    return;
                }

                $sql = strtolower($query->sql);

                foreach ($instruction['hold_after'] ?? [] as $fragment) {
                    if (! str_contains($sql, $fragment)) {
                        return;
                    }
                }

                $any = $instruction['hold_after_any'] ?? [];

                if (($any !== []) && ! collect($any)->contains(fn (string $fragment): bool => str_contains($sql, $fragment))) {
                    return;
                }

                $held = true;
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
                'generate' => app(SalesBoardGenerationService::class)
                    ->generateForConstruction(
                        Construction::query()->findOrFail($instruction['construction_id']),
                        CarbonImmutable::parse((string) $instruction['month']),
                        $actor,
                    )
                    ->outcome->value,
                'register' => (static function () use ($instruction, $actor): string {
                    $construction = Construction::query()->findOrFail($instruction['construction_id']);
                    $registrar = app(SalesDiscountPolicyRegistrar::class);

                    /**
                     * O que a tela mostra e o gestor confirma, avaliado antes
                     * de gravar -- a substituída e o alcance retroativo, que
                     * dependem do dia de hoje no relógio real do processo.
                     */
                    $assessment = $registrar->assess(
                        (int) $construction->getKey(),
                        CarbonImmutable::parse((string) $instruction['from']),
                        CarbonImmutable::parse((string) $instruction['until']),
                    );

                    return (string) $registrar->register(
                        $construction,
                        [
                            'maximum_discount_percent' => (string) $instruction['percent'],
                            'effective_from' => (string) $instruction['from'],
                            'effective_until' => (string) $instruction['until'],
                            'reason' => 'Política comercial revista pela diretoria.',
                        ],
                        $assessment->substitutedPolicyId(),
                        $actor,
                        $assessment->retroactiveThroughDate(),
                    )->getKey();
                })(),
            };

            return ['success' => true, 'outcome' => $outcome, 'exception' => null, 'message' => null];
        } catch (Throwable $exception) {
            return ['success' => false, 'outcome' => null, 'exception' => $exception::class, 'message' => $exception->getMessage()];
        }
    };
}

/**
 * Julho validado pela construtora e com a análise aberta, pronto para aprovar,
 * e quem registra a política: edita emissões e é da Gestão, porque a política
 * retroativa rejulga venda já registrada.
 *
 * @return array{cycle: SalesBoardCycle, construction: Construction, review: SalesBoardManagementReview, approver: User, manager: User, policy: SalesDiscountPolicy}
 */
function policyApprovalRaceScenario(): array
{
    $scenario = ManagementReviewFixture::submittedCycle();
    $approver = GovernanceFixture::approver();

    $manager = User::factory()->create();
    $manager->givePermissionTo(['emissions.update', 'sales-boards.approve']);

    return [
        'cycle' => $scenario['cycle'],
        'construction' => $scenario['construction'],
        'review' => ManagementReviewFixture::open($scenario['cycle'], $approver),
        'approver' => $approver,
        'manager' => $manager,
        'policy' => SalesDiscountPolicy::query()->where('construction_id', $scenario['construction']->id)->sole(),
    ];
}

/**
 * @param  array{cycle: SalesBoardCycle, construction: Construction, review: SalesBoardManagementReview, approver: User, manager: User, policy: SalesDiscountPolicy}  $scenario
 * @return array<string, mixed>
 */
function retroactiveThreePercentPolicy(array $scenario): array
{
    return [
        'action' => 'register',
        'construction_id' => $scenario['construction']->id,
        'actor_id' => $scenario['manager']->id,
        'from' => '2026-07-01',
        'until' => '2099-12-31',
        'percent' => '3.00',
    ];
}

it('refuses the retroactive policy that waits for the approval publishing the competence it reaches', function () {
    $scenario = policyApprovalRaceScenario();

    $marker = temporaryTestFilePath('policy-approval-lock', 'lock');
    @unlink($marker);

    [$approval, $registration] = Concurrency::driver('process')->run([
        policyApprovalRaceTask([
            'action' => 'approve',
            'review_id' => $scenario['review']->id,
            'actor_id' => $scenario['approver']->id,
            /**
             * A primeira leitura da obra dentro da aprovação: a trava
             * compartilhada logo depois do ciclo. Antes dela a obra vinha num
             * eager load comum, que fixava o snapshot sem esperar ninguém.
             */
            'hold_after' => ['`constructions`'],
            'lock_marker' => $marker,
            'hold_after_lock_ms' => 1500,
        ]),
        policyApprovalRaceTask([...retroactiveThreePercentPolicy($scenario), 'wait_for_marker' => $marker]),
    ]);

    @unlink($marker);

    expect($approval['success'])->toBeTrue()
        ->and($approval['outcome'])->toBe('aprovado')
        ->and($registration['success'])->toBeFalse()
        ->and($registration['exception'])->toBe(SalesDiscountPolicyPeriodException::class)
        ->and($registration['message'])->toContain('competência já aprovada e publicada desta obra (07/2026)')
        ->and(SalesBoardPublication::query()->where('sales_board_cycle_id', $scenario['cycle']->id)->exists())->toBeTrue()
        ->and(SalesDiscountPolicy::query()->where('construction_id', $scenario['construction']->id)->pluck('id')->all())
        ->toBe([$scenario['policy']->id]);
})->group('mysql');

it('refuses the approval that waits for a retroactive policy changing the verdict of the competence', function () {
    $scenario = policyApprovalRaceScenario();

    $marker = temporaryTestFilePath('policy-approval-lock', 'lock');
    @unlink($marker);

    [$registration, $approval] = Concurrency::driver('process')->run([
        policyApprovalRaceTask([
            ...retroactiveThreePercentPolicy($scenario),
            'hold_after' => ['`constructions`', 'for update'],
            'lock_marker' => $marker,
            'hold_after_lock_ms' => 1500,
        ]),
        policyApprovalRaceTask([
            'action' => 'approve',
            'review_id' => $scenario['review']->id,
            'actor_id' => $scenario['approver']->id,
            'wait_for_marker' => $marker,
        ]),
    ]);

    @unlink($marker);

    expect($registration['success'])->toBeTrue()
        ->and($approval['success'])->toBeFalse()
        ->and($approval['exception'])->toBe(SalesBoardManagementReviewException::class)
        ->and($approval['message'])->toContain('alteraram materialmente')
        ->and(SalesBoardPublication::query()->where('sales_board_cycle_id', $scenario['cycle']->id)->exists())->toBeFalse()
        ->and(SalesDiscountPolicy::query()->whereKey((int) $registration['outcome'])->value('maximum_discount_percent'))->toBe('3.00');
})->group('mysql');

/**
 * A política não trava os ciclos que alcança. Se travasse o intervalo de ciclos
 * da obra antes de pedir a obra em modo exclusivo, a geração da competência
 * seguinte -- que pega a obra em modo compartilhado pela FK do ciclo novo e
 * espera o mesmo intervalo para inseri-lo -- terminaria em deadlock 1213.
 */
it('registers a retroactive policy while the next competence is generated, without deadlock', function () {
    [$construction] = CycleFixture::readyConstruction(3);
    CycleFixture::generate($construction, '2026-07-01');

    $manager = User::factory()->create();
    $manager->givePermissionTo(['emissions.update', 'sales-boards.approve']);

    $marker = temporaryTestFilePath('policy-generation-lock', 'lock');
    @unlink($marker);

    [$registration, $generation] = Concurrency::driver('process')->run([
        policyApprovalRaceTask([
            'action' => 'register',
            'construction_id' => $construction->id,
            'actor_id' => $manager->id,
            'from' => '2026-07-01',
            'until' => '2099-12-31',
            'percent' => '3.00',
            // A primeira trava da transação do registro, exclusiva ou
            // compartilhada, e tempo para a geração chegar ao INSERT do ciclo
            // novo enquanto isso.
            'hold_after_any' => [' for update', ' lock in share mode', ' for share'],
            'lock_marker' => $marker,
            'hold_after_lock_ms' => 2500,
        ]),
        policyApprovalRaceTask([
            'action' => 'generate',
            'construction_id' => $construction->id,
            'month' => '2026-08-01',
            'actor_id' => GovernanceFixture::operator()->id,
            'wait_for_marker' => $marker,
        ]),
    ]);

    @unlink($marker);

    expect($registration['exception'])->toBeNull()
        ->and($generation['exception'])->toBeNull()
        ->and($generation['outcome'])->toBe('gerado')
        ->and(SalesBoardCycle::query()->where('construction_id', $construction->id)->count())->toBe(2);
})->group('mysql');

<?php

use App\Enums\SalesBoardRolloutHomologationStatus;
use App\Enums\SalesBoardSource;
use App\Exceptions\SalesBoardRolloutException;
use App\Models\Emission;
use App\Models\SalesBoardRolloutEvent;
use App\Models\SalesBoardRolloutHomologation;
use App\Models\User;
use App\Services\SalesBoards\SalesBoardRolloutActivationService;
use App\Services\SalesBoards\SalesBoardRolloutHomologationService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Tests\Support\CommittedRowsSweeper;
use Tests\Support\SalesBoards\GovernanceFixture;
use Tests\Support\SalesBoards\RolloutFixture;

/**
 * As corridas do rollout, em conexões reais.
 *
 * O que se protege aqui é a resposta a "quem produz os próximos quadros desta
 * Emissão?" nunca ficar ambígua: duas ativações simultâneas não podem produzir
 * dois eventos, e ativar contra desativar não pode terminar em modo automatizado
 * sem homologação vigente.
 *
 * Cada corrida tem barreira: o primeiro processo avisa quando está com a Emissão
 * travada e segura o lock; o segundo só dispara depois do aviso e mede quanto
 * esperou pelo próprio lock. Sem isso os dois processos às vezes rodavam em
 * série, davam o mesmo resultado de uma corrida serializada e o teste passava
 * sem ter provado lock nenhum.
 */
beforeEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requer MySQL para observar duas conexões disputando a mesma Emissão.');
    }

    Artisan::call('migrate:fresh', ['--no-interaction' => true]);

    $this->committedRows = CommittedRowsSweeper::afterFreshMigration();
});

/**
 * O cenário e os processos filhos commitam fora de transação: Emissão,
 * empreendimentos, unidades, políticas de desconto, usuários, homologações,
 * eventos, trilha de auditoria. Tudo isso sai daqui, e a verificação no fim
 * garante que o próximo arquivo da suíte encontra o banco como as migrations o
 * deixaram -- era uma política esquecida aqui que quebrava os `sole()` de
 * `SalesDiscountPolicyPeriodTest` no MySQL.
 */
afterEach(function () {
    if (DB::getDriverName() !== 'mysql' || ! isset($this->committedRows)) {
        return;
    }

    $this->committedRows->sweep();

    expect($this->committedRows->leftovers())->toBe([]);
});

/**
 * @return array{emission: int, homologation: int, actor: int}
 */
function rolloutRaceScenario(): array
{
    $scenario = RolloutFixture::emission(2);

    foreach ($scenario['constructions'] as $construction) {
        RolloutFixture::legacyBoard($construction);
    }

    $homologation = RolloutFixture::approvedHomologation($scenario['emission']);

    return [
        'emission' => (int) $scenario['emission']->getKey(),
        'homologation' => (int) $homologation->getKey(),
        'actor' => (int) GovernanceFixture::approver()->getKey(),
    ];
}

/**
 * Os dois marcadores de uma corrida com barreira.
 *
 * `ready` é escrito pelo segundo processo assim que ele sobe, para que o
 * primeiro só trave a Emissão quando o concorrente já estiver pronto para
 * disputá-la -- a diferença de boot entre dois processos passa de 100 ms e
 * engoliria a janela. `locked` é escrito pelo primeiro com o lock na mão.
 *
 * @return array{ready: string, locked: string}
 */
function rolloutRaceMarkers(string $race): array
{
    $markers = [
        'ready' => temporaryTestFilePath("sales-board-rollout-{$race}-ready", 'lock'),
        'locked' => temporaryTestFilePath("sales-board-rollout-{$race}-locked", 'lock'),
    ];

    array_map(static fn (string $marker): bool => @unlink($marker), $markers);

    return $markers;
}

/**
 * Quem trava a Emissão primeiro e a segura por `hold_after_lock_ms`.
 *
 * @param  array{ready: string, locked: string}  $markers
 * @return array{wait_for_ready: string, lock_marker: string, hold_after_lock_ms: int}
 */
function rolloutRaceHolder(array $markers): array
{
    return ['wait_for_ready' => $markers['ready'], 'lock_marker' => $markers['locked'], 'hold_after_lock_ms' => 1500];
}

/**
 * Quem chega com a Emissão já travada pelo outro processo.
 *
 * @param  array{ready: string, locked: string}  $markers
 * @return array{ready_marker: string, wait_for_marker: string}
 */
function rolloutRaceChallenger(array $markers): array
{
    return ['ready_marker' => $markers['ready'], 'wait_for_marker' => $markers['locked']];
}

/**
 * @param  array{action: string, emission: int, homologation?: int, actor: int, wait_for_ready?: string, lock_marker?: string, hold_after_lock_ms?: int, ready_marker?: string, wait_for_marker?: string}  $instruction
 */
function rolloutTask(array $instruction): Closure
{
    return static function () use ($instruction): array {
        $emissionLockMs = null;

        /**
         * Espera o outro processo escrever o marcador, ou desiste com erro.
         * Fica dentro da closure porque ela roda num processo filho, que não
         * carrega as funções deste arquivo.
         */
        $await = static function (string $marker, string $failure): void {
            $deadline = microtime(true) + 20;

            while (! is_file($marker) && microtime(true) < $deadline) {
                usleep(10_000);
            }

            if (! is_file($marker)) {
                throw new RuntimeException($failure);
            }
        };

        /**
         * O primeiro `SELECT ... FOR UPDATE` na Emissão é o ponto da corrida:
         * todas as ações do rollout travam a Emissão antes de decidir qualquer
         * coisa. O tempo dessa consulta, no concorrente, é o tempo em que ele
         * ficou bloqueado esperando o outro processo commitar.
         */
        DB::listen(static function (QueryExecuted $query) use ($instruction, &$emissionLockMs): void {
            $sql = strtolower($query->sql);

            if ($emissionLockMs !== null || ! str_contains($sql, 'from `emissions`') || ! str_contains($sql, 'for update')) {
                return;
            }

            $emissionLockMs = $query->time;

            if (isset($instruction['lock_marker'])) {
                file_put_contents($instruction['lock_marker'], 'locked');
                usleep(((int) ($instruction['hold_after_lock_ms'] ?? 0)) * 1000);
            }
        });

        try {
            if (isset($instruction['ready_marker'])) {
                file_put_contents($instruction['ready_marker'], 'ready');
            }

            if (isset($instruction['wait_for_ready'])) {
                $await($instruction['wait_for_ready'], 'O processo concorrente não subiu a tempo de disputar a Emissão.');
            }

            if (isset($instruction['wait_for_marker'])) {
                $await($instruction['wait_for_marker'], 'O processo concorrente não confirmou o lock da Emissão.');
            }

            $actor = User::query()->findOrFail($instruction['actor']);
            $emission = Emission::query()->findOrFail($instruction['emission']);

            $outcome = match ($instruction['action']) {
                'activate' => app(SalesBoardRolloutActivationService::class)->activate(
                    $emission,
                    SalesBoardRolloutHomologation::query()->findOrFail($instruction['homologation']),
                    $actor,
                    'Ativação concorrente para teste de corrida.',
                )->sales_board_source->value,
                'deactivate' => app(SalesBoardRolloutActivationService::class)->returnToLegacy(
                    $emission,
                    $actor,
                    'Retorno concorrente para teste de corrida.',
                )->sales_board_source->value,
                'approve' => app(SalesBoardRolloutHomologationService::class)->approve(
                    SalesBoardRolloutHomologation::query()->findOrFail($instruction['homologation']),
                    $actor,
                    'Aprovação concorrente para teste de corrida.',
                )->status->value,
            };

            return ['success' => true, 'outcome' => $outcome, 'exception' => null, 'emission_lock_ms' => $emissionLockMs];
        } catch (Throwable $exception) {
            return ['success' => false, 'outcome' => null, 'exception' => $exception::class, 'emission_lock_ms' => $emissionLockMs];
        }
    };
}

it('never activates the same emission twice', function () {
    $scenario = rolloutRaceScenario();
    $markers = rolloutRaceMarkers('activate');

    $results = Concurrency::driver('process')->run([
        rolloutTask(['action' => 'activate', ...$scenario, ...rolloutRaceHolder($markers)]),
        rolloutTask(['action' => 'activate', ...$scenario, ...rolloutRaceChallenger($markers)]),
    ]);

    array_map(static fn (string $marker): bool => @unlink($marker), $markers);

    $emission = Emission::query()->findOrFail($scenario['emission']);

    // Quem travou primeiro ativa; o outro, que chegou com a Emissão travada,
    // esperou o commit, encontrou-a já automatizada e recusou como domínio.
    expect($results[0]['success'])->toBeTrue()
        ->and($results[1]['exception'])->toBe(SalesBoardRolloutException::class)
        ->and($results[1]['emission_lock_ms'])->toBeGreaterThan(500)
        ->and($emission->sales_board_source)->toBe(SalesBoardSource::Automated)
        ->and($emission->sales_board_active_homologation_id)->toBe($scenario['homologation'])
        // Um único evento na trilha.
        ->and(SalesBoardRolloutEvent::query()->count())->toBe(1);
})->group('mysql');

it('never ends inconsistent when activation races a return to legacy', function () {
    $scenario = rolloutRaceScenario();

    // A Emissão começa automatizada, para as duas ações serem plausíveis.
    RolloutFixture::activate(
        Emission::query()->findOrFail($scenario['emission']),
        SalesBoardRolloutHomologation::query()->findOrFail($scenario['homologation']),
    );

    $markers = rolloutRaceMarkers('return-to-legacy');

    $results = Concurrency::driver('process')->run([
        rolloutTask(['action' => 'deactivate', ...$scenario, ...rolloutRaceHolder($markers)]),
        rolloutTask(['action' => 'activate', ...$scenario, ...rolloutRaceChallenger($markers)]),
    ]);

    array_map(static fn (string $marker): bool => @unlink($marker), $markers);

    $emission = Emission::query()->findOrFail($scenario['emission']);

    /**
     * O estado final é coerente em qualquer ordem: automatizado **com**
     * homologação vigente, ou legado **sem** competência inicial. O que não
     * pode existir é a combinação impossível -- automatizado sem homologação, ou
     * legado ainda produzindo alvos.
     */
    $consistent = $emission->sales_board_source === SalesBoardSource::Automated
        ? $emission->sales_board_active_homologation_id !== null
            && $emission->sales_board_automation_start_reference_month !== null
        : $emission->sales_board_active_homologation_id === null
            && $emission->sales_board_automation_start_reference_month === null;

    expect($consistent)->toBeTrue()
        ->and(collect($results)->where('success', true))->toHaveCount(1)
        // O retorno travou primeiro e venceu; a ativação esperou por ele e,
        // relendo sob lock, encontrou a homologação já consumida.
        ->and($results[0]['outcome'])->toBe(SalesBoardSource::Legacy->value)
        ->and($results[1]['exception'])->toBe(SalesBoardRolloutException::class)
        ->and($results[1]['emission_lock_ms'])->toBeGreaterThan(500)
        ->and($emission->sales_board_source)->toBe(SalesBoardSource::Legacy);
})->group('mysql');

it('never approves the same homologation twice', function () {
    $scenario = RolloutFixture::emission(2);

    foreach ($scenario['constructions'] as $construction) {
        RolloutFixture::legacyBoard($construction);
    }

    $homologation = RolloutFixture::open($scenario['emission']);
    RolloutFixture::recipients($scenario['emission']);
    RolloutFixture::reviewImpacts($homologation);

    $instruction = [
        'emission' => (int) $scenario['emission']->getKey(),
        'homologation' => (int) $homologation->getKey(),
        'actor' => (int) GovernanceFixture::approver()->getKey(),
    ];

    $markers = rolloutRaceMarkers('approve');

    $results = Concurrency::driver('process')->run([
        rolloutTask(['action' => 'approve', ...$instruction, ...rolloutRaceHolder($markers)]),
        rolloutTask(['action' => 'approve', ...$instruction, ...rolloutRaceChallenger($markers)]),
    ]);

    array_map(static fn (string $marker): bool => @unlink($marker), $markers);

    expect($results[0]['success'])->toBeTrue()
        ->and($results[1]['exception'])->toBe(SalesBoardRolloutException::class)
        ->and($results[1]['emission_lock_ms'])->toBeGreaterThan(500)
        ->and($homologation->fresh()->status)->toBe(SalesBoardRolloutHomologationStatus::Approved)
        // Um aprovador só, e uma data só.
        ->and($homologation->fresh()->approved_by_user_id)->not->toBeNull();
})->group('mysql');

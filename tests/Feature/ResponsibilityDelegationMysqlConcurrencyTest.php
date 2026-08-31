<?php

use App\Enums\MeasurementResponsibility;
use App\Models\Operation;
use App\Models\ResponsibilityDelegation;
use App\Models\User;
use App\Services\ResponsibilityDelegationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

/**
 * As invariantes de cadastro de delegação -- sem sobreposição do mesmo delegante
 * e sem ciclo de autoridade -- são verificadas por consulta dentro da transação
 * e só depois gravadas. Em SQLite a suíte nunca vê o intervalo entre a leitura e
 * a escrita, porque o banco serializa a escrita inteira. Em produção, MySQL, o
 * intervalo existe: duas transações podem ler o mesmo estado, não encontrar
 * conflito, e gravar linhas que juntas violam a invariante.
 *
 * Estes testes rodam o par de operações em dois processos de verdade, com uma
 * barreira que só libera as gravações depois que as duas verificações passaram
 * -- é a janela do TOCTOU, aberta de propósito. Se o banco serializa o par, uma
 * das transações nem chega à barreira, e é isso que a asserção observa.
 */
beforeEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requer MySQL para observar gap locks e a janela entre verificação e gravação.');
    }

    Artisan::call('migrate:fresh', ['--no-interaction' => true]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

/** Participante que pode delegar e receber delegação de qualquer escopo. */
function delegationRaceParticipant(): User
{
    $user = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $user->givePermissionTo([
        'delegations.create', 'measurements.review', 'measurements.pay',
        'measurements.receipts', 'measurements.finalize',
    ]);

    // Autoridade direta: sem uma operação própria o delegante não pode delegar.
    Operation::factory()->create(array_fill_keys(
        array_map(
            fn (MeasurementResponsibility $responsibility): string => $responsibility->operationColumn(),
            MeasurementResponsibility::cases(),
        ),
        $user->getKey(),
    ));

    return $user;
}

function createDelegationDirectly(User $delegator, User $delegate): ResponsibilityDelegation
{
    return app(ResponsibilityDelegationService::class)->createDelegation([
        'delegator_user_id' => $delegator->getKey(),
        'delegate_user_id' => $delegate->getKey(),
        'scope_type' => ResponsibilityDelegation::SCOPE_GLOBAL,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addDays(5),
        'reason' => 'Cobertura de férias',
    ], $delegator);
}

/**
 * Uma criação de delegação em processo próprio, com barreira opcional.
 *
 * `barrier_on_sql` é o trecho da consulta que marca o ponto de espera: na
 * ocorrência de número `barrier_after_matches` o processo anuncia que chegou
 * (`own_marker`) e espera o par (`peer_marker`). Quem espera em vão devolve
 * `barrier_met => false` -- sinal de que o outro processo não conseguiu sequer
 * verificar, porque o banco o bloqueou antes.
 *
 * @param  array{delegator_id: int, delegate_id: int, barrier_on_sql?: string, barrier_after_matches?: int, own_marker?: string, peer_marker?: string, barrier_timeout_ms?: int}  $instruction
 */
function delegationRaceTask(array $instruction): Closure
{
    return static function () use ($instruction): array {
        $barrierMet = ! isset($instruction['barrier_on_sql']);
        $matches = 0;

        if (isset($instruction['barrier_on_sql'])) {
            DB::listen(static function (QueryExecuted $query) use ($instruction, &$barrierMet, &$matches): void {
                if ($barrierMet || ! str_contains(strtolower($query->sql), $instruction['barrier_on_sql'])) {
                    return;
                }

                $matches++;

                if ($matches < ($instruction['barrier_after_matches'] ?? 1)) {
                    return;
                }

                $barrierMet = true;
                touch($instruction['own_marker']);
                $deadline = microtime(true) + (($instruction['barrier_timeout_ms'] ?? 3000) / 1000);

                while (! is_file($instruction['peer_marker']) && microtime(true) < $deadline) {
                    usleep(5_000);
                }

                $barrierMet = is_file($instruction['peer_marker']);
            });
        }

        Notification::fake();

        try {
            $delegator = User::query()->findOrFail($instruction['delegator_id']);
            $delegation = app(ResponsibilityDelegationService::class)->createDelegation([
                'delegator_user_id' => $delegator->getKey(),
                'delegate_user_id' => $instruction['delegate_id'],
                'scope_type' => ResponsibilityDelegation::SCOPE_GLOBAL,
                'starts_at' => now()->subHour(),
                'ends_at' => now()->addDays(5),
                'reason' => 'Cobertura de férias',
            ], $delegator);

            return [
                'success' => true,
                'delegation_id' => $delegation->getKey(),
                'barrier_met' => $barrierMet,
                'exception' => null,
                'message' => null,
            ];
        } catch (Throwable $exception) {
            return [
                'success' => false,
                'delegation_id' => null,
                'barrier_met' => $barrierMet,
                'exception' => $exception::class,
                'message' => $exception instanceof ValidationException
                    ? (string) collect($exception->errors())->flatten()->first()
                    : $exception->getMessage(),
            ];
        }
    };
}

/**
 * Existe caminho de arestas vigentes e não revogadas que sai de `$from` e volta
 * a ele? A invariante que `assertNoCycle()` protege, medida no estado final.
 */
function delegationGraphHasCycleFrom(int $userId): bool
{
    $edges = ResponsibilityDelegation::query()
        ->whereNull('revoked_at')
        ->get(['delegator_user_id', 'delegate_user_id'])
        ->groupBy('delegator_user_id')
        ->map(fn ($group) => $group->pluck('delegate_user_id')->map(fn ($id): int => (int) $id)->all());

    $frontier = $edges[$userId] ?? [];
    $seen = [];

    while ($frontier !== []) {
        $next = [];

        foreach ($frontier as $node) {
            if ($node === $userId) {
                return true;
            }

            if (isset($seen[$node])) {
                continue;
            }

            $seen[$node] = true;
            $next = array_merge($next, $edges[$node] ?? []);
        }

        $frontier = $next;
    }

    return false;
}

/**
 * Quantas criações de delegação o Activitylog registrou. A tentativa recusada
 * -- por conflito, por ciclo ou por deadlock refeito -- não pode deixar rastro:
 * o log é escrito dentro da mesma transação da gravação.
 */
function delegationCreationsLogged(): int
{
    return Activity::query()
        ->where('log_name', 'delegations')
        ->where('description', 'delegation_created')
        ->count();
}

/** @return array{own: string, peer: string} */
function delegationRaceMarkers(string $name): array
{
    $own = temporaryTestFilePath("p25-{$name}-a", 'lock');
    $peer = temporaryTestFilePath("p25-{$name}-b", 'lock');
    @unlink($own);
    @unlink($peer);

    return ['own' => $own, 'peer' => $peer];
}

it('runs under the isolation level the cycle lock depends on', function () {
    // O `FOR UPDATE` da busca de ciclo protege o intervalo vazio por gap lock, e
    // gap lock é comportamento de REPEATABLE READ: em READ COMMITTED o InnoDB
    // não o toma, e a proteção some sem nenhum sintoma. A conexão não fixa o
    // nível -- herda o do servidor --, então quem o mudar quebra aqui, e não em
    // produção.
    expect(DB::selectOne('select @@session.transaction_isolation as level')->level)
        ->toBe('REPEATABLE-READ');
})->group('mysql');

it('serializes two overlapping delegations of the same delegator on MySQL', function () {
    $delegator = delegationRaceParticipant();
    $firstDelegate = delegationRaceParticipant();
    $secondDelegate = delegationRaceParticipant();
    $markers = delegationRaceMarkers('overlap');
    $barrier = ['barrier_on_sql' => 'from `responsibility_delegations` where `delegator_user_id` = ?'];

    $results = Concurrency::driver('process')->run([
        delegationRaceTask([
            'delegator_id' => $delegator->getKey(),
            'delegate_id' => $firstDelegate->getKey(),
            'own_marker' => $markers['own'],
            'peer_marker' => $markers['peer'],
        ] + $barrier),
        delegationRaceTask([
            'delegator_id' => $delegator->getKey(),
            'delegate_id' => $secondDelegate->getKey(),
            'own_marker' => $markers['peer'],
            'peer_marker' => $markers['own'],
        ] + $barrier),
    ]);

    // Só um dos processos chega a verificar: o outro fica preso no lock do
    // delegante, comum aos dois, antes mesmo de consultar a sobreposição.
    expect(collect($results)->where('barrier_met', true))->toHaveCount(1)
        ->and(collect($results)->where('success', true))->toHaveCount(1)
        ->and(collect($results)->firstWhere('success', false)['exception'])
        ->toBe(ValidationException::class)
        ->and(collect($results)->firstWhere('success', false)['message'])
        ->toContain('delegação conflitante')
        ->and(ResponsibilityDelegation::query()->count())->toBe(1)
        ->and(delegationCreationsLogged())->toBe(1);
})->group('mysql');

it('serializes two delegations that would close a direct cycle on MySQL', function () {
    $first = delegationRaceParticipant();
    $second = delegationRaceParticipant();
    $markers = delegationRaceMarkers('direct-cycle');
    $barrier = ['barrier_on_sql' => '`delegator_user_id` in (?)'];

    $results = Concurrency::driver('process')->run([
        delegationRaceTask([
            'delegator_id' => $first->getKey(),
            'delegate_id' => $second->getKey(),
            'own_marker' => $markers['own'],
            'peer_marker' => $markers['peer'],
        ] + $barrier),
        delegationRaceTask([
            'delegator_id' => $second->getKey(),
            'delegate_id' => $first->getKey(),
            'own_marker' => $markers['peer'],
            'peer_marker' => $markers['own'],
        ] + $barrier),
    ]);

    expect(collect($results)->where('barrier_met', true))->toHaveCount(1)
        ->and(collect($results)->where('success', true))->toHaveCount(1)
        ->and(collect($results)->firstWhere('success', false)['exception'])
        ->toBe(ValidationException::class)
        ->and(collect($results)->firstWhere('success', false)['message'])
        ->toContain('Ciclo de delegação detectado')
        ->and(ResponsibilityDelegation::query()->count())->toBe(1)
        ->and(delegationGraphHasCycleFrom($first->getKey()))->toBeFalse()
        ->and(delegationCreationsLogged())->toBe(1);
})->group('mysql');

it('serializes two delegations that would close a cycle through a shared user on MySQL', function () {
    $first = delegationRaceParticipant();
    $second = delegationRaceParticipant();
    $third = delegationRaceParticipant();
    createDelegationDirectly($first, $second);
    $markers = delegationRaceMarkers('adjacent-cycle');
    $barrier = ['barrier_on_sql' => '`delegator_user_id` in (?)'];

    // Novas arestas B -> C e C -> A: partilham C, e portanto partilham o lock.
    $results = Concurrency::driver('process')->run([
        delegationRaceTask([
            'delegator_id' => $second->getKey(),
            'delegate_id' => $third->getKey(),
            'own_marker' => $markers['own'],
            'peer_marker' => $markers['peer'],
        ] + $barrier),
        delegationRaceTask([
            'delegator_id' => $third->getKey(),
            'delegate_id' => $first->getKey(),
            'own_marker' => $markers['peer'],
            'peer_marker' => $markers['own'],
        ] + $barrier),
    ]);

    expect(collect($results)->where('barrier_met', true))->toHaveCount(1)
        ->and(collect($results)->where('success', true))->toHaveCount(1)
        ->and(collect($results)->firstWhere('success', false)['exception'])
        ->toBe(ValidationException::class)
        ->and(collect($results)->firstWhere('success', false)['message'])
        ->toContain('Ciclo de delegação detectado')
        ->and(ResponsibilityDelegation::query()->count())->toBe(2)
        ->and(delegationGraphHasCycleFrom($first->getKey()))->toBeFalse()
        ->and(delegationCreationsLogged())->toBe(2);
})->group('mysql');

it('refuses two delegations that would close a cycle through disjoint users on MySQL', function () {
    $first = delegationRaceParticipant();
    $second = delegationRaceParticipant();
    $third = delegationRaceParticipant();
    $fourth = delegationRaceParticipant();
    createDelegationDirectly($second, $third);
    createDelegationDirectly($fourth, $first);
    $markers = delegationRaceMarkers('disjoint-cycle');
    $barrier = ['barrier_on_sql' => '`delegator_user_id` in (?)'];

    // Novas arestas A -> B e C -> D fecham A -> B -> C -> D -> A sem partilhar
    // nenhum dos quatro usuários: nada no lock de usuários une o par.
    $results = Concurrency::driver('process')->run([
        delegationRaceTask([
            'delegator_id' => $first->getKey(),
            'delegate_id' => $second->getKey(),
            'own_marker' => $markers['own'],
            'peer_marker' => $markers['peer'],
        ] + $barrier),
        delegationRaceTask([
            'delegator_id' => $third->getKey(),
            'delegate_id' => $fourth->getKey(),
            'own_marker' => $markers['peer'],
            'peer_marker' => $markers['own'],
        ] + $barrier),
    ]);

    // Aqui os dois verificam -- a barreira encontra o par --, e mesmo assim só
    // um grava: o `FOR UPDATE` da busca de ciclo trava a faixa do delegante onde
    // o outro processo pretende inserir. Quem perde a disputa refaz a
    // verificação, encontra a aresta recém-gravada e recusa por ciclo.
    expect(collect($results)->where('barrier_met', true))->toHaveCount(2)
        ->and(collect($results)->where('success', true))->toHaveCount(1)
        ->and(collect($results)->firstWhere('success', false)['exception'])
        ->toBe(ValidationException::class)
        ->and(collect($results)->firstWhere('success', false)['message'])
        ->toContain('Ciclo de delegação detectado')
        ->and(ResponsibilityDelegation::query()->count())->toBe(3)
        ->and(delegationGraphHasCycleFrom($first->getKey()))->toBeFalse()
        ->and(delegationCreationsLogged())->toBe(3);
})->group('mysql');

it('resolves a deadlock between two cycle checks without leaving a cycle on MySQL', function () {
    $first = delegationRaceParticipant();
    $second = delegationRaceParticipant();
    $third = delegationRaceParticipant();
    $fourth = delegationRaceParticipant();
    createDelegationDirectly($second, $third);
    createDelegationDirectly($fourth, $first);
    $markers = delegationRaceMarkers('deadlock-cycle');

    // O mesmo par disjunto, mas com a barreira na última consulta da busca: os
    // dois terminam de travar tudo o que leram antes de qualquer um inserir, que
    // é a condição de deadlock. Uma das transações é escolhida vítima; a
    // reexecução do `DB::transaction(..., 3)` enxerga a aresta já gravada.
    $barrier = ['barrier_on_sql' => '`delegator_user_id` in (?)', 'barrier_after_matches' => 2];

    $results = Concurrency::driver('process')->run([
        delegationRaceTask([
            'delegator_id' => $first->getKey(),
            'delegate_id' => $second->getKey(),
            'own_marker' => $markers['own'],
            'peer_marker' => $markers['peer'],
        ] + $barrier),
        delegationRaceTask([
            'delegator_id' => $third->getKey(),
            'delegate_id' => $fourth->getKey(),
            'own_marker' => $markers['peer'],
            'peer_marker' => $markers['own'],
        ] + $barrier),
    ]);

    expect(collect($results)->where('barrier_met', true))->toHaveCount(2)
        ->and(collect($results)->where('success', true))->toHaveCount(1)
        ->and(collect($results)->firstWhere('success', false)['exception'])
        ->toBe(ValidationException::class)
        ->and(collect($results)->firstWhere('success', false)['message'])
        ->toContain('Ciclo de delegação detectado')
        ->and(ResponsibilityDelegation::query()->count())->toBe(3)
        ->and(delegationGraphHasCycleFrom($first->getKey()))->toBeFalse()
        ->and(delegationCreationsLogged())->toBe(3);
})->group('mysql');

/**
 * Uma conexão MySQL própria, com o nível de isolamento pedido, para observar o
 * lock de fora da conexão do teste. Passa pelo mesmo `isolation_level` da
 * configuração, então o caminho exercitado aqui é o mesmo que a aplicação usa.
 */
function delegationProbeConnection(string $name, string $isolationLevel): ConnectionInterface
{
    config(["database.connections.{$name}" => array_merge(
        config('database.connections.mysql'),
        ['isolation_level' => $isolationLevel],
    )]);

    DB::purge($name);

    return DB::connection($name);
}

/**
 * O intervalo vazio fica protegido neste nível de isolamento?
 *
 * Uma conexão roda a consulta de arestas da detecção de ciclo para um delegante
 * sem nenhuma delegação -- o caso que importa, porque é onde a transação
 * concorrente quer inserir. A outra tenta inserir exatamente ali, com um segundo
 * de paciência. Se o gap lock existe, a inserção espera e estoura em lock wait;
 * se não existe, ela passa.
 */
function delegationEmptyRangeIsLocked(string $isolationLevel, int $delegatorId, int $delegateId): bool
{
    $reader = delegationProbeConnection('p25_probe_reader', $isolationLevel);
    $writer = delegationProbeConnection('p25_probe_writer', $isolationLevel);
    $writer->statement('set session innodb_lock_wait_timeout = 1');

    $reader->beginTransaction();
    $reader->select(
        'select id from responsibility_delegations where revoked_at is null and delegator_user_id in (?) and starts_at <= ? and ends_at >= ? for update',
        [$delegatorId, '2026-12-31 00:00:00', '2020-01-01 00:00:00'],
    );

    $writer->beginTransaction();

    try {
        $writer->insert(
            'insert into responsibility_delegations (delegator_user_id, delegate_user_id, scope_type, starts_at, ends_at, reason, created_at, updated_at)'
                .' values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$delegatorId, $delegateId, 'global', '2026-09-01 00:00:00', '2026-09-30 00:00:00', 'sonda', now(), now()],
        );
        $blocked = false;
    } catch (QueryException) {
        $blocked = true;
    }

    $writer->rollBack();
    $reader->rollBack();
    DB::purge('p25_probe_reader');
    DB::purge('p25_probe_writer');

    return $blocked;
}

it('loses the gap lock that protects the cycle check under READ COMMITTED', function () {
    $first = delegationRaceParticipant();
    $second = delegationRaceParticipant();

    // A prova negativa da configuração: o mesmo intervalo vazio, a mesma
    // consulta, e a proteção existe num nível e some no outro. É por isso que
    // `isolation_level` está declarado na conexão e não herdado do servidor.
    expect(delegationEmptyRangeIsLocked('REPEATABLE READ', $first->getKey(), $second->getKey()))->toBeTrue()
        ->and(delegationEmptyRangeIsLocked('READ COMMITTED', $first->getKey(), $second->getKey()))->toBeFalse()
        ->and(ResponsibilityDelegation::query()->count())->toBe(0);
})->group('mysql');

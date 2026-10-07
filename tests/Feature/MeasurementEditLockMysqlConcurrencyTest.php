<?php

use App\Filament\Resources\Measurements\Pages\EditMeasurement;
use App\Models\Measurement;
use App\Models\MeasurementAsset;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\Operation;
use App\Models\User;
use App\Services\MeasurementWorkflow;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\CommittedRowsSweeper;
use Tests\Support\MeasurementPhysicalProgressScenario as Scenario;

/*
 * Editar Medição × aprovação da Engenharia da MESMA medição.
 *
 * O `EditRecord` do Filament 5.9 grava as relações dentro do `getState()`: o
 * Repeater de arquivos é gravado antes da própria medição. Sem lock nenhum no
 * começo da transação da página, a edição tirava a fotografia do REPEATABLE
 * READ na primeira leitura da validação, travava o arquivo no UPDATE e só no
 * fim pedia a medição -- o contrário da aprovação, que trava a Operation, a
 * medição e só depois os arquivos. Isso deixava dois defeitos:
 *
 * - fotografia velha: a aprovação inteira cabia entre a primeira leitura da
 *   edição e a gravação do arquivo. A guarda do arquivo lia a fotografia antiga
 *   ("Engenharia pendente") e trocava o arquivo que a Engenharia acabara de
 *   aprovar; o snapshot ficava com um arquivo que já não existia;
 * - deadlock: com observação e arquivo, a edição segurava o arquivo e pedia a
 *   medição, a aprovação segurava a medição e pedia o arquivo, e o MySQL matava
 *   uma das duas com o erro 1213, que chegava à pessoa como erro de servidor.
 *
 * Cada teste dirige a página real (Livewire) num processo próprio e a aprovação
 * real em outro, com marcadores em arquivo para fixar a ordem dos passos. Com a
 * Operation e a medição travadas como primeiras instruções da transação da
 * página, as duas se serializam na Operation: ou a aprovação espera e aprova o
 * arquivo novo, ou a edição espera e é recusada pelo fluxo. O SQLite serializa
 * os escritores e não mostra nada disso.
 */
beforeEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requer MySQL para validar locks concorrentes. Execute: ./vendor/bin/sail composer test:measurements:mysql');
    }

    $this->assertStringStartsWith(
        'nimbus_parity_check',
        DB::connection()->getDatabaseName(),
        'Estes testes recriam o banco. Use o banco temporário de composer test:measurements:mysql.',
    );

    Artisan::call('migrate:fresh', ['--no-interaction' => true]);
    $this->committedRows = CommittedRowsSweeper::afterFreshMigration();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Notification::fake();
});

afterEach(function () {
    if (DB::getDriverName() !== 'mysql' || ! isset($this->committedRows)) {
        return;
    }

    $this->committedRows->sweep();

    expect($this->committedRows->leftovers())->toBe([]);
});

/**
 * A página Editar Medição num processo próprio, que troca o arquivo do
 * empreendimento e a observação e clica em Salvar.
 *
 * Os discos `local` e `tmp-for-tests` (o dos uploads temporários do Livewire na
 * suíte) apontam para as raízes isoladas do processo pai: sem isso o Livewire
 * falsificaria um disco temporário compartilhado, apagando-o.
 *
 * `pause_after` escolhe onde a transação da página para: `first_statement` (a
 * primeira instrução dentro dela) ou o começo de uma instrução SQL. Ali a
 * edição cria `own_marker`, espera o processo concorrente avisar em
 * `ready_marker` (quando dado) e dá até `pause_ms` para `peer_marker` aparecer;
 * `peer_arrived` diz se apareceu. `settle_ms` deixa o concorrente chegar à
 * espera seguinte antes de a edição continuar.
 *
 * `mounted_marker`, `wait_for_marker` e `started_marker` servem ao caso em que a
 * aprovação começa antes: a página monta, avisa, espera a aprovação segurar os
 * locks e avisa que vai salvar.
 *
 * `transaction_statements` guarda as duas primeiras instruções da transação da
 * página e `operation_lock_wait_ms` quanto o lock da Operation esperou.
 *
 * @param  array<string, mixed>  $instruction
 */
function measurementEditLockEditTask(array $instruction): Closure
{
    return static function () use ($instruction): array {
        config()->set('filesystems.private_disk', 'local');
        config()->set('filesystems.disks.local.root', $instruction['storage_root']);
        config()->set('filesystems.disks.tmp-for-tests', ['driver' => 'local', 'root' => $instruction['upload_root'], 'throw' => false]);
        Storage::forgetDisk(['local', 'tmp-for-tests']);
        Notification::fake();

        $waitFor = static function (string $marker, float $seconds): bool {
            $deadline = microtime(true) + $seconds;

            while (! is_file($marker) && microtime(true) < $deadline) {
                usleep(10_000);
            }

            return is_file($marker);
        };
        $observed = ['transaction_statements' => [], 'operation_lock_wait_ms' => null, 'peer_arrived' => null];
        $paused = false;

        try {
            $actor = User::query()->findOrFail($instruction['actor_id']);
            Auth::setUser($actor);

            $page = Livewire::test(EditMeasurement::class, ['record' => $instruction['measurement_id']])
                ->fillForm([
                    'notes' => $instruction['notes'],
                    'assets' => ['record-'.$instruction['asset_id'] => [
                        'plan_set_id' => $instruction['plan_set_id'],
                        'plan_line_id' => $instruction['plan_line_id'],
                        'storage_path' => [UploadedFile::fake()->createWithContent($instruction['file_name'], $instruction['file_content'])],
                    ]],
                ]);

            if (isset($instruction['mounted_marker'])) {
                file_put_contents($instruction['mounted_marker'], 'mounted');
            }

            if (isset($instruction['wait_for_marker']) && ! $waitFor($instruction['wait_for_marker'], 30)) {
                throw new RuntimeException('A aprovação não chegou a segurar os locks.');
            }

            DB::listen(static function (QueryExecuted $query) use ($instruction, $waitFor, &$observed, &$paused): void {
                if (DB::transactionLevel() < 1) {
                    return;
                }

                $sql = strtolower($query->sql);

                if (count($observed['transaction_statements']) < 2) {
                    $observed['transaction_statements'][] = $sql;
                }

                if ($observed['operation_lock_wait_ms'] === null && str_contains($sql, 'for update') && str_contains($sql, '`operations`')) {
                    $observed['operation_lock_wait_ms'] = $query->time;
                }

                if ($paused || ! isset($instruction['pause_after'])
                    || (($instruction['pause_after'] !== 'first_statement') && ! str_starts_with($sql, $instruction['pause_after']))) {
                    return;
                }

                $paused = true;
                file_put_contents($instruction['own_marker'], 'paused');

                if (isset($instruction['ready_marker']) && ! $waitFor($instruction['ready_marker'], 30)) {
                    throw new RuntimeException('A aprovação não começou enquanto a edição estava parada.');
                }

                $observed['peer_arrived'] = $waitFor($instruction['peer_marker'], $instruction['pause_ms'] / 1000);

                if (isset($instruction['settle_ms'])) {
                    usleep($instruction['settle_ms'] * 1000);
                }
            });

            if (isset($instruction['started_marker'])) {
                file_put_contents($instruction['started_marker'], 'started');
            }

            $page->call('save');

            $notifications = array_values(session()->get('filament.claimed_notifications') ?? session()->get('filament.notifications') ?? []);

            return [
                'success' => true,
                'exception' => null,
                'message' => null,
                'errors' => $page->errors()->toArray(),
                'notifications' => array_map(
                    static fn (array $notification): array => ['title' => (string) ($notification['title'] ?? ''), 'body' => (string) ($notification['body'] ?? '')],
                    $notifications,
                ),
            ] + $observed;
        } catch (Throwable $exception) {
            return ['success' => false, 'exception' => $exception::class, 'message' => $exception->getMessage(), 'errors' => [], 'notifications' => []] + $observed;
        }
    };
}

/**
 * A aprovação da Engenharia num processo próprio.
 *
 * `wait_for_marker` segura o começo até a edição avisar; `ready_marker` avisa
 * que a aprovação vai começar e `done_marker`, que ela terminou. `signal_on`
 * cria `signal_marker` quando a aprovação obtém o lock dessa tabela. `hold_on`
 * para a aprovação logo depois do lock dessa tabela: ela cria `holding_marker`,
 * espera a edição avisar em `started_marker` que vai salvar e segura os locks
 * por mais `hold_ms`.
 *
 * @param  array<string, mixed>  $instruction
 */
function measurementEditLockApprovalTask(array $instruction): Closure
{
    return static function () use ($instruction): array {
        config()->set('filesystems.private_disk', 'local');
        config()->set('filesystems.disks.local.root', $instruction['storage_root']);
        Storage::forgetDisk('local');
        Notification::fake();

        $waitFor = static function (string $marker, float $seconds): bool {
            $deadline = microtime(true) + $seconds;

            while (! is_file($marker) && microtime(true) < $deadline) {
                usleep(10_000);
            }

            return is_file($marker);
        };
        $observed = ['operation_lock_wait_ms' => null];
        $holding = false;

        DB::listen(static function (QueryExecuted $query) use ($instruction, $waitFor, &$observed, &$holding): void {
            $sql = strtolower($query->sql);

            if (! str_contains($sql, 'for update')) {
                return;
            }

            if ($observed['operation_lock_wait_ms'] === null && str_contains($sql, '`operations`')) {
                $observed['operation_lock_wait_ms'] = $query->time;
            }

            if (isset($instruction['signal_on']) && str_contains($sql, '`'.$instruction['signal_on'].'`') && ! is_file($instruction['signal_marker'])) {
                file_put_contents($instruction['signal_marker'], 'locked');
            }

            if ($holding || ! isset($instruction['hold_on']) || ! str_contains($sql, '`'.$instruction['hold_on'].'`')) {
                return;
            }

            $holding = true;
            file_put_contents($instruction['holding_marker'], 'holding');

            if (! $waitFor($instruction['started_marker'], 30)) {
                throw new RuntimeException('A edição não começou a salvar enquanto a aprovação segurava os locks.');
            }

            usleep($instruction['hold_ms'] * 1000);
        });

        try {
            $actor = User::query()->findOrFail($instruction['actor_id']);
            $measurement = Measurement::query()->findOrFail($instruction['measurement_id']);

            if (isset($instruction['wait_for_marker']) && ! $waitFor($instruction['wait_for_marker'], 30)) {
                throw new RuntimeException('A edição não chegou ao ponto combinado.');
            }

            if (isset($instruction['ready_marker'])) {
                file_put_contents($instruction['ready_marker'], 'ready');
            }

            app(MeasurementWorkflow::class)->approve(
                $measurement,
                $actor,
                engineeringProgress: [$instruction['plan_set_id'] => $instruction['percent']],
                expectedStage: MeasurementWorkflow::STAGE_ENGINEERING,
                expectedRevision: $instruction['revision'],
            );

            if (isset($instruction['done_marker'])) {
                file_put_contents($instruction['done_marker'], 'approved');
            }

            return ['success' => true, 'exception' => null, 'message' => null] + $observed;
        } catch (Throwable $exception) {
            return ['success' => false, 'exception' => $exception::class, 'message' => $exception->getMessage()] + $observed;
        }
    };
}

/**
 * Maio enviado e aguardando a Engenharia, com o arquivo original no disco.
 *
 * @return array{actor: User, operation: Operation, planSet: MeasurementPlanSet, lines: array<string, MeasurementPlanLine>, may: Measurement, asset: MeasurementAsset}
 */
function measurementEditLockScenario(): array
{
    $scenario = Scenario::plan();
    $may = Scenario::measurement($scenario, '2026-05');

    return $scenario + ['may' => $may, 'asset' => $may->assets()->firstOrFail()];
}

/**
 * @param  array{actor: User, planSet: MeasurementPlanSet, may: Measurement, asset: MeasurementAsset}  $scenario
 * @return array<string, mixed>
 */
function measurementEditLockEdit(array $scenario, string $notes, string $fileName): array
{
    return [
        'actor_id' => $scenario['actor']->id,
        'measurement_id' => $scenario['may']->id,
        'asset_id' => $scenario['asset']->id,
        'plan_set_id' => $scenario['asset']->plan_set_id,
        'plan_line_id' => $scenario['asset']->plan_line_id,
        'notes' => $notes,
        'file_name' => $fileName,
        'file_content' => "%PDF-1.7 arquivo trocado na edição: {$fileName}",
        'storage_root' => Storage::disk('local')->path(''),
        'upload_root' => Storage::disk('tmp-for-tests')->path(''),
    ];
}

/**
 * @param  array{actor: User, planSet: MeasurementPlanSet, may: Measurement}  $scenario
 * @return array<string, mixed>
 */
function measurementEditLockApproval(array $scenario): array
{
    return [
        'actor_id' => $scenario['actor']->id,
        'measurement_id' => $scenario['may']->id,
        'revision' => (int) $scenario['may']->fresh()->workflow_revision,
        'plan_set_id' => $scenario['planSet']->id,
        'percent' => 10,
        'storage_root' => Storage::disk('local')->path(''),
    ];
}

/**
 * @param  list<string>  $names
 * @return array<string, string>
 */
function measurementEditLockMarkers(string $prefix, array $names): array
{
    $markers = [];

    foreach ($names as $name) {
        $markers[$name] = temporaryTestFilePath("{$prefix}-{$name}", 'lock');
        @unlink($markers[$name]);
    }

    return $markers;
}

/**
 * @param  array<string, string>  $markers
 */
function measurementEditLockForgetMarkers(array $markers): void
{
    foreach ($markers as $marker) {
        @unlink($marker);
    }
}

/**
 * O que a Engenharia aprovou para o empreendimento: o arquivo do snapshot.
 *
 * @return array{storage_path: mixed, sha256: mixed}
 */
function measurementEditLockApprovedFile(Measurement $measurement): array
{
    $planSet = $measurement->fresh()->engineering_snapshot['plan_sets'][0] ?? [];

    return ['storage_path' => $planSet['storage_path'] ?? null, 'sha256' => $planSet['sha256'] ?? null];
}

it('makes the Engineering approval wait for an edit already in progress instead of approving under its stale snapshot on MySQL', function () {
    $scenario = measurementEditLockScenario();
    $markers = measurementEditLockMarkers('edit-lock-stale-snapshot', ['edit', 'approval']);

    // A edição para logo na primeira instrução da transação da página e dá
    // três segundos para a aprovação inteira acontecer ali no meio.
    $results = Concurrency::driver('process')->run([
        measurementEditLockEditTask(measurementEditLockEdit($scenario, 'Observação gravada pela edição.', 'edicao-fotografia.pdf') + [
            'pause_after' => 'first_statement',
            'own_marker' => $markers['edit'],
            'peer_marker' => $markers['approval'],
            'pause_ms' => 3000,
        ]),
        measurementEditLockApprovalTask(measurementEditLockApproval($scenario) + [
            'wait_for_marker' => $markers['edit'],
            'done_marker' => $markers['approval'],
        ]),
    ]);
    measurementEditLockForgetMarkers($markers);

    $may = $scenario['may']->fresh();
    $asset = $scenario['asset']->fresh();

    expect(collect($results)->pluck('message')->filter()->values()->all())->toBe([])
        ->and(collect($results)->pluck('success')->all())->toBe([true, true])
        ->and(measurementEditLockApprovedFile($may))->toBe($asset->only(['storage_path', 'sha256']))
        ->and($asset->storage_path)->toEndWith('.pdf')->not->toBe($scenario['asset']->storage_path)
        ->and($results[0]['peer_arrived'])->toBeFalse()
        ->and($results[0]['notifications'])->toBe([['title' => 'Medição atualizada com sucesso.', 'body' => '']])
        ->and($results[0]['transaction_statements'][0])->toStartWith('select * from `operations` where `operations`.`id` = ? limit 1 for update')
        ->and($results[0]['transaction_statements'][1])->toStartWith('select * from `measurements` where `measurements`.`id` = ? limit 1 for update')
        ->and($results[1]['operation_lock_wait_ms'])->toBeGreaterThan(1000)
        ->and($may->reviewForStage(MeasurementWorkflow::STAGE_ENGINEERING)?->status)->toBe('approved')
        ->and($may->notes)->toBe('Observação gravada pela edição.');
})->group('mysql');

it('serializes an edit of notes and file with the Engineering approval of the same measurement instead of deadlocking on MySQL', function () {
    $scenario = measurementEditLockScenario();
    $markers = measurementEditLockMarkers('edit-lock-deadlock', ['edit', 'ready', 'approval']);

    // A edição para logo depois de travar o arquivo no UPDATE e dá à aprovação
    // a chance de travar a medição: com a ordem antiga, é o ciclo de 2.
    $results = Concurrency::driver('process')->run([
        measurementEditLockEditTask(measurementEditLockEdit($scenario, 'Observação editada junto com o arquivo.', 'edicao-deadlock.pdf') + [
            'pause_after' => 'update `measurement_assets`',
            'own_marker' => $markers['edit'],
            'ready_marker' => $markers['ready'],
            'peer_marker' => $markers['approval'],
            'pause_ms' => 3000,
            'settle_ms' => 300,
        ]),
        measurementEditLockApprovalTask(measurementEditLockApproval($scenario) + [
            'wait_for_marker' => $markers['edit'],
            'ready_marker' => $markers['ready'],
            'signal_on' => 'measurements',
            'signal_marker' => $markers['approval'],
        ]),
    ]);
    measurementEditLockForgetMarkers($markers);

    $may = $scenario['may']->fresh();
    $asset = $scenario['asset']->fresh();

    expect(collect($results)->pluck('message')->filter()->values()->all())->toBe([])
        ->and(collect($results)->pluck('success')->all())->toBe([true, true])
        ->and(measurementEditLockApprovedFile($may))->toBe($asset->only(['storage_path', 'sha256']))
        ->and($asset->storage_path)->not->toBe($scenario['asset']->storage_path)
        ->and($results[0]['peer_arrived'])->toBeFalse()
        ->and($results[0]['notifications'])->toBe([['title' => 'Medição atualizada com sucesso.', 'body' => '']])
        ->and($results[1]['operation_lock_wait_ms'])->toBeGreaterThan(1000)
        ->and($may->reviewForStage(MeasurementWorkflow::STAGE_ENGINEERING)?->status)->toBe('approved')
        ->and($may->notes)->toBe('Observação editada junto com o arquivo.');
})->group('mysql');

it('refuses an edit that waited for the Engineering approval of the same measurement on MySQL', function () {
    $scenario = measurementEditLockScenario();
    $original = $scenario['asset']->only(['storage_path', 'sha256']);
    $originalNotes = $scenario['may']->notes;
    $markers = measurementEditLockMarkers('edit-lock-refusal', ['mounted', 'holding', 'started']);

    // A página já está aberta quando a aprovação trava a Operation, a medição e
    // os arquivos; a edição clica em Salvar com a aprovação ainda em curso.
    $results = Concurrency::driver('process')->run([
        measurementEditLockEditTask(measurementEditLockEdit($scenario, 'Observação que não deve ser gravada.', 'edicao-recusada.pdf') + [
            'mounted_marker' => $markers['mounted'],
            'wait_for_marker' => $markers['holding'],
            'started_marker' => $markers['started'],
        ]),
        measurementEditLockApprovalTask(measurementEditLockApproval($scenario) + [
            'wait_for_marker' => $markers['mounted'],
            'hold_on' => 'measurement_assets',
            'holding_marker' => $markers['holding'],
            'started_marker' => $markers['started'],
            'hold_ms' => 2500,
        ]),
    ]);
    measurementEditLockForgetMarkers($markers);

    $may = $scenario['may']->fresh();
    $asset = $scenario['asset']->fresh();

    expect(collect($results)->pluck('message')->filter()->values()->all())->toBe([])
        ->and(collect($results)->pluck('success')->all())->toBe([true, true])
        ->and($asset->only(['storage_path', 'sha256']))->toBe($original)
        ->and(measurementEditLockApprovedFile($may))->toBe($original)
        ->and($results[0]['notifications'])->toBe([[
            'title' => 'Medição não atualizada.',
            'body' => 'A Engenharia aprovou esta medição enquanto você editava. Atualize a página.',
        ]])
        ->and($results[0]['errors'])->toBe([])
        ->and($results[0]['operation_lock_wait_ms'])->toBeGreaterThan(1000)
        ->and($may->notes)->toBe($originalNotes)
        ->and($may->reviewForStage(MeasurementWorkflow::STAGE_ENGINEERING)?->status)->toBe('approved')
        ->and(Storage::disk('local')->allFiles('nimbus_docs/measurements/assets'))->toBe([$original['storage_path']]);
})->group('mysql');

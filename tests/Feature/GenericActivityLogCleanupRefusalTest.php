<?php

use App\Exceptions\ActivityLogCleanupRefusedException;
use App\Models\PuHistory;
use App\Support\ActivityLog\RefuseGenericActivityLogCleanup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Spatie\Activitylog\Commands\CleanActivitylogCommand;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Support\Config as ActivitylogConfig;

/**
 * `activitylog:clean`, do pacote, continua registrado, mas não apaga nada: a
 * limpeza da trilha é só a de `audit:clean-filtered`, coberta em
 * AuditRetentionConfigurationTest.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    // A idade das linhas é medida em segundos inteiros, como o banco guarda.
    $this->travelTo(now()->startOfSecond());
});

function genericCleanupRow(string $logName, int $ageInDays, ?string $event = null, ?string $subjectType = null): Activity
{
    $createdAt = now()->subDays($ageInDays);

    return Activity::query()->create([
        'log_name' => $logName,
        'description' => 'registro de '.$logName,
        'event' => $event,
        'subject_type' => $subjectType,
        'properties' => json_encode([]),
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
}

/**
 * Uma linha de cada classificação, todas com 400 dias -- que a limpeza
 * genérica apagaria com `--days=1` -- e uma de hoje.
 *
 * @return array<string, Activity>
 */
function genericCleanupRows(): array
{
    return [
        'measurement_decision' => genericCleanupRow('measurement_workflow', 400),
        'measurement_payment' => genericCleanupRow('measurement_payments', 400),
        'pu_homologation' => genericCleanupRow('pu-calculation', 400, event: 'homologated'),
        'pu_financial_input' => genericCleanupRow('default', 400, subjectType: PuHistory::class),
        'disposable' => genericCleanupRow('default', 400),
        'today' => genericCleanupRow('measurement_workflow', 0),
    ];
}

/**
 * @param  array<string, Activity>  $rows
 * @return list<string>
 */
function genericCleanupSurvivors(array $rows): array
{
    return collect($rows)
        ->filter(fn (Activity $row): bool => Activity::query()->whereKey($row->getKey())->exists())
        ->keys()
        ->all();
}

it('refuses activitylog:clean before deleting any row, whatever the age, the trail or the force', function (array $parameters, int $days, ?string $log) {
    $rows = genericCleanupRows();

    try {
        $this->artisan('activitylog:clean', $parameters)->run();
        $refusal = null;
    } catch (ActivityLogCleanupRefusedException $exception) {
        $refusal = $exception;
    }

    // O que foi pedido chega à recusa, para o log guardar a tentativa.
    expect($refusal?->getMessage())->toBe(ActivityLogCleanupRefusedException::MESSAGE)
        ->and($refusal?->context())->toBe(['max_age_in_days' => $days, 'log_name' => $log])
        ->and(genericCleanupSurvivors($rows))->toBe(array_keys($rows));
})->with([
    'package default age' => [[], 365, null],
    'one day, every trail' => [['--days' => 1], 1, null],
    'forced, one day' => [['--days' => 1, '--force' => true], 1, null],
    'forced on the measurement trail' => [['log' => 'measurement_workflow', '--days' => 30, '--force' => true], 30, 'measurement_workflow'],
    'forced on the PU trail' => [['log' => 'pu-calculation', '--days' => 30, '--force' => true], 30, 'pu-calculation'],
    'forced on the disposable trail' => [['log' => 'default', '--days' => 366, '--force' => true], 366, 'default'],
]);

it('refuses the forced cleanup in production, where the confirmation is skipped', function () {
    $rows = genericCleanupRows();
    $this->app['env'] = 'production';

    expect(fn () => $this->artisan('activitylog:clean', ['--days' => 1, '--force' => true])->run())
        ->toThrow(ActivityLogCleanupRefusedException::class);

    expect(genericCleanupSurvivors($rows))->toBe(array_keys($rows));
});

it('refuses the package cleanup action itself, for any caller that resolves it', function () {
    $rows = genericCleanupRows();

    $action = ActivitylogConfig::cleanActivityLogAction();

    expect($action)->toBeInstanceOf(RefuseGenericActivityLogCleanup::class)
        ->and(fn () => $action->execute(1, 'measurement_workflow'))->toThrow(ActivityLogCleanupRefusedException::class)
        ->and(genericCleanupSurvivors($rows))->toBe(array_keys($rows));
});

it('refuses the deletion itself, for a package version that reaches it by another method', function () {
    $rows = genericCleanupRows();
    $action = new class extends RefuseGenericActivityLogCleanup
    {
        public function deleteOlderThan(string $cutOffDate): int
        {
            return $this->deleteOldActivities($cutOffDate, null);
        }
    };

    expect(fn () => $action->deleteOlderThan(now()->toDateTimeString()))->toThrow(ActivityLogCleanupRefusedException::class)
        ->and(genericCleanupSurvivors($rows))->toBe(array_keys($rows));
});

it('keeps activitylog:clean as the package command, so the invocations above go through the package path', function () {
    $command = Artisan::all()['activitylog:clean'] ?? null;

    expect($command === null ? null : $command::class)->toBe(CleanActivitylogCommand::class);
});

it('leaves the supported cleanup working after a refused generic one', function () {
    $rows = genericCleanupRows();

    expect(fn () => $this->artisan('activitylog:clean', ['--days' => 1, '--force' => true])->run())
        ->toThrow(ActivityLogCleanupRefusedException::class);

    $this->artisan('audit:clean-filtered')
        ->expectsOutputToContain('deleted_disposable=1, deleted_protected_expired=0')
        ->assertSuccessful();

    expect(genericCleanupSurvivors($rows))->toBe(['measurement_decision', 'measurement_payment', 'pu_homologation', 'pu_financial_input', 'today']);
});

it('tells the operator what was asked and where the supported cleanup is', function () {
    $refusal = new ActivityLogCleanupRefusedException(30, 'measurement_workflow');

    expect($refusal->getMessage())
        ->toContain('Nenhum registro foi apagado')
        ->toContain('audit:clean-filtered')
        ->and($refusal->context())->toBe(['max_age_in_days' => 30, 'log_name' => 'measurement_workflow']);
});

it('keeps the refusal through the real config cache, before the first query', function () {
    // Processos à parte, como em produção: o `config:cache` de verdade e o
    // comando lendo só o cache. O banco é um SQLite em memória, sem tabelas:
    // se a recusa regredisse, a limpeza falharia em `activity_log`, e não com
    // a recusa -- e nunca alcançaria o banco de desenvolvimento do `.env`.
    $cache = temporaryTestFilePath('activitylog-clean-config-cache', 'php');
    $artisan = fn (string ...$command) => Process::path(base_path())
        ->env([
            'APP_ENV' => 'testing',
            'APP_CONFIG_CACHE' => $cache,
            'LOG_CHANNEL' => 'null',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'DB_URL' => false,
            // Sem quebra de linha na mensagem renderizada.
            'COLUMNS' => '2000',
        ])
        ->run([PHP_BINARY, 'artisan', ...$command]);

    try {
        $cached = $artisan('config:cache');

        expect($cached->successful())->toBeTrue($cached->output().$cached->errorOutput())
            ->and((require $cache)['activitylog']['actions']['clean_log'])->toBe(RefuseGenericActivityLogCleanup::class)
            ->and((require $cache)['database']['default'])->toBe('sqlite')
            ->and((require $cache)['database']['connections']['sqlite']['database'])->toBe(':memory:');

        $cleanup = $artisan('activitylog:clean', '--force', '--days=1');

        expect($cleanup->exitCode())->toBe(1)
            ->and($cleanup->output().$cleanup->errorOutput())->toContain('activitylog:clean está desativado nesta aplicação')
            ->and($cleanup->output().$cleanup->errorOutput())->not->toContain('no such table');
    } finally {
        if (is_file($cache)) {
            unlink($cache);
        }
    }
});

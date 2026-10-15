<?php

use App\Exceptions\AuditRetentionConfigurationException;
use App\Support\ActivityLog\AuditRetentionPolicy;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\SalesBoards\AutomationConfigFixture;

uses(RefreshDatabase::class);

beforeEach(function () {
    // A idade das linhas é medida em segundos inteiros, como o banco guarda.
    $this->travelTo(now()->startOfSecond());
});

/**
 * O `config/audit.php` de verdade, reavaliado com as variáveis indicadas
 * (nula = ausente) -- `config()->set()` só provaria que o repositório de
 * configuração guarda o que recebeu.
 *
 * @param  array<string, string|null>  $env
 * @return array<string, mixed>
 */
function auditRetentionConfigUnder(array $env): array
{
    return AutomationConfigFixture::withEnv($env, fn (): array => require config_path('audit.php'));
}

/**
 * A configuração de auditoria em vigor passa a ser a do arquivo avaliado com o
 * ambiente indicado.
 *
 * @param  array<string, string|null>  $env
 */
function auditRetentionUseEnv(array $env): void
{
    config()->set('audit', auditRetentionConfigUnder($env));
}

function auditRetentionRow(string $logName, string $description, int $ageInDays, int $extraSeconds = 0, ?string $event = null): Activity
{
    $createdAt = now()->subDays($ageInDays)->subSeconds($extraSeconds);

    return Activity::query()->create([
        'log_name' => $logName,
        'description' => $description,
        'event' => $event,
        'properties' => json_encode([]),
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
}

/**
 * Linhas que a limpeza apagaria com a política padrão -- a protegida além de
 * sete anos e a descartável além de um ano -- e uma recente de cada.
 *
 * @return array<string, Activity>
 */
function auditRetentionRows(): array
{
    return [
        'protected_expired' => auditRetentionRow('measurement_workflow', 'evidência protegida expirada', 2555 + 30),
        'protected_old' => auditRetentionRow('measurement_workflow', 'evidência protegida dentro do prazo', 365 + 30),
        'disposable_expired' => auditRetentionRow('default', 'registro descartável expirado', 365 + 30),
        'recent' => auditRetentionRow('measurement_workflow', 'evidência recente', 1),
    ];
}

/**
 * @param  array<string, Activity>  $rows
 */
function auditRetentionSurvivors(array $rows): array
{
    return collect($rows)
        ->filter(fn (Activity $row): bool => Activity::query()->whereKey($row->getKey())->exists())
        ->keys()
        ->all();
}

dataset('unreadable retention values', [
    'empty' => [''],
    'zero' => ['0'],
    'null literal' => ['null'],
    'text' => ['abc'],
    'unit' => ['7 years'],
    'negative' => ['-1'],
    'decimal point' => ['2.555'],
    'decimal comma' => ['2555,0'],
    'exponent' => ['1e3'],
    'leading zero' => ['0365'],
    'leading space' => [' 2555'],
    'plus sign' => ['+2555'],
    'boolean literal' => ['true'],
    'below the approved minimum' => ['364'],
    'above the technical maximum' => ['36501'],
    'integer overflow' => ['99999999999999999999'],
]);

// ── Configuração e cache ─────────────────────────────────────────────────────

it('reads the retention defaults when the variables are absent', function () {
    $config = auditRetentionConfigUnder([
        'AUDIT_RETENTION_DISPOSABLE_DAYS' => null,
        'AUDIT_RETENTION_WORKFLOW_DAYS' => null,
    ]);
    $policy = AuditRetentionPolicy::fromValues($config['retention_disposable_days'], $config['retention_workflow_days']);

    expect($policy->disposableDays)->toBe(365)
        ->and($policy->protectedDays)->toBe(2555);
});

it('accepts plain day counts written in the environment', function () {
    $config = auditRetentionConfigUnder([
        'AUDIT_RETENTION_DISPOSABLE_DAYS' => '400',
        'AUDIT_RETENTION_WORKFLOW_DAYS' => '3000',
    ]);
    $policy = AuditRetentionPolicy::fromValues($config['retention_disposable_days'], $config['retention_workflow_days']);

    expect($policy->disposableDays)->toBe(400)
        ->and($policy->protectedDays)->toBe(3000);
});

it('evaluates the configuration without failing and keeps the refused value through the config cache', function (string $raw) {
    // A aplicação sobe com o valor ilegível: só a limpeza o recusa.
    $config = auditRetentionConfigUnder([
        'AUDIT_RETENTION_DISPOSABLE_DAYS' => null,
        'AUDIT_RETENTION_WORKFLOW_DAYS' => $raw,
    ]);

    /**
     * A mesma serialização do `config:cache`: o arquivo em cache guarda o valor
     * recusado, e não um número inventado no lugar dele.
     */
    $path = temporaryTestFilePath('audit-retention-config-cache', 'php');
    file_put_contents($path, '<?php return '.var_export($config, true).';'.PHP_EOL);
    $cached = require $path;
    unlink($path);

    expect($cached['retention_workflow_days'])->toBe($config['retention_workflow_days'])
        ->and($cached['retention_disposable_days'])->toBe(365)
        ->and(fn () => AuditRetentionPolicy::fromValues($cached['retention_disposable_days'], $cached['retention_workflow_days']))
        ->toThrow(AuditRetentionConfigurationException::class);
})->with('unreadable retention values');

it('leaves every protected classification untouched whatever the retention variables hold', function () {
    $default = auditRetentionConfigUnder([
        'AUDIT_RETENTION_DISPOSABLE_DAYS' => null,
        'AUDIT_RETENTION_WORKFLOW_DAYS' => null,
    ]);
    $unreadable = auditRetentionConfigUnder([
        'AUDIT_RETENTION_DISPOSABLE_DAYS' => 'abc',
        'AUDIT_RETENTION_WORKFLOW_DAYS' => '-1',
    ]);
    $classifications = ['protected_logs', 'protected_events', 'disposable_events', 'protected_subject_types'];

    // As categorias protegidas de antes da Fase 4A continuam todas na lista --
    // a da medição, a do PU e as dos demais módulos.
    expect(collect($unreadable)->only($classifications)->all())->toBe(collect($default)->only($classifications)->all())
        ->and($default['protected_logs'])->toContain(
            'measurement_financial_rules', 'measurement_evidence', 'measurement_workflow', 'measurement_file_access',
            'measurements', 'measurement_payments', 'operations', 'delegations', 'areas', 'nimbus', 'sales_board',
            'contracts', 'contract_installments', 'construction_units', 'construction_unit_exchanges',
            'construction_unit_retirements', 'constructions', 'emissions', 'guarantee_competences', 'guarantees',
            'measurement_receipts', 'delegation', 'pu-baseline-evidence', 'business_calendars',
        )
        ->and($default['protected_events']['pu-calculation'])->toContain('homologated', 'settlement_recorded', 'operations_activated');
});

// ── Limpeza que falha fechada ────────────────────────────────────────────────

it('aborts the whole cleanup without deleting any row when the protected retention is unreadable', function (string $raw) {
    $rows = auditRetentionRows();
    auditRetentionUseEnv(['AUDIT_RETENTION_DISPOSABLE_DAYS' => null, 'AUDIT_RETENTION_WORKFLOW_DAYS' => $raw]);

    $this->artisan('audit:clean-filtered')
        ->expectsOutputToContain('a limpeza foi abortada e nenhum registro foi apagado')
        ->expectsOutputToContain('AUDIT_RETENTION_WORKFLOW_DAYS')
        ->assertFailed();

    // Nem a protegida, nem a descartável: a limpeza não roda em parte.
    expect(auditRetentionSurvivors($rows))->toBe(array_keys($rows));
})->with('unreadable retention values');

it('aborts the whole cleanup without deleting any row when the disposable retention is unreadable', function (string $raw) {
    $rows = auditRetentionRows();
    auditRetentionUseEnv(['AUDIT_RETENTION_DISPOSABLE_DAYS' => $raw, 'AUDIT_RETENTION_WORKFLOW_DAYS' => null]);

    $this->artisan('audit:clean-filtered')
        ->expectsOutputToContain('a limpeza foi abortada e nenhum registro foi apagado')
        ->expectsOutputToContain('AUDIT_RETENTION_DISPOSABLE_DAYS')
        ->assertFailed();

    expect(auditRetentionSurvivors($rows))->toBe(array_keys($rows));
})->with('unreadable retention values');

it('refuses a protected retention shorter than the disposable one', function () {
    $rows = auditRetentionRows();
    auditRetentionUseEnv(['AUDIT_RETENTION_DISPOSABLE_DAYS' => '500', 'AUDIT_RETENTION_WORKFLOW_DAYS' => '400']);

    $this->artisan('audit:clean-filtered')
        ->expectsOutputToContain('A retenção protegida (400 dias) é menor que a descartável (500 dias)')
        ->assertFailed();

    expect(auditRetentionSurvivors($rows))->toBe(array_keys($rows));
});

it('also refuses the dry run, which is the pre-deploy check', function () {
    $rows = auditRetentionRows();
    auditRetentionUseEnv(['AUDIT_RETENTION_DISPOSABLE_DAYS' => null, 'AUDIT_RETENTION_WORKFLOW_DAYS' => '']);

    $this->artisan('audit:clean-filtered --dry-run')
        ->expectsOutputToContain('a limpeza foi abortada e nenhum registro foi apagado')
        ->doesntExpectOutputToContain('Dry-run:')
        ->assertFailed();

    expect(auditRetentionSurvivors($rows))->toBe(array_keys($rows));
});

it('refuses unreadable values set at runtime too, as a second line of defence', function (mixed $value) {
    $rows = auditRetentionRows();
    config()->set('audit.retention_workflow_days', $value);

    $this->artisan('audit:clean-filtered')->assertFailed();

    expect(auditRetentionSurvivors($rows))->toBe(array_keys($rows));
})->with([
    'zero int' => [0],
    'negative int' => [-1],
    'float' => [2555.0],
    'boolean' => [true],
    'null' => [null],
    'array' => [[2555]],
    'below the minimum' => [364],
]);

it('makes the refusal observable in the application log, since the scheduler discards the command output', function () {
    Log::spy();
    auditRetentionUseEnv(['AUDIT_RETENTION_DISPOSABLE_DAYS' => null, 'AUDIT_RETENTION_WORKFLOW_DAYS' => 'abc']);

    $this->artisan('audit:clean-filtered')->assertFailed();

    Log::shouldHaveReceived('critical')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'nenhum registro foi apagado')
            && count($context['problems']) === 1
            && str_contains($context['problems'][0], 'AUDIT_RETENTION_WORKFLOW_DAYS'));
});

// ── Política válida ──────────────────────────────────────────────────────────

it('applies each approved cutoff to its own classification, with the exact boundary kept', function () {
    auditRetentionUseEnv(['AUDIT_RETENTION_DISPOSABLE_DAYS' => '400', 'AUDIT_RETENTION_WORKFLOW_DAYS' => '3000']);

    $rows = [
        'disposable_at_cutoff' => auditRetentionRow('default', 'descartável no corte', 400),
        'disposable_past_cutoff' => auditRetentionRow('default', 'descartável além do corte', 400, 1),
        'protected_past_disposable_cutoff' => auditRetentionRow('measurement_workflow', 'protegida além do corte descartável', 400, 1),
        'protected_at_cutoff' => auditRetentionRow('measurement_workflow', 'protegida no corte', 3000),
        'protected_past_cutoff' => auditRetentionRow('measurement_workflow', 'protegida além do corte', 3000, 1),
        // Evento protegido numa trilha descartável e evento descartável dela.
        'pu_protected_event' => auditRetentionRow('pu-calculation', 'homologação', 400, 1, 'homologated'),
        'pu_disposable_event' => auditRetentionRow('pu-calculation', 'exportação', 400, 1, 'exported'),
    ];

    $this->artisan('audit:clean-filtered')
        ->expectsOutputToContain('deleted_disposable=2, deleted_protected_expired=1')
        ->assertSuccessful();

    expect(auditRetentionSurvivors($rows))->toBe([
        'disposable_at_cutoff',
        'protected_past_disposable_cutoff',
        'protected_at_cutoff',
        'pu_protected_event',
    ]);
});

// ── Integração com a aplicação ───────────────────────────────────────────────

it('keeps the cleanup scheduled daily at 04:00 and the schedule readable with an unreadable retention', function () {
    auditRetentionUseEnv(['AUDIT_RETENTION_DISPOSABLE_DAYS' => null, 'AUDIT_RETENTION_WORKFLOW_DAYS' => 'abc']);

    $cleanup = collect(app(Schedule::class)->events())
        ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'audit:clean-filtered'));

    expect($cleanup)->toHaveCount(1)
        ->and($cleanup->first()->expression)->toBe('0 4 * * *')
        ->and($cleanup->first()->description)->toBe('audit-log-cleanup-filtered');

    $this->artisan('schedule:list')->assertSuccessful();
});

it('boots, caches the configuration and lists the schedule with an unreadable retention, and the real cached cleanup refuses', function () {
    // Processos à parte, como em produção: o `config:cache` de verdade, a
    // limpeza lendo só o cache -- a variável já não existe -- e o scheduler.
    // Nenhum deles chega a um banco: a limpeza recusa antes da primeira
    // consulta.
    $cache = temporaryTestFilePath('audit-retention-real-config-cache', 'php');
    // O banco dos processos é sempre um SQLite em memória, dito aqui e não
    // herdado: se a recusa regredisse, a limpeza não alcançaria o banco de
    // desenvolvimento do `.env`.
    $artisan = fn (array $env, string ...$command) => Process::path(base_path())
        ->env([
            'APP_ENV' => 'testing',
            'APP_CONFIG_CACHE' => $cache,
            'LOG_CHANNEL' => 'null',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'DB_URL' => false,
            ...$env,
        ])
        ->run([PHP_BINARY, 'artisan', ...$command]);

    try {
        $cached = $artisan(['AUDIT_RETENTION_WORKFLOW_DAYS' => 'abc', 'AUDIT_RETENTION_DISPOSABLE_DAYS' => false], 'config:cache');

        expect($cached->successful())->toBeTrue($cached->output().$cached->errorOutput())
            ->and((require $cache)['audit']['retention_workflow_days'])->toBe('abc')
            ->and((require $cache)['database']['default'])->toBe('sqlite')
            ->and((require $cache)['database']['connections']['sqlite']['database'])->toBe(':memory:');

        $cleanup = $artisan(['AUDIT_RETENTION_WORKFLOW_DAYS' => false, 'AUDIT_RETENTION_DISPOSABLE_DAYS' => false], 'audit:clean-filtered');

        expect($cleanup->exitCode())->toBe(1)
            ->and($cleanup->output().$cleanup->errorOutput())->toContain('a limpeza foi abortada e nenhum registro foi apagado')
            ->and($cleanup->output().$cleanup->errorOutput())->toContain('"abc"');

        $schedule = $artisan(['AUDIT_RETENTION_WORKFLOW_DAYS' => false, 'AUDIT_RETENTION_DISPOSABLE_DAYS' => false], 'schedule:list');

        expect($schedule->successful())->toBeTrue($schedule->output().$schedule->errorOutput())
            ->and($schedule->output())->toContain('audit:clean-filtered');
    } finally {
        if (is_file($cache)) {
            unlink($cache);
        }
    }
});

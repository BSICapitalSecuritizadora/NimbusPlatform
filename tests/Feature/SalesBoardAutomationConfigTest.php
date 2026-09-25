<?php

use App\Support\SalesBoards\SalesBoardAutomationConfig;
use Tests\Support\SalesBoards\AutomationConfigFixture;

/**
 * O interruptor da automação é mecanismo de segurança, não preferência.
 *
 * `(bool) env(...)` transforma qualquer texto não vazio em `true`: `off`, `no` e
 * um erro de digitação ligariam a automação em silêncio. Aqui toda leitura falha
 * fechada -- o que não for inequivocamente "ligado" é desligado, e o limiar que
 * não for um inteiro não negativo é limiar ausente, nunca "avisar agora".
 */

/**
 * Executa o callback com variáveis de ambiente trocadas, restaurando-as depois.
 *
 * @param  array<string, string|null>  $values
 */
function withSalesBoardAutomationEnv(array $values, Closure $callback): mixed
{
    return AutomationConfigFixture::withEnv($values, $callback);
}

/**
 * O arquivo de configuração avaliado com o ambiente indicado.
 *
 * @param  array<string, string|null>  $env
 * @return array<string, mixed>
 */
function salesBoardConfigUnder(array $env): array
{
    return AutomationConfigFixture::under($env);
}

/**
 * Os sete limiares de lembrete e a variável de ambiente de cada um.
 *
 * @return array<string, string>
 */
function salesBoardReminderEnvKeys(): array
{
    return [
        'blocked_after_days' => 'SALES_BOARD_AUTOMATION_BLOCKED_REMINDER_DAYS',
        'failed_after_attempts' => 'SALES_BOARD_AUTOMATION_FAILED_ESCALATION_ATTEMPTS',
        'ready_for_builder_after_days' => 'SALES_BOARD_AUTOMATION_READY_REMINDER_DAYS',
        'builder_review_after_days' => 'SALES_BOARD_AUTOMATION_BUILDER_REMINDER_DAYS',
        'builder_review_escalation_after_days' => 'SALES_BOARD_AUTOMATION_BUILDER_ESCALATION_DAYS',
        'management_review_after_days' => 'SALES_BOARD_AUTOMATION_MANAGEMENT_REMINDER_DAYS',
        'management_review_escalation_after_days' => 'SALES_BOARD_AUTOMATION_MANAGEMENT_ESCALATION_DAYS',
    ];
}

it('reads the automation flag from the environment as a real boolean', function (?string $raw, bool $expected) {
    $enabled = salesBoardConfigUnder(['SALES_BOARD_AUTOMATION_ENABLED' => $raw])['automation']['enabled'];

    expect($enabled)->toBeBool()->toBe($expected);
})->with([
    'unset' => [null, false],
    'empty' => ['', false],
    'zero' => ['0', false],
    'false' => ['false', false],
    'FALSE' => ['FALSE', false],
    'no' => ['no', false],
    'NO' => ['NO', false],
    'off' => ['off', false],
    'OFF' => ['OFF', false],
    'null literal' => ['null', false],
    'blank' => ['   ', false],
    'invalid word' => ['banana', false],
    'enabled word' => ['enabled', false],
    'two' => ['2', false],
    'one' => ['1', true],
    'true' => ['true', true],
    'TRUE' => ['TRUE', true],
    'yes' => ['yes', true],
    'YES' => ['YES', true],
    'on' => ['on', true],
    'ON' => ['ON', true],
    'padded yes' => [' yes ', true],
]);

it('parses any flag value fail closed', function (mixed $raw, bool $expected) {
    expect(SalesBoardAutomationConfig::flag($raw))->toBeBool()->toBe($expected);
})->with([
    'null' => [null, false],
    'int zero' => [0, false],
    'bool false' => [false, false],
    'int two' => [2, false],
    'negative' => [-1, false],
    'float one' => [1.0, false],
    'array' => [['true'], false],
    'invalid string' => ['banana', false],
    'int one' => [1, true],
    'bool true' => [true, true],
    'string on' => ['On', true],
]);

it('reads every reminder threshold as a non negative integer or null', function (?string $raw, ?int $expected) {
    $reminders = salesBoardConfigUnder(array_fill_keys(array_values(salesBoardReminderEnvKeys()), $raw))['automation']['reminders'];

    expect(array_keys($reminders))->toBe(array_keys(salesBoardReminderEnvKeys()));

    foreach ($reminders as $value) {
        $expected === null
            ? expect($value)->toBeNull()
            : expect($value)->toBeInt()->toBe($expected);
    }
})->with([
    'unset' => [null, null],
    'empty' => ['', null],
    'blank' => ['   ', null],
    'zero' => ['0', 0],
    'one' => ['1', 1],
    'ten' => ['10', 10],
    'padded ten' => [' 10 ', 10],
    'negative' => ['-1', null],
    'text' => ['abc', null],
    'decimal' => ['1.5', null],
    'exponent' => ['1e3', null],
    'null literal' => ['null', null],
]);

it('parses any threshold value fail closed', function (mixed $raw, ?int $expected) {
    expect(SalesBoardAutomationConfig::threshold($raw))->toBe($expected);
})->with([
    'null' => [null, null],
    'int zero' => [0, 0],
    'int ten' => [10, 10],
    'negative int' => [-3, null],
    'bool true' => [true, null],
    'bool false' => [false, null],
    'float' => [1.5, null],
    'array' => [[3], null],
]);

it('never lets an invalid threshold turn into an immediate reminder', function () {
    $reminders = salesBoardConfigUnder([
        'SALES_BOARD_AUTOMATION_BUILDER_REMINDER_DAYS' => 'abc',
        'SALES_BOARD_AUTOMATION_MANAGEMENT_REMINDER_DAYS' => '-1',
        'SALES_BOARD_AUTOMATION_READY_REMINDER_DAYS' => '1.5',
    ])['automation']['reminders'];

    // Antes, os três viravam 0 -- "avisar agora" -- que é o oposto de desligado.
    expect($reminders['builder_review_after_days'])->toBeNull()
        ->and($reminders['management_review_after_days'])->toBeNull()
        ->and($reminders['ready_for_builder_after_days'])->toBeNull();
});

it('keeps the parsed values through the config cache serialization', function (string $raw) {
    $config = salesBoardConfigUnder([
        'SALES_BOARD_AUTOMATION_ENABLED' => $raw,
        'SALES_BOARD_AUTOMATION_BLOCKED_REMINDER_DAYS' => 'abc',
        'SALES_BOARD_AUTOMATION_BUILDER_REMINDER_DAYS' => '3',
    ]);

    /**
     * A mesma serialização do `config:cache`: o arquivo em cache guarda o valor
     * já interpretado, e não a variável de ambiente -- é por isso que a
     * interpretação precisa acontecer no arquivo de configuração.
     */
    $path = temporaryTestFilePath('sales-board-config-cache', 'php');
    file_put_contents($path, '<?php return '.var_export($config, true).';'.PHP_EOL);

    $cached = require $path;

    expect($cached['automation']['enabled'])->toBeBool()->toBeFalse()
        ->and($cached['automation']['reminders']['blocked_after_days'])->toBeNull()
        ->and($cached['automation']['reminders']['builder_review_after_days'])->toBeInt()->toBe(3);

    unlink($path);
})->with(['off', 'no', 'false', 'banana']);

/**
 * A habilitação por variável de ambiente da Fase F saiu: quem está sob
 * automação vem só do rollout por Emissão. O teste antigo conferia que um JSON
 * ilegível caía em lista vazia; o que importa agora é que a variável não chegue
 * à configuração de jeito nenhum -- nem legível, nem ilegível.
 */
it('never reads automation targets from the environment', function (string $raw) {
    expect(salesBoardConfigUnder(['SALES_BOARD_AUTOMATION_TARGETS' => $raw])['automation'])
        ->not->toHaveKey('targets');
})->with([
    'unreadable' => ['nao-e-json'],
    'readable' => ['[{"construction_id":7,"start_reference_month":"2026-08-01"}]'],
]);

it('falls back to the default retry cadence when the configured value is unreadable', function (?string $raw, int $expected) {
    $retry = salesBoardConfigUnder([
        'SALES_BOARD_AUTOMATION_BLOCKED_RETRY_HOURS' => $raw,
        'SALES_BOARD_AUTOMATION_FAILED_MAX_BACKOFF_HOURS' => $raw,
    ])['automation']['retry'];

    // Antes, `(int) 'abc'` virava 0 e a política aplicava max(1, 0): a obra
    // bloqueada era rederivada de hora em hora.
    expect($retry['blocked_after_hours'])->toBeInt()->toBe($expected)
        ->and($retry['failed_max_backoff_hours'])->toBeInt()->toBe($expected);
})->with([
    'unset' => [null, 24],
    'empty' => ['', 24],
    'text' => ['abc', 24],
    'with unit' => ['24h', 24],
    'zero' => ['0', 24],
    'negative' => ['-5', 24],
    'decimal' => ['1.5', 24],
    'valid' => ['12', 12],
    'padded' => [' 6 ', 6],
]);

it('keeps the retry cadence fail safe even when set at runtime', function () {
    config()->set('sales_board.automation.retry.blocked_after_hours', 'abc');
    config()->set('sales_board.automation.retry.failed_max_backoff_hours', 0);

    expect(SalesBoardAutomationConfig::retryHours('blocked_after_hours', 24))->toBe(24)
        ->and(SalesBoardAutomationConfig::retryHours('failed_max_backoff_hours', 24))->toBe(24);
});

it('reads the memory limit of the run fail safe', function (?string $raw, string $expected) {
    expect(salesBoardConfigUnder(['SALES_BOARD_AUTOMATION_MEMORY_LIMIT' => $raw])['automation']['memory_limit'])
        ->toBe($expected);
})->with([
    'unset' => [null, '512M'],
    'text' => ['muito', '512M'],
    'zero' => ['0', '512M'],
    'megabytes' => ['1024M', '1024M'],
    'lowercase gigabytes' => ['1g', '1G'],
    'unlimited' => ['-1', '-1'],
]);

it('never lets the stale run threshold undercut the scheduler overlap lock', function (mixed $configured, int $expected) {
    config()->set('sales_board.automation.stale_run_after_minutes', $configured);

    expect(SalesBoardAutomationConfig::staleRunMinutes())->toBe($expected);
})->with([
    'default' => [null, 180],
    'unreadable' => ['abc', 180],
    'below the lock' => [30, SalesBoardAutomationConfig::OVERLAP_LOCK_MINUTES],
    'above the lock' => [240, 240],
]);

it('reads the runtime switch fail closed, whatever was set at runtime', function (mixed $value, bool $expected) {
    config()->set('sales_board.automation.enabled', $value);

    expect(SalesBoardAutomationConfig::enabled())->toBeBool()->toBe($expected);
})->with([
    'off string' => ['off', false],
    'no string' => ['no', false],
    'null' => [null, false],
    'true' => [true, true],
]);

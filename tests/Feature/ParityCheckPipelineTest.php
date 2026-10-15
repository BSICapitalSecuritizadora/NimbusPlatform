<?php

use Illuminate\Support\Facades\Process;

/**
 * Durante o desenvolvimento, a execução MySQL fica disponível manualmente
 * por `composer test:parity`, sem consumir horas no CI a cada alteração.
 */
it('keeps the MySQL parity job out of the CI workflow', function () {
    $workflow = file_get_contents(base_path('.github/workflows/tests.yml'));

    $invocations = collect(preg_split('/\R/', $workflow))
        ->map(fn (string $line): string => trim($line))
        ->filter(fn (string $line): bool => str_starts_with($line, 'run:') && str_contains($line, 'parity-check.sh'))
        ->values()
        ->all();

    expect($invocations)->toBe([])
        ->and($workflow)->not->toMatch('/^  parity:/m');
});

it('runs the parity script through bash in the composer script', function () {
    $composer = json_decode(file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['scripts']['test:parity'])->toContain('bash ./scripts/parity-check.sh')
        ->and($composer['scripts']['test:parity'])->not->toContain('./scripts/parity-check.sh');
});

it('versions the parity script as executable', function () {
    $index = Process::path(base_path())->run(['git', 'ls-files', '--stage', '--', 'scripts/parity-check.sh']);

    if ($index->failed() || trim($index->output()) === '') {
        $this->markTestSkipped('Sem repositório git neste ambiente para ler o modo do arquivo versionado.');
    }

    expect(trim($index->output()))->toStartWith('100755 ');
});

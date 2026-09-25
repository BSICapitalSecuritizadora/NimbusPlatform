<?php

use Illuminate\Support\Facades\Process;

/**
 * O job `parity` do CI é o único lugar onde os grupos `parity` e `mysql` rodam.
 *
 * Por semanas ele morreu com exit 126 antes do primeiro teste: o workflow
 * chamava `./scripts/parity-check.sh` e o git guardava o arquivo sem bit de
 * execução. O vermelho era sempre o mesmo, e ninguém distinguiria dele uma
 * regressão real de lock ou de collation. Estes testes rodam na suíte comum,
 * no SQLite, justamente para que a volta do defeito apareça onde alguém olha.
 */
it('runs the parity script through bash in the CI workflow', function () {
    $workflow = file_get_contents(base_path('.github/workflows/tests.yml'));

    $invocations = collect(preg_split('/\R/', $workflow))
        ->map(fn (string $line): string => trim($line))
        ->filter(fn (string $line): bool => str_starts_with($line, 'run:') && str_contains($line, 'parity-check.sh'))
        ->values()
        ->all();

    expect($invocations)->toBe(['run: bash ./scripts/parity-check.sh']);
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

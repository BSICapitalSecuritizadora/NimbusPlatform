<?php

use Illuminate\Support\Facades\File;

/**
 * Arquivos que sobem um `queue:work` em cada ambiente onde a aplicação roda.
 *
 * @return list<string>
 */
function queueWorkerDefinitionPaths(): array
{
    return [
        base_path('startup.sh'),
        base_path('App_Data/jobs/continuous/queue-worker/run.sh'),
        base_path('infra/production/supervisor/bsi-queue.conf'),
    ];
}

/**
 * Todo `--timeout=N` passado a um `queue:work` no arquivo.
 *
 * @return list<int>
 */
function queueWorkerTimeouts(string $contents): array
{
    preg_match_all('/queue:work\b[^\r\n]*?--timeout=(\d+)/', $contents, $matches);

    return array_map(intval(...), $matches[1]);
}

/**
 * `retry_after` é o prazo que a fila espera antes de considerar o job perdido e
 * entregá-lo a outro worker. Se for menor que o `--timeout` com que o worker
 * sobe, um job longo — e os de extração levam minutos contra a API do Gemini —
 * é reprocessado em paralelo com a execução que ainda está viva: as obrigações
 * sugeridas seriam gravadas duas vezes, e o `delete` de sugestões anteriores
 * rodaria no meio da execução concorrente.
 *
 * O padrão de 90s que vem do Laravel pressupõe job curto e não cobre este app.
 */
it('keeps retry_after above the longest queue worker timeout', function (): void {
    $timeouts = collect(queueWorkerDefinitionPaths())
        ->flatMap(fn (string $path): array => queueWorkerTimeouts(File::get($path)));

    expect($timeouts)->not->toBeEmpty();

    $longestTimeout = $timeouts->max();

    $retryAfterByConnection = collect(config('queue.connections'))
        ->filter(fn (array $connection): bool => isset($connection['retry_after']))
        ->map(fn (array $connection): int => (int) $connection['retry_after']);

    // Sem isto o teste passaria vazio se as conexões perdessem a chave.
    expect($retryAfterByConnection)->toHaveCount(3);

    $tooShort = $retryAfterByConnection->filter(
        fn (int $retryAfter): bool => $retryAfter <= $longestTimeout
    );

    expect($tooShort->all())->toBe(
        [],
        "Conexões com retry_after menor ou igual ao timeout de {$longestTimeout}s do worker."
    );
});

it('declares a timeout for every queue worker it starts', function (): void {
    foreach (queueWorkerDefinitionPaths() as $path) {
        expect(File::exists($path))->toBeTrue("Arquivo de worker ausente: {$path}");
        expect(queueWorkerTimeouts(File::get($path)))->not->toBeEmpty(
            "O queue:work em {$path} sobe sem --timeout e herda o padrão de 60s."
        );
    }
});

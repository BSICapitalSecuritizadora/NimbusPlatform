<?php

namespace App\Console\Commands;

use App\Models\Bank;
use App\Models\MeasurementAsset;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Separa os arquivos do disco público que foram gravados dentro da raiz
 * privada enquanto `PUBLIC_STORAGE_ROOT` apontava para o mesmo diretório que
 * `PRIVATE_STORAGE_ROOT`.
 *
 * Só os diretórios escritos pelo disco `public` são movidos. Diretórios com
 * nome ambíguo (`documents`, `emissions`) ficam onde estão: eles existem nas
 * duas árvores e mover o conjunto levaria documentos privados para a área
 * pública — exatamente o problema que este comando corrige.
 */
class SplitPublicStorageRoot extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'storage:split-public-root {--dry-run : Apenas relata o que seria movido}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Move os arquivos do disco público que ficaram na raiz privada para PUBLIC_STORAGE_ROOT';

    /**
     * Diretórios de primeiro nível gravados pelo disco `public`.
     *
     * @var list<string>
     */
    private const PUBLIC_DIRECTORIES = ['banks', 'measurements'];

    /**
     * Diretórios que existem nas duas árvores e exigem conferência manual.
     *
     * @var list<string>
     */
    private const AMBIGUOUS_DIRECTORIES = ['documents', 'emissions'];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $privateRoot = rtrim((string) config('filesystems.disks.local.root'), '/');
        $publicRoot = rtrim((string) config('filesystems.disks.public.root'), '/');
        $isDryRun = (bool) $this->option('dry-run');

        if ($privateRoot === '' || $publicRoot === '') {
            $this->error('As raízes de armazenamento não estão configuradas.');

            return self::FAILURE;
        }

        if ($privateRoot === $publicRoot) {
            $this->error("As raízes privada e pública apontam para o mesmo diretório ({$privateRoot}). Corrija PUBLIC_STORAGE_ROOT antes de rodar este comando.");

            return self::FAILURE;
        }

        // Quando PUBLIC_STORAGE_ROOT está ausente ou sobreposto à raiz privada,
        // a configuração cai no diretório padrão, dentro da pasta de deploy.
        // Mover os arquivos para lá os apagaria no deploy seguinte.
        if (str_starts_with($publicRoot.'/', rtrim(base_path(), '/').'/')) {
            $this->error("A raiz pública ({$publicRoot}) está dentro da pasta de deploy e seria apagada no próximo deploy. Defina PUBLIC_STORAGE_ROOT com um caminho persistente antes de rodar este comando.");

            return self::FAILURE;
        }

        $this->line("Origem (raiz privada): {$privateRoot}");
        $this->line("Destino (raiz pública): {$publicRoot}");

        if ($isDryRun) {
            $this->comment('Modo simulação: nenhum arquivo será movido.');
        }

        $movedFiles = 0;

        foreach (self::PUBLIC_DIRECTORIES as $directory) {
            $movedFiles += $this->moveDirectory("{$privateRoot}/{$directory}", "{$publicRoot}/{$directory}", $isDryRun);
        }

        $this->newLine();
        $this->reportAmbiguousDirectories($privateRoot);
        $this->reportMissingReferences($publicRoot, $isDryRun);

        $this->newLine();
        $this->info($isDryRun
            ? "{$movedFiles} arquivo(s) seriam movidos."
            : "{$movedFiles} arquivo(s) movidos.");

        return self::SUCCESS;
    }

    /**
     * Move o conteúdo de um diretório preservando o que já existir no destino.
     * Um arquivo já presente no destino é considerado migrado e o da origem é
     * deixado intacto para conferência manual — nada é sobrescrito nem apagado.
     */
    private function moveDirectory(string $source, string $destination, bool $isDryRun): int
    {
        if (! File::isDirectory($source)) {
            $this->line("· {$source} não existe — nada a mover.");

            return 0;
        }

        $movedFiles = 0;

        foreach (File::allFiles($source) as $file) {
            $relativePath = str_replace('\\', '/', $file->getRelativePathname());
            $target = "{$destination}/{$relativePath}";

            if (File::exists($target)) {
                $this->warn("· já existe no destino, mantido na origem: {$relativePath}");

                continue;
            }

            if (! $isDryRun) {
                File::ensureDirectoryExists(dirname($target));
                File::move($file->getPathname(), $target);
            }

            $movedFiles++;
        }

        $this->line("· {$source} → {$destination}: {$movedFiles} arquivo(s)");

        return $movedFiles;
    }

    /**
     * Diretórios homônimos nas duas árvores não podem ser separados por nome.
     */
    private function reportAmbiguousDirectories(string $privateRoot): void
    {
        foreach (self::AMBIGUOUS_DIRECTORIES as $directory) {
            $path = "{$privateRoot}/{$directory}";

            if (File::isDirectory($path)) {
                $this->warn("Conferência manual: {$path} pode misturar arquivos públicos e privados e não foi tocado.");
            }
        }
    }

    /**
     * Confere se cada caminho registrado no banco existe na raiz pública.
     */
    private function reportMissingReferences(string $publicRoot, bool $isDryRun): void
    {
        $referencedPaths = Bank::query()
            ->whereNotNull('logo_path')
            ->pluck('logo_path')
            ->merge(
                MeasurementAsset::query()
                    ->whereNotNull('storage_path')
                    ->pluck('storage_path')
            )
            ->filter()
            ->unique();

        $missingPaths = $referencedPaths->reject(
            fn (string $path): bool => File::exists($publicRoot.'/'.ltrim($path, '/'))
        );

        if ($missingPaths->isEmpty()) {
            $this->info('Todos os caminhos registrados no banco existem na raiz pública.');

            return;
        }

        $this->warn($isDryRun
            ? "{$missingPaths->count()} caminho(s) do banco ainda não estão na raiz pública (esperado antes da migração):"
            : "{$missingPaths->count()} caminho(s) do banco não foram encontrados na raiz pública:");

        foreach ($missingPaths->take(20) as $path) {
            $this->line("  - {$path}");
        }
    }
}

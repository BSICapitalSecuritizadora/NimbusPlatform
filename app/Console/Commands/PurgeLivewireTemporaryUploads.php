<?php

namespace App\Console\Commands;

use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Throwable;

/**
 * Expurga os envios temporários do Livewire (`livewire-tmp`) que ninguém
 * confirmou.
 *
 * A limpeza do próprio Livewire só roda quando alguém faz o envio seguinte, e
 * `app:cleanup-temporary-uploads` olha outro diretório. Um envio abandonado no
 * meio de uma importação deixa no disco uma planilha com CPF/CNPJ sem prazo; o
 * agendamento diário limita isso a cerca de dois dias.
 *
 * Simula por padrão: sem `--force`, só relata quantidades, bytes e datas -- nunca
 * nomes de arquivo, que embutem o nome original em base64. Com `--force`, apaga o
 * que passou de `--hours`: o arquivo e o `.json` de metadados juntos, e também o
 * `.json` que ficou sem arquivo.
 *
 * No disco temporário ativo, `--hours` menor que 1 é recusado: há assistentes
 * abertos com envios de minutos atrás. Num disco que não é o ativo -- o resíduo
 * antigo no Blob, por exemplo -- `--hours=0` alcança tudo, depois de uma
 * simulação.
 */
class PurgeLivewireTemporaryUploads extends Command
{
    protected $signature = 'uploads:purge-livewire-temporary
                            {--disk= : Disco a varrer (padrão: o disco temporário ativo do Livewire)}
                            {--hours=24 : Idade mínima, em horas, do que pode ser apagado}
                            {--force : Apaga de fato; sem esta opção o comando só relata}';

    protected $description = 'Relata ou expurga envios temporários do Livewire (livewire-tmp) abandonados';

    private const METADATA_SUFFIX = '.json';

    public function handle(): int
    {
        $activeDisk = (string) FileUploadConfiguration::disk();
        $diskName = filled($this->option('disk')) ? (string) $this->option('disk') : $activeDisk;
        $hours = $this->option('hours');

        if (! is_numeric($hours) || ((int) $hours != $hours) || ((int) $hours < 0)) {
            $this->error('Informe --hours como um número inteiro de horas, zero ou maior.');

            return self::FAILURE;
        }

        $hours = (int) $hours;

        if (($diskName === $activeDisk) && ($hours < 1)) {
            $this->error("--hours precisa ser de pelo menos 1 no disco temporário ativo ({$activeDisk}): há envios em andamento nos assistentes abertos.");

            return self::FAILURE;
        }

        try {
            $disk = Storage::disk($diskName);
        } catch (InvalidArgumentException) {
            $this->error("O disco [{$diskName}] não existe nesta configuração.");

            return self::FAILURE;
        }

        $directory = FileUploadConfiguration::path();
        $cutoff = now()->subHours($hours)->getTimestamp();

        $inventory = $this->inventory($disk, $directory);
        $stale = array_filter($inventory, fn (array $entry): bool => $entry['modified'] <= $cutoff);

        $this->line(sprintf('Disco: %s · diretório: %s · mais velhos que %d hora(s).', $diskName, $directory, $hours));
        $this->report('Encontrados', $inventory);
        $this->report($this->option('force') ? 'A apagar' : 'Seriam apagados', $stale);

        if (! $this->option('force')) {
            $this->comment('Simulação: nada foi apagado. Repita com --force para apagar.');

            return self::SUCCESS;
        }

        $deleted = 0;
        $failed = 0;

        foreach (array_keys($stale) as $path) {
            $removed = rescue(fn (): bool => (bool) $disk->delete($path), false, report: false);

            $removed ? $deleted++ : $failed++;
        }

        $this->info(sprintf('Apagados: %d arquivo(s).', $deleted));

        if ($failed > 0) {
            $this->warn(sprintf('Não foi possível apagar %d arquivo(s); rode o comando de novo.', $failed));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Cada arquivo do diretório, com tamanho, data e tipo. O envio e o `.json` dele
     * ficam com a data mais recente dos dois, para que saiam juntos; o `.json`
     * sem envio ao lado é órfão e conta pela própria data -- o Livewire grava o
     * `.json` antes do envio, e um envio em andamento não pode perdê-lo.
     *
     * @return array<string, array{metadata: bool, orphan: bool, size: int, modified: int}>
     */
    private function inventory(Filesystem $disk, string $directory): array
    {
        $files = rescue(fn (): array => $disk->files($directory), [], report: false);
        $paths = array_flip($files);
        $entries = [];

        foreach ($files as $path) {
            $entries[$path] = [
                'metadata' => str_ends_with($path, self::METADATA_SUFFIX),
                'orphan' => false,
                'size' => (int) rescue(fn (): int => $disk->size($path), 0, report: false),
                'modified' => (int) rescue(fn (): int => $disk->lastModified($path), 0, report: false),
            ];
        }

        foreach ($entries as $path => $entry) {
            if (! $entry['metadata']) {
                continue;
            }

            $upload = substr($path, 0, -strlen(self::METADATA_SUFFIX));

            if (isset($paths[$upload])) {
                $pairModified = max($entry['modified'], $entries[$upload]['modified']);

                $entries[$path]['modified'] = $pairModified;
                $entries[$upload]['modified'] = $pairModified;
            } else {
                $entries[$path]['orphan'] = true;
            }
        }

        return $entries;
    }

    /**
     * @param  array<string, array{metadata: bool, orphan: bool, size: int, modified: int}>  $entries
     */
    private function report(string $title, array $entries): void
    {
        $uploads = count(array_filter($entries, fn (array $entry): bool => ! $entry['metadata']));
        $metadata = count($entries) - $uploads;
        $orphans = count(array_filter($entries, fn (array $entry): bool => $entry['orphan']));
        $bytes = array_sum(array_column($entries, 'size'));
        $dates = array_column($entries, 'modified');

        $this->line(sprintf(
            '%s: %d envio(s), %d metadado(s) .json (%d sem envio), %s. Mais antigo: %s. Mais recente: %s.',
            $title,
            $uploads,
            $metadata,
            $orphans,
            self::bytes($bytes),
            $dates === [] ? '—' : self::moment(min($dates)),
            $dates === [] ? '—' : self::moment(max($dates)),
        ));
    }

    private static function moment(int $timestamp): string
    {
        try {
            return BusinessTime::at(CarbonImmutable::createFromTimestamp($timestamp))->format('d/m/Y H:i');
        } catch (Throwable) {
            return '—';
        }
    }

    private static function bytes(int $bytes): string
    {
        return match (true) {
            $bytes >= 1_048_576 => number_format($bytes / 1_048_576, 1, ',', '.').' MB',
            $bytes >= 1024 => number_format($bytes / 1024, 1, ',', '.').' KB',
            default => $bytes.' bytes',
        };
    }
}

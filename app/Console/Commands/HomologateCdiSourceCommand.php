<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\PuCalculator\Services\CdiSourceHomologationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use JsonException;
use Throwable;

class HomologateCdiSourceCommand extends Command
{
    /** @var string */
    protected $signature = 'pu:index-rates:homologate-di-source
                            {--from=2025-01-01 : Data inicial da comparação (AAAA-MM-DD)}
                            {--to= : Data final solicitada (AAAA-MM-DD; padrão: hoje)}
                            {--no-artifact : Não gravar o dossiê JSON local}';

    /** @var string */
    protected $description = 'Compara, sem persistência operacional, Taxa DI B3 e BCB SGS 4389';

    public function handle(CdiSourceHomologationService $service): int
    {
        try {
            $from = $this->parseDate((string) $this->option('from'));
            $toOption = $this->option('to');
            $to = $this->parseDate(is_string($toOption) && $toOption !== '' ? $toOption : CarbonImmutable::today()->toDateString());

            if ($from->greaterThan($to)) {
                $this->components->error('A data inicial deve ser anterior ou igual à data final.');

                return self::INVALID;
            }

            $this->components->info(sprintf(
                'Capturando e comparando B3 MediaCDI × BCB SGS 4389 de %s a %s, sem gravar index_rates.',
                $from->toDateString(),
                $to->toDateString(),
            ));

            $report = $service->execute($from, $to);
            $artifactPath = null;

            if (! (bool) $this->option('no-artifact')) {
                $artifactPath = $this->writeArtifact($report);
            }

            $summary = $report['comparison']['summary'] ?? [];
            $this->table(['Resultado', 'Quantidade'], [
                ['Iguais', $summary['present_equal'] ?? 0],
                ['Divergentes', $summary['present_different'] ?? 0],
                ['Somente B3', $summary['only_b3'] ?? 0],
                ['Somente BCB', $summary['only_bcb'] ?? 0],
                ['Inválidos', $summary['invalid_value'] ?? 0],
                ['Duplicados', $summary['duplicate_date'] ?? 0],
            ]);
            $this->newLine();
            $this->line(sprintf('<info>Conclusão:</info> %s', $report['classification']));

            if ($artifactPath !== null) {
                $this->line(sprintf('<info>Dossiê:</info> %s', $artifactPath));
            }

            $this->components->warn('A execução não aprova a fonte e não persiste snapshots operacionais.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function parseDate(string $value): CarbonImmutable
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        if ($date === null || $date->toDateString() !== $value) {
            throw new \InvalidArgumentException(sprintf('Data inválida: %s. Use AAAA-MM-DD.', $value));
        }

        return $date;
    }

    /**
     * @param  array<string, mixed>  $report
     *
     * @throws JsonException
     */
    private function writeArtifact(array $report): string
    {
        $disk = (string) config('pu_indexes.source_homologation.artifact_disk', 'local');
        $directory = trim((string) config('pu_indexes.source_homologation.artifact_directory'), '/');
        $file = sprintf('cdi-b3-vs-bcb-4389-%s.json', CarbonImmutable::now()->format('Ymd-His-u'));
        $relativePath = sprintf('%s/%s', $directory, $file);
        $json = json_encode(
            $report,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
        Storage::disk($disk)->put($relativePath, $json);

        return Storage::disk($disk)->path($relativePath);
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\DocumentStorageService;
use Illuminate\Console\Command;

/**
 * Preenche `documents.checksum` nos documentos anteriores à coluna.
 *
 * Enquanto o hash estiver nulo, a detecção de duplicidade daquele documento
 * continua sendo o indício por nome de arquivo. Rodar este comando uma vez após
 * o deploy converte esse indício em identidade para todo o acervo.
 *
 * Cada documento exige ler o arquivo inteiro — em disco remoto, uma
 * transferência completa. Por isso o comando é manual, processa em blocos e pode
 * ser interrompido e retomado: o que já foi preenchido não é lido de novo.
 */
class BackfillDocumentChecksums extends Command
{
    protected $signature = 'documents:backfill-checksums
                            {--chunk=100 : Quantidade de documentos lidos por bloco}
                            {--limit=0 : Interrompe após este número de documentos (0 = sem limite)}';

    protected $description = 'Calcula o SHA-256 dos documentos que ainda não têm checksum gravado';

    public function handle(DocumentStorageService $documentStorageService): int
    {
        $chunkSize = max(1, (int) $this->option('chunk'));
        $limit = max(0, (int) $this->option('limit'));

        $pendingCount = Document::query()->whereNull('checksum')->count();

        if ($pendingCount === 0) {
            $this->components->info('Nenhum documento pendente: todos já têm checksum.');

            return self::SUCCESS;
        }

        $this->components->info("Documentos sem checksum: {$pendingCount}.");

        $progressBar = $this->output->createProgressBar($limit > 0 ? min($limit, $pendingCount) : $pendingCount);
        $progressBar->start();

        $filled = 0;
        $unreadable = 0;
        $shouldStop = false;

        Document::query()
            ->whereNull('checksum')
            ->select(['id', 'file_path', 'storage_disk'])
            ->chunkById($chunkSize, function ($documents) use (
                $documentStorageService,
                $progressBar,
                $limit,
                &$filled,
                &$unreadable,
                &$shouldStop,
            ): bool {
                foreach ($documents as $document) {
                    $checksum = $documentStorageService->checksum(
                        (string) $document->file_path,
                        $document->resolved_storage_disk,
                    );

                    if ($checksum === null) {
                        $unreadable++;
                    } else {
                        // Atualização direta: o `saving` do model releria o
                        // arquivo para derivar os metadados de novo, dobrando o
                        // custo de cada documento sem mudar o resultado.
                        Document::query()->whereKey($document->id)->update(['checksum' => $checksum]);
                        $filled++;
                    }

                    $progressBar->advance();

                    if ($limit > 0 && ($filled + $unreadable) >= $limit) {
                        $shouldStop = true;

                        return false;
                    }
                }

                return true;
            });

        $progressBar->finish();
        $this->newLine(2);

        $this->components->info("Checksums gravados: {$filled}.");

        if ($unreadable > 0) {
            $this->components->warn("Arquivos ilegíveis (checksum não gravado): {$unreadable}.");
        }

        if ($shouldStop) {
            $this->components->warn('Limite atingido; rode novamente para continuar.');
        }

        return self::SUCCESS;
    }
}

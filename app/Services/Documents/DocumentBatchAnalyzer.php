<?php

namespace App\Services\Documents;

use App\Concerns\DerivesStoredFileMetadata;
use App\DTOs\Documents\DocumentBatchFileAnalysis;
use App\Models\Document;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Number;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Inspeciona os arquivos de um lote antes de qualquer gravação.
 *
 * A mesma análise serve à etapa de conferência e ao processamento, de propósito:
 * o que o usuário revisa na tela é exatamente o que o servidor vai decidir. O
 * estado do formulário é controlável pelo cliente, então o processamento
 * reexecuta esta análise em vez de confiar no que voltou da tela.
 */
class DocumentBatchAnalyzer
{
    public function __construct(private readonly DocumentBatchLimits $limits) {}

    /**
     * @param  array<string, TemporaryUploadedFile>  $files  Indexados pela chave do `FileUpload`.
     * @return array<string, DocumentBatchFileAnalysis>
     */
    public function analyze(array $files): array
    {
        $checksums = $this->resolveChecksums($files);
        $duplicateKeys = $this->resolveDuplicateKeysWithinBatch($checksums);
        $existingWarnings = $this->resolveExistingDocumentWarnings($files, $checksums);

        $analyses = [];

        foreach ($files as $key => $file) {
            $analyses[$key] = new DocumentBatchFileAnalysis(
                key: $key,
                originalName: $file->getClientOriginalName(),
                extension: mb_strtolower($file->getClientOriginalExtension()),
                sizeBytes: (int) $file->getSize(),
                error: $this->validationErrorFor($file),
                duplicateOfKey: $duplicateKeys[$key] ?? null,
                duplicateWarning: $existingWarnings[$key] ?? null,
            );
        }

        return $analyses;
    }

    /**
     * Aplica as mesmas regras de formato e tamanho do cadastro individual, com o
     * limite por arquivo do lote no lugar do limite individual.
     *
     * Esta é a segunda barreira, não a primeira: as regras de upload temporário
     * do Livewire (`config/livewire.php`) já recusam extensões fora da lista no
     * momento do envio. Ela existe porque aquela lista é global e checa extensão,
     * enquanto aqui o tipo é o detectado no conteúdo do arquivo.
     */
    public function validationErrorFor(TemporaryUploadedFile $file): ?string
    {
        $allowedMimeTypes = $this->limits->allowedMimeTypes();

        $rules = ['file'];

        if ($allowedMimeTypes !== []) {
            $rules[] = 'mimetypes:'.implode(',', $allowedMimeTypes);
        }

        $rules[] = 'max:'.$this->limits->maxFileKilobytes();

        $validator = Validator::make(
            ['arquivo' => $file],
            ['arquivo' => $rules],
            [
                'arquivo.mimetypes' => 'Formato não aceito para documentos.',
                'arquivo.max' => 'Arquivo acima do limite de '.Number::fileSize($this->limits->maxFileBytes()).' por documento no lote.',
                'arquivo.file' => 'O arquivo enviado não pôde ser lido.',
            ],
        );

        return $validator->fails() ? $validator->errors()->first('arquivo') : null;
    }

    /**
     * SHA-256 de cada arquivo do lote — o mesmo algoritmo que
     * {@see DerivesStoredFileMetadata} grava em `documents.checksum`. É o que
     * torna a comparação com o acervo uma identidade, e não uma semelhança.
     *
     * @param  array<string, TemporaryUploadedFile>  $files
     * @return array<string, string> Chave do arquivo => hash (ausente quando ilegível).
     */
    private function resolveChecksums(array $files): array
    {
        $checksums = [];

        foreach ($files as $key => $file) {
            $checksum = $this->hashContents($file);

            if ($checksum !== null) {
                $checksums[$key] = $checksum;
            }
        }

        return $checksums;
    }

    /**
     * Arquivos repetidos dentro do próprio lote.
     *
     * O nome sozinho geraria falso positivo — dois documentos distintos podem se
     * chamar "aditamento.pdf" —, então a comparação é sempre pelo conteúdo.
     *
     * @param  array<string, string>  $checksums
     * @return array<string, string> Chave do arquivo repetido => chave da primeira ocorrência.
     */
    private function resolveDuplicateKeysWithinBatch(array $checksums): array
    {
        $firstKeyByChecksum = [];
        $duplicates = [];

        foreach ($checksums as $key => $checksum) {
            if (isset($firstKeyByChecksum[$checksum])) {
                $duplicates[$key] = $firstKeyByChecksum[$checksum];

                continue;
            }

            $firstKeyByChecksum[$checksum] = $key;
        }

        return $duplicates;
    }

    private function hashContents(TemporaryUploadedFile $file): ?string
    {
        $stream = rescue(fn () => $file->readStream(), null, report: false);

        if (! is_resource($stream)) {
            return null;
        }

        try {
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);

            return hash_final($hash);
        } finally {
            fclose($stream);
        }
    }

    /**
     * Advertência (nunca bloqueio) de duplicidade com o acervo já cadastrado.
     *
     * Dois níveis, em ordem de força:
     *
     * 1. **Checksum igual** — o arquivo já está cadastrado, com certeza.
     * 2. **Nome igual em documento sem checksum** — indício, não prova. Cobre os
     *    documentos anteriores à coluna `checksum` que ainda não passaram pelo
     *    `documents:backfill-checksums`; o tamanho coincidente reforça o indício.
     *
     * Nos dois casos é apenas aviso: bloquear rejeitaria um documento legítimo, e
     * criar versão automaticamente contrariaria o versionamento explícito que já
     * existe na listagem.
     *
     * @param  array<string, TemporaryUploadedFile>  $files
     * @param  array<string, string>  $checksums
     * @return array<string, string> Chave do arquivo => advertência.
     */
    private function resolveExistingDocumentWarnings(array $files, array $checksums): array
    {
        if ($files === []) {
            return [];
        }

        $names = [];

        foreach ($files as $file) {
            $names[] = $file->getClientOriginalName();
        }

        $existingDocuments = Document::query()
            ->whereNull('replaced_at')
            ->where(function ($query) use ($checksums, $names): void {
                $query->whereIn('file_name', array_unique($names));

                if ($checksums !== []) {
                    $query->orWhereIn('checksum', array_values(array_unique($checksums)));
                }
            })
            ->get(['id', 'title', 'file_name', 'file_size', 'checksum']);

        if ($existingDocuments->isEmpty()) {
            return [];
        }

        $byChecksum = $existingDocuments
            ->filter(fn (Document $document): bool => filled($document->checksum))
            ->keyBy('checksum');

        // Só os documentos sem checksum caem no indício por nome: para os demais
        // a comparação exata já respondeu, e avisar por homonímia depois disso
        // seria ruído.
        $byName = $existingDocuments
            ->filter(fn (Document $document): bool => blank($document->checksum))
            ->keyBy('file_name');

        $warnings = [];

        foreach ($files as $key => $file) {
            $checksum = $checksums[$key] ?? null;

            if ($checksum !== null && ($match = $byChecksum->get($checksum)) instanceof Document) {
                $warnings[$key] = "Documento idêntico já cadastrado: \"{$match->title}\". Confirme que não é uma duplicata antes de cadastrar.";

                continue;
            }

            $match = $byName->get($file->getClientOriginalName());

            if (! $match instanceof Document) {
                continue;
            }

            $warnings[$key] = ((int) $match->file_size === (int) $file->getSize())
                ? "Provável duplicidade: já existe o documento \"{$match->title}\" com este nome e tamanho de arquivo."
                : "Possível duplicidade: já existe o documento \"{$match->title}\" com este nome de arquivo.";
        }

        return $warnings;
    }
}

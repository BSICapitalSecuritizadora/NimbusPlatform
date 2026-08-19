<?php

namespace App\Services\Documents;

use App\DTOs\Documents\DocumentBatchDefaults;
use App\DTOs\Documents\DocumentBatchFileAnalysis;
use App\DTOs\Documents\DocumentBatchItem;
use App\DTOs\Documents\DocumentBatchItemOutcome;
use App\DTOs\Documents\DocumentBatchResult;
use App\Enums\DocumentBatchItemStatus;
use App\Exceptions\UploadScanUnavailableException;
use App\Models\Document;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;
use Throwable;

/**
 * Cadastra vários documentos de uma vez, um arquivo de cada vez.
 *
 * O que este serviço acrescenta ao cadastro individual é apenas a orquestração:
 * armazenamento, metadados derivados do disco, varredura antivírus, log de
 * atividade e vínculo com as séries continuam vindo do próprio model
 * {@see Document} — não há regra duplicada aqui.
 *
 * Garantias por arquivo:
 *
 * - **Independência.** Cada documento tem sua própria transação; a falha de um
 *   não desfaz os que já foram criados.
 * - **Ordem gravação → banco.** Se o armazenamento falhar, nenhum registro é
 *   criado. Se o banco (ou a varredura, que roda no `saving`) falhar depois da
 *   gravação, o arquivo órfão é removido do disco.
 * - **Sem sobrescrita.** O nome de armazenamento é um ULID, como no cadastro
 *   individual, e a existência do caminho é conferida antes de gravar.
 * - **Nunca publicado.** Todo documento nasce rascunho; publicar segue sendo
 *   ação explícita na listagem.
 */
class DocumentBatchCreator
{
    /**
     * Mesmo diretório usado pelo `FileUpload` do cadastro individual.
     */
    public const DIRECTORY = 'documents';

    private const MAX_STORAGE_NAME_ATTEMPTS = 3;

    public function __construct(
        private readonly DocumentBatchAnalyzer $analyzer,
        private readonly DocumentBatchLimits $limits,
    ) {}

    /**
     * @param  array<int, DocumentBatchItem>  $items
     */
    public function create(array $items, DocumentBatchDefaults $defaults): DocumentBatchResult
    {
        $files = [];

        foreach ($items as $item) {
            $files[$item->key] = $item->file;
        }

        $analyses = $this->analyzer->analyze($files);

        $startedAt = microtime(true);
        $timeBudgetSeconds = $this->limits->timeBudgetSeconds();
        $isTimeBudgetExhausted = false;
        $isScannerUnavailable = false;
        $processedCount = 0;

        $outcomes = [];

        foreach ($items as $item) {
            $analysis = $analyses[$item->key] ?? null;

            if (! $analysis instanceof DocumentBatchFileAnalysis) {
                $outcomes[] = $this->outcome($item, DocumentBatchItemStatus::Failed, 'Arquivo não encontrado no envio.');

                continue;
            }

            if ($analysis->isRejected()) {
                $outcomes[] = $this->outcome($item, DocumentBatchItemStatus::Rejected, $analysis->error, $analysis);

                continue;
            }

            if ($analysis->isDuplicatedInBatch()) {
                $duplicatedName = $analyses[$analysis->duplicateOfKey]->originalName ?? $analysis->originalName;

                $outcomes[] = $this->outcome(
                    $item,
                    DocumentBatchItemStatus::Duplicated,
                    "Arquivo idêntico a \"{$duplicatedName}\", já incluído neste lote.",
                    $analysis,
                );

                continue;
            }

            // Antivírus fora do ar é uma condição do serviço, não do arquivo:
            // insistir nos demais só multiplicaria o timeout do clamd até
            // estourar o tempo da requisição. Os pendentes ficam para nova
            // tentativa, quando o serviço voltar.
            if ($isScannerUnavailable) {
                $outcomes[] = $this->outcome(
                    $item,
                    DocumentBatchItemStatus::NotProcessed,
                    'A verificação antivírus está indisponível. Tente novamente quando o serviço voltar.',
                    $analysis,
                );

                continue;
            }

            // O primeiro arquivo é sempre processado: sem isso um orçamento
            // curto demais faria o lote não andar nunca, inclusive nas
            // tentativas seguintes.
            if ($processedCount > 0 && ($isTimeBudgetExhausted || (microtime(true) - $startedAt) >= $timeBudgetSeconds)) {
                $isTimeBudgetExhausted = true;

                $outcomes[] = $this->outcome(
                    $item,
                    DocumentBatchItemStatus::NotProcessed,
                    "O tempo limite de processamento do lote ({$timeBudgetSeconds}s) foi atingido antes deste arquivo. Reenvie os pendentes.",
                    $analysis,
                );

                continue;
            }

            try {
                $outcomes[] = $this->createDocument($item, $defaults, $analysis);
                $processedCount++;
            } catch (UploadScanUnavailableException) {
                $isScannerUnavailable = true;

                $outcomes[] = $this->outcome(
                    $item,
                    DocumentBatchItemStatus::Failed,
                    'A verificação antivírus está indisponível. Tente novamente quando o serviço voltar.',
                    $analysis,
                );
            }
        }

        $result = new DocumentBatchResult($outcomes);

        $this->logBatchSummary($result, $defaults);

        return $result;
    }

    /**
     * @throws UploadScanUnavailableException quando o antivírus não pode avaliar o arquivo.
     */
    private function createDocument(
        DocumentBatchItem $item,
        DocumentBatchDefaults $defaults,
        DocumentBatchFileAnalysis $analysis,
    ): DocumentBatchItemOutcome {
        $disk = Document::defaultStorageDisk();

        try {
            $storedPath = $this->storeFile($item->file, $disk);
        } catch (Throwable $exception) {
            Log::error('Falha ao armazenar arquivo do cadastro de documentos em lote.', [
                'original_name' => $analysis->originalName,
                'disk' => $disk,
                'exception' => $exception->getMessage(),
            ]);

            return $this->outcome(
                $item,
                DocumentBatchItemStatus::Failed,
                'Não foi possível gravar o arquivo no armazenamento. Nenhum registro foi criado.',
                $analysis,
            );
        }

        try {
            $document = DB::transaction(function () use ($item, $defaults, $storedPath, $disk): Document {
                $document = Document::query()->create([
                    'title' => $this->normalizeTitle($item),
                    'category' => $defaults->category,
                    'file_path' => $storedPath,
                    'file_name' => $item->originalName(),
                    'storage_disk' => $disk,
                    'is_published' => false,
                    'is_public' => false,
                ]);

                $document->emissions()->sync($defaults->emissionIds);

                return $document;
            });
        } catch (UploadScanUnavailableException $exception) {
            // A indisponibilidade sobe para quem controla o lote decidir; aqui só
            // o arquivo órfão precisa sumir.
            $this->deleteOrphanFile($disk, $storedPath);

            throw $exception;
        } catch (ValidationException $exception) {
            $this->deleteOrphanFile($disk, $storedPath);

            return $this->outcome(
                $item,
                DocumentBatchItemStatus::Rejected,
                $exception->validator->errors()->first() ?: 'O arquivo não passou nas verificações de segurança.',
                $analysis,
            );
        } catch (Throwable $exception) {
            $this->deleteOrphanFile($disk, $storedPath);

            Log::error('Falha ao registrar documento do cadastro em lote.', [
                'original_name' => $analysis->originalName,
                'relative_path' => $storedPath,
                'exception' => $exception->getMessage(),
            ]);

            return $this->outcome(
                $item,
                DocumentBatchItemStatus::Failed,
                'Não foi possível registrar o documento. O arquivo gravado foi descartado.',
                $analysis,
            );
        }

        return $this->outcome(
            $item,
            DocumentBatchItemStatus::Created,
            null,
            $analysis,
            $document->id,
        );
    }

    /**
     * Grava o arquivo com o mesmo padrão do cadastro individual: nome de
     * armazenamento em ULID no diretório `documents`, com o nome original
     * preservado à parte, na coluna `file_name`.
     *
     * @throws RuntimeException quando o arquivo não chega ao disco.
     */
    private function storeFile(TemporaryUploadedFile $file, string $disk): string
    {
        $filesystem = Storage::disk($disk);
        $extension = mb_strtolower($file->getClientOriginalExtension());
        $suffix = $extension === '' ? '' : '.'.$extension;

        for ($attempt = 1; $attempt <= self::MAX_STORAGE_NAME_ATTEMPTS; $attempt++) {
            $storedName = Str::ulid()->toString().$suffix;

            // Um ULID repetido é praticamente impossível, mas sobrescrever o
            // arquivo de um documento já cadastrado seria irreversível.
            if ($filesystem->exists(self::DIRECTORY.'/'.$storedName)) {
                continue;
            }

            $path = $file->storeAs(self::DIRECTORY, $storedName, ['disk' => $disk]);

            // `storeAs()` devolve o caminho mesmo quando o disco está configurado
            // com `throw => false` e a escrita falhou em silêncio.
            if (is_string($path) && $path !== '' && $filesystem->exists($path)) {
                return $path;
            }

            throw new RuntimeException("O arquivo não foi gravado no disco [{$disk}].");
        }

        throw new RuntimeException('Não foi possível gerar um nome de armazenamento livre para o arquivo.');
    }

    private function deleteOrphanFile(string $disk, string $path): void
    {
        $deleted = rescue(fn (): bool => Storage::disk($disk)->delete($path), false, report: false);

        if ($deleted) {
            return;
        }

        Log::warning('Arquivo órfão do cadastro em lote não pôde ser removido.', [
            'disk' => $disk,
            'relative_path' => $path,
        ]);
    }

    private function normalizeTitle(DocumentBatchItem $item): string
    {
        $title = trim($item->title);

        if ($title === '') {
            $title = DocumentBatchItem::titleFromFileName($item->originalName());
        }

        return mb_substr($title, 0, 255);
    }

    private function outcome(
        DocumentBatchItem $item,
        DocumentBatchItemStatus $status,
        ?string $reason = null,
        ?DocumentBatchFileAnalysis $analysis = null,
        ?int $documentId = null,
    ): DocumentBatchItemOutcome {
        return new DocumentBatchItemOutcome(
            key: $item->key,
            originalName: $item->originalName(),
            title: $this->normalizeTitle($item),
            status: $status,
            reason: $reason,
            documentId: $documentId,
            duplicateWarning: $status === DocumentBatchItemStatus::Created
                ? $analysis?->duplicateWarning
                : null,
        );
    }

    private function logBatchSummary(DocumentBatchResult $result, DocumentBatchDefaults $defaults): void
    {
        Log::info('Cadastro de documentos em lote concluído.', [
            'user_id' => auth()->id(),
            'category' => $defaults->category,
            'emission_ids' => $defaults->emissionIds,
            'total' => count($result->outcomes),
            'created' => $result->createdCount(),
            'duplicated' => $result->countWithStatus(DocumentBatchItemStatus::Duplicated),
            'rejected' => $result->countWithStatus(DocumentBatchItemStatus::Rejected),
            'failed' => $result->countWithStatus(DocumentBatchItemStatus::Failed),
            'not_processed' => $result->countWithStatus(DocumentBatchItemStatus::NotProcessed),
            'document_ids' => $result->createdDocumentIds(),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Support\SalesBoards;

use App\DTOs\SalesBoards\StagedBuilderResponseAttachment;
use App\Services\SalesBoards\SalesBoardBuilderResponseEvidenceStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * O armazenamento da resposta da construtora sem disco.
 *
 * A maioria dos cenários envia a validação só para chegar à Gestão, e não
 * para provar o upload; gravar, varrer e apagar arquivos em cada um deles
 * custaria tempo e deixaria lixo no disco privado do checkout. As linhas dos
 * anexos continuam nascendo pelo serviço de envio, como na produção -- só o
 * arquivo não existe. Os testes que provam a gravação usam o armazenamento
 * real com `Storage::fake()`.
 *
 * Também é o que os processos filhos dos testes `mysql` usam: eles commitam, e
 * um arquivo gravado ali não seria apagado por ninguém.
 */
final class InMemoryBuilderResponseEvidenceStore extends SalesBoardBuilderResponseEvidenceStore
{
    /**
     * @param  list<UploadedFile>  $files
     * @return list<StagedBuilderResponseAttachment>
     */
    public function stage(array $files, int $cycleId): array
    {
        return array_map(
            fn (UploadedFile $file): StagedBuilderResponseAttachment => new StagedBuilderResponseAttachment(
                disk: 'local',
                path: 'nimbus_docs/test/'.self::STORAGE_DIRECTORY.'/'.$cycleId.'/'.Str::random(40).'.pdf',
                originalName: (string) $file->getClientOriginalName(),
                mimeType: 'application/pdf',
                sizeBytes: max(1, (int) $file->getSize()),
                checksum: hash('sha256', (string) $file->getClientOriginalName()),
            ),
            array_values($files),
        );
    }

    /**
     * @param  list<StagedBuilderResponseAttachment>  $staged
     */
    public function discard(array $staged): void {}

    /**
     * @param  list<StagedBuilderResponseAttachment>  $staged
     */
    public function deleteTemporaryUploads(array $staged): void {}
}

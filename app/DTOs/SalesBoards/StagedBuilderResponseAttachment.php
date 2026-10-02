<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;
use Illuminate\Http\UploadedFile;

/**
 * Um arquivo da resposta da construtora já gravado no disco privado, à espera
 * da transação do envio.
 *
 * Gravar acontece fora da transação -- a varredura e a cópia de até 5 arquivos
 * de 20 MB não podem segurar os locks do ciclo --, e a linha só nasce dentro
 * dela. Entre uma coisa e outra, quem guarda o que foi gravado é esta
 * estrutura: se o envio for recusado, o arquivo é apagado; se der certo, o
 * temporário do upload é que vai embora.
 *
 * Metadados derivados do arquivo gravado, não do que o navegador declarou.
 */
readonly class StagedBuilderResponseAttachment extends BaseDTO
{
    public function __construct(
        public string $disk,
        public string $path,
        public string $originalName,
        public ?string $mimeType,
        public int $sizeBytes,
        public ?string $checksum,
        public ?UploadedFile $temporaryUpload = null,
    ) {}
}

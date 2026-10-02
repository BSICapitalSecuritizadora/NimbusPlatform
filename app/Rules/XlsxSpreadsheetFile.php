<?php

namespace App\Rules;

use App\Support\Uploads\LocalUploadedFile;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Throwable;
use ZipArchive;

/**
 * Só aceita a planilha que os importadores sabem ler: um .xlsx de verdade.
 *
 * O tipo declarado não basta. O libmagic identifica parte dos XLSX legítimos
 * como `application/zip`, e a lista de tipos aceitos precisa admiti-lo -- então
 * a decisão é da estrutura: um zip com `[Content_Types].xml` e
 * `xl/workbook.xml`, e a extensão original `.xlsx`. É o mesmo critério da
 * importação de Recebíveis.
 *
 * O arquivo é aberto por {@see LocalUploadedFile}, para que a regra funcione
 * também com o envio temporário num disco sem caminho local.
 */
class XlsxSpreadsheetFile implements ValidationRule
{
    public const MESSAGE = 'Envie a planilha no formato .xlsx (Excel ou o modelo da plataforma).';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! ($value instanceof UploadedFile) || ! self::isSpreadsheet($value)) {
            $fail(self::MESSAGE);
        }
    }

    public static function isSpreadsheet(UploadedFile $file): bool
    {
        if (strtolower((string) $file->getClientOriginalExtension()) !== 'xlsx') {
            return false;
        }

        try {
            return LocalUploadedFile::using($file, static function (string $path): bool {
                $archive = new ZipArchive;

                if ($archive->open($path) !== true) {
                    return false;
                }

                try {
                    return ($archive->locateName('[Content_Types].xml') !== false)
                        && ($archive->locateName('xl/workbook.xml') !== false);
                } finally {
                    $archive->close();
                }
            });
        } catch (Throwable) {
            return false;
        }
    }
}

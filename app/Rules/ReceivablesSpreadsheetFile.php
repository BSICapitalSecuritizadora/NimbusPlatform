<?php

namespace App\Rules;

use App\Support\Uploads\LocalUploadedFile;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use ZipArchive;

class ReceivablesSpreadsheetFile implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            $fail('Envie uma planilha Excel valida no formato .xlsx.');

            return;
        }

        if (strtolower($value->getClientOriginalExtension()) !== 'xlsx') {
            $fail('Envie uma planilha Excel valida no formato .xlsx.');

            return;
        }

        /**
         * O zip é aberto por um caminho local legível. Com o envio temporário num
         * disco remoto, `getRealPath()` era relativo e toda planilha era recusada
         * como ilegível; {@see LocalUploadedFile} copia o arquivo nesse caso.
         */
        try {
            $isValidSpreadsheet = LocalUploadedFile::using($value, static function (string $path): ?bool {
                $archive = new ZipArchive;

                if ($archive->open($path) !== true) {
                    return null;
                }

                try {
                    return ($archive->locateName('[Content_Types].xml') !== false)
                        && ($archive->locateName('xl/workbook.xml') !== false);
                } finally {
                    $archive->close();
                }
            });
        } catch (RuntimeException) {
            $fail('Nao foi possivel ler o arquivo enviado.');

            return;
        }

        if ($isValidSpreadsheet !== true) {
            $fail('Envie uma planilha Excel valida no formato .xlsx.');
        }
    }
}

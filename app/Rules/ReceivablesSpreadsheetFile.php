<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
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

        $realPath = $value->getRealPath();

        if (! is_string($realPath) || ($realPath === '') || ! is_file($realPath)) {
            $fail('Nao foi possivel ler o arquivo enviado.');

            return;
        }

        $archive = new ZipArchive;
        $status = $archive->open($realPath);

        if ($status !== true) {
            $fail('Envie uma planilha Excel valida no formato .xlsx.');

            return;
        }

        $isValidSpreadsheet = ($archive->locateName('[Content_Types].xml') !== false)
            && ($archive->locateName('xl/workbook.xml') !== false);

        $archive->close();

        if (! $isValidSpreadsheet) {
            $fail('Envie uma planilha Excel valida no formato .xlsx.');
        }
    }
}

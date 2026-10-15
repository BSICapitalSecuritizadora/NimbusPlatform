<?php

namespace App\Http\Middleware;

use App\Filament\Support\AcceptsSpreadsheetImportUploads;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Troca a regra do envio temporário do Livewire pela regra de importação de
 * planilha, só no envio que pediu esse perfil por uma URL assinada.
 *
 * A regra global (`livewire.temporary_file_upload.rules`) vale para todo envio
 * da aplicação e não aceita CSV. Os campos de importação que leem CSV pedem a
 * URL de envio com `purpose=spreadsheet-import`
 * ({@see AcceptsSpreadsheetImportUploads}); como o
 * parâmetro entra na assinatura, o navegador não consegue pedir o perfil por
 * conta própria nem tirá-lo de uma URL que o recebeu. Sem assinatura válida, a
 * regra global continua valendo -- e o controlador do Livewire recusa o envio.
 */
class ApplySpreadsheetImportUploadRules
{
    public const PURPOSE = 'spreadsheet-import';

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs('livewire.upload-file')
            || $request->query('purpose') !== self::PURPOSE
            || ! $request->hasValidRelativeSignature()) {
            return $next($request);
        }

        $globalRules = config('livewire.temporary_file_upload.rules');

        config(['livewire.temporary_file_upload.rules' => config('livewire.temporary_file_upload.spreadsheet_import_rules')]);

        try {
            return $next($request);
        } finally {
            config(['livewire.temporary_file_upload.rules' => $globalRules]);
        }
    }
}

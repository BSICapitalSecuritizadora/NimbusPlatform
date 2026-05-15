<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Emissions\IntegralizationHistorySpreadsheetTemplate;
use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class IntegralizationHistoryTemplateDownloadController extends Controller
{
    public function __invoke(IntegralizationHistorySpreadsheetTemplate $integralizationHistorySpreadsheetTemplate): BinaryFileResponse
    {
        abort_unless(auth()->user()?->canAny(['emissions.view', 'settings.view']) ?? false, Response::HTTP_FORBIDDEN);

        $path = $integralizationHistorySpreadsheetTemplate->resolvePath();

        abort_unless(filled($path) && is_file($path), Response::HTTP_NOT_FOUND);

        return response()->download(
            $path,
            $integralizationHistorySpreadsheetTemplate->downloadName(),
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }
}

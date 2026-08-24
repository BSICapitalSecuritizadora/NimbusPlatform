<?php

namespace App\Http\Controllers\Admin;

use App\Actions\ConstructionUnits\ConstructionUnitSpreadsheetTemplate;
use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class ConstructionUnitTemplateDownloadController extends Controller
{
    public function __invoke(ConstructionUnitSpreadsheetTemplate $template): BinaryFileResponse
    {
        abort_unless(auth()->user()?->can('constructions.view') ?? false, Response::HTTP_FORBIDDEN);

        return response()->download(
            $template->build(),
            $template->downloadName(),
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        )->deleteFileAfterSend();
    }
}

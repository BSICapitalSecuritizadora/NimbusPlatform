<?php

namespace App\Http\Controllers\Admin;

use App\Actions\ConstructionUnitValues\UnitValueSpreadsheetTemplate;
use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class UnitValueTemplateDownloadController extends Controller
{
    public function __invoke(UnitValueSpreadsheetTemplate $template): BinaryFileResponse
    {
        abort_unless(auth()->user()?->can('constructions.update') ?? false, Response::HTTP_FORBIDDEN);

        return response()->download(
            $template->build(),
            $template->downloadName(),
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        )->deleteFileAfterSend();
    }
}

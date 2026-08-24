<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Contracts\ContractSpreadsheetTemplate;
use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class ContractTemplateDownloadController extends Controller
{
    public function __invoke(ContractSpreadsheetTemplate $template): BinaryFileResponse
    {
        abort_unless(auth()->user()?->can('contracts.view') ?? false, Response::HTTP_FORBIDDEN);

        return response()->download(
            $template->build(),
            $template->downloadName(),
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        )->deleteFileAfterSend();
    }
}

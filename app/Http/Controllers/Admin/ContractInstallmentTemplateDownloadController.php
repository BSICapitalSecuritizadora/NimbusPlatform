<?php

namespace App\Http\Controllers\Admin;

use App\Actions\ContractInstallments\ContractInstallmentSpreadsheetTemplate;
use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class ContractInstallmentTemplateDownloadController extends Controller
{
    public function __invoke(ContractInstallmentSpreadsheetTemplate $template): BinaryFileResponse
    {
        abort_unless(auth()->user()?->can('contract-installments.view') ?? false, Response::HTTP_FORBIDDEN);

        return response()->download(
            $template->build(),
            $template->downloadName(),
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        )->deleteFileAfterSend();
    }
}

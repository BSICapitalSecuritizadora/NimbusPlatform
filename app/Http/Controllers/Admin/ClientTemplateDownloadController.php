<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Clients\ClientSpreadsheetTemplate;
use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class ClientTemplateDownloadController extends Controller
{
    public function __invoke(ClientSpreadsheetTemplate $template): BinaryFileResponse
    {
        abort_unless(auth()->user()?->can('clients.view') ?? false, Response::HTTP_FORBIDDEN);

        return response()->download(
            $template->build(),
            $template->downloadName(),
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        )->deleteFileAfterSend();
    }
}

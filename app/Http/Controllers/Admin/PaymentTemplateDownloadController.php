<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Emissions\PaymentSpreadsheetTemplate;
use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class PaymentTemplateDownloadController extends Controller
{
    public function __invoke(PaymentSpreadsheetTemplate $paymentSpreadsheetTemplate): BinaryFileResponse
    {
        abort_unless(auth()->user()?->canAny(['emissions.view', 'settings.view']) ?? false, Response::HTTP_FORBIDDEN);

        $path = $paymentSpreadsheetTemplate->resolvePath();

        abort_unless(filled($path) && is_file($path), Response::HTTP_NOT_FOUND);

        return response()->download(
            $path,
            $paymentSpreadsheetTemplate->downloadName(),
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }
}

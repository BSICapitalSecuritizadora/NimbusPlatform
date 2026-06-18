<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ObligationEvidence;
use App\Services\DocumentStorageService;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ObligationEvidenceDownloadController extends Controller
{
    public function __invoke(
        ObligationEvidence $evidence,
        DocumentStorageService $documentStorageService,
    ): StreamedResponse {
        Gate::authorize('obligations.view');

        if (! $documentStorageService->exists($evidence->path, $evidence->disk)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return $documentStorageService->download($evidence->path, $evidence->original_name, $evidence->disk);
    }
}

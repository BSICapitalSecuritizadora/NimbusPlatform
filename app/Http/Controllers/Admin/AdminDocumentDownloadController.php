<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Services\DocumentStorageService;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminDocumentDownloadController extends Controller
{
    public function __invoke(
        Document $document,
        DocumentStorageService $documentStorageService,
    ): StreamedResponse {
        Gate::authorize('documents.view');

        $disk = $document->resolved_storage_disk;
        $path = $document->file_path;

        if (! $documentStorageService->exists($path, $disk)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $downloadName = $document->file_name ?: basename($path);

        return $documentStorageService->download($path, $downloadName, $disk);
    }
}

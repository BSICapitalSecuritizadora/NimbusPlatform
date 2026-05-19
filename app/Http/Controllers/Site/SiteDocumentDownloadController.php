<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Services\DocumentStorageService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SiteDocumentDownloadController extends Controller
{
    public function __invoke(
        Document $document,
        DocumentStorageService $documentStorageService,
    ): StreamedResponse {
        abort_unless(
            Document::query()->whereKey($document->id)->visibleOnPublicSite()->exists(),
            Response::HTTP_NOT_FOUND,
        );

        $disk = $document->resolved_storage_disk;
        $path = $document->file_path;

        if (! $documentStorageService->exists($path, $disk)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $downloadName = $document->file_name ?: basename($path);

        return $documentStorageService->download($path, $downloadName, $disk);
    }
}

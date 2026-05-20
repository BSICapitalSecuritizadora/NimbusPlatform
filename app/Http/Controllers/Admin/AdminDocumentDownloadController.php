<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentDownload;
use App\Services\DocumentStorageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminDocumentDownloadController extends Controller
{
    public function __invoke(
        Request $request,
        Document $document,
        DocumentStorageService $documentStorageService,
    ): StreamedResponse {
        Gate::authorize('documents.view');

        $disk = $document->resolved_storage_disk;
        $path = $document->file_path;

        if (! $documentStorageService->exists($path, $disk)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        DocumentDownload::create([
            'document_id' => $document->id,
            'admin_user_id' => $request->user()?->id,
            'source' => 'admin',
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'referer' => $request->headers->get('referer'),
            'downloaded_at' => now(),
        ]);

        $downloadName = $document->file_name ?: basename($path);

        return $documentStorageService->download($path, $downloadName, $disk);
    }
}

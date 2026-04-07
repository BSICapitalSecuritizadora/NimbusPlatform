<?php

namespace App\Http\Controllers\Nimbus;

use App\Http\Controllers\Controller;
use App\Models\Nimbus\PortalDocument;
use App\Services\DocumentStorageService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function index(Request $request): View
    {
        $portalUser = $request->user('nimbus');

        $documents = $portalUser->documents()
            ->latest()
            ->get();

        return view('nimbus.documents.index', [
            'documents' => $documents,
        ]);
    }

    public function download(
        Request $request,
        PortalDocument $document,
        DocumentStorageService $documentStorageService,
    ): StreamedResponse {
        $portalUser = $request->user('nimbus');

        if ((int) $document->nimbus_portal_user_id !== (int) $portalUser->id) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $disk = (string) config('filesystems.default');

        if (! $documentStorageService->exists($document->file_path, $disk)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return $documentStorageService->download(
            $document->file_path,
            $document->file_original_name ?: basename($document->file_path),
            $disk,
        );
    }
}

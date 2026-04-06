<?php

namespace App\Http\Controllers\Nimbus;

use App\Http\Controllers\Controller;
use App\Models\Nimbus\PortalDocument;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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

    public function download(Request $request, PortalDocument $document): StreamedResponse
    {
        $portalUser = $request->user('nimbus');

        if ((int) $document->nimbus_portal_user_id !== (int) $portalUser->id) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $disk = config('filesystems.default');

        if (! Storage::disk($disk)->exists($document->file_path)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return Storage::disk($disk)->download($document->file_path, $document->file_original_name);
    }
}

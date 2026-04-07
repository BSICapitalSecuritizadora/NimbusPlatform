<?php

namespace App\Http\Controllers\Nimbus;

use App\Http\Controllers\Controller;
use App\Models\Nimbus\DocumentCategory;
use App\Models\Nimbus\GeneralDocument;
use App\Models\Nimbus\PortalDocument;
use App\Services\DocumentStorageService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function index(Request $request): View
    {
        $portalUser = $request->user('nimbus');

        $q = $request->query('q');
        $categoryId = $request->query('category_id');

        $categories = DocumentCategory::query()->orderBy('name')->get();

        $generalDocuments = GeneralDocument::query()
            ->where('is_active', true)
            ->when($categoryId, function (Builder $query, $categoryId) {
                $query->where('nimbus_category_id', $categoryId);
            })
            ->when($q, function (Builder $query, $q) {
                $query->where(function (Builder $qBuilder) use ($q) {
                    $qBuilder->where('title', 'like', "%{$q}%")
                             ->orWhere('description', 'like', "%{$q}%");
                });
            })
            ->with('category')
            ->latest('published_at')
            ->get();

        $userDocs = $portalUser->documents()
            ->latest()
            ->get();

        return view('nimbus.documents.index', [
            'documents' => $generalDocuments,
            'userDocs' => $userDocs,
            'categories' => $categories,
            'currentCategory' => $categoryId,
            'term' => $q,
        ]);
    }

    public function preview(
        Request $request,
        PortalDocument $document,
        DocumentStorageService $documentStorageService,
    ): BinaryFileResponse {
        $portalUser = $request->user('nimbus');

        if ((int) $document->nimbus_portal_user_id !== (int) $portalUser->id) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $disk = (string) config('filesystems.default');

        if (! $documentStorageService->exists($document->file_path, $disk)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return $documentStorageService->preview(
            $document->file_path,
            null,
            $document->file_original_name ?: basename($document->file_path),
            $disk,
        );
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

    public function previewGeneral(
        Request $request,
        GeneralDocument $document,
        DocumentStorageService $documentStorageService,
    ): BinaryFileResponse {
        if (! $document->is_active) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $disk = (string) config('filesystems.default');

        if (! $documentStorageService->exists($document->file_path, $disk)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return $documentStorageService->preview(
            $document->file_path,
            $document->file_mime,
            $document->file_original_name ?: basename($document->file_path),
            $disk,
        );
    }

    public function downloadGeneral(
        Request $request,
        GeneralDocument $document,
        DocumentStorageService $documentStorageService,
    ): StreamedResponse {
        if (! $document->is_active) {
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

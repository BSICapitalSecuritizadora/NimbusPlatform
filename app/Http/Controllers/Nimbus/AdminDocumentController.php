<?php

namespace App\Http\Controllers\Nimbus;

use App\Http\Controllers\Controller;
use App\Models\Nimbus\GeneralDocument;
use App\Models\Nimbus\PortalDocument;
use App\Models\User;
use App\Services\DocumentStorageService;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminDocumentController extends Controller
{
    public function previewGeneral(
        Request $request,
        GeneralDocument $document,
        DocumentStorageService $documentStorageService,
    ): BinaryFileResponse {
        $this->authorizeDocumentAccess($request->user(), 'nimbus.general-documents.view');
        $this->abortIfMissing($documentStorageService, $document->file_path);

        return $documentStorageService->previewPrivate(
            $document->file_path,
            $document->file_mime,
            $document->file_original_name ?: basename($document->file_path),
        );
    }

    public function downloadGeneral(
        Request $request,
        GeneralDocument $document,
        DocumentStorageService $documentStorageService,
    ): StreamedResponse {
        $this->authorizeDocumentAccess($request->user(), 'nimbus.general-documents.view');
        $this->abortIfMissing($documentStorageService, $document->file_path);

        return $documentStorageService->downloadPrivate(
            $document->file_path,
            $document->file_original_name ?: basename($document->file_path),
        );
    }

    public function previewPortal(
        Request $request,
        PortalDocument $document,
        DocumentStorageService $documentStorageService,
    ): BinaryFileResponse {
        $this->authorizeDocumentAccess($request->user(), 'nimbus.portal-documents.view');
        $this->abortIfMissing($documentStorageService, $document->file_path);

        return $documentStorageService->previewPrivate(
            $document->file_path,
            $document->file_mime,
            $document->file_original_name ?: basename($document->file_path),
        );
    }

    public function downloadPortal(
        Request $request,
        PortalDocument $document,
        DocumentStorageService $documentStorageService,
    ): StreamedResponse {
        $this->authorizeDocumentAccess($request->user(), 'nimbus.portal-documents.view');
        $this->abortIfMissing($documentStorageService, $document->file_path);

        return $documentStorageService->downloadPrivate(
            $document->file_path,
            $document->file_original_name ?: basename($document->file_path),
        );
    }

    protected function authorizeDocumentAccess(?User $user, string $permission): void
    {
        $adminPanel = Filament::getPanel('admin');

        abort_unless(
            $user
                && $user->canAccessPanel($adminPanel)
                && ($user->hasAnyRole(['super-admin', 'admin']) || $user->can($permission)),
            Response::HTTP_FORBIDDEN,
        );
    }

    protected function abortIfMissing(DocumentStorageService $documentStorageService, string $path): void
    {
        if (! $documentStorageService->privateExists($path)) {
            abort(Response::HTTP_NOT_FOUND);
        }
    }
}

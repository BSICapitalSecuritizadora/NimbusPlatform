<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Services\DocumentStorageService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentDownloadController extends Controller
{
    public function __invoke(
        Request $request,
        Document $document,
        DocumentStorageService $documentStorageService,
    ): StreamedResponse {
        $investor = $request->user('investor');

        // 1) Valida ACL usando o "motor" (scope)
        $allowed = Document::query()
            ->whereKey($document->id)
            ->visibleToInvestor($investor->id)
            ->exists();

        if (! $allowed) {
            // anti-leak: se não publicado, retornamos 404 (melhor segurança)
            if (! $document->is_published) {
                abort(Response::HTTP_NOT_FOUND);
            }

            abort(Response::HTTP_FORBIDDEN);
        }

        // 2) Log de download (Auditoria de Acesso / Compliance)
        \App\Models\DocumentDownload::create([
            'document_id' => $document->id,
            'investor_id' => $investor->id,
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 2000),
            'referer' => $request->headers->get('referer'),
            'downloaded_at' => now(),
            'session_id' => $request->session()->getId(),
        ]);

        // 3) Entrega o arquivo (local/disk atual)
        $disk = $document->resolved_storage_disk;
        $path = $document->file_path;

        if (! $documentStorageService->exists($path, $disk)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $downloadName = $document->file_name ?: basename($path);

        return $documentStorageService->download($path, $downloadName, $disk);
    }
}

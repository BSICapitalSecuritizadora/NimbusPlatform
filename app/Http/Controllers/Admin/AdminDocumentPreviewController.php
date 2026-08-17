<?php

namespace App\Http\Controllers\Admin;

use App\Enums\MalwareScanStatus;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentDownload;
use App\Services\DocumentStorageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Abre um documento da operação no visualizador do navegador.
 *
 * Existe para que a proveniência do dossiê seja clicável: o download força
 * `attachment`, e um arquivo baixado ignora a âncora `#page=N`. Servindo o PDF
 * inline, o link da cláusula leva o revisor direto à página citada.
 *
 * As garantias de segurança são as mesmas do download — mesma policy, mesma
 * exigência de varredura limpa, mesmo storage privado — e a resposta inline
 * herda de {@see DocumentStorageService::preview()} a CSP restritiva e a lista
 * de MIMEs seguros: o que não for PDF ou imagem volta como anexo.
 *
 * O acesso continua sendo registrado: ver o documento é a mesma exposição que
 * baixá-lo, e a auditoria não pode ter um caminho cego.
 */
class AdminDocumentPreviewController extends Controller
{
    public function __invoke(
        Request $request,
        Document $document,
        DocumentStorageService $documentStorageService,
    ): BinaryFileResponse|StreamedResponse {
        Gate::authorize('documents.view');

        abort_unless($document->scan_status === MalwareScanStatus::Clean, Response::HTTP_NOT_FOUND);

        $disk = $document->resolved_storage_disk;
        $path = $document->file_path;

        if (! $documentStorageService->exists($path, $disk)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        DocumentDownload::create([
            'document_id' => $document->id,
            'admin_user_id' => $request->user()?->id,
            'source' => 'admin_preview',
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'referer' => $request->headers->get('referer'),
            'downloaded_at' => now(),
        ]);

        return $documentStorageService->preview(
            $path,
            $document->mime_type,
            $document->file_name ?: basename($path),
            $disk,
        );
    }
}

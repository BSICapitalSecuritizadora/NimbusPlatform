<?php

namespace App\Http\Controllers\Site;

use App\Enums\MalwareScanStatus;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Services\DocumentStorageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use League\Flysystem\PathTraversalDetected;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class SiteDocumentDownloadController extends Controller
{
    public function __invoke(
        Request $request,
        Document $document,
        DocumentStorageService $documentStorageService,
    ): StreamedResponse {
        $disk = $document->resolved_storage_disk;
        $path = trim((string) $document->file_path);

        $isPubliclyVisible = Document::query()
            ->whereKey($document->id)
            ->visibleOnPublicSite()
            ->exists();

        /**
         * O site público é anônimo: um documento não publicado não pode retornar 403,
         * porque isso confirmaria a existência do registro. A negativa de autorização
         * aqui é sempre 404. O 403 fica no portal autenticado do investidor, onde a
         * identidade do usuário já é conhecida.
         */
        if (! $isPubliclyVisible) {
            $this->logFailure($request, $document, $disk, $path, 'documento_nao_visivel_no_site_publico', false, null);

            abort(Response::HTTP_NOT_FOUND);
        }

        if ($path === '') {
            $this->logFailure($request, $document, $disk, $path, 'caminho_do_arquivo_vazio', true, null);

            abort(Response::HTTP_NOT_FOUND);
        }

        if ($document->scan_status !== MalwareScanStatus::Clean) {
            $this->logFailure($request, $document, $disk, $path, 'varredura_antivirus_nao_concluida', true, null);

            abort(Response::HTTP_NOT_FOUND);
        }

        try {
            $fileExists = $documentStorageService->exists($path, $disk);
        } catch (PathTraversalDetected) {
            $this->logFailure($request, $document, $disk, $path, 'caminho_do_arquivo_invalido', true, null);

            abort(Response::HTTP_NOT_FOUND);
        } catch (Throwable $exception) {
            $this->logFailure($request, $document, $disk, $path, 'falha_ao_verificar_existencia_no_disco', true, $exception);

            abort(Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        if (! $fileExists) {
            $this->logFailure($request, $document, $disk, $path, 'arquivo_ausente_no_disco', true, null, false);

            abort(Response::HTTP_NOT_FOUND);
        }

        $downloadName = $document->file_name ?: basename($path);

        try {
            return $documentStorageService->download($path, $downloadName, $disk);
        } catch (Throwable $exception) {
            $this->logFailure($request, $document, $disk, $path, 'falha_ao_ler_o_arquivo', true, $exception, true);

            abort(Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Registra o contexto do download sem expor conteúdo do arquivo ou dados pessoais.
     */
    protected function logFailure(
        Request $request,
        Document $document,
        string $disk,
        string $path,
        string $reason,
        bool $authorized,
        ?Throwable $exception,
        ?bool $fileExists = null,
    ): void {
        $context = [
            'reason' => $reason,
            'document_id' => $document->id,
            'user_id' => $request->user()?->id,
            'disk' => $disk,
            'relative_path' => $path,
            'file_exists' => $fileExists,
            'authorized' => $authorized,
            'environment' => app()->environment(),
        ];

        if ($exception instanceof Throwable) {
            $context['exception'] = $exception::class;
            $context['exception_message'] = $exception->getMessage();

            Log::error('Falha inesperada no download de documento do site público.', $context);

            return;
        }

        Log::warning('Download de documento do site público recusado.', $context);
    }
}

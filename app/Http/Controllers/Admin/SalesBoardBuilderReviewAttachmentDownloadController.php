<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SalesBoardBuilderReviewAttachment;
use App\Models\User;
use App\Services\DocumentStorageService;
use App\Support\SalesBoards\SalesBoardAccess;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Baixa um arquivo da resposta da construtora anexado a uma validação.
 *
 * Quem enxerga o Quadro de Vendas enxerga a validação e a resposta que a
 * sustenta: a regra é a mesma das telas do módulo
 * ({@see SalesBoardAccess::canView()}), conferida a cada requisição. Arquivo
 * que não passou pela varredura, ou que sumiu do disco, responde 404 -- o
 * mesmo que um arquivo que nunca existiu.
 */
class SalesBoardBuilderReviewAttachmentDownloadController extends Controller
{
    public function __invoke(
        SalesBoardBuilderReviewAttachment $attachment,
        DocumentStorageService $documentStorageService,
    ): StreamedResponse {
        $user = auth()->user();

        abort_unless(SalesBoardAccess::canView($user instanceof User ? $user : null), Response::HTTP_FORBIDDEN);

        abort_unless($attachment->isAvailable(), Response::HTTP_NOT_FOUND);

        if (! $documentStorageService->isAllowedMeasurementReadDisk((string) $attachment->disk)
            || ! $documentStorageService->exists((string) $attachment->path, (string) $attachment->disk)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return $documentStorageService->download(
            (string) $attachment->path,
            (string) $attachment->original_name,
            (string) $attachment->disk,
        );
    }
}

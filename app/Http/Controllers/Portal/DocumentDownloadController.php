<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class DocumentDownloadController extends Controller
{
    public function __invoke(Request $request, Document $document)
    {
        $investor = $request->user('investor');

        // Verifica se o documento está acessível para o investidor (usando a query com todos os vínculos de ACL + público)
        $canAccess = Document::query()
            ->whereKey($document->id)
            ->visibleToInvestor($investor->id)
            ->exists();

        if (! $canAccess) {
            abort(Response::HTTP_FORBIDDEN);
        }

        $disk = Storage::disk(config('filesystems.default'));
        $path = $document->file_path;

        if (! $disk->exists($path)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        if ($disk->providesTemporaryUrls()) {
            $url = $disk->temporaryUrl($path, now()->addMinutes(10));

            return redirect()->away($url);
        }

        $downloadName = $document->file_name ?: basename($path);

        return $disk->download($path, $downloadName);
    }
}

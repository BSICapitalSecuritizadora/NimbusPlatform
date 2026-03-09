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

        // Se você guarda no disk "public":
        $disk = config('filesystems.default'); // ou 'public'
        $path = $document->file_path;

        if (! Storage::disk($disk)->exists($path)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        // Nome amigável:
        $downloadName = $document->file_name ?: basename($path);

        return Storage::disk($disk)->download($path, $downloadName);
    }
}

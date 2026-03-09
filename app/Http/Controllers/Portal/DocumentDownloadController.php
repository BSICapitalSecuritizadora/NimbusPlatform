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

        // Regra recomendada:
        // - precisa estar publicado
        // - e precisa ter permissão: vinculado ao investidor OU (publicado + público, se você permitir)
        if (! $document->is_published) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $canAccess =
            $document->investors()->whereKey($investor->id)->exists()
            || ($document->is_public === true); // opcional: permita docs públicos no portal

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

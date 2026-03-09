<?php

namespace App\Http\Controllers\Investor;

use App\Http\Controllers\Controller;
use App\Models\Document;
use Illuminate\Support\Facades\Storage;

class InvestorDocumentsController extends Controller
{
    public function index()
    {
        $investor = auth('investor')->user();

        // Somente documentos publicados e vinculados ao investidor
        $documents = $investor->documents()
            ->where('documents.is_published', true)
            ->orderByDesc('documents.created_at')
            ->get();

        return view('investor.documents.index', compact('documents'));
    }

    public function download(Document $document)
    {
        $investor = auth('investor')->user();

        // Download seguro: publicado + vínculo
        $allowed = $investor->documents()
            ->where('documents.id', $document->id)
            ->where('documents.is_published', true)
            ->exists();

        abort_unless($allowed, 403);

        // Ajuste o disk conforme seu FileUpload (recomendo usar 'public' no FileUpload)
        $disk = 'public';

        $fileName = $document->file_name ?: basename($document->file_path);

        return Storage::disk($disk)->download($document->file_path, $fileName);
    }
}

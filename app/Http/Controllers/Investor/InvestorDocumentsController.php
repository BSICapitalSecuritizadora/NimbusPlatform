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

        // Lista documentos através do escopo mestre de ACL validado
        $documents = Document::query()
            ->visibleToInvestor($investor->id)
            ->orderByVisibilityPriority($investor->id)
            ->latest()
            ->paginate(20);

        return view('investor.documents.index', compact('documents'));
    }

    public function download(Document $document)
    {
        $investor = auth('investor')->user();

        // Download seguro via escopo mestre de ACL validado
        $allowed = Document::query()
            ->whereKey($document->id)
            ->visibleToInvestor($investor->id)
            ->exists();

        abort_unless($allowed, 403);

        // Ajuste o disk conforme seu FileUpload (recomendo usar 'public' no FileUpload)
        $disk = 'public';

        $fileName = $document->file_name ?: basename($document->file_path);

        return Storage::disk($disk)->download($document->file_path, $fileName);
    }
}

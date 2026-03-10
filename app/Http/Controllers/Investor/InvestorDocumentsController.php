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

        $allowed = Document::query()
            ->whereKey($document->id)
            ->visibleToInvestor($investor->id)
            ->exists();

        abort_unless($allowed, 403);

        $disk = Storage::disk(config('filesystems.default'));
        $path = $document->file_path;

        abort_unless($disk->exists($path), 404);

        if ($disk->providesTemporaryUrls()) {
            $url = $disk->temporaryUrl($path, now()->addMinutes(10));

            return redirect()->away($url);
        }

        $fileName = $document->file_name ?: basename($path);

        return $disk->download($path, $fileName);
    }
}

<?php

namespace App\Http\Controllers\Investor;

use App\Http\Controllers\Controller;
use App\Models\Document;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvestorDocumentsController extends Controller
{
    public function index(): View
    {
        return view('investor.documents.index');
    }

    public function download(Document $document): StreamedResponse|RedirectResponse|Response
    {
        $investor = auth('investor')->user();

        if (! $document->is_published) {
            abort(404);
        }

        $allowed = Document::query()
            ->whereKey($document->id)
            ->visibleToInvestor($investor->id)
            ->exists();

        abort_unless($allowed, 403);

        $disk = Storage::disk($document->resolved_storage_disk);
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

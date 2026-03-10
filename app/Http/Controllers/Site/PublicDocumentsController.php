<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Document;

class PublicDocumentsController extends Controller
{
    public function index()
    {
        $documents = Document::query()
            ->visibleOnPublicSite()
            ->orderByDesc('published_at')
            ->orderByDesc('created_at')
            ->paginate(20);

        return view('site.public-documents', compact('documents'));
    }
}

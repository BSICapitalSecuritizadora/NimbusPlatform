<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Emission;

class HomeController extends Controller
{
    public function index()
    {
        $emissions = Emission::query()
            ->where('is_public', true)
            ->orderByDesc('id')
            ->limit(6)
            ->get(['id', 'name', 'type', 'status', 'issuer', 'maturity_date']);

        $riDocuments = Document::query()
            ->published()
            ->public()
            ->orderByDesc('published_at')
            ->limit(6)
            ->get(['id', 'title', 'category', 'published_at']);

        return view('site.home', compact('emissions', 'riDocuments'));
    }
}
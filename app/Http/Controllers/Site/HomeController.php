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
            ->orderByDesc('issue_date')
            ->limit(6)
            ->get(['id', 'name', 'logo_path', 'type', 'if_code', 'status', 'issuer', 'maturity_date', 'issued_volume']);

        $riDocuments = Document::query()
            ->published()
            ->public()
            ->orderByDesc('published_at')
            ->limit(3)
            ->get(['id', 'title', 'category', 'published_at', 'file_path', 'storage_disk']);

        return view('site.home', compact('emissions', 'riDocuments'));
    }
}
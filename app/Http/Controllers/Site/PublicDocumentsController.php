<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PublicDocumentsController extends Controller
{
    public function index(Request $request): RedirectResponse
    {
        return redirect()->route('site.ri', array_filter([
            'q' => $request->query('q'),
            'category' => $request->query('category'),
        ]));
    }
}

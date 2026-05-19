<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;

class HomeController extends Controller
{
    public function index(): \Illuminate\Contracts\View\View
    {
        return view('site.home');
    }
}

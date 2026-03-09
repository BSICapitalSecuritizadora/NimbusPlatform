<?php

namespace App\Http\Controllers\Investor;

use App\Http\Controllers\Controller;

class InvestorDashboardController extends Controller
{
    public function index()
    {
        return view('investor.dashboard');
    }
}

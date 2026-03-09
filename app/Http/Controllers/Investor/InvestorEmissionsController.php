<?php

namespace App\Http\Controllers\Investor;

use App\Http\Controllers\Controller;

class InvestorEmissionsController extends Controller
{
    public function index()
    {
        $investor = auth('investor')->user();

        $emissions = $investor->emissions()
            ->orderBy('name')
            ->get();

        return view('investor.emissions.index', compact('emissions'));
    }
}

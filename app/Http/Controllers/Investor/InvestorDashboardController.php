<?php

namespace App\Http\Controllers\Investor;

use App\Http\Controllers\Controller;
use App\Models\Document;

class InvestorDashboardController extends Controller
{
    public function index()
    {
        $investor = auth('investor')->user();

        // Count new documents before we update the timestamp
        $newDocsCount = Document::query()
            ->visibleToInvestor($investor->id)
            ->where(function($q) use ($investor) {
                $q->where('published_at', '>', $investor->last_portal_seen_at ?? '1970-01-01')
                  ->orWhere(function($qNull) use ($investor) {
                      $qNull->whereNull('published_at')->where('created_at', '>', $investor->last_portal_seen_at ?? '1970-01-01');
                  });
            })
            ->count();

        // Update the timestamp for the current access
        $investor->forceFill(['last_portal_seen_at' => now()])->save();

        return view('investor.dashboard', compact('investor', 'newDocsCount'));
    }
}

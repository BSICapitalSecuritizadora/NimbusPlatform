<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Emission;
use App\Models\EmissionAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class SiteController extends Controller
{
    public function governance()
    {
        $documents = Document::query()
            ->visibleOnPublicSite()
            ->where('category', 'governanca')
            ->orderByDesc('published_at')
            ->get();

        return view('site.governance', compact('documents'));
    }

    public function complianceBsi()
    {
        $documents = Document::query()
            ->visibleOnPublicSite()
            ->where('category', 'governanca')
            ->orderByDesc('published_at')
            ->get();

        return view('site.compliance', compact('documents'));
    }

    public function emissions(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $type = $request->query('type');
        $issue_date_order = $request->query('issue_date_order');
        $maturity_date_order = $request->query('maturity_date_order');

        $emissions = Emission::query()
            ->where('is_public', true)
            ->whereNotNull('if_code')
            ->when($q !== '', function ($qq) use ($q) {
                $qq->where(function ($query) use ($q) {
                    $query->where('name', 'like', "%{$q}%")
                        ->orWhere('issuer', 'like', "%{$q}%");
                });
            })
            ->when($type, function ($qq) use ($type) {
                $qq->where('type', $type);
            })
            ->when($issue_date_order === 'asc' || $issue_date_order === 'desc', function ($qq) use ($issue_date_order) {
                $qq->orderBy('issue_date', $issue_date_order);
            })
            ->when($maturity_date_order === 'asc' || $maturity_date_order === 'desc', function ($qq) use ($maturity_date_order) {
                $qq->orderBy('maturity_date', $maturity_date_order);
            })
            ->when(! $issue_date_order && ! $maturity_date_order, function ($qq) {
                $qq->orderByDesc('issue_date');
            })
            ->paginate(12)
            ->withQueryString();

        return view('site.emissions', compact('emissions', 'q', 'type', 'issue_date_order', 'maturity_date_order'));
    }

    public function emissionShow(Request $request, $if_code)
    {
        $emission = Emission::where('if_code', $if_code)
            ->where('is_public', true)
            ->firstOrFail();

        $investorCanView = $this->investorCanViewEmission($request, $emission);
        $authorizedAccess = $this->resolveAuthorizedEmissionAccess($request, $emission);

        if (! $investorCanView && ! $authorizedAccess) {
            return view('site.emission-access', [
                'emission' => $emission,
                'access' => null,
            ]);
        }

        $authorizedAccess?->markAuthorizedUsage();

        $emission->load([
            'documents' => function ($q) {
                $q->where('is_public', true)->orderByDesc('published_at');
            },
            'payments' => function ($q) {
                $q->where('payment_date', '<=', today())->orderBy('payment_date');
            },
        ]);

        return view('site.emission-detail', compact('emission'));
    }

    protected function investorCanViewEmission(Request $request, Emission $emission): bool
    {
        $investor = $request->user('investor');

        if (! $investor) {
            return false;
        }

        return $emission->investors()->whereKey($investor->id)->exists();
    }

    protected function resolveAuthorizedEmissionAccess(Request $request, Emission $emission): ?EmissionAccess
    {
        $authorizedAccessId = $request->session()->get(
            EmissionAccess::authorizationSessionKeyForEmission($emission->id),
        );

        if (! $authorizedAccessId) {
            return null;
        }

        $access = EmissionAccess::query()
            ->whereKey($authorizedAccessId)
            ->where('emission_id', $emission->id)
            ->first();

        if (! $access || ! $access->isVerified() || ! $access->isActive()) {
            $request->session()->forget(
                EmissionAccess::authorizationSessionKeyForEmission($emission->id),
            );

            return null;
        }

        return $access;
    }

    public function ri(Request $request)
    {
        $categories = collect(Document::CATEGORY_OPTIONS)
            ->except(['governanca'])
            ->toArray();

        $category = $request->query('category');
        $q = trim((string) $request->query('q', ''));

        $dateField = Schema::hasColumn('documents', 'published_at') ? 'published_at' : 'created_at';

        $docs = Document::query()
            ->with('emissions:emissions.id,emissions.name')
            ->published()
            ->public()
            ->where('category', '!=', 'governanca')
            ->when($category, fn ($qq) => $qq->where('category', $category))
            ->when($q !== '', fn ($qq) => $qq->where('title', 'like', "%{$q}%"))
            ->orderByDesc($dateField)
            ->paginate(15)
            ->withQueryString();

        return view('site.ri', compact('docs', 'categories', 'category', 'q', 'dateField'));
    }
}

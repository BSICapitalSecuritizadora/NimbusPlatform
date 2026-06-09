<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Http\Requests\Site\ContactFormRequest;
use App\Mail\ContactFormMail;
use App\Models\ContactMessage;
use App\Models\Document;
use App\Models\Emission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

class SiteController extends Controller
{
    public function submitContact(ContactFormRequest $request): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validated();

        ContactMessage::create($data);

        Mail::to(config('services.contact.email'))->send(new ContactFormMail($data));

        return back()->with('contact_success', true);
    }

    public function governance()
    {
        $documents = Document::query()
            ->visibleOnPublicSite()
            ->where('category', 'governanca')
            ->orderByDesc('published_at')
            ->get();

        return view('site.governance', compact('documents'));
    }

    public function documentosAcl()
    {
        $latestEmissions = Emission::query()
            ->where('is_public', true)
            ->whereNotNull('if_code')
            ->orderByDesc('issue_date')
            ->limit(3)
            ->get();

        $stats = [
            'total_volume' => Emission::where('is_public', true)->sum('issued_volume'),
            'active_count' => Emission::where('is_public', true)->count(),
            'document_count' => Document::visibleOnPublicSite()->count(),
        ];

        return view('site.servicos.documentos-acl', compact('latestEmissions', 'stats'));
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
                        ->orWhere('issuer', 'like', "%{$q}%")
                        ->orWhere('if_code', 'like', "%{$q}%")
                        ->orWhere('isin_code', 'like', "%{$q}%");
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

    public function criRealEstate(): \Illuminate\View\View
    {
        $featuredEmissions = Emission::query()
            ->with(['documents' => function ($query) {
                $query->visibleOnPublicSite()->orderByDesc('published_at');
            }])
            ->where('is_public', true)
            ->whereNotNull('if_code')
            ->where('type', 'CRI')
            ->orderByDesc('issue_date')
            ->limit(3)
            ->get();

        return view('site.imobiliario.cri', compact('featuredEmissions'));
    }

    public function emissionShow(string $if_code)
    {
        $emission = Emission::where('if_code', $if_code)
            ->where('is_public', true)
            ->firstOrFail();

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

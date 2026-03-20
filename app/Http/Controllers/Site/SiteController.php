<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Emission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class SiteController extends Controller
{
    public function services()
    {
        return view('site.services');
    }

    public function about()
    {
        return view('site.about');
    }

    public function governance()
    {
        return view('site.governance');
    }

    public function contact()
    {
        return view('site.contact');
    }

    public function emissions(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        $emissions = Emission::query()
            ->where('is_public', true)
            ->when($q !== '', function ($qq) use ($q) {
                $qq->where('name', 'like', "%{$q}%")
                    ->orWhere('issuer', 'like', "%{$q}%");
            })
            ->orderByDesc('issue_date')
            ->paginate(12)
            ->withQueryString();

        return view('site.emissions', compact('emissions', 'q'));
    }

    public function emissionShow($if_code)
    {
        $emission = Emission::where('if_code', $if_code)
            ->where('is_public', true)
            ->with(['documents' => function($q) {
                $q->where('is_public', true)->orderByDesc('published_at');
            }])
            ->firstOrFail();

        return view('site.emission-detail', compact('emission'));
    }

    public function ri(Request $request)
    {
        $categories = [
            'anuncios' => 'Anúncios',
            'assembleias' => 'Assembleias',
            'convocacoes_assembleias' => 'Convocações para Assembleias',
            'demonstracoes_financeiras' => 'Demonstrações Financeiras',
            'documentos_operacao' => 'Documentos da Operação',
            'fatos_relevantes' => 'Fatos Relevantes',
            'relatorios_anuais' => 'Relatórios Anuais',
        ];

        $category = $request->query('category');
        $q = trim((string) $request->query('q', ''));

        $dateField = Schema::hasColumn('documents', 'published_at') ? 'published_at' : 'created_at';

        $docs = Document::query()
            ->published()
            ->public()
            ->when($category, fn ($qq) => $qq->where('category', $category))
            ->when($q !== '', fn ($qq) => $qq->where('title', 'like', "%{$q}%"))
            ->orderByDesc($dateField)
            ->paginate(15)
            ->withQueryString();

        return view('site.ri', compact('docs', 'categories', 'category', 'q', 'dateField'));
    }
}
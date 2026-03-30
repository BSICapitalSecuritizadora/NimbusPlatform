<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Emission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class SiteController extends Controller
{
    public function criRealEstate()
    {
        return view('site.imobiliario.cri');
    }

    public function loteamentos()
    {
        return view('site.imobiliario.loteamentos');
    }

    public function incorporacao()
    {
        return view('site.imobiliario.incorporacao');
    }

    public function cra()
    {
        return view('site.agronegocio.cra');
    }

    public function cooperativas()
    {
        return view('site.agronegocio.cooperativas');
    }

    public function projetos()
    {
        return view('site.agronegocio.projetos');
    }

    public function crFuturo()
    {
        return view('site.infra-empresas.cr-futuro');
    }

    public function recebiveis()
    {
        return view('site.infra-empresas.recebiveis');
    }

    public function estruturacaoSobMedida()
    {
        return view('site.infra-empresas.estruturacao');
    }

    public function originacao()
    {
        return view('site.servicos.originacao');
    }

    public function estruturaJuridica()
    {
        return view('site.servicos.estrutura-juridica');
    }

    public function registroDistribuicao()
    {
        return view('site.servicos.registro-distribuicao');
    }

    public function portalInvestidor()
    {
        return view('site.servicos.portal-investidor');
    }

    public function relatorios()
    {
        return view('site.servicos.relatorios');
    }

    public function compliance()
    {
        return view('site.servicos.compliance');
    }

    public function documentosAcl()
    {
        return view('site.servicos.documentos-acl');
    }

    public function auditoriaAcessos()
    {
        return view('site.servicos.auditoria-acessos');
    }

    public function integracoes()
    {
        return view('site.servicos.integracoes');
    }

    public function services()
    {
        return view('site.service');
    }

    public function about()
    {
        return view('site.about');
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

    public function complianceBsi()
    {
        $documents = Document::query()
            ->visibleOnPublicSite()
            ->where('category', 'governanca')
            ->orderByDesc('published_at')
            ->get();

        return view('site.compliance', compact('documents'));
    }

    public function contact()
    {
        return view('site.contact');
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

    public function emissionShow($if_code)
    {
        $emission = Emission::where('if_code', $if_code)
            ->where('is_public', true)
            ->with(['documents' => function ($q) {
                $q->where('is_public', true)->orderByDesc('published_at');
            }])
            ->with(['payments' => function ($q) {
                $q->where('payment_date', '<=', today())->orderBy('payment_date');
            }])
            ->firstOrFail();

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

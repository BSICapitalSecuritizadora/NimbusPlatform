<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Document;
use Illuminate\Http\Request;

class DocumentsController extends Controller
{
    public function index(Request $request)
    {
        $investor = $request->user('investor');

        // === inputs ===
        $search     = trim((string) $request->query('q', ''));
        $category   = $request->query('category');
        $emissionId = $request->query('emission_id');
        $dateFrom   = $request->query('from'); // YYYY-MM-DD
        $dateTo     = $request->query('to');   // YYYY-MM-DD
        $onlyNew    = (bool) $request->boolean('only_new');

        // Campo de data para filtro (recomendado: published_at se existir)
        $dateField = \Schema::hasColumn('documents', 'published_at') ? 'published_at' : 'created_at';

        // Emissões disponíveis (somente as do investidor)
        $emissions = $investor->emissions()
            ->select('emissions.id', 'emissions.name')
            ->orderBy('emissions.name')
            ->get();

        // Categorias (fixas do seu domínio)
        $categories = [
            'anuncios' => 'Anúncios',
            'assembleias' => 'Assembleias',
            'convocacoes_assembleias' => 'Convocações para Assembleias',
            'demonstracoes_financeiras' => 'Demonstrações Financeiras',
            'documentos_operacao' => 'Documentos da Operação',
            'fatos_relevantes' => 'Fatos Relevantes',
            'relatorios_anuais' => 'Relatórios Anuais',
        ];

        // Base ACL
        $query = Document::query()
            ->visibleToInvestor($investor->id)
            ->when($search !== '', fn ($q) => $q->where('title', 'like', "%{$search}%"))
            ->when($category, fn ($q) => $q->where('category', $category))
            ->when($emissionId, fn ($q) => $q->whereHas('emissions', fn ($qq) => $qq->whereKey($emissionId)))
            ->when($dateFrom, fn ($q) => $q->whereDate($dateField, '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate($dateField, '<=', $dateTo))
            ->when($onlyNew, fn ($q) => $q->where($dateField, '>', $investor->last_portal_seen_at ?? '1970-01-01'))
            ->orderByDesc($dateField);

        $documents = $query->paginate(15)->withQueryString();

        // “novos desde último acesso” (para exibir badge/contador)
        $newCount = Document::query()
            ->visibleToInvestor($investor->id)
            ->where($dateField, '>', $investor->last_portal_seen_at ?? '1970-01-01')
            ->count();



        return view('portal.documents.index', compact(
            'documents',
            'emissions',
            'categories',
            'newCount',
            'search',
            'category',
            'emissionId',
            'dateFrom',
            'dateTo',
            'onlyNew',
            'dateField'
        ));
    }
}
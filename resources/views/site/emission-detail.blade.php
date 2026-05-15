@extends('site.layout')

@section('title', $emission->name . ' - Detalhes da Emissao - BSI Capital')

@section('content')
@php
    $cspNonce = \Illuminate\Support\Facades\Vite::cspNonce();

    $statusPalette = match ($emission->status) {
        'active' => ['bg' => 'rgba(34,197,94,0.12)', 'border' => 'rgba(34,197,94,0.22)', 'text' => '#15803d', 'label' => $emission->status_label],
        'closed' => ['bg' => 'rgba(239,68,68,0.12)', 'border' => 'rgba(239,68,68,0.22)', 'text' => '#b91c1c', 'label' => $emission->status_label],
        default => ['bg' => 'rgba(245,158,11,0.12)', 'border' => 'rgba(245,158,11,0.22)', 'text' => '#b45309', 'label' => $emission->status_label],
    };

    $summaryCards = [
        ['label' => 'Tipo', 'value' => $emission->type ?? '—'],
        ['label' => 'Remuneração', 'value' => $emission->formatted_remuneration ?? '—'],
        ['label' => 'Vencimento', 'value' => $emission->maturity_date?->format('d/m/Y') ?? '—'],
        ['label' => 'Volume emitido', 'value' => $emission->issued_volume ? 'R$ ' . number_format((float) $emission->issued_volume, 2, ',', '.') : '—'],
    ];

@endphp

<section class="hero position-relative overflow-hidden" style="padding-top: 4.75rem; padding-bottom: 4rem;">
    <div class="container position-relative">
        <div class="row g-4 align-items-end">
            <div class="col-lg-8">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                    <span class="badge px-3 py-2 text-uppercase">Detalhe da emissão</span>
                    @if($emission->type)
                        <span class="badge badge-soft px-3 py-2">{{ $emission->type }}</span>
                    @endif
                    <span class="badge px-3 py-2" style="background: {{ $statusPalette['bg'] }}; border: 1px solid {{ $statusPalette['border'] }}; color: {{ $statusPalette['text'] }};">
                        {{ $statusPalette['label'] }}
                    </span>
                </div>

                <div class="d-flex flex-wrap align-items-center gap-4 mb-4">
                    @if($emission->logo_path)
                        <div class="surface-card-dark d-inline-flex align-items-center justify-content-center px-4 py-3" style="min-height: 86px; min-width: 180px;">
                            <img src="{{ Storage::disk($emission->logo_storage_disk)->url($emission->logo_path) }}" alt="{{ $emission->name }}" style="max-height: 52px; max-width: 180px; object-fit: contain;">
                        </div>
                    @endif
                    <div>
                        <h1 class="display-5 fw-bold mb-2">{{ $emission->name }}</h1>
                        <div class="d-flex flex-wrap gap-3 small text-white-50">
                            <span>IF {{ $emission->if_code ?? '—' }}</span>
                            <span>ISIN {{ $emission->isin_code ?? '—' }}</span>
                            <span>Emissor: {{ $emission->issuer ?? '—' }}</span>
                        </div>
                    </div>
                </div>

                <p class="lead mb-0" style="max-width: 860px;">
                    Detalhamento técnico completo da estrutura, cronograma de fluxos e documentos regulatórios. Centralizamos todas as informações vitais da emissão para garantir a rastreabilidade e o monitoramento preciso do ativo.
                </p>
            </div>

            <div class="col-lg-4">
                <div class="surface-card-dark p-4 p-lg-5 h-100">
                    <div class="section-kicker mb-2">Visão rápida</div>
                    <h2 class="h3 fw-bold text-white mb-3">Resumo operacional</h2>
                    <div class="d-flex flex-column gap-3">
                        <div class="d-flex justify-content-between gap-3">
                            <span class="text-white-50">Número da emissão</span>
                            <span class="fw-semibold text-white">{{ $emission->emission_number ?? '—' }}</span>
                        </div>
                        <div class="d-flex justify-content-between gap-3">
                            <span class="text-white-50">Série</span>
                            <span class="fw-semibold text-white">{{ $emission->series ?? '—' }}</span>
                        </div>
                        <div class="d-flex justify-content-between gap-3">
                            <span class="text-white-50">Data de emissão</span>
                            <span class="fw-semibold text-white">{{ $emission->issue_date?->format('d/m/Y') ?? '—' }}</span>
                        </div>
                        <div class="d-flex justify-content-between gap-3">
                            <span class="text-white-50">Segmento</span>
                            <span class="fw-semibold text-white">{{ $emission->segment ?? '—' }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="py-5">
    <div class="container py-lg-4">
        <div class="row g-4 mb-4">
            @foreach($summaryCards as $summaryCard)
                <div class="col-sm-6 col-xl-3">
                    <div class="surface-card h-100 p-4">
                        <div class="section-kicker mb-2">{{ $summaryCard['label'] }}</div>
                        <div class="h4 fw-bold text-brand mb-0" style="line-height: 1.4;">{{ $summaryCard['value'] }}</div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="surface-card p-3 p-lg-4 mb-4">
            <ul class="nav nav-pills gap-2 emission-detail-tabs" id="emissionTabs" role="tablist">
                <li class="nav-item"><a class="nav-link active" href="#caracteristicas">Características</a></li>
                <li class="nav-item"><a class="nav-link" href="#pagamentos">Pagamentos</a></li>
                <li class="nav-item"><a class="nav-link" href="#documentos">Documentos</a></li>
            </ul>
        </div>

        <div class="surface-card p-4 p-lg-5 mb-4" id="caracteristicas">
            <div class="row g-4 align-items-end mb-4">
                <div class="col-lg-8">
                    <div class="section-kicker mb-2">Características</div>
                    <h2 class="h3 fw-bold text-brand mb-2">Ficha Técnica e Variáveis da Operação</h2>
                    <p class="section-copy mb-0">Informações consolidadas sobre os aspectos jurídicos, comerciais e de governança. Dados estruturados para fundamentar o monitoramento contínuo e a análise de conformidade do ativo.</p>
                </div>
            </div>

            <div class="row g-4">
                @foreach([
                    'Série' => $emission->series,
                    'Número da emissão' => $emission->emission_number,
                    'Emissor' => $emission->issuer,
                    'Coordenador líder' => $emission->lead_coordinator,
                    'Agente fiduciário' => $emission->trustee_agent,
                    'Data de emissão' => $emission->issue_date?->format('d/m/Y'),
                    'Data de vencimento' => $emission->maturity_date?->format('d/m/Y'),
                    'Remuneração' => $emission->formatted_remuneration,
                    'Tipo de oferta' => $emission->offer_type,
                    'Segmento' => $emission->segment,
                    'Concentração' => $emission->concentration,
                    'Preço de emissão' => $emission->issued_price ? 'R$ ' . number_format((float) $emission->issued_price, 2, ',', '.') : null,
                    'Quantidade emitida' => $emission->issued_quantity ? number_format((float) $emission->issued_quantity, 0, ',', '.') : null,
                    'Quantidade integralizada' => $emission->integralized_quantity ? number_format((float) $emission->integralized_quantity, 0, ',', '.') : null,
                    'Volume emitido' => $emission->issued_volume ? 'R$ ' . number_format((float) $emission->issued_volume, 0, ',', '.') : null,
                ] as $label => $value)
                    <div class="col-sm-6 col-xl-4">
                        <div class="surface-card-soft h-100 p-4">
                            <div class="small text-uppercase text-muted fw-semibold mb-2">{{ $label }}</div>
                            <div class="fw-semibold text-brand">{{ $value ?: '—' }}</div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="surface-card p-4 p-lg-5 mb-4" id="pagamentos">
            <div class="row g-4 align-items-end mb-4">
                <div class="col-lg-8">
                    <div class="section-kicker mb-2">Fluxo financeiro</div>
                    <h2 class="h3 fw-bold text-brand mb-2">Fluxo de pagamentos e acompanhamento</h2>
                    <p class="section-copy mb-0">Consolidação dos eventos financeiros da operação, incluindo histórico de PU e integralização.</p>
                </div>
            </div>

            <div class="surface-card-soft p-4 mb-4" style="min-height: 360px;">
                @if(isset($emission->payments) && $emission->payments->count() > 0)
                    <div style="position: relative; height: 320px; width: 100%;">
                        <canvas id="paymentsChart"></canvas>
                    </div>
                @else
                    <div class="d-flex align-items-center justify-content-center rounded-4 border border-brand-subtle text-muted text-center px-4" style="min-height: 320px; border-style: dashed !important;">
                        Nenhum dado de pagamento registrado até o momento.
                    </div>
                @endif
            </div>

            <div class="row g-4">
                <div class="col-lg-6">
                    <div class="surface-card-soft h-100 p-4">
                        @php
                            $lastFiveDays = collect();
                            for ($i = 0; $i < 5; $i++) {
                                $date = \Carbon\Carbon::today()->subDays($i);
                                $pu = $emission->puHistories()->where('date', '<=', $date->format('Y-m-d'))->orderByDesc('date')->first();
                                $lastFiveDays->push([
                                    'date' => $date,
                                    'value' => $pu?->unit_value,
                                ]);
                            }
                            $todayPu = $lastFiveDays->first()['value'] ?? $emission->current_pu;
                        @endphp

                        <div class="d-flex justify-content-between align-items-center gap-3 mb-3">
                            <div>
                                <div class="section-kicker mb-1">PU atual</div>
                                <div class="h4 fw-bold text-brand mb-0">{{ $todayPu ? 'R$ ' . number_format((float) $todayPu, 6, ',', '.') : '—' }}</div>
                            </div>
                            <span class="badge badge-soft px-3 py-2">Últimos 5 dias</span>
                        </div>

                        <div class="d-flex flex-column gap-2">
                            @foreach($lastFiveDays as $dayPu)
                                <div class="d-flex justify-content-between gap-3 rounded-4 bg-white px-3 py-2">
                                    <span class="small text-muted">{{ $dayPu['date']->format('d/m/Y') }}</span>
                                    <span class="small fw-semibold text-brand">{{ $dayPu['value'] ? 'R$ ' . number_format((float) $dayPu['value'], 6, ',', '.') : '—' }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="surface-card-soft h-100 p-4">
                        @php
                            $integralizationHistory = $emission->integralizationHistories()->orderByDesc('date')->take(5)->get();
                            $totalIntegralization = $emission->integralizationHistories()->sum('quantity');
                        @endphp

                        <div class="d-flex justify-content-between align-items-center gap-3 mb-3">
                            <div>
                                <div class="section-kicker mb-1">Integralização</div>
                                <div class="h4 fw-bold text-brand mb-0">
                                    {{ $totalIntegralization ? number_format((float) $totalIntegralization, 0, ',', '.') : ($emission->integralization_status ?: '—') }}
                                </div>
                            </div>
                            <span class="badge badge-soft px-3 py-2">Histórico</span>
                        </div>

                        <div class="d-flex flex-column gap-2">
                            @forelse($integralizationHistory as $history)
                                <div class="d-flex justify-content-between gap-3 rounded-4 bg-white px-3 py-2">
                                    <span class="small text-muted">{{ $history->date->format('d/m/Y') }}</span>
                                    <span class="small fw-semibold text-brand">{{ number_format((float) $history->quantity, 0, ',', '.') }}</span>
                                </div>
                            @empty
                                <div class="rounded-4 bg-white px-3 py-4 small text-muted text-center">
                                    Nenhum evento de integralização registrado.
                                </div>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="surface-card p-4 p-lg-5" id="documentos">
            @php
                $allCategories = [
                    'anuncios' => 'Anúncios',
                    'assembleias' => 'Assembleias',
                    'convocacoes_assembleias' => 'Convocações para Assembleias',
                    'demonstracoes_financeiras' => 'Demonstrações Financeiras',
                    'documentos_operacao' => 'Documentos da Operação',
                    'fatos_relevantes' => 'Fatos Relevantes',
                    'relatorios_anuais' => 'Relatórios Anuais',
                ];
                $documents = $emission->documents;
                $documentDisplayLimit = 5;
            @endphp

            <div class="row g-4 align-items-end mb-4">
                <div class="col-lg-8">
                    <div class="section-kicker mb-2">Consulta documental</div>
                    <h2 class="h3 fw-bold text-brand mb-2">Repositório de Documentos e Atos da Operação</h2>
                    <p class="section-copy mb-0">Acesso integral aos fatos relevantes, relatórios e documentos regulatórios da emissão. Utilize os filtros por categoria para navegar pelo histórico documental com total transparência e rastreabilidade.</p>
                </div>
                @if($documents->count() > 0)
                    @php
                        $docCategories = $documents->pluck('category')
                            ->filter()
                            ->unique()
                            ->sortBy(fn (string $category): string => strtolower($allCategories[$category] ?? $category));
                    @endphp
                    <div class="col-lg-4">
                        @if($docCategories->isNotEmpty())
                            <label for="docCategoryFilter" class="form-label">Categoria</label>
                            <select id="docCategoryFilter" class="form-select" data-limit="{{ $documentDisplayLimit }}">
                                <option value="">Todas</option>
                                @foreach($docCategories as $category)
                                    <option value="{{ $category }}">{{ $allCategories[$category] ?? ucfirst($category) }}</option>
                                @endforeach
                            </select>
                        @endif
                    </div>
                @endif
            </div>

            @if($documents->count() > 0)
                <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-4">
                    <div class="small text-muted">
                        @if($documents->count() > $documentDisplayLimit)
                            Exibindo os documentos mais recentes por padrão. Utilize o filtro para aprofundar a consulta.
                        @else
                            {{ $documents->count() }} documento(s) disponível(is) para consulta técnica e download.
                        @endif
                    </div>
                </div>

                <div class="d-grid gap-3 d-lg-none">
                    @foreach($documents as $index => $doc)
                        <article class="surface-card-soft p-4 emission-doc-card doc-entry" data-category="{{ $doc->category }}" data-index="{{ $index }}" style="{{ $index >= $documentDisplayLimit ? 'display: none;' : '' }}">
                            <div class="d-flex flex-column gap-3">
                                <div>
                                    <div class="small text-muted mb-2">{{ optional($doc->published_at)->format('d/m/Y') ?? '—' }}</div>
                                    <div class="fw-semibold text-brand mb-2">{{ $doc->title }}</div>

                                    <div class="d-flex flex-wrap gap-2">
                                        @if($doc->category)
                                            <span class="badge px-3 py-2" style="background: rgba(212,175,55,0.12); border: 1px solid rgba(212,175,55,0.22); color: var(--brand);">
                                                {{ $allCategories[$doc->category] ?? ucfirst($doc->category) }}
                                            </span>
                                        @endif
                                    </div>
                                </div>

                                @if($doc->description)
                                    <div class="small text-muted">{{ $doc->description }}</div>
                                @endif

                                <a href="{{ Storage::disk($doc->resolved_storage_disk)->url($doc->file_path) }}" target="_blank" class="btn btn-outline-brand btn-sm px-3">
                                    Baixar
                                </a>
                            </div>
                        </article>
                    @endforeach
                </div>

                <div class="table-shell d-none d-lg-block">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr>
                                <th style="width: 160px;">Data</th>
                                <th>Título</th>
                                <th style="width: 120px;" class="text-end">Ação</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($documents as $index => $doc)
                                <tr class="doc-entry" data-category="{{ $doc->category }}" data-index="{{ $index }}" style="{{ $index >= $documentDisplayLimit ? 'display: none;' : '' }}">
                                    <td class="text-muted">{{ optional($doc->published_at)->format('d/m/Y') ?? '—' }}</td>
                                    <td>
                                        <div class="fw-semibold text-brand mb-1">{{ $doc->title }}</div>
                                        <div class="d-flex flex-wrap gap-2">
                                            @if($doc->category)
                                                <span class="badge px-3 py-2" style="background: rgba(212,175,55,0.12); border: 1px solid rgba(212,175,55,0.22); color: var(--brand);">
                                                    {{ $allCategories[$doc->category] ?? ucfirst($doc->category) }}
                                                </span>
                                            @endif
                                            @if($doc->description)
                                                <span class="small text-muted">{{ $doc->description }}</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="text-end">
                                        <a href="{{ Storage::disk($doc->resolved_storage_disk)->url($doc->file_path) }}" target="_blank" class="btn btn-outline-brand btn-sm px-3">
                                            Baixar
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="surface-card-soft p-5 text-center text-muted">
                    Nenhum documento disponível para esta operação.
                </div>
            @endif
        </div>
    </div>
</section>

@push('scripts')
<script nonce="{{ $cspNonce }}">
document.addEventListener('DOMContentLoaded', function() {
    const tabLinks = document.querySelectorAll('#emissionTabs .nav-link');

    tabLinks.forEach(function(link) {
        link.addEventListener('click', function() {
            tabLinks.forEach(function(item) {
                item.classList.remove('active');
            });

            this.classList.add('active');
        });
    });

    const docFilter = document.getElementById('docCategoryFilter');

    if (docFilter) {
        const defaultVisibleCount = Number(docFilter.dataset.limit || 5);

        docFilter.addEventListener('change', function() {
            const selectedCategory = this.value;
            const entries = document.querySelectorAll('.doc-entry');

            if (selectedCategory === '') {
                entries.forEach(function(entry) {
                    entry.style.display = Number(entry.dataset.index) < defaultVisibleCount ? '' : 'none';
                });

                return;
            }

            entries.forEach(function(entry) {
                entry.style.display = entry.dataset.category === selectedCategory ? '' : 'none';
            });
        });
    }
});
</script>

@if(isset($emission->payments) && $emission->payments->count() > 0)
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script nonce="{{ $cspNonce }}">
document.addEventListener('DOMContentLoaded', function() {
    const chartElement = document.getElementById('paymentsChart');

    if (! chartElement) {
        return;
    }

    const labels = {!! json_encode($emission->payments->pluck('payment_date')->map(fn ($date) => $date->format('d/m/Y'))) !!};

    new Chart(chartElement, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Prêmio',
                    data: {!! json_encode($emission->payments->pluck('premium_value')) !!},
                    backgroundColor: '#a06e28',
                    borderRadius: 6,
                    barPercentage: 0.52,
                },
                {
                    label: 'Juros',
                    data: {!! json_encode($emission->payments->pluck('interest_value')) !!},
                    backgroundColor: '#64748b',
                    borderRadius: 6,
                    barPercentage: 0.52,
                },
                {
                    label: 'Amortização Extraordinária',
                    data: {!! json_encode($emission->payments->pluck('extra_amortization_value')) !!},
                    backgroundColor: '#1d3fb8',
                    borderRadius: 6,
                    barPercentage: 0.52,
                },
                {
                    label: 'Amortização',
                    data: {!! json_encode($emission->payments->pluck('amortization_value')) !!},
                    backgroundColor: '#091b23',
                    borderRadius: 6,
                    barPercentage: 0.52,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false,
            },
            scales: {
                x: {
                    stacked: true,
                    grid: {
                        display: false,
                    },
                    ticks: {
                        color: '#5c6980',
                    }
                },
                y: {
                    stacked: true,
                    display: false,
                    border: {
                        display: false,
                    },
                    grid: {
                        display: false,
                    },
                    ticks: { display: false },
                }
            },
            plugins: {
                legend: {
                    position: 'top',
                    labels: {
                        usePointStyle: true,
                        boxWidth: 10,
                        padding: 18,
                        color: '#091b23',
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(11, 18, 32, 0.92)',
                    padding: 12,
                    cornerRadius: 10,
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label || '';

                            if (label) {
                                label += ': ';
                            }

                            if (context.parsed.y !== null) {
                                label += new Intl.NumberFormat('pt-BR', {
                                    style: 'currency',
                                    currency: 'BRL',
                                    minimumFractionDigits: 2
                                }).format(context.parsed.y);
                            }

                            return label;
                        }
                    }
                }
            }
        }
    });
});
</script>
@endif

@endpush
@endsection

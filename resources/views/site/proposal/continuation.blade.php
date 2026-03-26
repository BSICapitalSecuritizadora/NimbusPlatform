@extends('site.layout')

@section('title', 'Formulário de Empreendimento')

@php
    $firstProject = $proposal->projects->first();
    $projectCount = $proposal->projects->count();
    $fileCount = $proposal->files->count();
@endphp

@push('head')
<style>
    .proposal-page {
        min-height: 70vh;
        background:
            linear-gradient(180deg, rgba(255, 255, 255, 0.55), transparent 180px),
            radial-gradient(1100px 420px at 50% -8%, rgba(0, 32, 91, 0.10), transparent 72%),
            var(--bg);
    }

    .proposal-page .proposal-card {
        border-radius: 30px;
        border: 1px solid var(--border);
        box-shadow: 0 20px 45px rgba(0, 32, 91, 0.08);
    }

    .proposal-page .proposal-card .card-body {
        position: relative;
        z-index: 1;
    }

    .proposal-page .proposal-hero-card {
        position: relative;
        overflow: hidden;
        background: linear-gradient(
            145deg,
            color-mix(in oklab, var(--surface) 95%, white 5%),
            color-mix(in oklab, var(--surface) 88%, var(--brand) 12%)
        );
    }

    .proposal-page .proposal-hero-card::before {
        content: '';
        position: absolute;
        inset: 0 auto 0 0;
        width: 6px;
        background: linear-gradient(180deg, var(--gold), var(--brand));
    }

    .proposal-page .proposal-hero-card::after {
        content: '';
        position: absolute;
        right: -80px;
        bottom: -90px;
        width: 240px;
        height: 240px;
        background: radial-gradient(circle, rgba(212, 175, 55, 0.18), transparent 68%);
        pointer-events: none;
    }

    .proposal-page .proposal-eyebrow,
    .proposal-page .section-kicker {
        font-size: 0.78rem;
        font-weight: 700;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        color: var(--gold);
    }

    .proposal-page .proposal-title {
        font-size: clamp(2rem, 3vw, 2.8rem);
        font-weight: 800;
        letter-spacing: -0.03em;
        color: var(--brand);
    }

    .proposal-page .proposal-subtitle,
    .proposal-page .section-copy,
    .proposal-page .project-subtitle,
    .proposal-page .meta-caption,
    .proposal-page .attachment-meta {
        color: var(--muted);
    }

    .proposal-page .status-label,
    .proposal-page .meta-label,
    .proposal-page .detail-label,
    .proposal-page .metric-label {
        margin-bottom: 0.45rem;
        font-size: 0.76rem;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: var(--muted);
    }

    .proposal-page .status-pill,
    .proposal-page .project-chip {
        display: inline-flex;
        align-items: center;
        padding: 0.75rem 1rem;
        border-radius: 999px;
        border: 1px solid color-mix(in oklab, var(--gold) 30%, var(--border) 70%);
        background: color-mix(in oklab, var(--gold) 10%, var(--surface) 90%);
        color: var(--brand);
        font-weight: 700;
    }

    .proposal-page .status-pill {
        gap: 0.65rem;
    }

    .proposal-page .status-pill::before {
        content: '';
        width: 0.7rem;
        height: 0.7rem;
        border-radius: 50%;
        background: var(--gold);
        box-shadow: 0 0 0 0.35rem rgba(212, 175, 55, 0.18);
    }

    .proposal-page .hero-meta-card,
    .proposal-page .detail-tile,
    .proposal-page .metric-card {
        height: 100%;
        padding: 1.15rem 1.2rem;
        border: 1px solid var(--border);
        border-radius: 22px;
        background: color-mix(in oklab, var(--surface) 94%, var(--brand) 6%);
    }

    .proposal-page .meta-value,
    .proposal-page .detail-value,
    .proposal-page .metric-value,
    .proposal-page .attachment-name {
        color: var(--brand);
        font-weight: 700;
    }

    .proposal-page .metric-value {
        font-size: 1.45rem;
        font-weight: 800;
        letter-spacing: -0.03em;
    }

    .proposal-page .section-title,
    .proposal-page .project-title {
        font-weight: 800;
        letter-spacing: -0.03em;
        color: var(--brand);
    }

    .proposal-page .section-title {
        margin-bottom: 0.35rem;
        font-size: 1.65rem;
    }

    .proposal-page .project-title {
        font-size: 1.7rem;
    }

    .proposal-page .summary-highlight {
        padding: 1.2rem 1.25rem;
        border-radius: 24px;
        border: 1px solid color-mix(in oklab, var(--gold) 18%, var(--border) 82%);
        background: linear-gradient(
            135deg,
            color-mix(in oklab, var(--brand) 8%, var(--surface) 92%),
            color-mix(in oklab, var(--gold) 10%, var(--surface) 90%)
        );
    }

    .proposal-page .summary-highlight strong,
    .proposal-page .panel-title {
        color: var(--brand);
        font-weight: 700;
    }

    .proposal-page .summary-highlight p {
        margin: 0;
        color: var(--muted);
        line-height: 1.7;
    }

    .proposal-page .panel-title {
        display: flex;
        align-items: center;
        gap: 0.7rem;
        margin-bottom: 1rem;
        font-size: 1rem;
    }

    .proposal-page .panel-title::before {
        content: '';
        width: 10px;
        height: 10px;
        border-radius: 50%;
        background: var(--gold);
        box-shadow: 0 0 0 0.3rem rgba(212, 175, 55, 0.15);
    }

    .proposal-page .proposal-list {
        overflow: hidden;
        border: 1px solid var(--border);
        border-radius: 22px;
        background: color-mix(in oklab, var(--surface) 96%, var(--brand) 4%);
    }

    .proposal-page .proposal-list .list-group-item {
        padding: 1rem 1.1rem;
        background: transparent;
        border-color: var(--border);
    }

    .proposal-page .proposal-list .list-group-item strong {
        color: var(--brand);
    }

    .proposal-page .project-card {
        position: relative;
        overflow: hidden;
    }

    .proposal-page .project-card::before {
        content: '';
        position: absolute;
        inset: 0 0 auto 0;
        height: 1px;
        background: linear-gradient(90deg, transparent, color-mix(in oklab, var(--gold) 55%, var(--brand) 45%), transparent);
    }

    .proposal-page .table-shell {
        overflow: hidden;
        border: 1px solid var(--border);
        border-radius: 22px;
        background: color-mix(in oklab, var(--surface) 96%, var(--brand) 4%);
    }

    .proposal-page .table-shell .table,
    .proposal-page .proposal-form-card .table {
        margin-bottom: 0;
    }

    .proposal-page .table-shell .table thead th,
    .proposal-page .proposal-form-card .table thead th {
        background: color-mix(in oklab, var(--brand) 8%, var(--surface) 92%);
        color: var(--brand);
        font-size: 0.76rem;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
    }

    .proposal-page .attachment-card {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        padding: 1.15rem 1.2rem;
        border: 1px solid var(--border);
        border-radius: 22px;
        background: color-mix(in oklab, var(--surface) 95%, var(--brand) 5%);
        color: var(--text);
        text-decoration: none;
        transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
    }

    .proposal-page .attachment-card:hover {
        transform: translateY(-2px);
        border-color: color-mix(in oklab, var(--gold) 35%, var(--border) 65%);
        box-shadow: 0 18px 34px rgba(0, 32, 91, 0.08);
    }

    .proposal-page .attachment-cta {
        color: var(--brand);
        font-size: 0.88rem;
        font-weight: 700;
        white-space: nowrap;
    }

    .proposal-page .proposal-form-card .form-control,
    .proposal-page .proposal-form-card .form-select {
        padding: 0.8rem 1rem;
        border: 1px solid color-mix(in oklab, var(--border) 85%, var(--brand) 15%);
        border-radius: 18px;
        background: color-mix(in oklab, var(--surface) 96%, var(--brand) 4%);
        box-shadow: none;
    }

    .proposal-page .proposal-form-card .form-control:focus,
    .proposal-page .proposal-form-card .form-select:focus {
        border-color: color-mix(in oklab, var(--gold) 35%, var(--brand) 65%);
        box-shadow: 0 0 0 0.2rem rgba(0, 32, 91, 0.08);
    }

    .proposal-page .proposal-form-card .input-group-text {
        border: 1px solid color-mix(in oklab, var(--border) 85%, var(--brand) 15%);
        border-right: 0;
        border-radius: 18px 0 0 18px;
        background: color-mix(in oklab, var(--surface) 96%, var(--brand) 4%);
        color: var(--muted);
    }

    .proposal-page .proposal-form-card .input-group > .form-control {
        border-left: 0;
        border-radius: 0 18px 18px 0;
    }

    .proposal-page .proposal-form-card .form-label {
        font-size: 0.76rem;
        font-weight: 700;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        color: var(--muted);
    }

    .proposal-page .proposal-form-card hr {
        border-color: var(--border);
        opacity: 1;
    }

    @media (max-width: 991.98px) {
        .proposal-page .proposal-hero-card .text-lg-end {
            text-align: left !important;
        }

        .proposal-page .project-chip {
            align-self: flex-start;
        }
    }

    @media (max-width: 767.98px) {
        .proposal-page .proposal-card {
            border-radius: 24px;
        }

        .proposal-page .proposal-title {
            font-size: 1.8rem;
        }

        .proposal-page .project-title {
            font-size: 1.45rem;
        }

        .proposal-page .metric-value {
            font-size: 1.15rem;
        }

        .proposal-page .attachment-card {
            align-items: flex-start;
            flex-direction: column;
        }
    }
</style>
@endpush

@section('content')
<section class="proposal-page py-5">
    <div class="container py-lg-4">
        <div class="row justify-content-center">
            <div class="col-xl-10">
                <div class="d-flex flex-column gap-4">
                    @if (session('success'))
                        <div class="alert alert-success border-0 shadow-sm rounded-4 px-4 py-3 mb-0">{{ session('success') }}</div>
                    @endif

                    <div class="card proposal-card proposal-hero-card border-0">
                        <div class="card-body p-4 p-lg-5">
                            <div class="row g-4 align-items-start">
                                <div class="col-lg-8">
                                    <div class="proposal-eyebrow mb-3">Portal da Proposta</div>
                                    <h1 class="proposal-title mb-2">Formulário de Empreendimento</h1>
                                    <div class="proposal-subtitle fs-5">{{ $proposal->company->name }} • {{ $proposal->company->cnpj }}</div>
                                    <div class="proposal-subtitle mt-3">
                                        Acompanhe os dados enviados e, quando necessário, complemente as informações do empreendimento no mesmo padrão visual da plataforma.
                                    </div>
                                </div>
                                <div class="col-lg-4 text-lg-end">
                                    <div class="status-label">Status Atual</div>
                                    <span class="status-pill">{{ $proposal->status_label }}</span>
                                </div>
                            </div>

                            <div class="row g-3 mt-2">
                                <div class="col-md-4">
                                    <div class="hero-meta-card">
                                        <div class="meta-label">Empreendimentos</div>
                                        <div class="meta-value">{{ $projectCount }}</div>
                                        <div class="meta-caption small">Itens vinculados à proposta atual.</div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="hero-meta-card">
                                        <div class="meta-label">Arquivos Enviados</div>
                                        <div class="meta-value">{{ $fileCount }}</div>
                                        <div class="meta-caption small">Documentos compartilhados no fluxo.</div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="hero-meta-card">
                                        <div class="meta-label">Última Atualização</div>
                                        <div class="meta-value">{{ $proposal->completed_at?->format('d/m/Y H:i') ?? 'Em preenchimento' }}</div>
                                        <div class="meta-caption small">Registro mais recente disponível nesta proposta.</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    @if ($proposal->projects->isNotEmpty())
                        @php
                            $company = $proposal->company;
                            $contact = $proposal->contact;
                            $companyAddress = collect([
                                trim(implode(', ', array_filter([$company->logradouro, $company->numero]))),
                                $company->complemento,
                            ])->filter()->implode(', ');
                            $companyRegion = collect([
                                $company->bairro,
                                trim(implode(' - ', array_filter([$company->cidade, $company->estado]))),
                                $company->cep ? 'CEP '.$company->cep : null,
                            ])->filter()->implode(' • ');
                            $contactPhones = collect([
                                $contact->phone_personal ? 'Pessoal: '.$contact->phone_personal.($contact->whatsapp ? ' (WhatsApp)' : '') : null,
                                $contact->phone_company ? 'Empresa: '.$contact->phone_company : null,
                            ])->filter()->implode(' • ');
                            $operationDetails = [
                                ['label' => 'Nome do Empreendimento', 'value' => $firstProject?->company_name ?: '—'],
                                ['label' => 'Site', 'value' => $firstProject?->site ?: '—'],
                                ['label' => 'Valor Solicitado', 'value' => 'R$ '.number_format((float) $firstProject?->value_requested, 2, ',', '.')],
                                ['label' => 'Valor de Mercado do Terreno', 'value' => 'R$ '.number_format((float) $firstProject?->land_market_value, 2, ',', '.')],
                                ['label' => 'Área do Terreno', 'value' => number_format((float) $firstProject?->land_area, 2, ',', '.').' m²'],
                                ['label' => 'Lançamento', 'value' => $firstProject?->launch_date?->format('m/Y') ?: '—'],
                                ['label' => 'Lançamento das Vendas', 'value' => $firstProject?->sales_launch_date?->format('m/Y') ?: '—'],
                                ['label' => 'Início das Obras', 'value' => $firstProject?->construction_start_date?->format('m/Y') ?: '—'],
                                ['label' => 'Previsão de Entrega', 'value' => $firstProject?->delivery_forecast_date?->format('m/Y') ?: '—'],
                                ['label' => 'Prazo Remanescente', 'value' => $firstProject ? ((int) $firstProject->remaining_months).' meses' : '—'],
                            ];
                        @endphp

                        <div class="card proposal-card border-0">
                            <div class="card-body p-4 p-lg-5">
                                <div class="row g-4 align-items-center">
                                    <div class="col-lg-7">
                                        <div class="section-kicker mb-2">Acompanhamento</div>
                                        <h2 class="section-title">Resumo do envio</h2>
                                        <p class="section-copy mb-0">
                                            Recebemos as informações da sua proposta. A equipe comercial seguirá com a análise interna e manterá o acompanhamento pelos próximos passos do processo.
                                        </p>
                                    </div>
                                    <div class="col-lg-5">
                                        <div class="summary-highlight">
                                            <strong class="d-block mb-2">Ambiente seguro para o proponente</strong>
                                            <p>Os dados de análise comercial, indicadores internos e parâmetros do time de vendas permanecem restritos ao painel administrativo.</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card proposal-card border-0">
                            <div class="card-body p-4 p-lg-5">
                                <div class="row g-4 align-items-start mb-4">
                                    <div class="col-lg-7">
                                        <div class="section-kicker mb-2">Etapa 1</div>
                                        <h2 class="section-title">Cadastro Inicial</h2>
                                        <p class="section-copy mb-0">Dados institucionais e de contato compartilhados no primeiro envio da proposta.</p>
                                    </div>
                                </div>

                                <div class="row g-4">
                                    <div class="col-lg-6">
                                        <div class="panel-title">Empresa</div>
                                        <ul class="list-group proposal-list">
                                            <li class="list-group-item"><strong>Razão social:</strong> {{ $company->name }}</li>
                                            <li class="list-group-item"><strong>CNPJ:</strong> {{ $company->cnpj }}</li>
                                            <li class="list-group-item"><strong>IE:</strong> {{ $company->ie ?: '—' }}</li>
                                            <li class="list-group-item"><strong>Setores:</strong> {{ $company->sectors->pluck('name')->join(', ') ?: '—' }}</li>
                                            <li class="list-group-item"><strong>Site:</strong> {{ $company->site ?: '—' }}</li>
                                            <li class="list-group-item"><strong>Endereço:</strong> {{ $companyAddress ?: '—' }}</li>
                                            <li class="list-group-item"><strong>Localidade:</strong> {{ $companyRegion ?: '—' }}</li>
                                        </ul>
                                    </div>

                                    <div class="col-lg-6">
                                        <div class="panel-title">Contato e observações</div>
                                        <ul class="list-group proposal-list">
                                            <li class="list-group-item"><strong>Contato:</strong> {{ $contact->name }}</li>
                                            <li class="list-group-item"><strong>E-mail:</strong> {{ $contact->email }}</li>
                                            <li class="list-group-item"><strong>Telefones:</strong> {{ $contactPhones ?: '—' }}</li>
                                            <li class="list-group-item"><strong>Cargo:</strong> {{ $contact->cargo ?: '—' }}</li>
                                            <li class="list-group-item"><strong>Observações:</strong><br>{{ $proposal->observations ?: 'Sem observações.' }}</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card proposal-card border-0">
                            <div class="card-body p-4 p-lg-5">
                                <div class="row g-4 align-items-start mb-4">
                                    <div class="col-lg-7">
                                        <div class="section-kicker mb-2">Etapa 2</div>
                                        <h2 class="section-title">Dados Gerais da Operação</h2>
                                        <p class="section-copy mb-0">Informações macro da operação, com foco em valor, terreno, cronograma e prazo do empreendimento.</p>
                                    </div>
                                </div>

                                <div class="row g-3">
                                    @foreach ($operationDetails as $detail)
                                        <div class="col-md-6 col-xl-4">
                                            <div class="detail-tile">
                                                <div class="detail-label">{{ $detail['label'] }}</div>
                                                <div class="detail-value">{{ $detail['value'] }}</div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>

                        @foreach ($proposal->projects as $project)
                            @php
                                $projectAddress = collect([
                                    trim(implode(', ', array_filter([$project->logradouro, $project->numero]))),
                                    $project->complemento,
                                ])->filter()->implode(', ');
                                $projectRegion = collect([
                                    $project->bairro,
                                    trim(implode(' - ', array_filter([$project->cidade, $project->estado]))),
                                    $project->cep ? 'CEP '.$project->cep : null,
                                ])->filter()->implode(' • ');
                                $paymentFlowTotal = \App\Models\ProposalProject::calculatePaymentFlowTotal(
                                    $project->value_received,
                                    $project->value_until_keys,
                                    $project->value_post_keys,
                                );
                                $projectMetrics = [
                                    ['label' => 'Unidades Totais', 'value' => $project->units_total],
                                    ['label' => 'Vendas (%)', 'value' => number_format((float) $project->sales_percentage, 2, ',', '.').'%'],
                                    ['label' => 'VGV Total', 'value' => 'R$ '.number_format((float) $project->value_total_sale, 2, ',', '.')],
                                    ['label' => 'Fluxo de Pagamento', 'value' => 'R$ '.number_format((float) $paymentFlowTotal, 2, ',', '.')],
                                ];
                            @endphp

                            <div class="card proposal-card project-card border-0">
                                <div class="card-body p-4 p-lg-5">
                                    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-start gap-3 mb-4">
                                        <div>
                                            <div class="section-kicker mb-2">Empreendimento {{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</div>
                                            <h2 class="project-title mb-1">{{ $project->name }}</h2>
                                            <div class="project-subtitle">{{ $projectRegion ?: 'Localização não informada.' }}</div>
                                        </div>
                                        <span class="project-chip">Informações enviadas pelo proponente</span>
                                    </div>

                                    <div class="row g-3 mb-4">
                                        @foreach ($projectMetrics as $metric)
                                            <div class="col-md-6 col-xl-3">
                                                <div class="metric-card">
                                                    <div class="metric-label">{{ $metric['label'] }}</div>
                                                    <div class="metric-value">{{ $metric['value'] }}</div>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>

                                    <div class="row g-4">
                                        <div class="col-lg-6">
                                            <div class="panel-title">Endereço do Empreendimento</div>
                                            <ul class="list-group proposal-list">
                                                <li class="list-group-item"><strong>Endereço:</strong> {{ $projectAddress ?: '—' }}</li>
                                                <li class="list-group-item"><strong>Localidade:</strong> {{ $projectRegion ?: '—' }}</li>
                                                <li class="list-group-item"><strong>Site do empreendimento:</strong> {{ $project->site ?: '—' }}</li>
                                            </ul>
                                        </div>

                                        <div class="col-lg-6">
                                            <div class="panel-title">Resumo das Unidades</div>
                                            <ul class="list-group proposal-list">
                                                <li class="list-group-item"><strong>Permutadas:</strong> {{ $project->units_exchanged }}</li>
                                                <li class="list-group-item"><strong>Quitadas:</strong> {{ $project->units_paid }}</li>
                                                <li class="list-group-item"><strong>Não Quitadas:</strong> {{ $project->units_unpaid }}</li>
                                                <li class="list-group-item"><strong>Estoque:</strong> {{ $project->units_stock }}</li>
                                                <li class="list-group-item"><strong>Total:</strong> {{ $project->units_total }}</li>
                                                <li class="list-group-item"><strong>% Vendidas:</strong> {{ number_format((float) $project->sales_percentage, 2, ',', '.') }}%</li>
                                            </ul>
                                        </div>

                                        <div class="col-lg-6">
                                            <div class="panel-title">Resumo Financeiro</div>
                                            <ul class="list-group proposal-list">
                                                <li class="list-group-item"><strong>Custo Incorrido:</strong> R$ {{ number_format((float) $project->cost_incurred, 2, ',', '.') }}</li>
                                                <li class="list-group-item"><strong>Custo a Incorrer:</strong> R$ {{ number_format((float) $project->cost_to_incur, 2, ',', '.') }}</li>
                                                <li class="list-group-item"><strong>Custo Total:</strong> R$ {{ number_format((float) $project->cost_total, 2, ',', '.') }}</li>
                                                <li class="list-group-item"><strong>Estágio da Obra:</strong> {{ number_format((float) $project->work_stage_percentage, 2, ',', '.') }}%</li>
                                                <li class="list-group-item"><strong>VGV Total:</strong> R$ {{ number_format((float) $project->value_total_sale, 2, ',', '.') }}</li>
                                                <li class="list-group-item"><strong>Recebíveis:</strong> R$ {{ number_format((float) $paymentFlowTotal, 2, ',', '.') }}</li>
                                            </ul>
                                        </div>

                                        <div class="col-lg-6">
                                            <div class="panel-title">Valores de Venda</div>
                                            <ul class="list-group proposal-list">
                                                <li class="list-group-item"><strong>Quitadas:</strong> R$ {{ number_format((float) $project->value_paid, 2, ',', '.') }}</li>
                                                <li class="list-group-item"><strong>Vendidas:</strong> R$ {{ number_format((float) $project->value_unpaid, 2, ',', '.') }}</li>
                                                <li class="list-group-item"><strong>Estoque:</strong> R$ {{ number_format((float) $project->value_stock, 2, ',', '.') }}</li>
                                                <li class="list-group-item"><strong>VGV Total:</strong> R$ {{ number_format((float) $project->value_total_sale, 2, ',', '.') }}</li>
                                            </ul>
                                        </div>

                                        <div class="col-12">
                                            <div class="panel-title">Fluxo de Pagamento</div>
                                            <ul class="list-group proposal-list">
                                                <li class="list-group-item"><strong>Valor já Recebido:</strong> R$ {{ number_format((float) $project->value_received, 2, ',', '.') }}</li>
                                                <li class="list-group-item"><strong>A receber até as chaves:</strong> R$ {{ number_format((float) $project->value_until_keys, 2, ',', '.') }}</li>
                                                <li class="list-group-item"><strong>A receber pós chaves:</strong> R$ {{ number_format((float) $project->value_post_keys, 2, ',', '.') }}</li>
                                                <li class="list-group-item"><strong>Total:</strong> R$ {{ number_format((float) $paymentFlowTotal, 2, ',', '.') }}</li>
                                            </ul>
                                        </div>
                                    </div>

                                    @if ($project->characteristics)
                                        <div class="mt-5 pt-4 border-top">
                                            <div class="row g-4 align-items-start mb-4">
                                                <div class="col-lg-8">
                                                    <div class="section-kicker mb-2">Composição da Torre</div>
                                                    <h3 class="section-title h4 mb-1">Características do Empreendimento</h3>
                                                    <p class="section-copy mb-0">Visão consolidada da configuração do produto e das tipologias cadastradas.</p>
                                                </div>
                                            </div>

                                            <div class="row g-3 mb-4">
                                                <div class="col-sm-6 col-xl">
                                                    <div class="detail-tile">
                                                        <div class="detail-label">Blocos</div>
                                                        <div class="detail-value">{{ $project->characteristics->blocks }}</div>
                                                    </div>
                                                </div>
                                                <div class="col-sm-6 col-xl">
                                                    <div class="detail-tile">
                                                        <div class="detail-label">Pavimentos</div>
                                                        <div class="detail-value">{{ $project->characteristics->floors }}</div>
                                                    </div>
                                                </div>
                                                <div class="col-sm-6 col-xl">
                                                    <div class="detail-tile">
                                                        <div class="detail-label">Andares Tipo</div>
                                                        <div class="detail-value">{{ $project->characteristics->typical_floors }}</div>
                                                    </div>
                                                </div>
                                                <div class="col-sm-6 col-xl">
                                                    <div class="detail-tile">
                                                        <div class="detail-label">Unidades por Andar</div>
                                                        <div class="detail-value">{{ $project->characteristics->units_per_floor }}</div>
                                                    </div>
                                                </div>
                                                <div class="col-sm-6 col-xl">
                                                    <div class="detail-tile">
                                                        <div class="detail-label">Total</div>
                                                        <div class="detail-value">{{ $project->characteristics->total_units }}</div>
                                                    </div>
                                                </div>
                                            </div>

                                            @if ($project->characteristics->unitTypes->isNotEmpty())
                                                <div class="table-shell">
                                                    <div class="table-responsive">
                                                        <table class="table align-middle mb-0">
                                                            <thead>
                                                                <tr>
                                                                    <th>Tipo</th>
                                                                    <th>Unidades</th>
                                                                    <th>Dormitórios</th>
                                                                    <th>Vagas</th>
                                                                    <th>Área Útil</th>
                                                                    <th>Preço Médio</th>
                                                                    <th>Preço / m²</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                @foreach ($project->characteristics->unitTypes as $unitType)
                                                                    <tr>
                                                                        <td>Tipo {{ $unitType->order }}</td>
                                                                        <td>{{ $unitType->total_units }}</td>
                                                                        <td>{{ $unitType->bedrooms ?: '—' }}</td>
                                                                        <td>{{ $unitType->parking_spaces ?: '—' }}</td>
                                                                        <td>{{ number_format((float) $unitType->useful_area, 2, ',', '.') }} m²</td>
                                                                        <td>R$ {{ number_format((float) $unitType->average_price, 2, ',', '.') }}</td>
                                                                        <td>R$ {{ number_format((float) $unitType->price_per_m2, 2, ',', '.') }}</td>
                                                                    </tr>
                                                                @endforeach
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                </div>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endforeach

                        @if ($proposal->files->isNotEmpty())
                            <div class="card proposal-card border-0">
                                <div class="card-body p-4 p-lg-5">
                                    <div class="row g-4 align-items-start mb-4">
                                        <div class="col-lg-7">
                                            <div class="section-kicker mb-2">Documentos</div>
                                            <h2 class="section-title">Arquivos Anexados</h2>
                                            <p class="section-copy mb-0">Documentos enviados ao longo do fluxo para apoio à análise da proposta.</p>
                                        </div>
                                    </div>

                                    <div class="d-flex flex-column gap-3">
                                        @foreach ($proposal->files as $file)
                                            @php
                                                $fileMeta = collect([
                                                    $file->file_size ? number_format($file->file_size / 1024, 0, ',', '.').' KB' : null,
                                                    $file->created_at?->format('d/m/Y H:i'),
                                                ])->filter()->implode(' • ');
                                            @endphp
                                            <a class="attachment-card" href="{{ route('site.proposal.continuation.files.download', [$access, $file]) }}">
                                                <div>
                                                    <div class="attachment-name">{{ $file->original_name }}</div>
                                                    <div class="attachment-meta small">{{ $fileMeta ?: 'Disponível para download.' }}</div>
                                                </div>
                                                <span class="attachment-cta">Baixar arquivo</span>
                                            </a>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        @endif
                    @else
                        <div class="card proposal-card border-0">
                            <div class="card-body p-4 p-lg-5">
                                <div class="row g-4 align-items-center">
                                    <div class="col-lg-7">
                                        <div class="section-kicker mb-2">Próxima Etapa</div>
                                        <h2 class="section-title">Complementar informações do empreendimento</h2>
                                        <p class="section-copy mb-0">
                                            Preencha os dados abaixo com atenção. Essa etapa organiza o empreendimento, unidades, cronograma, fluxo financeiro e documentos complementares.
                                        </p>
                                    </div>
                                    <div class="col-lg-5">
                                        <div class="summary-highlight">
                                            <strong class="d-block mb-2">Antes de enviar</strong>
                                            <p>Revise os dados gerais da operação, preencha cada empreendimento com identificação clara e anexe os documentos que apoiam a análise.</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card proposal-card proposal-form-card border-0">
                            <div class="card-body p-4 p-lg-5">
                                <form method="POST" action="{{ route('site.proposal.continuation.store', $access) }}" class="row g-4" id="formEmpreendimento" enctype="multipart/form-data">
                                    @csrf

                                    <div class="col-12">
                                        <div class="section-kicker mb-2">Dados Gerais</div>
                                        <h2 class="section-title h4 mb-1">Informações da operação</h2>
                                        <p class="section-copy mb-0">Dados principais para identificação da operação, cronograma e endereço do empreendimento.</p>
                                    </div>

                                    <div class="col-md-5"><label class="form-label">Nome do Empreendimento *</label><input type="text" name="nome" class="form-control" value="{{ old('nome') }}" required></div>
                                    <div class="col-md-4"><label class="form-label">Site</label><input type="url" name="site" class="form-control" value="{{ old('site') }}"></div>
                                    <div class="col-md-3"><label class="form-label">Valor Solicitado *</label><div class="input-group"><span class="input-group-text">R$</span><input type="text" name="valor_solicitado" class="form-control money" value="{{ old('valor_solicitado') }}" required></div></div>
                                    <div class="col-md-4"><label class="form-label">Valor atual de mercado do terreno</label><div class="input-group"><span class="input-group-text">R$</span><input type="text" name="valor_mercado_terreno" class="form-control money" value="{{ old('valor_mercado_terreno') }}"></div></div>
                                    <div class="col-md-4"><label class="form-label">Área do Terreno (m²) *</label><input type="number" step="0.01" name="area_terreno" class="form-control" value="{{ old('area_terreno') }}" required></div>
                                    <div class="col-md-4"><label class="form-label">Lançamento *</label><input type="month" name="data_lancamento" class="form-control" value="{{ old('data_lancamento') }}" required></div>
                                    <div class="col-md-3"><label class="form-label">Lançamento das Vendas *</label><input type="month" name="lancamento_vendas" class="form-control" value="{{ old('lancamento_vendas') }}" required></div>
                                    <div class="col-md-3"><label class="form-label">Início das Obras *</label><input type="month" name="inicio_obras" id="inicio_obras" class="form-control" value="{{ old('inicio_obras') }}" required></div>
                                    <div class="col-md-3"><label class="form-label">Previsão de Entrega *</label><input type="month" name="previsao_entrega" id="previsao_entrega" class="form-control" value="{{ old('previsao_entrega') }}" required></div>
                                    <div class="col-md-3"><label class="form-label">Prazo Remanescente (meses)</label><input type="number" name="prazo_remanescente" id="prazo_remanescente" class="form-control" value="{{ old('prazo_remanescente') }}" readonly></div>
                                    <div class="col-md-3"><label class="form-label">CEP *</label><input type="text" name="cep" id="cep" class="form-control" value="{{ old('cep') }}" required></div>
                                    <div class="col-md-6"><label class="form-label">Rua</label><input type="text" name="logradouro" id="logradouro" class="form-control" value="{{ old('logradouro') }}" readonly></div>
                                    <div class="col-md-3"><label class="form-label">Complemento</label><input type="text" name="complemento" class="form-control" value="{{ old('complemento') }}"></div>
                                    <div class="col-md-3"><label class="form-label">Número *</label><input type="text" name="numero" class="form-control" value="{{ old('numero') }}" required></div>
                                    <div class="col-md-4"><label class="form-label">Bairro</label><input type="text" name="bairro" id="bairro" class="form-control" value="{{ old('bairro') }}" readonly></div>
                                    <div class="col-md-4"><label class="form-label">Cidade</label><input type="text" name="cidade" id="cidade" class="form-control" value="{{ old('cidade') }}" readonly></div>
                                    <div class="col-md-2"><label class="form-label">Estado</label><input type="text" name="estado" id="estado" class="form-control" value="{{ old('estado') }}" readonly></div>

                                    <div class="col-12">
                                        <hr class="my-2">
                                    </div>

                                    <div class="col-12">
                                        <div class="section-kicker mb-2">Empreendimentos</div>
                                        <h2 class="section-title h4 mb-1">Cadastro das torres e blocos</h2>
                                        <p class="section-copy mb-0">Se houver mais de um empreendimento na mesma operação, adicione quantos blocos forem necessários.</p>
                                    </div>

                                    <div class="col-12 d-flex justify-content-end">
                                        <button type="button" id="addEmpreendimento" class="btn btn-outline-brand">Adicionar Empreendimento</button>
                                    </div>

                                    <div class="col-12" id="blocos-empreendimento">
                                        <div class="bloco-dinamico">
                                            <div class="proposal-list p-3 p-lg-4">
                                                <div class="mb-4">
                                                    <label class="form-label">Identificação do Empreendimento</label>
                                                    <input type="text" name="nome_empreendimento[]" class="form-control" placeholder="Ex: Torre Madrid" required>
                                                </div>

                                                <div class="d-flex flex-column gap-4">
                                                    <div>
                                                        <div class="panel-title">Resumo das Unidades</div>
                                                        <div class="table-shell">
                                                            <div class="table-responsive">
                                                                <table class="table table-bordered">
                                                                    <thead>
                                                                        <tr>
                                                                            <th>Permutadas</th>
                                                                            <th>Quitadas</th>
                                                                            <th>Não Quitadas</th>
                                                                            <th>Estoque</th>
                                                                            <th>Total</th>
                                                                            <th>% Vendidas</th>
                                                                        </tr>
                                                                    </thead>
                                                                    <tbody>
                                                                        <tr>
                                                                            <td><input type="number" name="unidades_permutadas[]" class="form-control unidade-campo" min="0"></td>
                                                                            <td><input type="number" name="unidades_quitadas[]" class="form-control unidade-campo" min="0"></td>
                                                                            <td><input type="number" name="unidades_nao_quitadas[]" class="form-control unidade-campo" min="0"></td>
                                                                            <td><input type="number" name="unidades_estoque[]" class="form-control unidade-campo" min="0"></td>
                                                                            <td><input type="number" name="unidades_total[]" class="form-control" readonly></td>
                                                                            <td><input type="number" name="percentual_vendas[]" class="form-control" readonly></td>
                                                                        </tr>
                                                                    </tbody>
                                                                </table>
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <div>
                                                        <div class="panel-title">Resumo Financeiro</div>
                                                        <div class="table-shell">
                                                            <div class="table-responsive">
                                                                <table class="table table-bordered">
                                                                    <thead>
                                                                        <tr>
                                                                            <th>Custo Incorrido</th>
                                                                            <th>Custo a Incorrer</th>
                                                                            <th>Custo Total</th>
                                                                            <th>Estágio da Obra (%)</th>
                                                                        </tr>
                                                                    </thead>
                                                                    <tbody>
                                                                        <tr>
                                                                            <td><div class="input-group"><span class="input-group-text">R$</span><input type="text" name="custo_incidido[]" class="form-control money"></div></td>
                                                                            <td><div class="input-group"><span class="input-group-text">R$</span><input type="text" name="custo_a_incorrer[]" class="form-control money"></div></td>
                                                                            <td><div class="input-group"><span class="input-group-text">R$</span><input type="text" name="custo_total[]" class="form-control money" readonly></div></td>
                                                                            <td><input type="number" name="estagio_obra[]" class="form-control" readonly></td>
                                                                        </tr>
                                                                    </tbody>
                                                                </table>
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <div>
                                                        <div class="panel-title">Valores de Venda</div>
                                                        <div class="table-shell">
                                                            <div class="table-responsive">
                                                                <table class="table table-bordered">
                                                                    <thead>
                                                                        <tr>
                                                                            <th>Quitadas</th>
                                                                            <th>Não Quitadas</th>
                                                                            <th>Estoque</th>
                                                                            <th>Total Venda</th>
                                                                        </tr>
                                                                    </thead>
                                                                    <tbody>
                                                                        <tr>
                                                                            <td><div class="input-group"><span class="input-group-text">R$</span><input type="text" name="valor_quitadas[]" class="form-control money"></div></td>
                                                                            <td><div class="input-group"><span class="input-group-text">R$</span><input type="text" name="valor_nao_quitadas[]" class="form-control money"></div></td>
                                                                            <td><div class="input-group"><span class="input-group-text">R$</span><input type="text" name="valor_estoque[]" class="form-control money"></div></td>
                                                                            <td><div class="input-group"><span class="input-group-text">R$</span><input type="text" name="valor_total_venda[]" class="form-control money" readonly></div></td>
                                                                        </tr>
                                                                    </tbody>
                                                                </table>
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <div>
                                                        <div class="panel-title">Fluxo de Pagamento</div>
                                                        <div class="table-shell">
                                                            <div class="table-responsive">
                                                                <table class="table table-bordered">
                                                                    <thead>
                                                                        <tr>
                                                                            <th>Já Recebido</th>
                                                                            <th>Até Chaves</th>
                                                                            <th>Chaves + Pós Chaves</th>
                                                                        </tr>
                                                                    </thead>
                                                                    <tbody>
                                                                        <tr>
                                                                            <td><div class="input-group"><span class="input-group-text">R$</span><input type="text" name="valor_ja_recebido[]" class="form-control money"></div></td>
                                                                            <td><div class="input-group"><span class="input-group-text">R$</span><input type="text" name="valor_ate_chaves[]" class="form-control money"></div></td>
                                                                            <td><div class="input-group"><span class="input-group-text">R$</span><input type="text" name="valor_chaves_pos[]" class="form-control money"></div></td>
                                                                        </tr>
                                                                    </tbody>
                                                                </table>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-12">
                                        <hr class="my-2">
                                    </div>

                                    <div class="col-12">
                                        <div class="section-kicker mb-2">Características</div>
                                        <h2 class="section-title h4 mb-1">Características do Empreendimento</h2>
                                        <p class="section-copy mb-0">Configuração física do produto e dados das tipologias da operação.</p>
                                    </div>

                                    <div class="col-12">
                                        <div class="proposal-list p-3 p-lg-4">
                                            <div class="row g-3 mb-4">
                                                <div class="col-md-2"><label class="form-label">Blocos</label><input type="number" min="1" name="car_bloco" id="car_bloco" class="form-control" required></div>
                                                <div class="col-md-2"><label class="form-label">Pavimentos</label><input type="number" min="1" name="car_pavimentos" id="car_pavimentos" class="form-control" required></div>
                                                <div class="col-md-3"><label class="form-label">Andares Tipo</label><input type="number" min="1" name="car_andares_tipo" id="car_andares_tipo" class="form-control" required></div>
                                                <div class="col-md-3"><label class="form-label">Unidades/Andar</label><input type="number" min="1" name="car_unidades_andar" id="car_unidades_andar" class="form-control" required></div>
                                                <div class="col-md-2"><label class="form-label">Total</label><input type="number" name="car_total" id="car_total" class="form-control" readonly></div>
                                            </div>

                                            <div class="table-shell">
                                                <div class="table-responsive">
                                                    <table class="table table-bordered align-middle">
                                                        <thead>
                                                            <tr>
                                                                <th>&nbsp;</th>
                                                                <th>Tipo 1</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <tr><th>Total</th><td><input type="number" name="tipo_total[]" class="form-control" min="1" required></td></tr>
                                                            <tr><th>Dormitórios</th><td><input type="text" name="tipo_dormitorios[]" class="form-control" required></td></tr>
                                                            <tr><th>Vagas</th><td><input type="text" name="tipo_vagas[]" class="form-control" required></td></tr>
                                                            <tr><th>Área Útil (m²)</th><td><input type="number" step="0.01" name="tipo_area[]" class="form-control tipo-area" required></td></tr>
                                                            <tr><th>Preço Médio</th><td><div class="input-group"><span class="input-group-text">R$</span><input type="text" name="tipo_preco_medio[]" class="form-control tipo-preco-medio money" required></div></td></tr>
                                                            <tr><th>Preço / m²</th><td><div class="input-group"><span class="input-group-text">R$</span><input type="text" name="tipo_preco_m2[]" class="form-control tipo-preco-m2" readonly></div></td></tr>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-12">
                                        <label class="form-label">Arquivos do Empreendimento</label>
                                        <input type="file" name="arquivos[]" class="form-control" multiple>
                                    </div>

                                    <div class="col-12 d-flex flex-column flex-sm-row gap-3 justify-content-between align-items-sm-center">
                                        <div class="section-copy mb-0">Após salvar, os dados seguirão para análise comercial interna.</div>
                                        <button type="submit" class="btn btn-brand">Salvar Empreendimento(s)</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</section>
@endsection

@push('scripts')
<script src="https://unpkg.com/imask"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const cepInput = document.getElementById('cep'), inicioObras = document.getElementById('inicio_obras'), previsaoEntrega = document.getElementById('previsao_entrega'), prazoRemanescente = document.getElementById('prazo_remanescente');
    const formatMoney = (input) => { let value = input.value.replace(/\D/g, ''); let floatValue = parseFloat(value) / 100; input.value = isNaN(floatValue) ? '' : floatValue.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
    const parseMoney = (value) => value ? (parseFloat(value.replace(/[^\d,-]/g, '').replace(/\./g, '').replace(',', '.')) || 0) : 0;
    document.querySelectorAll('.money').forEach((input) => input.addEventListener('input', (event) => formatMoney(event.target)));
    document.querySelectorAll('.bloco-dinamico').forEach((bloco) => bindBlock(bloco));
    ['car_bloco', 'car_andares_tipo', 'car_unidades_andar'].forEach((id) => document.getElementById(id)?.addEventListener('input', updateCharacteristics));
    [inicioObras, previsaoEntrega].forEach((input) => input?.addEventListener('change', updateRemainingMonths));
    document.addEventListener('input', (event) => { if (event.target.classList.contains('tipo-area') || event.target.classList.contains('tipo-preco-medio')) updatePricePerM2(); });
    document.getElementById('addEmpreendimento')?.addEventListener('click', function () { const container = document.getElementById('blocos-empreendimento'); const base = container.querySelector('.bloco-dinamico'); const clone = base.cloneNode(true); clone.querySelectorAll('input').forEach((input) => input.value = ''); container.appendChild(clone); clone.querySelectorAll('.money').forEach((input) => input.addEventListener('input', (event) => formatMoney(event.target))); bindBlock(clone); });
    if (cepInput) { IMask(cepInput, { mask: '00000-000' }); cepInput.addEventListener('blur', function () { const cep = this.value.replace(/\D/g, ''); if (cep.length !== 8) return; fetch(`https://viacep.com.br/ws/${cep}/json/`).then((response) => response.json()).then((data) => { if (!data.erro) { document.getElementById('logradouro').value = data.logradouro || ''; document.getElementById('bairro').value = data.bairro || ''; document.getElementById('cidade').value = data.localidade || ''; document.getElementById('estado').value = data.uf || ''; } }); }); }
    function bindBlock(bloco) { const unidadeCampos = bloco.querySelectorAll('.unidade-campo'), totalUnidades = bloco.querySelector('input[name="unidades_total[]"]'), percentualVendas = bloco.querySelector('input[name="percentual_vendas[]"]'), custoIncidido = bloco.querySelector('input[name="custo_incidido[]"]'), custoAIncorrer = bloco.querySelector('input[name="custo_a_incorrer[]"]'), custoTotal = bloco.querySelector('input[name="custo_total[]"]'), estagioObra = bloco.querySelector('input[name="estagio_obra[]"]'), valorQuitadas = bloco.querySelector('input[name="valor_quitadas[]"]'), valorNaoQuitadas = bloco.querySelector('input[name="valor_nao_quitadas[]"]'), valorEstoque = bloco.querySelector('input[name="valor_estoque[]"]'), valorTotalVenda = bloco.querySelector('input[name="valor_total_venda[]"]'); unidadeCampos.forEach((input) => input.addEventListener('input', function () { const values = Array.from(unidadeCampos).map((field) => parseInt(field.value || '0', 10) || 0), total = values.reduce((acc, item) => acc + item, 0), quitadas = parseInt(bloco.querySelector('input[name="unidades_quitadas[]"]').value || '0', 10) || 0, naoQuitadas = parseInt(bloco.querySelector('input[name="unidades_nao_quitadas[]"]').value || '0', 10) || 0, permutadas = parseInt(bloco.querySelector('input[name="unidades_permutadas[]"]').value || '0', 10) || 0, base = total - permutadas; totalUnidades.value = total; percentualVendas.value = base > 0 ? (((quitadas + naoQuitadas) / base) * 100).toFixed(2) : '0.00'; })); [custoIncidido, custoAIncorrer, valorQuitadas, valorNaoQuitadas, valorEstoque].forEach((input) => input.addEventListener('input', function () { const totalCost = parseMoney(custoIncidido.value) + parseMoney(custoAIncorrer.value), totalSale = parseMoney(valorQuitadas.value) + parseMoney(valorNaoQuitadas.value) + parseMoney(valorEstoque.value); custoTotal.value = totalCost ? totalCost.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : ''; estagioObra.value = totalCost > 0 ? ((parseMoney(custoIncidido.value) / totalCost) * 100).toFixed(2) : '0.00'; valorTotalVenda.value = totalSale ? totalSale.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : ''; })); }
    function updateRemainingMonths() { if (!inicioObras || !previsaoEntrega || !prazoRemanescente || !inicioObras.value || !previsaoEntrega.value) return; const [sy, sm] = inicioObras.value.split('-').map(Number), [ey, em] = previsaoEntrega.value.split('-').map(Number); prazoRemanescente.value = ((ey - sy) * 12) + (em - sm); }
    function updateCharacteristics() { const total = (parseInt(document.getElementById('car_bloco')?.value || '0', 10) || 0) * (parseInt(document.getElementById('car_andares_tipo')?.value || '0', 10) || 0) * (parseInt(document.getElementById('car_unidades_andar')?.value || '0', 10) || 0); const field = document.getElementById('car_total'); if (field) field.value = total || ''; }
    function updatePricePerM2() { const areas = document.querySelectorAll('.tipo-area'), prices = document.querySelectorAll('.tipo-preco-medio'), pricePerM2 = document.querySelectorAll('.tipo-preco-m2'); prices.forEach((field, index) => { const area = parseFloat(areas[index]?.value || '0'), price = parseMoney(field.value); pricePerM2[index].value = area > 0 ? (price / area).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : ''; }); }
});
</script>
@endpush

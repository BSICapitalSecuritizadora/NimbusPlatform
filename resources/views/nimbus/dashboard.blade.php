@extends('nimbus.layouts.portal')

@section('title', 'Início')

@section('content')
@php
    $portalUser = Auth::guard('nimbus')->user();
    $firstName = explode(' ', $portalUser->full_name ?? $portalUser->email)[0];
    $portalNow = now()->timezone('America/Sao_Paulo')->locale('pt_BR');
    $greeting = match (true) {
        $portalNow->hour < 12 => 'Bom dia',
        $portalNow->hour < 18 => 'Boa tarde',
        default => 'Boa noite',
    };
    $totalSubmissions = (int) ($stats['total'] ?? 0);
    $pendingSubmissions = (int) ($stats['pending'] ?? 0);
    $closedSubmissions = (int) ($stats['approved'] ?? 0);
    $visibleSubmissions = $submissions->count();
    $closedRate = $totalSubmissions > 0 ? (int) round(($closedSubmissions / $totalSubmissions) * 100) : 0;
    $latestSubmission = $submissions->first();
    $latestSubmittedAt = $latestSubmission?->submitted_at?->timezone('America/Sao_Paulo');
    $latestProtocol = $latestSubmission?->reference_code;

    if (blank($latestProtocol) && $latestSubmission) {
        $latestProtocol = sprintf('BSI-%s-%04d', $portalNow->format('Y'), $latestSubmission->id);
    }

    $latestActivity = $latestSubmittedAt?->diffForHumans() ?? 'Sem atividade recente';
    $pendingSummary = match (true) {
        $pendingSubmissions === 0 => 'Nenhuma pendência ativa',
        $pendingSubmissions === 1 => '1 solicitação requer atenção',
        default => "{$pendingSubmissions} solicitações requerem atenção",
    };
    $closedSummary = $totalSubmissions > 0
        ? "{$closedRate}% do histórico encerrado"
        : 'Sem histórico encerrado';
@endphp

    <div class="mb-6 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <div class="flex flex-wrap items-center gap-3">
            <div class="font-jetbrains text-[11px] uppercase tracking-[.18em] text-ink-400">Portal › Início</div>
            <div class="nd-meta-chip">Painel do cliente</div>
        </div>
        <div class="nd-meta-chip nd-meta-chip-muted">Última atualização · {{ $portalNow->format('d.m.Y · H:i') }}</div>
    </div>

    <div class="welcome relative mb-8 overflow-hidden rounded-[10px] bg-gradient-to-br from-navy-900 via-navy-800 to-navy-700 px-8 py-8 shadow-[0_28px_70px_rgba(11,27,54,0.20)] sm:px-10 sm:py-10 lg:px-12 lg:py-11">
        <div class="absolute inset-y-0 right-0 w-[42%] opacity-[0.12]" style="background: radial-gradient(circle at top right, var(--color-gold-500) 0%, transparent 65%);"></div>
        <div class="absolute inset-x-0 bottom-0 h-px bg-[linear-gradient(90deg,transparent,rgba(255,255,255,0.22),transparent)]"></div>

        <div class="relative z-10 flex flex-col gap-8 lg:flex-row lg:items-end lg:justify-between lg:gap-10 lg:pl-8">
            <div class="flex-1">
                <div class="font-jetbrains text-[11px] uppercase tracking-[.18em] text-gold-400 mb-4">Portal do Cliente · Acesso Externo</div>
                <h1 class="font-fraunces text-[30px] font-normal text-white leading-tight mb-3">
                    {{ $greeting }}, {{ $firstName }}.<br>
                    <span class="text-white/90">Sua área de relacionamento institucional.</span>
                </h1>
                <p class="font-inter text-[14.5px] text-[#B8C2D5] max-w-2xl leading-relaxed">
                    Acompanhe envios, documentos e o status das solicitações em curso junto à mesa de operações da BSI Capital.
                </p>

                <div class="mt-6 flex flex-wrap gap-3">
                    <div class="welcome-chip">
                        <span class="welcome-chip-value">{{ sprintf('%02d', $pendingSubmissions) }}</span>
                        <span>em acompanhamento</span>
                    </div>
                    <div class="welcome-chip">
                        <span class="welcome-chip-value">{{ $closedRate }}%</span>
                        <span>encerrados</span>
                    </div>
                    <div class="welcome-chip">
                        <span class="welcome-chip-value">{{ $latestActivity }}</span>
                        <span>última movimentação</span>
                    </div>
                </div>
            </div>

            <div class="flex w-full flex-col gap-4 lg:max-w-[320px]">
                <div class="font-jetbrains text-[10.5px] text-white/50 tracking-widest uppercase lg:text-right">
                    Sessão ativa · {{ $portalNow->translatedFormat('d M Y · H:i') }} BRT
                </div>
                <a href="{{ route('nimbus.submissions.create') }}" class="p-btn-primary flex items-center justify-between h-[48px] px-6 bg-gold-500 border border-gold-600 rounded-[4px] text-white text-[14px] font-semibold no-underline shadow-lg transition-all hover:bg-gold-400 hover:text-white">
                    <span>Abrir nova solicitação</span>
                    <i class="bi bi-arrow-up-right text-[14px]"></i>
                </a>

                <div class="welcome-aside">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <div class="font-jetbrains text-[10px] uppercase tracking-[.18em] text-white/45">Resumo operacional</div>
                            <div class="mt-1 font-inter text-[14px] font-semibold text-white">{{ $pendingSummary }}</div>
                        </div>
                        <div class="portal-status-pill">Online</div>
                    </div>

                    <div class="mt-4 grid gap-3">
                        <div class="welcome-aside-row">
                            <span>Último protocolo</span>
                            <strong>{{ $latestProtocol ?? 'Sem protocolo' }}</strong>
                        </div>
                        <div class="welcome-aside-row">
                            <span>Registros visíveis</span>
                            <strong>{{ sprintf('%02d', $visibleSubmissions) }}</strong>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
        <div class="kpi gold shadow-portal-subtle">
            <div class="flex items-start justify-between mb-4">
                <div class="font-inter text-[11.5px] font-medium uppercase tracking-[.12em] text-ink-500">Envios Realizados</div>
                <div class="w-7 h-7 bg-ink-50 rounded-[6px] flex items-center justify-center text-ink-500">
                    <flux:icon.archive-box class="size-[18px]" variant="outline" stroke-width="1.6" />
                </div>
            </div>
            <div class="flex items-baseline gap-2 mb-6">
                <div class="font-fraunces text-[42px] font-medium tracking-tight text-navy-900 leading-none">{{ sprintf('%02d', $totalSubmissions) }}</div>
                <div class="font-inter text-[13px] font-medium text-ink-400">total</div>
            </div>
            <div class="kpi-meta font-inter text-[12px] text-ink-500">
                {{ $totalSubmissions > 0 ? 'Visão rápida com '.sprintf('%02d', $visibleSubmissions).' registros recentes' : 'Pronto para iniciar sua primeira solicitação' }}
            </div>
        </div>

        <div class="kpi amber shadow-portal-subtle">
            <div class="flex items-start justify-between mb-4">
                <div class="font-inter text-[11.5px] font-medium uppercase tracking-[.12em] text-ink-500">Aguardando Análise</div>
                <div class="w-7 h-7 bg-amber-50 rounded-[6px] flex items-center justify-center text-amber-600">
                    <flux:icon.clock class="size-[18px]" variant="outline" stroke-width="1.6" />
                </div>
            </div>
            <div class="flex items-baseline gap-2 mb-6">
                <div class="font-fraunces text-[42px] font-medium tracking-tight text-navy-900 leading-none">{{ sprintf('%02d', $pendingSubmissions) }}</div>
                <div class="font-inter text-[13px] font-medium text-ink-400">em curso</div>
            </div>
            <div class="kpi-meta font-inter text-[12px] text-ink-500">
                {{ $pendingSummary }}
            </div>
        </div>

        <div class="kpi emerald shadow-portal-subtle">
            <div class="flex items-start justify-between mb-4">
                <div class="font-inter text-[11.5px] font-medium uppercase tracking-[.12em] text-ink-500">Ciclos Encerrados</div>
                <div class="w-7 h-7 bg-emerald-50 rounded-[6px] flex items-center justify-center text-emerald-600">
                    <flux:icon.check-circle class="size-[18px]" variant="outline" stroke-width="1.6" />
                </div>
            </div>
            <div class="flex items-baseline gap-2 mb-6">
                <div class="font-fraunces text-[42px] font-medium tracking-tight text-navy-900 leading-none">{{ sprintf('%02d', $closedSubmissions) }}</div>
                <div class="font-inter text-[13px] font-medium text-ink-400">do histórico</div>
            </div>
            <div class="kpi-meta font-inter text-[12px] text-ink-500">
                {{ $closedSummary }}
            </div>
        </div>
    </div>

    <div class="bg-white border border-ink-200 rounded-[8px] shadow-portal-subtle overflow-hidden">
        <div class="px-7 py-6 border-b border-ink-100 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <h5 class="font-fraunces text-[20px] font-medium text-navy-900 mb-1">Suas Solicitações Recentes</h5>
                <div class="font-jetbrains text-[11px] uppercase tracking-[.1em] text-ink-400">
                    Atualizado em tempo real · {{ $visibleSubmissions }} registros visíveis
                </div>
            </div>
            <div class="filterbar flex items-center gap-3">
                <div class="nd-meta-chip nd-meta-chip-muted">Visão rápida · {{ $visibleSubmissions }} itens</div>
                <a href="{{ route('nimbus.submissions.index') }}" class="flex items-center justify-center px-[14px] py-[8px] bg-white border border-ink-200 rounded-[5px] text-ink-700 text-[13px] font-medium no-underline hover:bg-ink-50 transition-colors">
                    Ver todas
                </a>
            </div>
        </div>

        <div class="overflow-x-auto">
            @if($submissions->isEmpty())
                <div class="nd-empty-state py-12 text-center">
                    <div class="nd-empty-icon mb-4">
                        <i class="bi bi-inbox text-4xl text-ink-200"></i>
                    </div>
                    <h6 class="font-fraunces text-lg text-navy-900 mb-2">Nenhuma solicitação encontrada</h6>
                    <p class="font-inter text-sm text-ink-500 max-w-sm mx-auto mb-6">Você ainda não realizou nenhum envio. Comece pela abertura de uma nova solicitação para iniciar o fluxo com a mesa operacional.</p>
                    <a href="{{ route('nimbus.submissions.create') }}" class="inline-flex items-center justify-center gap-2 rounded-[5px] border border-gold-600 bg-gold-500 px-5 py-2.5 text-[13px] font-semibold text-[#1A1305] no-underline transition-all hover:bg-gold-400">
                        <span>Criar primeira solicitação</span>
                        <i class="bi bi-arrow-right"></i>
                    </a>
                </div>
            @else
                <table class="bsi w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-ink-50 border-b border-ink-200">
                            <th class="px-7 py-3 font-inter text-[11px] font-semibold uppercase tracking-[.12em] text-ink-500">Protocolo/Referência</th>
                            <th class="px-7 py-3 font-inter text-[11px] font-semibold uppercase tracking-[.12em] text-ink-500">Operação</th>
                            <th class="px-7 py-3 font-inter text-[11px] font-semibold uppercase tracking-[.12em] text-ink-500">Data de envio</th>
                            <th class="px-7 py-3 font-inter text-[11px] font-semibold uppercase tracking-[.12em] text-ink-500 text-right">Valor</th>
                            <th class="px-7 py-3 font-inter text-[11px] font-semibold uppercase tracking-[.12em] text-ink-500">Situação</th>
                            <th class="px-7 py-3 font-inter text-[11px] font-semibold uppercase tracking-[.12em] text-ink-500 text-right">Ação</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($submissions as $s)
                            @php
                                $status = $s->status;
                                $statusLabel = \App\Models\Nimbus\Submission::statusOptions()[$status] ?? $status;
                                $protocol = $s->reference_code ?: sprintf('BSI-%s-%04d', $portalNow->format('Y'), $s->id);
                                $submissionTimestamp = $s->submitted_at?->timezone('America/Sao_Paulo');
                                $submissionType = filled($s->submission_type)
                                    ? \Illuminate\Support\Str::headline(str_replace('_', ' ', mb_strtolower((string) $s->submission_type)))
                                    : 'Operação em análise';

                                $badgeClass = match($status) {
                                    \App\Models\Nimbus\Submission::STATUS_PENDING => 'pending',
                                    \App\Models\Nimbus\Submission::STATUS_UNDER_REVIEW => 'review',
                                    \App\Models\Nimbus\Submission::STATUS_NEEDS_CORRECTION => 'pending',
                                    \App\Models\Nimbus\Submission::STATUS_COMPLETED => 'approved',
                                    \App\Models\Nimbus\Submission::STATUS_REJECTED => 'rejected',
                                    default => 'draft',
                                };
                            @endphp
                            <tr class="border-b border-ink-100 hover:bg-[#FAFBFD] transition-colors">
                                <td class="px-7 py-[18px]">
                                    <div class="font-jetbrains text-[13px] font-medium text-navy-900 uppercase">#{{ $protocol }}</div>
                                    <div class="font-inter text-[12px] text-ink-500">{{ $s->company_name ?? 'Sem razão social informada' }}</div>
                                </td>
                                <td class="px-7 py-[18px]">
                                    <div class="font-inter text-[13px] font-medium text-navy-900">{{ $s->title ?? 'Solicitação enviada' }}</div>
                                    <div class="font-inter text-[12px] text-ink-500">{{ $submissionType }}</div>
                                </td>
                                <td class="px-7 py-[18px]">
                                    <div class="font-jetbrains text-[13px] text-navy-900">{{ $submissionTimestamp?->format('d / m / Y') ?? '—' }}</div>
                                    <div class="font-inter text-[11.5px] text-ink-400">{{ $submissionTimestamp?->format('H:i') ?? 'Sem horário' }} · São Paulo</div>
                                </td>
                                <td class="px-7 py-[18px] text-right font-jetbrains text-[13.5px] font-medium text-navy-900">
                                    @if ($s->annual_revenue !== null)
                                        R$ {{ number_format((float) $s->annual_revenue, 2, ',', '.') }}
                                    @else
                                        <span class="font-inter text-[12px] font-medium text-ink-400">A confirmar</span>
                                    @endif
                                </td>
                                <td class="px-7 py-[18px]">
                                    <div class="badge {{ $badgeClass }}">
                                        <div class="badge-pulse"></div>
                                        {{ $statusLabel }}
                                    </div>
                                </td>
                                <td class="px-7 py-[18px] text-right">
                                    <a href="{{ route('nimbus.submissions.show', $s->id) }}" class="row-cta text-[13px]">
                                        <span>Detalhes</span>
                                        <i class="bi bi-chevron-right text-[11px] ms-1"></i>
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>

@endsection

@push('styles')
<style>
    .nd-meta-chip {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 12px;
        border-radius: 999px;
        border: 1px solid rgba(11, 27, 54, 0.08);
        background: rgba(255, 255, 255, 0.82);
        color: var(--color-navy-700);
        font: 600 10.5px/1 'JetBrains Mono', monospace;
        letter-spacing: .08em;
        text-transform: uppercase;
    }

    .nd-meta-chip-muted {
        color: var(--color-ink-500);
        background: rgba(255, 255, 255, 0.65);
    }

    .welcome-chip {
        display: inline-flex;
        align-items: center;
        gap: 10px;
        padding: 10px 14px;
        border-radius: 999px;
        border: 1px solid rgba(255, 255, 255, 0.10);
        background: rgba(255, 255, 255, 0.08);
        color: rgba(255, 255, 255, 0.74);
        font: 500 12.5px/1 'Inter', sans-serif;
        backdrop-filter: blur(12px);
    }

    .welcome-chip-value {
        color: #fff;
        font-weight: 600;
    }

    .welcome-aside {
        border: 1px solid rgba(255, 255, 255, 0.12);
        background: linear-gradient(180deg, rgba(255, 255, 255, 0.12), rgba(255, 255, 255, 0.06));
        border-radius: 12px;
        padding: 18px 18px 16px;
        backdrop-filter: blur(16px);
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.08);
    }

    .welcome-aside-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        padding-top: 12px;
        margin-top: 12px;
        border-top: 1px solid rgba(255, 255, 255, 0.08);
        color: rgba(255, 255, 255, 0.70);
        font: 500 12px/1.4 'Inter', sans-serif;
    }

    .welcome-aside-row strong {
        color: #fff;
        font-size: 12.5px;
        font-weight: 600;
    }

    .portal-status-pill {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        padding: 6px 10px;
        border-radius: 999px;
        background: rgba(212, 175, 55, 0.16);
        color: #e8dcc7;
        font: 600 10px/1 'JetBrains Mono', monospace;
        letter-spacing: .08em;
        text-transform: uppercase;
    }

    .portal-status-pill::before {
        content: "";
        width: 6px;
        height: 6px;
        border-radius: 999px;
        background: #C9A66A;
        box-shadow: 0 0 0 4px rgba(201, 166, 106, 0.12);
    }

    .kpi {
        position: relative;
        overflow: hidden;
        box-shadow: 0 18px 40px rgba(11, 27, 54, 0.06);
        transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
    }

    .kpi::after {
        content: "";
        position: absolute;
        top: -44px;
        right: -40px;
        width: 120px;
        height: 120px;
        border-radius: 999px;
        background: radial-gradient(circle, rgba(11, 27, 54, 0.05), transparent 70%);
        pointer-events: none;
    }

    .kpi:hover {
        transform: translateY(-2px);
        border-color: rgba(160, 110, 40, 0.34);
        box-shadow: 0 24px 52px rgba(11, 27, 54, 0.10);
    }

    .kpi.amber {
        border-top: 2px solid var(--color-amber-600);
    }

    .kpi.emerald {
        border-top: 2px solid var(--color-emerald-600);
    }

    .badge {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 5px 11px 5px 9px;
        font: 600 11.5px/1 'Inter', sans-serif;
        letter-spacing: .06em; text-transform: uppercase;
        border-radius: 4px;
        border: 1px solid transparent;
    }
    .badge.pending { background: var(--color-gold-50); color: var(--color-gold-700); border-color: rgba(160,100,40,.18); }
    .badge.review { background: var(--color-navy-50); color: var(--color-navy-700); border-color: rgba(34,66,76,.16); }
    .badge.approved { background: #E6F1EC; color: #1E7A56; border-color: rgba(30,122,86,.18); }
    .badge.rejected { background: #F7E5E8; color: #9B2D3E; border-color: rgba(155,45,62,.18); }
    .badge.draft { background: var(--color-ink-50); color: var(--color-ink-500); border-color: var(--color-ink-200); }
</style>
@endpush

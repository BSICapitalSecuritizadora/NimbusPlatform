@extends('nimbus.layouts.portal')

@section('title', 'Início')

@section('content')
@php
    $firstName = explode(' ', Auth::guard('nimbus')->user()->full_name ?? Auth::guard('nimbus')->user()->email)[0];
@endphp

    <!-- Welcome Banner -->
    <div class="nd-welcome-banner mb-5">
        <div class="nd-welcome-content">
            <div class="nd-welcome-greeting">
                <span class="nd-welcome-wave">👋</span>
                <span>Olá, <strong>{{ $firstName }}</strong>!</span>
            </div>
            <p class="nd-welcome-message">Bem-vindo ao seu portal exclusivo de solicitações e documentos.</p>
        </div>
        <div class="nd-welcome-cta">
            <a href="{{ route('nimbus.submissions.create') }}" class="nd-btn nd-btn-gold shadow-lg">
                <i class="bi bi-plus-lg"></i>
                <span>Nova Solicitação</span>
            </a>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="row g-4 mb-5">
        <div class="col-md-4">
            <div class="nd-stat-card nd-stat-card-primary">
                <div class="nd-stat-icon">
                    <i class="bi bi-inbox-fill"></i>
                </div>
                <div class="nd-stat-info">
                    <div class="nd-stat-value">{{ number_format($stats['total'] ?? 0) }}</div>
                    <div class="nd-stat-label">Envios Realizados</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="nd-stat-card nd-stat-card-gold">
                <div class="nd-stat-icon">
                    <i class="bi bi-clock-fill"></i>
                </div>
                <div class="nd-stat-info">
                    <div class="nd-stat-value">{{ number_format($stats['pending'] ?? 0) }}</div>
                    <div class="nd-stat-label">Aguardando Análise</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="nd-stat-card nd-stat-card-success">
                <div class="nd-stat-icon">
                    <i class="bi bi-check-circle-fill"></i>
                </div>
                <div class="nd-stat-info">
                    <div class="nd-stat-value">{{ number_format($stats['approved'] ?? 0) }}</div>
                    <div class="nd-stat-label">Solicitações Aprovadas</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Submissions -->
    <div class="nd-card">
        <div class="nd-card-header d-flex align-items-center justify-content-between">
            <h5 class="nd-card-title mb-0">
                <i class="bi bi-clock-history me-2"></i>
                Suas Solicitações Recentes
            </h5>
            <a href="{{ route('nimbus.submissions.index') }}" class="nd-btn nd-btn-outline nd-btn-sm">
                Ver Todas
                <i class="bi bi-chevron-right"></i>
            </a>
        </div>
        <div class="nd-card-body p-0">
            @if(empty($submissions))
                <div class="nd-empty-state">
                    <div class="nd-empty-icon">
                        <i class="bi bi-inbox"></i>
                    </div>
                    <h6>Nenhuma solicitação encontrada</h6>
                    <p>Você ainda não realizou nenhum envio. Clique no botão acima para criar sua primeira solicitação.</p>
                    <a href="{{ route('nimbus.submissions.create') }}" class="nd-btn nd-btn-primary">
                        <i class="bi bi-plus-lg"></i>
                        Criar Primeira Solicitação
                    </a>
                </div>
            @else
                <div class="nd-table-wrapper" style="border: none; border-radius: 0;">
                    <table class="nd-table">
                        <thead>
                            <tr>
                                <th>Referência</th>
                                <th>Data de Envio</th>
                                <th>Situação</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach (array_slice($submissions, 0, 5) as $s)
                                @php
                                    $status = $s['status'] ?? null;
                                    $statusConfig = [
                                        'label' => \App\Models\Nimbus\Submission::statusLabelFor($status),
                                        'class' => \App\Models\Nimbus\Submission::statusColorFor($status),
                                        'icon' => \App\Models\Nimbus\Submission::statusIconFor($status),
                                    ];
                                @endphp
                                <tr>
                                    <td>
                                        <span class="fw-semibold text-dark">#{{ $s['id'] ?? 'N/A' }}</span>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center text-muted small">
                                            <i class="bi bi-calendar3 me-1"></i>
                                            {{ date('d/m/Y H:i', strtotime($s['submitted_at'] ?? 'now')) }}
                                        </div>
                                    </td>
                                    <td>
                                        <span class="nd-badge nd-badge-{{ $statusConfig['class'] }}">
                                            <i class="bi {{ $statusConfig['icon'] }}"></i>
                                            {{ $statusConfig['label'] }}
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <a href="{{ route('nimbus.submissions.show', $s['id']) }}" class="nd-btn nd-btn-ghost nd-btn-sm" title="Ver detalhes">
                                            Detalhes
                                            <i class="bi bi-chevron-right"></i>
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

@endsection

@push('styles')
<style>
    /* Welcome Banner */
    .nd-welcome-banner {
        background: linear-gradient(135deg, var(--nd-navy-800) 0%, var(--nd-navy-900) 100%);
        border-radius: var(--nd-radius-2xl);
        padding: 2rem 2.5rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 2rem;
        box-shadow: var(--nd-shadow-lg);
        position: relative;
        overflow: hidden;
    }
    
    .nd-welcome-banner::before {
        content: ''; position: absolute; top: -50%; right: -10%; width: 300px; height: 300px;
        background: radial-gradient(circle, rgba(212, 168, 75, 0.1) 0%, transparent 70%); border-radius: 50%;
    }
    
    .nd-welcome-content { position: relative; z-index: 1; }
    
    .nd-welcome-greeting {
        font-family: var(--nd-font-heading); font-size: 1.5rem; color: #ffffff;
        margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.75rem;
    }
    
    .nd-welcome-wave { font-size: 1.75rem; animation: wave 2s ease-in-out infinite; }
    
    @keyframes wave {
        0%, 100% { transform: rotate(0deg); }
        25% { transform: rotate(15deg); }
        75% { transform: rotate(-10deg); }
    }
    
    .nd-welcome-message { color: rgba(255, 255, 255, 0.7); margin: 0; font-size: 0.9375rem; }
    .nd-welcome-cta { flex-shrink: 0; position: relative; z-index: 1; }
    .nd-welcome-cta .nd-btn { padding: 0.875rem 1.5rem; font-size: 0.9375rem; }
    
    /* Stat Cards */
    .nd-stat-card {
        display: flex; align-items: center; gap: 1.25rem; background: var(--nd-white);
        border: 1px solid var(--nd-surface-200); border-radius: var(--nd-radius-xl);
        padding: 1.5rem; transition: var(--nd-transition);
    }
    
    .nd-stat-card:hover { transform: translateY(-2px); box-shadow: var(--nd-shadow-md); }
    
    .nd-stat-icon {
        width: 56px; height: 56px; border-radius: var(--nd-radius-lg); display: flex;
        align-items: center; justify-content: center; font-size: 1.5rem; flex-shrink: 0;
    }
    
    .nd-stat-card-primary .nd-stat-icon { background: var(--nd-navy-100); color: var(--nd-navy-700); }
    .nd-stat-card-gold .nd-stat-icon { background: var(--nd-gold-100); color: var(--nd-gold-700); }
    .nd-stat-card-success .nd-stat-icon { background: var(--nd-success-light); color: var(--nd-success-dark); }
    
    .nd-stat-value {
        font-family: var(--nd-font-heading); font-size: 1.75rem; font-weight: 700;
        color: var(--nd-navy-800); line-height: 1.1;
    }
    .nd-stat-label { color: var(--nd-gray-500); font-size: 0.8125rem; margin-top: 0.125rem; }
    
    /* Empty State */
    .nd-empty-state { text-align: center; padding: 3rem 2rem; }
    .nd-empty-state .nd-empty-icon { width: 80px; height: 80px; margin: 0 auto 1.5rem; background: var(--nd-surface-100); border-radius: 50%; display: flex; align-items: center; justify-content: center; }
    .nd-empty-state .nd-empty-icon i { font-size: 2rem; color: var(--nd-gray-400); }
    .nd-empty-state h6 { color: var(--nd-navy-800); font-weight: 600; margin-bottom: 0.5rem; }
    .nd-empty-state p { color: var(--nd-gray-500); font-size: 0.875rem; max-width: 360px; margin: 0 auto 1.5rem; }
</style>
@endpush

<?php $__env->startSection('title', $emission->name . ' - Detalhes da Emissao - BSI Capital'); ?>

<?php $__env->startPush('head'); ?>
<style>
    .emission-timeline-card {
        position: relative;
        overflow: hidden;
        background: linear-gradient(180deg, color-mix(in srgb, var(--surface) 96%, white 4%), color-mix(in srgb, var(--surface-alt) 94%, var(--brand) 6%));
    }

    .emission-timeline-card::before {
        content: "";
        position: absolute;
        inset: 0 0 auto 0;
        height: 1px;
        background: linear-gradient(90deg, rgba(160, 110, 40, 0), rgba(160, 110, 40, 0.34), rgba(9, 27, 35, 0.08), rgba(160, 110, 40, 0));
    }

    .emission-timeline-point {
        height: 100%;
        padding: 1rem 1.1rem;
        border: 1px solid color-mix(in srgb, var(--brand) 8%, var(--border));
        border-radius: 14px;
        background: color-mix(in srgb, var(--surface) 97%, white 3%);
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.75);
    }

    .emission-timeline-label {
        margin-bottom: 0.45rem;
        color: var(--muted);
        font-size: 0.78rem;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
    }

    .emission-timeline-value {
        color: var(--brand);
        font-size: clamp(1.18rem, 1.04rem + 0.34vw, 1.48rem);
        font-weight: 800;
        letter-spacing: -0.03em;
        line-height: 1.15;
    }

    .emission-timeline-status-badge,
    .emission-timeline-progress-pill {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 2.2rem;
        padding: 0.45rem 0.9rem;
        border-radius: 999px;
        font-size: 0.78rem;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-align: center;
    }

    .emission-timeline-progress-pill {
        border: 1px solid color-mix(in srgb, var(--brand) 12%, var(--border));
        background: color-mix(in srgb, var(--surface) 94%, white 6%);
        color: var(--brand);
    }

    .emission-timeline-track-shell {
        position: relative;
        padding-block: 0.45rem;
    }

    .emission-timeline-track {
        position: relative;
        overflow: visible;
        height: 0.5rem;
        border-radius: 999px;
        background: color-mix(in srgb, var(--border) 84%, white);
        box-shadow: inset 0 1px 1px rgba(9, 27, 35, 0.08);
    }

    .emission-timeline-track-fill {
        height: 100%;
        border-radius: inherit;
        background: linear-gradient(90deg, color-mix(in srgb, #7ca6d8 78%, white), color-mix(in srgb, var(--brand) 76%, #7ca6d8 24%));
        box-shadow: 0 0 0 1px rgba(124, 166, 216, 0.18), 0 3px 10px rgba(9, 27, 35, 0.14);
    }

    .emission-timeline-current-dot {
        position: absolute;
        top: 50%;
        width: 1rem;
        height: 1rem;
        border: 3px solid #fff;
        border-radius: 999px;
        background: var(--brand);
        box-shadow: 0 0 0 4px rgba(9, 27, 35, 0.12), 0 8px 18px rgba(9, 27, 35, 0.18);
        transform: translateY(-50%);
    }

    .emission-timeline-current-dot::after {
        content: "";
        position: absolute;
        inset: -0.38rem;
        border-radius: inherit;
        border: 1px solid rgba(9, 27, 35, 0.14);
    }

    .emission-timeline-meta {
        color: var(--muted);
        font-size: 0.82rem;
        font-weight: 600;
        line-height: 1.55;
    }

    .emission-detail-tabs-container {
        display: inline-flex;
        background: rgba(9,27,35,0.03);
        padding: 0.35rem;
        border-radius: 50rem;
        border: 1px solid rgba(9,27,35,0.06);
    }
    .emission-detail-tabs-container .nav-link {
        color: #5d687b;
        font-weight: 600;
        font-size: 0.95rem;
        padding: 0.6rem 1.75rem;
        border-radius: 50rem;
        transition: all 0.3s ease;
        border: none;
        background: transparent;
    }
    .emission-detail-tabs-container .nav-link:hover {
        color: var(--brand);
    }
    .emission-detail-tabs-container .nav-link.active {
        color: var(--brand);
        background: #ffffff;
        box-shadow: 0 2px 10px rgba(9,27,35,0.08);
    }

    .liquid-glass-logo {
        background: rgba(255, 255, 255, 0.04);
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 20px;
        box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.15), inset 0 1px 0 rgba(255, 255, 255, 0.1);
        transition: all 0.3s ease;
    }
    .liquid-glass-logo:hover {
        background: rgba(255, 255, 255, 0.06);
        border-color: rgba(255, 255, 255, 0.12);
        box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.2), inset 0 1px 0 rgba(255, 255, 255, 0.15);
    }

    .tech-data-card {
        background: #ffffff;
        border: 1px solid rgba(9,27,35,0.04);
        border-radius: 16px;
        box-shadow: 0 4px 14px rgba(9,27,35,0.02);
        transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .tech-data-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 24px rgba(9,27,35,0.06);
        border-color: rgba(9,27,35,0.08);
    }
    .tech-data-label {
        font-size: 0.7rem;
        letter-spacing: 0.05em;
        color: #8c98a4;
        font-weight: 700;
        margin-bottom: 0.4rem;
        text-transform: uppercase;
    }
    .tech-data-value {
        font-size: 1.05rem;
        color: var(--brand);
        font-weight: 600;
        line-height: 1.3;
    }
    
    .history-row {
        background: rgba(9,27,35,0.015);
        border: 1px solid rgba(9,27,35,0.03);
        border-radius: 12px;
        transition: all 0.2s ease;
    }
    .history-row:hover {
        background: #ffffff;
        border-color: rgba(9,27,35,0.08);
        box-shadow: 0 4px 12px rgba(9,27,35,0.03);
    }
    
    .badge-premium {
        background: rgba(212,175,55,0.12);
        color: var(--gold);
        border: 1px solid rgba(212,175,55,0.25);
        font-weight: 600;
        font-size: 0.75rem;
        border-radius: 8px;
    }
    
    .document-table-container {
        background: #ffffff;
        border: 1px solid rgba(9,27,35,0.04);
        border-radius: 16px;
        box-shadow: 0 4px 14px rgba(9,27,35,0.02);
        overflow: hidden;
    }
    .document-table-container thead th {
        background: rgba(9,27,35,0.02);
        border-bottom: 1px solid rgba(9,27,35,0.05);
        color: #8c98a4;
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        padding: 1rem 1.5rem;
        border-top: none;
    }
    .document-table-container tbody td {
        padding: 1.25rem 1.5rem;
        border-bottom: 1px solid rgba(9,27,35,0.03);
        vertical-align: middle;
    }
    .document-table-container tbody tr:last-child td {
        border-bottom: none;
    }
    .document-table-container tbody tr {
        transition: all 0.2s ease;
    }
    .document-table-container tbody tr:hover {
        background: rgba(9,27,35,0.015);
    }
    
    .doc-action-btn {
        background: rgba(9,27,35,0.03);
        color: var(--brand);
        border: 1px solid transparent;
        font-weight: 600;
        border-radius: 10px;
        padding: 0.4rem 1.2rem;
        transition: all 0.2s ease;
    }
    .doc-action-btn:hover {
        background: #ffffff;
        border-color: rgba(9,27,35,0.1);
        box-shadow: 0 4px 12px rgba(9,27,35,0.05);
        color: var(--brand);
        transform: translateY(-2px);
    }
    
    .doc-filter-select {
        background-color: #ffffff;
        border: 1px solid rgba(9,27,35,0.1);
        border-radius: 12px;
        padding: 0.7rem 1rem;
        font-weight: 500;
        color: var(--brand);
        box-shadow: 0 2px 8px rgba(9,27,35,0.02);
        transition: all 0.2s ease;
    }
    .doc-filter-select:focus {
        border-color: var(--gold);
        box-shadow: 0 0 0 4px rgba(212,175,55,0.15);
    }
</style>
<?php $__env->stopPush(); ?>

<?php $__env->startSection('content'); ?>
<?php
    $cspNonce = \Illuminate\Support\Facades\Vite::cspNonce();

    $statusPalette = match ($emission->status) {
        'active' => ['bg' => 'rgba(34,197,94,0.12)', 'border' => 'rgba(34,197,94,0.22)', 'text' => '#15803d', 'label' => $emission->status_label],
        'closed' => ['bg' => 'rgba(239,68,68,0.12)', 'border' => 'rgba(239,68,68,0.22)', 'text' => '#b91c1c', 'label' => $emission->status_label],
        default => ['bg' => 'rgba(245,158,11,0.12)', 'border' => 'rgba(245,158,11,0.22)', 'text' => '#b45309', 'label' => $emission->status_label],
    };

    $summaryCards = [
        ['label' => 'Tipo', 'value' => $emission->type ?? '—'],
        ['label' => 'Remuneração', 'value' => $emission->formatted_remuneration ?? '—'],
        ['label' => 'Quantidade emitida', 'value' => $emission->issued_quantity ? number_format((float) $emission->issued_quantity, 0, ',', '.') : '—'],
        ['label' => 'Total emitido', 'value' => $emission->issued_volume ? 'R$ ' . number_format((float) $emission->issued_volume, 2, ',', '.') : '—'],
    ];

    $timelineStartDate = $emission->issue_date?->copy()->startOfDay();
    $timelineEndDate = $emission->maturity_date?->copy()->startOfDay();
    $timelineCurrentDate = today()->startOfDay();
    $timelineProgressPercentage = 0;
    $timelineTotalDays = null;
    $timelineElapsedDays = null;
    $timelineRemainingDays = null;

    if (($timelineStartDate !== null) && ($timelineEndDate !== null)) {
        if ($timelineEndDate->lessThanOrEqualTo($timelineStartDate)) {
            $timelineProgressPercentage = $timelineCurrentDate->greaterThanOrEqualTo($timelineEndDate) ? 100 : 0;
            $timelineTotalDays = 0;
            $timelineElapsedDays = $timelineCurrentDate->greaterThanOrEqualTo($timelineEndDate) ? 0 : null;
            $timelineRemainingDays = $timelineCurrentDate->greaterThanOrEqualTo($timelineEndDate) ? 0 : 0;
        } elseif ($timelineCurrentDate->lessThanOrEqualTo($timelineStartDate)) {
            $timelineProgressPercentage = 0;
            $timelineTotalDays = $timelineStartDate->diffInDays($timelineEndDate);
            $timelineElapsedDays = 0;
            $timelineRemainingDays = $timelineCurrentDate->diffInDays($timelineEndDate);
        } elseif ($timelineCurrentDate->greaterThanOrEqualTo($timelineEndDate)) {
            $timelineProgressPercentage = 100;
            $timelineTotalDays = $timelineStartDate->diffInDays($timelineEndDate);
            $timelineElapsedDays = $timelineTotalDays;
            $timelineRemainingDays = 0;
        } else {
            $elapsedDays = $timelineStartDate->diffInDays($timelineCurrentDate);
            $totalDays = max(1, $timelineStartDate->diffInDays($timelineEndDate));

            $timelineTotalDays = $totalDays;
            $timelineElapsedDays = $elapsedDays;
            $timelineRemainingDays = $timelineCurrentDate->diffInDays($timelineEndDate);
            $timelineProgressPercentage = (int) round(($elapsedDays / $totalDays) * 100);
        }
    }

    $timelineStatusLabel = $emission->integralization_status ?: ($emission->status_label ?: 'Status não informado');
    $timelineFillMinimumWidth = $timelineProgressPercentage > 0 ? '1.1rem' : '0';
    $timelineProgressLabel = ($timelineTotalDays !== null)
        ? number_format($timelineProgressPercentage, 0, ',', '.') . '% do prazo decorrido'
        : 'Prazo não informado';
    $timelineElapsedLabel = match (true) {
        ($timelineStartDate === null) || ($timelineElapsedDays === null) => 'Data de emissão não informada',
        $timelineCurrentDate->lessThan($timelineStartDate) => 'Emissão ainda não iniciada',
        $timelineElapsedDays === 1 => '1 dia desde a emissão',
        default => number_format($timelineElapsedDays, 0, ',', '.') . ' dias desde a emissão',
    };
    $timelineRemainingLabel = match (true) {
        ($timelineEndDate === null) || ($timelineRemainingDays === null) => 'Vencimento não informado',
        $timelineCurrentDate->greaterThanOrEqualTo($timelineEndDate) => 'Prazo encerrado',
        $timelineRemainingDays === 1 => '1 dia até o vencimento',
        default => number_format($timelineRemainingDays, 0, ',', '.') . ' dias até o vencimento',
    };
    $timelineIndicatorLeft = match (true) {
        $timelineTotalDays === null => null,
        $timelineProgressPercentage <= 0 => '0',
        $timelineProgressPercentage >= 100 => 'calc(100% - 1rem)',
        default => 'calc(' . $timelineProgressPercentage . '% - 0.5rem)',
    };

?>

<section class="hero position-relative overflow-hidden" style="padding-top: 4.75rem; padding-bottom: 4rem;">
    <div class="container position-relative">
        <div class="row g-4 align-items-end">
            <div class="col-lg-8">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                    <span class="badge px-3 py-2 text-uppercase">Detalhe da emissão</span>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($emission->type): ?>
                        <span class="badge badge-type-<?php echo e(strtolower($emission->type)); ?> px-3 py-2"><?php echo e($emission->type); ?></span>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    <span class="badge badge-status-<?php echo e($emission->status); ?> px-3 py-2">
                        <?php echo e($statusPalette['label']); ?>

                    </span>
                </div>

                <div class="d-flex flex-wrap align-items-center gap-4 mb-4">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($emission->logo_path): ?>
                        <div class="liquid-glass-logo d-inline-flex align-items-center justify-content-center px-4 py-3" style="min-height: 86px; min-width: 180px;">
                            <img src="<?php echo e(Storage::disk($emission->logo_storage_disk)->url($emission->logo_path)); ?>" alt="<?php echo e($emission->name); ?>" style="max-height: 52px; max-width: 180px; object-fit: contain; filter: drop-shadow(0 2px 4px rgba(0,0,0,0.2));">
                        </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    <div>
                        <h1 class="display-5 fw-bold mb-2"><?php echo e($emission->name); ?></h1>
                        <div class="d-flex flex-wrap gap-3 small text-white-50">
                            <span>IF <?php echo e($emission->if_code ?? '—'); ?></span>
                            <span>ISIN <?php echo e($emission->isin_code ?? '—'); ?></span>
                            <span>Emissor: <?php echo e($emission->issuer ?? '—'); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="surface-card-dark p-4 p-lg-5 h-100">
                    <div class="section-kicker mb-2">Visão rápida</div>
                    <h2 class="h3 fw-bold text-white mb-3">Resumo operacional</h2>
                    <div class="d-flex flex-column gap-3">
                        <div class="d-flex justify-content-between gap-3">
                            <span class="text-white-50">Número da emissão</span>
                            <span class="fw-semibold text-white"><?php echo e($emission->emission_number ?? '—'); ?></span>
                        </div>
                        <div class="d-flex justify-content-between gap-3">
                            <span class="text-white-50">Série</span>
                            <span class="fw-semibold text-white"><?php echo e($emission->series ?? '—'); ?></span>
                        </div>
                        <div class="d-flex justify-content-between gap-3">
                            <span class="text-white-50">Tipo de oferta</span>
                            <span class="fw-semibold text-white"><?php echo e($emission->offer_type ?? '—'); ?></span>
                        </div>
                        <div class="d-flex justify-content-between gap-3">
                            <span class="text-white-50">Segmento</span>
                            <span class="fw-semibold text-white"><?php echo e($emission->segment ?? '—'); ?></span>
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
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = $summaryCards; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $summaryCard): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoop($loop->index); ?><?php endif; ?>
                <div class="col-sm-6 col-xl-3">
                    <div class="surface-card h-100 p-4">
                        <div class="section-kicker mb-2"><?php echo e($summaryCard['label']); ?></div>
                        <div class="h4 fw-bold text-brand mb-0" style="line-height: 1.4;"><?php echo e($summaryCard['value']); ?></div>
                    </div>
                </div>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
        </div>

        <div class="surface-card emission-timeline-card p-4 p-lg-4 mb-4">
            <div class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-start gap-3 mb-4">
                <div class="row g-3 flex-grow-1">
                    <div class="col-md-6">
                        <div class="emission-timeline-point">
                            <div class="emission-timeline-label">Data de Emissão</div>
                            <div class="emission-timeline-value"><?php echo e($emission->issue_date?->format('d/m/Y') ?? '—'); ?></div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="emission-timeline-point">
                            <div class="emission-timeline-label">Data de Vencimento</div>
                            <div class="emission-timeline-value"><?php echo e($emission->maturity_date?->format('d/m/Y') ?? '—'); ?></div>
                        </div>
                    </div>
                </div>

                <div class="d-flex flex-column align-items-xl-end gap-2">
                    <span
                        class="emission-timeline-status-badge"
                        style="background: <?php echo e($statusPalette['bg']); ?>; border: 1px solid <?php echo e($statusPalette['border']); ?>; color: <?php echo e($statusPalette['text']); ?>;"
                    >
                        <?php echo e($timelineStatusLabel); ?>

                    </span>
                    <span class="emission-timeline-progress-pill"><?php echo e($timelineProgressLabel); ?></span>
                </div>
            </div>

            <div class="emission-timeline-track-shell mb-3">
                <div
                    class="emission-timeline-track"
                    role="progressbar"
                    aria-label="Progresso da emissão"
                    aria-valuenow="<?php echo e($timelineProgressPercentage); ?>"
                    aria-valuemin="0"
                    aria-valuemax="100"
                >
                    <div
                        class="emission-timeline-track-fill"
                        style="width: <?php echo e($timelineProgressPercentage); ?>%; min-width: <?php echo e($timelineFillMinimumWidth); ?>;"
                    ></div>

                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($timelineIndicatorLeft !== null): ?>
                        <span class="emission-timeline-current-dot" style="left: <?php echo e($timelineIndicatorLeft); ?>;"></span>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>
            </div>

            <div class="d-flex flex-column flex-lg-row justify-content-between gap-2">
                <div class="emission-timeline-meta"><?php echo e($timelineElapsedLabel); ?></div>
                <div class="emission-timeline-meta text-lg-end"><?php echo e($timelineRemainingLabel); ?></div>
            </div>
        </div>

        <div class="mb-4 d-flex justify-content-center">
            <ul class="nav nav-pills gap-1 emission-detail-tabs-container" id="emissionTabs" role="tablist">
                <li class="nav-item"><a class="nav-link active" href="#caracteristicas">Características</a></li>
                <li class="nav-item"><a class="nav-link" href="#pagamentos">Pagamentos</a></li>
                <li class="nav-item"><a class="nav-link" href="#documentos">Documentos</a></li>
            </ul>
        </div>

        <div class="surface-card p-4 p-lg-5 mb-4" id="caracteristicas">
            <div class="row g-4 align-items-end mb-4">
                <div class="col-lg-8">
                    <div class="section-kicker mb-2">Características</div>
                    <h2 class="h3 fw-bold text-brand mb-2">Ficha Técnica da Operação</h2>
                    <p class="section-copy mb-0">Informações consolidadas sobre os principais aspectos jurídicos, comerciais e operacionais da emissão, organizadas para apoiar o acompanhamento, a análise e a transparência da operação.</p>
                </div>
            </div>

            <div class="row g-4">
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = [
                    'Série' => $emission->series,
                    'Número da emissão' => $emission->emission_number,
                    'Emissor' => $emission->issuer,
                    'Coordenador líder' => $emission->lead_coordinator,
                    'Agente fiduciário' => $emission->trustee_agent,
                    'Devedor' => $emission->debtor,
                    'Remuneração' => $emission->formatted_remuneration,
                    'Periodicidade de Pagamento de Juros' => $emission->interest_payment_frequency,
                    'Tipo de oferta' => $emission->offer_type,
                    'Segmento' => $emission->segment,
                    'Concentração' => $emission->concentration,
                    'Preço de emissão' => $emission->issued_price ? 'R$ ' . number_format((float) $emission->issued_price, 2, ',', '.') : null,
                    'Quantidade emitida' => $emission->issued_quantity ? number_format((float) $emission->issued_quantity, 0, ',', '.') : null,
                    'Quantidade integralizada' => $emission->integralized_quantity ? number_format((float) $emission->integralized_quantity, 0, ',', '.') : null,
                    'Total emitido' => $emission->issued_volume ? 'R$ ' . number_format((float) $emission->issued_volume, 0, ',', '.') : null,
                ]; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $label => $value): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoop($loop->index); ?><?php endif; ?>
                    <div class="col-sm-6 col-xl-4">
                        <div class="tech-data-card h-100 p-4">
                            <div class="tech-data-label"><?php echo e($label); ?></div>
                            <div class="tech-data-value"><?php echo e($value ?: '—'); ?></div>
                        </div>
                    </div>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
            </div>
        </div>

        <div class="surface-card p-4 p-lg-5 mb-4" id="pagamentos">
            <div class="row g-4 align-items-end mb-4">
                <div class="col-lg-8">
                    <div class="section-kicker mb-2">Monitoramento financeiro</div>
                    <h2 class="h3 fw-bold text-brand mb-2">Fluxo de pagamentos e acompanhamento</h2>
                    <p class="section-copy mb-0">Consolidação dos eventos financeiros da operação, incluindo histórico de PU e integralização.</p>
                </div>
            </div>

            <div class="tech-data-card p-4 mb-4" style="min-height: 360px;">
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($emission->payments) && $emission->payments->count() > 0): ?>
                    <div style="position: relative; height: 320px; width: 100%;">
                        <canvas id="paymentsChart"></canvas>
                    </div>
                <?php else: ?>
                    <div class="d-flex align-items-center justify-content-center rounded-4 border border-brand-subtle text-muted text-center px-4" style="min-height: 320px; border-style: dashed !important;">
                        Nenhum dado de pagamento registrado até o momento.
                    </div>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>

            <div class="row g-4">
                <div class="col-lg-6">
                    <div class="tech-data-card h-100 p-4">
                        <?php
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
                        ?>

                        <div class="d-flex justify-content-between align-items-center gap-3 mb-3">
                            <div>
                                <div class="section-kicker mb-1">PU atual</div>
                                <div class="h4 fw-bold text-brand mb-0"><?php echo e($todayPu ? 'R$ ' . number_format((float) $todayPu, 6, ',', '.') : '—'); ?></div>
                            </div>
                            <span class="badge badge-premium px-3 py-2">Últimos 5 dias</span>
                        </div>

                        <div class="d-flex flex-column gap-2">
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = $lastFiveDays; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $dayPu): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoop($loop->index); ?><?php endif; ?>
                                <div class="d-flex justify-content-between gap-3 history-row px-3 py-2">
                                    <span class="small text-muted"><?php echo e($dayPu['date']->format('d/m/Y')); ?></span>
                                    <span class="small fw-semibold text-brand"><?php echo e($dayPu['value'] ? 'R$ ' . number_format((float) $dayPu['value'], 6, ',', '.') : '—'); ?></span>
                                </div>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="tech-data-card h-100 p-4">
                        <?php
                            $integralizationHistory = $emission->integralizationHistories()->orderByDesc('date')->take(5)->get();
                            $totalIntegralization = $emission->integralizationHistories()->sum('quantity');
                        ?>

                        <div class="d-flex justify-content-between align-items-center gap-3 mb-3">
                            <div>
                                <div class="section-kicker mb-1">Integralização</div>
                                <div class="h4 fw-bold text-brand mb-0">
                                    <?php echo e($totalIntegralization ? number_format((float) $totalIntegralization, 0, ',', '.') : ($emission->integralization_status ?: '—')); ?>

                                </div>
                            </div>
                            <span class="badge badge-premium px-3 py-2">Histórico</span>
                        </div>

                        <div class="d-flex flex-column gap-2">
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $integralizationHistory; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $history): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoop($loop->index); ?><?php endif; ?>
                                <div class="d-flex justify-content-between gap-3 history-row px-3 py-2">
                                    <span class="small text-muted"><?php echo e($history->date->format('d/m/Y')); ?></span>
                                    <span class="small fw-semibold text-brand"><?php echo e(number_format((float) $history->quantity, 0, ',', '.')); ?></span>
                                </div>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
                                <div class="history-row px-3 py-4 small text-muted text-center">
                                    Nenhum evento de integralização registrado.
                                </div>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="surface-card p-4 p-lg-5" id="documentos">
            <?php
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
            ?>

            <div class="row g-4 align-items-end mb-4">
                <div class="col-lg-8">
                    <div class="section-kicker mb-2">Consulta documental</div>
                    <h2 class="h3 fw-bold text-brand mb-2">Repositório de Documentos e Atos da Operação</h2>
                    <p class="section-copy mb-0">Acesso integral ao histórico de fatos relevantes, relatórios e documentos regulatórios da emissão. Utilize os filtros para navegar com total transparência e rastreabilidade.</p>
                </div>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($documents->count() > 0): ?>
                    <?php
                        $docCategories = $documents->pluck('category')
                            ->filter()
                            ->unique()
                            ->sortBy(fn (string $category): string => strtolower($allCategories[$category] ?? $category));
                    ?>
                    <div class="col-lg-4">
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($docCategories->isNotEmpty()): ?>
                            <label for="docCategoryFilter" class="form-label tech-data-label">Categoria</label>
                            <select id="docCategoryFilter" class="form-select shadow-none doc-filter-select" data-limit="<?php echo e($documentDisplayLimit); ?>">
                                <option value="">Todas</option>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = $docCategories; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $category): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoop($loop->index); ?><?php endif; ?>
                                    <option value="<?php echo e($category); ?>"><?php echo e($allCategories[$category] ?? ucfirst($category)); ?></option>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
                            </select>
                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </div>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>

            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($documents->count() > 0): ?>
                <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-4">
                    <div class="small text-muted">
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($documents->count() > $documentDisplayLimit): ?>
                            Exibindo os documentos mais recentes por padrão. Utilize o filtro para aprofundar a consulta.
                        <?php else: ?>
                            <?php echo e($documents->count()); ?> documento(s) disponível(is) para consulta técnica e download.
                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </div>
                </div>

                <div class="d-grid gap-3 d-lg-none">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = $documents; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $index => $doc): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoop($loop->index); ?><?php endif; ?>
                        <article class="tech-data-card p-4 emission-doc-card doc-entry" data-category="<?php echo e($doc->category); ?>" data-index="<?php echo e($index); ?>" style="<?php echo e($index >= $documentDisplayLimit ? 'display: none;' : ''); ?>">
                            <div class="d-flex flex-column gap-3">
                                <div>
                                    <div class="small text-muted mb-2"><?php echo e(optional($doc->published_at)->format('d/m/Y') ?? '—'); ?></div>
                                    <div class="fw-semibold text-brand mb-2"><?php echo e($doc->title); ?></div>

                                    <div class="d-flex flex-wrap gap-2">
                                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($doc->category): ?>
                                            <span class="badge badge-premium px-3 py-2">
                                                <?php echo e($allCategories[$doc->category] ?? ucfirst($doc->category)); ?>

                                            </span>
                                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                    </div>
                                </div>

                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($doc->description): ?>
                                    <div class="small text-muted"><?php echo e($doc->description); ?></div>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

                                <a href="<?php echo e(Storage::disk($doc->resolved_storage_disk)->url($doc->file_path)); ?>" target="_blank" class="btn doc-action-btn text-center">
                                    Baixar
                                </a>
                            </div>
                        </article>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
                </div>

                <div class="document-table-container d-none d-lg-block">
                    <table class="table align-middle mb-0 border-0">
                        <thead>
                            <tr>
                                <th style="width: 160px; border-top: none;">Data</th>
                                <th style="border-top: none;">Título</th>
                                <th style="width: 120px; border-top: none;" class="text-end">Ação</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = $documents; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $index => $doc): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoop($loop->index); ?><?php endif; ?>
                                <tr class="doc-entry" data-category="<?php echo e($doc->category); ?>" data-index="<?php echo e($index); ?>" style="<?php echo e($index >= $documentDisplayLimit ? 'display: none;' : ''); ?>">
                                    <td class="text-muted"><?php echo e(optional($doc->published_at)->format('d/m/Y') ?? '—'); ?></td>
                                    <td>
                                        <div class="fw-semibold text-brand mb-1"><?php echo e($doc->title); ?></div>
                                        <div class="d-flex flex-wrap align-items-center gap-2">
                                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($doc->category): ?>
                                                <span class="badge badge-premium px-3 py-1">
                                                    <?php echo e($allCategories[$doc->category] ?? ucfirst($doc->category)); ?>

                                                </span>
                                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($doc->description): ?>
                                                <span class="small text-muted"><?php echo e($doc->description); ?></span>
                                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="text-end">
                                        <a href="<?php echo e(Storage::disk($doc->resolved_storage_disk)->url($doc->file_path)); ?>" target="_blank" class="btn doc-action-btn w-100">
                                            Baixar
                                        </a>
                                    </td>
                                </tr>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="surface-card-soft p-5 text-center text-muted">
                    Nenhum documento disponível para esta operação.
                </div>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>
    </div>
</section>

<?php $__env->startPush('scripts'); ?>
<script nonce="<?php echo e($cspNonce); ?>">
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

<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($emission->payments) && $emission->payments->count() > 0): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script nonce="<?php echo e($cspNonce); ?>">
document.addEventListener('DOMContentLoaded', function() {
    const chartElement = document.getElementById('paymentsChart');

    if (! chartElement) {
        return;
    }

    const labels = <?php echo json_encode($emission->payments->pluck('payment_date')->map(fn ($date) => $date->format('d/m/Y'))); ?>;

    new Chart(chartElement, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Prêmio',
                    data: <?php echo json_encode($emission->payments->pluck('premium_value')); ?>,
                    backgroundColor: '#a06e28',
                    borderRadius: 6,
                    barPercentage: 0.52,
                },
                {
                    label: 'Juros',
                    data: <?php echo json_encode($emission->payments->pluck('interest_value')); ?>,
                    backgroundColor: '#64748b',
                    borderRadius: 6,
                    barPercentage: 0.52,
                },
                {
                    label: 'Amortização Extraordinária',
                    data: <?php echo json_encode($emission->payments->pluck('extra_amortization_value')); ?>,
                    backgroundColor: '#1d3fb8',
                    borderRadius: 6,
                    barPercentage: 0.52,
                },
                {
                    label: 'Amortização',
                    data: <?php echo json_encode($emission->payments->pluck('amortization_value')); ?>,
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
                    backgroundColor: '#ffffff',
                    titleColor: '#091b23',
                    bodyColor: '#5c6980',
                    borderColor: 'rgba(9,27,35,0.08)',
                    borderWidth: 1,
                    padding: 14,
                    cornerRadius: 12,
                    boxPadding: 6,
                    usePointStyle: true,
                    boxWidth: 8,
                    boxHeight: 8,
                    titleFont: {
                        family: "'Inter', sans-serif",
                        size: 13,
                        weight: '700'
                    },
                    bodyFont: {
                        family: "'Inter', sans-serif",
                        size: 12,
                        weight: '500'
                    },
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
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

<?php $__env->stopPush(); ?>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('site.layout', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /var/www/html/resources/views/site/emission-detail.blade.php ENDPATH**/ ?>
@extends('site.layout')

@section('title', 'Formulário de Empreendimento')

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
    <livewire:proposal-continuation-form :access="$access" :proposal="$proposal" />
@endsection



<section class="py-5 min-h-[70vh] [background:linear-gradient(180deg,_rgba(255,255,255,0.55),_transparent_180px),radial-gradient(1100px_420px_at_50%_-8%,_rgba(9,27,35,0.10),_transparent_72%),var(--bg)]">
    {{-- Institutional visual layer (aligned with the public site palette in site/partials/styles).
         Defines classes referenced in this view and keeps everything on the --brand / --gold tokens. --}}
    <style>
        .cf-shell {
            --cf-radius-lg: 14px;
            --cf-radius-md: 10px;
            --cf-radius-sm: 6px;
            --cf-shadow: 0 6px 18px rgba(9, 27, 35, 0.05);
            --cf-shadow-hover: 0 10px 28px rgba(9, 27, 35, 0.09);
        }

        .premium-card {
            position: relative;
            background: var(--surface, #fff);
            border: 1px solid var(--border);
            border-radius: var(--cf-radius-lg);
            box-shadow: var(--cf-shadow);
            padding: 1.75rem;
        }

        @media (min-width: 992px) {
            .premium-card {
                padding: 2.5rem;
            }
        }

        .section-header {
            margin-bottom: 1.5rem;
        }

        .form-section-title {
            font-size: 1.6rem;
            font-weight: 800;
            letter-spacing: -0.03em;
            line-height: 1.15;
            color: var(--brand);
            margin: 0.15rem 0 0.4rem;
        }

        .form-section-subtitle {
            margin: 0;
            color: var(--muted);
            line-height: 1.6;
        }

        /* Primary action — mirrors the public site .btn-brand */
        .btn-primary-premium {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            background: var(--brand);
            color: #e6e4e4;
            border: 1px solid var(--brand);
            border-radius: var(--cf-radius-sm);
            padding: 0.85rem 1.9rem;
            font-weight: 700;
            font-size: 0.82rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            box-shadow: 0 4px 12px rgba(9, 27, 35, 0.08);
            transition: transform 0.2s ease, background 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease, opacity 0.2s ease;
            cursor: pointer;
        }

        .btn-primary-premium:hover:not(:disabled) {
            background: var(--brand-strong, #06151c);
            border-color: var(--brand-strong, #06151c);
            color: #fff;
            transform: translateY(-1px);
            box-shadow: var(--cf-shadow-hover);
        }

        .btn-primary-premium:disabled {
            opacity: 0.65;
            cursor: not-allowed;
        }

        /* Prefixed money inputs */
        .custom-input-wrap {
            display: flex;
            align-items: stretch;
            overflow: hidden;
            border: 1px solid color-mix(in srgb, var(--border) 80%, var(--brand) 20%);
            border-radius: var(--cf-radius-sm);
            background-color: color-mix(in srgb, #ffffff 90%, var(--surface) 10%);
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .custom-input-wrap:focus-within {
            border-color: color-mix(in srgb, var(--gold) 55%, var(--brand) 45%);
            box-shadow: 0 0 0 3px rgba(160, 110, 40, 0.16);
        }

        .custom-input-prefix {
            display: inline-flex;
            align-items: center;
            flex: none;
            padding: 0 0.75rem;
            font-weight: 700;
            font-size: 0.85rem;
            color: var(--muted);
            background: color-mix(in srgb, var(--brand) 6%, var(--surface));
            border-right: 1px solid color-mix(in srgb, var(--border) 86%, var(--brand) 14%);
        }

        /* Flux wraps its <input> in a block-level [data-flux-input] div, so the
           inline flex:1 never applies. Force the wrapper to grow and the control
           to fill it, so the money field spans the whole cell (no empty gap). */
        .custom-input-wrap > [data-flux-input] {
            flex: 1 1 0%;
            min-width: 0;
        }

        .custom-input-wrap [data-flux-control] {
            width: 100%;
        }

        /* Data tables */
        .table-premium {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
        }

        .table-premium th,
        .table-premium td {
            padding: 0.7rem 0.85rem;
            border-bottom: 1px solid var(--border);
            text-align: left;
            vertical-align: middle;
        }

        .table-premium tbody tr:last-child th,
        .table-premium tbody tr:last-child td {
            border-bottom: 0;
        }

        /* File upload dropzone */
        .file-upload-box {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 2.5rem 1.5rem;
            border: 1.5px dashed color-mix(in srgb, var(--brand) 22%, var(--border));
            border-radius: var(--cf-radius-md);
            background: color-mix(in srgb, var(--surface) 96%, var(--brand) 4%);
            transition: border-color 0.2s ease, background 0.2s ease;
        }

        .file-upload-box:hover {
            border-color: color-mix(in srgb, var(--gold) 45%, var(--brand));
            background: color-mix(in srgb, var(--gold) 8%, var(--surface));
        }

        .file-upload-box svg {
            width: 44px;
            height: 44px;
            flex: none;
            margin-bottom: 0.85rem;
            color: color-mix(in srgb, var(--brand) 55%, var(--muted));
        }

        .file-upload-box p {
            margin-bottom: 0.15rem;
        }

        /* Gold accent divider — public site .section-divider signature */
        .cf-divider {
            width: 64px;
            height: 3px;
            border-radius: 999px;
            margin: 0 0 0.9rem;
            background: linear-gradient(90deg, var(--gold), color-mix(in srgb, var(--gold) 35%, var(--brand) 65%), var(--brand));
        }

        /* Section bullet dots (robust sizing, independent of utility build) */
        .cf-dot {
            display: inline-block;
            width: 10px;
            height: 10px;
            flex: none;
            border-radius: 999px;
            background: var(--gold);
            box-shadow: 0 0 0 0.3rem rgba(160, 110, 40, 0.15);
        }

        /* Success toast — auto-dismiss, company palette (navy + gold) */
        .cf-toast {
            position: fixed;
            top: 1.25rem;
            right: 1.25rem;
            z-index: 1080;
            display: flex;
            align-items: flex-start;
            gap: 0.8rem;
            width: min(92vw, 400px);
            padding: 0.95rem 1.05rem 1rem;
            overflow: hidden;
            color: #e6e4e4;
            background: linear-gradient(135deg, var(--brand-strong, #06151c), var(--brand));
            border: 1px solid color-mix(in srgb, var(--gold) 42%, var(--brand));
            border-left: 4px solid var(--gold);
            border-radius: 10px;
            box-shadow: 0 18px 42px rgba(9, 27, 35, 0.32);
        }

        .cf-toast-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: none;
            width: 32px;
            height: 32px;
            border-radius: 999px;
            color: var(--brand);
            background: var(--gold);
        }

        .cf-toast-icon svg {
            width: 18px;
            height: 18px;
        }

        .cf-toast-body {
            flex: 1 1 auto;
            min-width: 0;
        }

        .cf-toast-title {
            display: block;
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: var(--gold);
            margin-bottom: 0.15rem;
        }

        .cf-toast-message {
            display: block;
            font-size: 0.92rem;
            line-height: 1.45;
            color: color-mix(in srgb, #e6e4e4 92%, transparent);
        }

        .cf-toast-close {
            flex: none;
            padding: 0;
            margin: -0.15rem -0.1rem 0 0;
            width: 1.5rem;
            height: 1.5rem;
            line-height: 1;
            font-size: 1.15rem;
            color: color-mix(in srgb, #e6e4e4 60%, transparent);
            background: transparent;
            border: 0;
            cursor: pointer;
            transition: color 0.2s ease;
        }

        .cf-toast-close:hover {
            color: var(--gold);
        }

        .cf-toast-bar {
            position: absolute;
            left: 0;
            bottom: 0;
            height: 3px;
            width: 100%;
            background: var(--gold);
            transform-origin: left center;
            animation: cf-toast-progress 4s linear forwards;
        }

        @keyframes cf-toast-progress {
            from { transform: scaleX(1); }
            to { transform: scaleX(0); }
        }

        @media (max-width: 575.98px) {
            .cf-toast {
                top: 0.85rem;
                right: 0.85rem;
                left: 0.85rem;
                width: auto;
            }
        }

        /* ── Hero panel ───────────────────────────────────────────── */
        .cf-hero {
            position: relative;
            overflow: hidden;
            border: 1px solid var(--border);
            border-radius: 14px;
            background:
                radial-gradient(120% 150% at 100% 0%, color-mix(in srgb, var(--gold) 10%, transparent), transparent 55%),
                linear-gradient(145deg, color-mix(in srgb, var(--surface) 96%, #fff 4%), color-mix(in srgb, var(--surface) 85%, var(--brand) 15%));
            box-shadow: 0 16px 40px rgba(9, 27, 35, 0.07);
        }

        .cf-hero::before {
            content: "";
            position: absolute;
            inset: 0 auto 0 0;
            width: 5px;
            background: linear-gradient(180deg, var(--gold), var(--brand));
        }

        .cf-hero-inner {
            position: relative;
            padding: 1.75rem 1.5rem;
        }

        @media (min-width: 992px) {
            .cf-hero-inner {
                padding: 2.75rem;
            }
        }

        .cf-hero-top {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1.25rem 1.75rem;
        }

        .cf-hero-head {
            flex: 1 1 22rem;
            min-width: 0;
        }

        .cf-kicker {
            font-size: 0.76rem;
            font-weight: 700;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            color: var(--gold);
            margin-bottom: 0.6rem;
        }

        .cf-hero-title {
            font-size: clamp(1.85rem, 1.25rem + 2.4vw, 2.75rem);
            font-weight: 800;
            letter-spacing: -0.035em;
            line-height: 1.05;
            color: var(--brand);
            margin: 0 0 0.7rem;
        }

        .cf-hero-company {
            font-size: 1.02rem;
            font-weight: 600;
            color: color-mix(in srgb, var(--brand) 74%, var(--muted));
            margin-bottom: 0.85rem;
        }

        .cf-hero-lead {
            max-width: 48ch;
            color: var(--muted);
            line-height: 1.65;
            margin: 0;
        }

        .cf-status {
            flex: 0 0 auto;
            text-align: right;
        }

        @media (max-width: 767.98px) {
            .cf-status {
                text-align: left;
            }
        }

        .cf-status-label {
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: var(--muted);
            margin-bottom: 0.5rem;
        }

        .cf-status-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.6rem;
            max-width: 17rem;
            padding: 0.62rem 1.05rem;
            border-radius: 999px;
            font-weight: 700;
            font-size: 0.9rem;
            line-height: 1.25;
            text-align: left;
            color: var(--brand);
            background: color-mix(in srgb, var(--gold) 12%, var(--surface));
            border: 1px solid color-mix(in srgb, var(--gold) 40%, var(--border));
        }

        .cf-meta-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 1rem;
            margin-top: 2rem;
            padding-top: 1.75rem;
            border-top: 1px solid color-mix(in srgb, var(--border) 82%, transparent);
        }

        @media (max-width: 767.98px) {
            .cf-meta-grid {
                grid-template-columns: 1fr;
                gap: 0.75rem;
            }
        }

        .cf-meta-card {
            position: relative;
            padding: 1.15rem 1.2rem 1.25rem;
            overflow: hidden;
            background: color-mix(in srgb, var(--surface) 93%, #fff 7%);
            border: 1px solid var(--border);
            border-radius: 10px;
            transition: border-color 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease;
        }

        .cf-meta-card::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(90deg, var(--gold), transparent 72%);
        }

        .cf-meta-card:hover {
            transform: translateY(-2px);
            border-color: color-mix(in srgb, var(--gold) 30%, var(--border));
            box-shadow: 0 10px 24px rgba(9, 27, 35, 0.08);
        }

        .cf-meta-label {
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: var(--muted);
            margin-bottom: 0.4rem;
        }

        .cf-meta-value {
            font-size: 1.15rem;
            font-weight: 800;
            letter-spacing: -0.02em;
            line-height: 1.2;
            color: var(--brand);
            margin-bottom: 0.3rem;
            overflow-wrap: anywhere;
        }

        .cf-meta-caption {
            font-size: 0.82rem;
            line-height: 1.45;
            color: var(--muted);
            margin: 0;
        }

        .file-upload-box input[type="file"] {
            margin-top: 1rem;
            width: 100%;
            max-width: 22rem;
            font-size: 0.85rem;
            color: var(--muted);
        }

        .file-upload-box input[type="file"]::file-selector-button {
            margin-right: 0.85rem;
            padding: 0.5rem 1rem;
            border: 1px solid var(--brand);
            border-radius: var(--cf-radius-sm);
            background: var(--brand);
            color: #e6e4e4;
            font-weight: 600;
            font-size: 0.78rem;
            letter-spacing: 0.03em;
            cursor: pointer;
            transition: background 0.2s ease;
        }

        .file-upload-box input[type="file"]::file-selector-button:hover {
            background: var(--brand-strong, #06151c);
        }

        /* ── Intro / "Próxima Etapa" card ─────────────────────────── */
        .cf-intro-grid {
            display: grid;
            grid-template-columns: 1.4fr 1fr;
            gap: 1.5rem 2rem;
            align-items: center;
        }

        @media (max-width: 991.98px) {
            .cf-intro-grid {
                grid-template-columns: 1fr;
                gap: 1.5rem;
            }
        }

        .cf-intro-title {
            font-size: clamp(1.45rem, 1.1rem + 1.2vw, 1.75rem);
            font-weight: 800;
            letter-spacing: -0.03em;
            line-height: 1.12;
            color: var(--brand);
            margin: 0 0 0.5rem;
        }

        .cf-intro-lead {
            margin: 0;
            max-width: 52ch;
            color: var(--muted);
            line-height: 1.65;
        }

        .cf-callout {
            position: relative;
            display: flex;
            gap: 0.9rem;
            padding: 1.35rem 1.4rem;
            border: 1px solid color-mix(in srgb, var(--gold) 26%, var(--border));
            border-radius: 10px;
            background: linear-gradient(135deg, color-mix(in srgb, var(--brand) 6%, var(--surface)), color-mix(in srgb, var(--gold) 13%, var(--surface)));
        }

        .cf-callout-icon {
            flex: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 38px;
            height: 38px;
            border-radius: 9px;
            color: var(--brand);
            background: color-mix(in srgb, var(--gold) 28%, var(--surface));
            border: 1px solid color-mix(in srgb, var(--gold) 46%, var(--border));
        }

        .cf-callout-icon svg {
            width: 20px;
            height: 20px;
        }

        .cf-callout-title {
            display: block;
            font-weight: 800;
            letter-spacing: -0.01em;
            color: var(--brand);
            margin-bottom: 0.3rem;
        }

        .cf-callout-text {
            margin: 0;
            font-size: 0.92rem;
            line-height: 1.55;
            color: var(--muted);
        }

        /* ── Form card & section headers ──────────────────────────── */
        .cf-card {
            background: var(--surface, #fff);
            border: 1px solid var(--border);
            border-radius: 14px;
            box-shadow: 0 6px 18px rgba(9, 27, 35, 0.05);
        }

        .cf-section-head {
            margin-bottom: 0.25rem;
        }

        .cf-section-title {
            font-size: 1.35rem;
            font-weight: 800;
            letter-spacing: -0.03em;
            line-height: 1.15;
            color: var(--brand);
            margin: 0 0 0.35rem;
        }

        .cf-section-head .cf-divider {
            margin: 0.6rem 0 0.85rem;
        }

        .cf-section-subtitle {
            margin: 0;
            color: var(--muted);
            line-height: 1.6;
        }

        /* Required-fields legend */
        .cf-required-note {
            display: inline-flex;
            align-items: baseline;
            gap: 0.4rem;
            margin-top: 0.95rem;
            font-size: 0.78rem;
            font-weight: 600;
            letter-spacing: 0.02em;
            color: var(--muted);
        }

        .cf-required-note::before {
            content: "*";
            color: var(--gold);
            font-weight: 800;
            font-size: 1rem;
            line-height: 1;
        }

        /* Dados Gerais subgroups reuse .cf-project-card; give them a touch more room */
        .cf-group-grid > .cf-project-card {
            padding: 1.25rem 1.15rem 1.35rem;
        }

        @media (min-width: 992px) {
            .cf-group-grid > .cf-project-card {
                padding: 1.6rem 1.75rem 1.75rem;
            }
        }

        /* Standard Flux fields/inputs must fill their grid column (this project's
           Flux controls do not stretch to 100% on their own). */
        .cf-group-grid [class*="col-"] > * {
            width: 100%;
        }

        .cf-group-grid [data-flux-input] {
            display: block;
            width: 100%;
        }

        .cf-group-grid [data-flux-control] {
            width: 100%;
        }

        /* Flux validation errors: the icon size + red-tone utilities are missing
           from the compiled CSS, so the SVG renders full-size in the inherited
           color. Constrain the icon and restore the error red here. */
        .cf-shell [data-flux-error] {
            color: #dc2626;
        }

        .cf-shell [data-flux-error] svg {
            display: inline-block;
            flex: none;
            width: 1rem;
            height: 1rem;
            vertical-align: -0.15em;
            margin-right: 0.3rem;
        }

        /* "Resumo das Unidades" split into two rows — inputs fill their cells. */
        .cf-units-split [data-flux-input] {
            display: block;
            width: 100%;
        }

        .cf-units-split [data-flux-control] {
            width: 100%;
        }

        /* "Configuração dos blocos": keep the number inputs inside their columns
           (default Flux width overflows the narrow cols and makes them merge). */
        .cf-blocks-grid [data-flux-input] {
            display: block;
            width: 100%;
        }

        .cf-blocks-grid [data-flux-control] {
            width: 100%;
            min-width: 0;
        }

        /* Typology table: make every input fill its cell so plain and money
           fields line up consistently across the type columns. */
        .cf-typology-table td [data-flux-input] {
            display: block;
            width: 100%;
        }

        .cf-typology-table td [data-flux-control] {
            width: 100%;
        }

        .cf-typology-table td .custom-input-wrap {
            width: 100%;
        }

        .cf-subcard-title {
            font-size: 1.1rem;
            font-weight: 800;
            letter-spacing: -0.02em;
            line-height: 1.2;
            color: var(--brand);
            margin: 0;
        }

        /* Institutional field labels (readable, on-brand) */
        .cf-card [data-flux-label] {
            font-size: 0.8rem;
            font-weight: 600;
            letter-spacing: 0.01em;
            color: color-mix(in srgb, var(--brand) 72%, var(--muted));
        }

        /* Inputs — institutional refinement of Flux controls */
        .cf-card [data-flux-control] {
            border-radius: 6px;
            border-color: color-mix(in srgb, var(--border) 80%, var(--brand) 20%);
            background-color: color-mix(in srgb, #ffffff 90%, var(--surface) 10%);
            color: var(--text);
            transition: border-color 0.18s ease, box-shadow 0.18s ease, background-color 0.18s ease;
        }

        .cf-card [data-flux-control]::placeholder {
            color: color-mix(in srgb, var(--muted) 76%, transparent);
        }

        .cf-card [data-flux-control]:hover:not(:focus):not([readonly]) {
            border-color: color-mix(in srgb, var(--brand) 32%, var(--border));
        }

        .cf-card [data-flux-control]:focus,
        .cf-card [data-flux-control]:focus-visible {
            outline: none;
            border-color: color-mix(in srgb, var(--gold) 55%, var(--brand) 45%);
            box-shadow: 0 0 0 3px rgba(160, 110, 40, 0.16);
            background-color: #fff;
        }

        /* Computed / read-only fields get a clear non-editable hint */
        .cf-card [data-flux-control][readonly] {
            background-color: color-mix(in srgb, var(--surface) 58%, #fff 42%);
            color: color-mix(in srgb, var(--brand) 55%, var(--muted));
            cursor: default;
        }

        .cf-card [data-flux-control]::-webkit-calendar-picker-indicator {
            opacity: 0.55;
            cursor: pointer;
        }

        /* ── Project cards & data tables ──────────────────────────── */
        .cf-project-card {
            border: 1px solid var(--border);
            border-radius: 10px;
            background: color-mix(in srgb, var(--surface) 96%, var(--brand) 4%);
            padding: 1rem;
        }

        @media (min-width: 992px) {
            .cf-project-card {
                padding: 1.5rem;
            }
        }

        .cf-subsection-label {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            margin-bottom: 0.85rem;
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--brand);
        }

        .cf-table-wrap {
            overflow: hidden;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: color-mix(in srgb, var(--surface) 97%, var(--brand) 3%);
        }

        .table-premium thead th {
            background: color-mix(in srgb, var(--brand) 8%, var(--surface));
            color: var(--brand);
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .table-premium td [data-flux-control],
        .table-premium td .custom-input-wrap {
            min-width: 6.5rem;
        }

        /* ── Action buttons (institutional) ───────────────────────── */
        .cf-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.45rem;
            padding: 0.6rem 1.2rem;
            border-radius: 6px;
            border: 1px solid transparent;
            font-weight: 700;
            font-size: 0.78rem;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            line-height: 1.1;
            white-space: nowrap;
            cursor: pointer;
            transition: transform 0.18s ease, background 0.18s ease, border-color 0.18s ease, color 0.18s ease, box-shadow 0.18s ease;
        }

        .cf-btn:focus-visible {
            outline: none;
            box-shadow: 0 0 0 3px rgba(160, 110, 40, 0.22);
        }

        .cf-btn svg {
            width: 15px;
            height: 15px;
            flex: none;
        }

        .cf-btn-outline {
            color: var(--brand);
            background: color-mix(in srgb, var(--surface) 86%, #fff 14%);
            border-color: color-mix(in srgb, var(--brand) 24%, var(--border));
        }

        .cf-btn-outline:hover {
            color: var(--brand);
            border-color: color-mix(in srgb, var(--gold) 52%, var(--brand));
            background: color-mix(in srgb, var(--gold) 12%, var(--surface));
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(9, 27, 35, 0.08);
        }

        .cf-btn-danger {
            color: #9b2d3e;
            background: transparent;
            border-color: transparent;
            text-transform: none;
            letter-spacing: 0.01em;
            font-size: 0.82rem;
            padding: 0.4rem 0.65rem;
        }

        .cf-btn-danger:hover {
            color: #7f2433;
            background: rgba(155, 45, 62, 0.09);
        }

        .cf-btn-danger.cf-btn-sm {
            font-size: 0.75rem;
            padding: 0.2rem 0.45rem;
        }

        /* Transposed typology table — row (attribute) headers */
        .table-premium tbody th {
            background: color-mix(in srgb, var(--brand) 4%, var(--surface));
            color: var(--muted);
            font-size: 0.74rem;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            white-space: nowrap;
            min-width: 9rem;
            vertical-align: middle;
        }
    </style>

    <div class="cf-shell container py-lg-4">
        <div class="row justify-content-center">
            <div class="col-xl-10">
                <div class="d-flex flex-column gap-4">

                    {{-- Success toast — company palette, auto-dismisses within 4s --}}
                    @if ($successMessage || session('success'))
                        <div
                            wire:key="cf-success-toast-{{ md5($successMessage ?? session('success')) }}"
                            x-data="{ show: true }"
                            x-init="setTimeout(() => show = false, 4000)"
                            x-show="show"
                            x-transition.duration.400ms
                            class="cf-toast"
                            role="status"
                            aria-live="polite"
                        >
                            <span class="cf-toast-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M20 6L9 17l-5-5" />
                                </svg>
                            </span>
                            <div class="cf-toast-body">
                                <span class="cf-toast-title">Tudo certo</span>
                                <span class="cf-toast-message">{{ $successMessage ?? session('success') }}</span>
                            </div>
                            <button type="button" class="cf-toast-close" x-on:click="show = false" aria-label="Fechar aviso">&times;</button>
                            <span class="cf-toast-bar" aria-hidden="true"></span>
                        </div>
                    @endif

                    @if ($errors->any() && ! $showReadonlySummary)
                        <div class="rounded-2xl border-0 shadow-sm px-4 py-3 mb-0 bg-red-50 text-red-800 border border-red-200">
                            <strong class="d-block mb-2">Revise os campos destacados antes de salvar.</strong>
                            <ul class="mb-0 ps-3">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    {{-- Hero Card --}}
                    <div class="cf-hero">
                        <div class="cf-hero-inner">
                            <div class="cf-hero-top">
                                <div class="cf-hero-head">
                                    <div class="cf-kicker">Portal da Proposta</div>
                                    <h1 class="cf-hero-title">Formulário de Empreendimento</h1>
                                    <div class="cf-divider"></div>
                                    <div class="cf-hero-company">{{ $proposal->company->name }} • {{ $proposal->company->cnpj }}</div>
                                    <p class="cf-hero-lead">
                                        Acompanhe os dados enviados e, quando necessário, complemente as informações do empreendimento no mesmo padrão visual da plataforma.
                                    </p>
                                </div>

                                <div class="cf-status">
                                    <div class="cf-status-label">Status Atual</div>
                                    <span class="cf-status-pill">
                                        <span class="cf-dot"></span>
                                        {{ \App\Enums\ProposalStatus::labelFor($proposal->status) }}
                                    </span>
                                </div>
                            </div>

                            <div class="cf-meta-grid">
                                @foreach ([
                                    ['Empreendimentos', $projectCount, 'Itens vinculados à proposta atual.'],
                                    ['Arquivos Enviados', $fileCount, 'Documentos compartilhados no fluxo.'],
                                    ['Última Atualização', $proposal->completed_at?->format('d/m/Y H:i') ?? 'Em preenchimento', 'Registro mais recente disponível nesta proposta.'],
                                ] as [$metaLabel, $metaValue, $metaCaption])
                                    <div class="cf-meta-card">
                                        <div class="cf-meta-label">{{ $metaLabel }}</div>
                                        <div class="cf-meta-value">{{ $metaValue }}</div>
                                        <p class="cf-meta-caption">{{ $metaCaption }}</p>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    {{-- Summary (readonly) or Form --}}
                    @if ($showReadonlySummary)
                        @include('site.proposal.partials.summary')
                    @else
                        {{-- Intro Card --}}
                        <div class="premium-card">
                            <div class="cf-intro-grid">
                                <div class="cf-intro-head">
                                    <div class="cf-kicker">Próxima Etapa</div>
                                    <h2 class="cf-intro-title">
                                        {{ $proposal->status === \App\Enums\ProposalStatus::AwaitingInformation->value
                                            ? 'Atualize as informações solicitadas'
                                            : 'Complementar informações do empreendimento' }}
                                    </h2>
                                    <p class="cf-intro-lead">
                                        {{ $proposal->status === \App\Enums\ProposalStatus::AwaitingInformation->value
                                            ? 'O time comercial solicitou novos dados. Revise os campos abaixo, atualize o que for necessário e salve novamente a proposta.'
                                            : 'Preencha os dados abaixo com atenção. Essa etapa organiza o empreendimento, unidades, cronograma, fluxo financeiro e documentos complementares.' }}
                                    </p>
                                </div>

                                <aside class="cf-callout">
                                    <span class="cf-callout-icon" aria-hidden="true">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2" />
                                            <rect x="9" y="3" width="6" height="4" rx="1" />
                                            <path d="m9 14 2 2 4-4" />
                                        </svg>
                                    </span>
                                    <div>
                                        <strong class="cf-callout-title">Antes de enviar</strong>
                                        <p class="cf-callout-text">Revise os dados gerais da operação, preencha cada empreendimento com identificação clara e anexe os documentos que apoiam a análise.</p>
                                    </div>
                                </aside>
                            </div>
                        </div>

                        {{-- Form Card --}}
                        <div class="cf-card">
                            <div class="p-4 p-lg-5">
                                <form wire:submit="save" class="row g-4">

                                    {{-- Section: Dados Gerais --}}
                                    <div class="col-12 cf-section-head">
                                        <div class="cf-kicker">Dados Gerais</div>
                                        <h2 class="cf-section-title">Informações da operação</h2>
                                        <div class="cf-divider"></div>
                                        <p class="cf-section-subtitle">Dados principais para identificação da operação, cronograma e endereço do empreendimento.</p>
                                        <span class="cf-required-note">Campos obrigatórios</span>
                                    </div>

                                    <div class="col-12 cf-group-grid d-flex flex-column gap-4">
                                        {{-- Grupo: Identificação da operação --}}
                                        <div class="cf-project-card">
                                            <div class="cf-subsection-label">
                                                <span class="cf-dot"></span>
                                                Identificação da operação
                                            </div>
                                            <div class="row g-3">
                                                <div class="col-md-8">
                                                    <flux:field>
                                                        <flux:label>Nome do Empreendimento *</flux:label>
                                                        <flux:input wire:model.blur="form.developmentName" />
                                                        <flux:error name="form.developmentName" />
                                                    </flux:field>
                                                </div>

                                                <div class="col-md-4">
                                                    <flux:field>
                                                        <flux:label>Site</flux:label>
                                                        <flux:input type="url" wire:model.blur="form.websiteUrl" />
                                                        <flux:error name="form.websiteUrl" />
                                                    </flux:field>
                                                </div>
                                            </div>
                                        </div>

                                        {{-- Grupo: Valores e métricas --}}
                                        <div class="cf-project-card">
                                            <div class="cf-subsection-label">
                                                <span class="cf-dot"></span>
                                                Valores e métricas
                                            </div>
                                            <div class="row g-3">
                                                <div class="col-md-4">
                                                    <flux:field>
                                                        <flux:label>Valor Solicitado *</flux:label>
                                                        <div class="custom-input-wrap">
                                                            <span class="custom-input-prefix">R$</span>
                                                            <flux:input class="rounded-l-none!" style="border:none;box-shadow:none;border-radius:0;flex:1;background:transparent" wire:model.blur="form.requestedAmount" mask:dynamic="$money($input, ',', '.', 2)" inputmode="decimal" />
                                                        </div>
                                                        <flux:error name="form.requestedAmount" />
                                                    </flux:field>
                                                </div>

                                                <div class="col-md-4">
                                                    <flux:field>
                                                        <flux:label>Valor atual de mercado do terreno</flux:label>
                                                        <div class="custom-input-wrap">
                                                            <span class="custom-input-prefix">R$</span>
                                                            <flux:input class="rounded-l-none!" style="border:none;box-shadow:none;border-radius:0;flex:1;background:transparent" wire:model.blur="form.landMarketValue" mask:dynamic="$money($input, ',', '.', 2)" inputmode="decimal" />
                                                        </div>
                                                        <flux:error name="form.landMarketValue" />
                                                    </flux:field>
                                                </div>

                                                <div class="col-md-4">
                                                    <flux:field>
                                                        <flux:label>Área do Terreno (m²) *</flux:label>
                                                        <flux:input wire:model.blur="form.landArea" mask:dynamic="$money($input, ',', '.', 2)" inputmode="decimal" />
                                                        <flux:error name="form.landArea" />
                                                    </flux:field>
                                                </div>
                                            </div>
                                        </div>

                                        {{-- Grupo: Cronograma --}}
                                        <div class="cf-project-card">
                                            <div class="cf-subsection-label">
                                                <span class="cf-dot"></span>
                                                Cronograma
                                            </div>
                                            <div class="row g-3">
                                                <div class="col-12 col-sm-6 col-md-4 col-lg">
                                                    <flux:field>
                                                        <flux:label>Lançamento *</flux:label>
                                                        <flux:input type="month" wire:model.blur="form.launchDate" />
                                                        <flux:error name="form.launchDate" />
                                                    </flux:field>
                                                </div>

                                                <div class="col-12 col-sm-6 col-md-4 col-lg">
                                                    <flux:field>
                                                        <flux:label>Lançamento das Vendas *</flux:label>
                                                        <flux:input type="month" wire:model.blur="form.salesLaunchDate" />
                                                        <flux:error name="form.salesLaunchDate" />
                                                    </flux:field>
                                                </div>

                                                <div class="col-12 col-sm-6 col-md-4 col-lg">
                                                    <flux:field>
                                                        <flux:label>Início das Obras *</flux:label>
                                                        <flux:input type="month" wire:model.live="form.constructionStartDate" />
                                                        <flux:error name="form.constructionStartDate" />
                                                    </flux:field>
                                                </div>

                                                <div class="col-12 col-sm-6 col-md-4 col-lg">
                                                    <flux:field>
                                                        <flux:label>Previsão de Entrega *</flux:label>
                                                        <flux:input type="month" wire:model.live="form.deliveryForecastDate" />
                                                        <flux:error name="form.deliveryForecastDate" />
                                                    </flux:field>
                                                </div>

                                                <div class="col-12 col-sm-6 col-md-4 col-lg">
                                                    <flux:field>
                                                        <flux:label>Prazo Remanescente (meses)</flux:label>
                                                        <flux:input type="number" wire:model="form.remainingMonths" readonly />
                                                    </flux:field>
                                                </div>
                                            </div>
                                        </div>

                                        {{-- Grupo: Endereço do empreendimento --}}
                                        <div class="cf-project-card">
                                            <div class="cf-subsection-label">
                                                <span class="cf-dot"></span>
                                                Endereço do empreendimento
                                            </div>
                                            <div class="row g-3">
                                                <div class="col-12 col-sm-4 col-md-3">
                                                    <flux:field>
                                                        <flux:label>CEP *</flux:label>
                                                        <flux:input wire:model.live.debounce.600ms="form.zipCode" mask="99999-999" inputmode="numeric" />
                                                        <flux:error name="form.zipCode" />
                                                        <flux:description wire:loading wire:target="form.zipCode">Buscando endereço pelo CEP...</flux:description>
                                                    </flux:field>
                                                </div>

                                                <div class="col-12 col-sm-8 col-md-6">
                                                    <flux:field>
                                                        <flux:label>Rua *</flux:label>
                                                        <flux:input wire:model.blur="form.street" />
                                                        <flux:error name="form.street" />
                                                    </flux:field>
                                                </div>

                                                <div class="col-12 col-sm-6 col-md-3">
                                                    <flux:field>
                                                        <flux:label>Complemento</flux:label>
                                                        <flux:input wire:model.blur="form.addressComplement" />
                                                        <flux:error name="form.addressComplement" />
                                                    </flux:field>
                                                </div>

                                                <div class="col-12 col-sm-6 col-md-3">
                                                    <flux:field>
                                                        <flux:label>Número *</flux:label>
                                                        <flux:input wire:model.blur="form.addressNumber" />
                                                        <flux:error name="form.addressNumber" />
                                                    </flux:field>
                                                </div>

                                                <div class="col-12 col-sm-6 col-md-3">
                                                    <flux:field>
                                                        <flux:label>Bairro *</flux:label>
                                                        <flux:input wire:model.blur="form.neighborhood" />
                                                        <flux:error name="form.neighborhood" />
                                                    </flux:field>
                                                </div>

                                                <div class="col-12 col-sm-7 col-md-4">
                                                    <flux:field>
                                                        <flux:label>Cidade *</flux:label>
                                                        <flux:input wire:model.blur="form.city" />
                                                        <flux:error name="form.city" />
                                                    </flux:field>
                                                </div>

                                                <div class="col-12 col-sm-5 col-md-2">
                                                    <flux:field>
                                                        <flux:label>Estado *</flux:label>
                                                        <flux:input maxlength="2" wire:model.blur="form.state" />
                                                        <flux:error name="form.state" />
                                                    </flux:field>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-12"><hr class="my-2 border-[var(--border)] opacity-100"></div>

                                    {{-- Section: Empreendimentos --}}
                                    <div class="col-12 d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3">
                                        <div>
                                            <div class="cf-kicker">Empreendimentos</div>
                                            <h2 class="cf-section-title">Cadastro das torres e blocos</h2>
                                            <p class="cf-section-subtitle">Se houver mais de um empreendimento na mesma operação, adicione quantos blocos forem necessários.</p>
                                        </div>
                                        <button type="button" class="cf-btn cf-btn-outline" wire:click="addProject">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14" /></svg>
                                            Adicionar Empreendimento
                                        </button>
                                    </div>

                                    <div class="col-12 d-flex flex-column gap-4">
                                        @foreach ($projects as $index => $project)
                                            <div wire:key="proposal-project-{{ $index }}">
                                                <div class="cf-project-card">
                                                    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-4">
                                                        <div>
                                                            <div class="cf-kicker">
                                                                Empreendimento {{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}
                                                            </div>
                                                            <h3 class="cf-subcard-title">Resumo operacional e financeiro</h3>
                                                        </div>

                                                        @if ($projectCount > 1)
                                                            <button type="button" class="cf-btn cf-btn-danger" wire:click="removeProject({{ $index }})">
                                                                Remover
                                                            </button>
                                                        @endif
                                                    </div>

                                                    <input type="hidden" wire:model="form.projects.{{ $index }}.id">

                                                    <div class="mb-4">
                                                        <flux:field>
                                                            <flux:label>Identificação do Empreendimento *</flux:label>
                                                            <flux:input wire:model.blur="form.projects.{{ $index }}.name" />
                                                            <flux:error name="form.projects.{{ $index }}.name" />
                                                        </flux:field>
                                                    </div>

                                                    <div class="d-flex flex-column gap-4">
                                                        {{-- Resumo das Unidades --}}
                                                        <div>
                                                            <div class="cf-subsection-label">
                                                                <span class="cf-dot"></span>
                                                                Resumo das Unidades
                                                            </div>
                                                            <div class="cf-units-split d-flex flex-column gap-3">
                                                                <div class="cf-table-wrap">
                                                                    <div class="table-responsive">
                                                                        <table class="table-premium">
                                                                            <thead>
                                                                                <tr>
                                                                                    @foreach (['Permutadas', 'Quitadas', 'Não Quitadas'] as $col)
                                                                                        <th class="bg-[color-mix(in_oklab,var(--brand)_8%,var(--surface)_92%)] text-[var(--brand)] text-[0.76rem] font-bold tracking-[0.08em] uppercase">{{ $col }}</th>
                                                                                    @endforeach
                                                                                </tr>
                                                                            </thead>
                                                                            <tbody>
                                                                                <tr>
                                                                                    <td><flux:input type="number" min="0" wire:model.live.debounce.300ms="form.projects.{{ $index }}.exchangedUnits" /></td>
                                                                                    <td><flux:input type="number" min="0" wire:model.live.debounce.300ms="form.projects.{{ $index }}.paidUnits" /></td>
                                                                                    <td><flux:input type="number" min="0" wire:model.live.debounce.300ms="form.projects.{{ $index }}.unpaidUnits" /></td>
                                                                                </tr>
                                                                            </tbody>
                                                                        </table>
                                                                    </div>
                                                                </div>
                                                                <div class="cf-table-wrap">
                                                                    <div class="table-responsive">
                                                                        <table class="table-premium">
                                                                            <thead>
                                                                                <tr>
                                                                                    @foreach (['Estoque', 'Total', '% Vendidas'] as $col)
                                                                                        <th class="bg-[color-mix(in_oklab,var(--brand)_8%,var(--surface)_92%)] text-[var(--brand)] text-[0.76rem] font-bold tracking-[0.08em] uppercase">{{ $col }}</th>
                                                                                    @endforeach
                                                                                </tr>
                                                                            </thead>
                                                                            <tbody>
                                                                                <tr>
                                                                                    <td><flux:input type="number" min="0" wire:model.live.debounce.300ms="form.projects.{{ $index }}.stockUnits" /></td>
                                                                                    <td><flux:input type="number" wire:model="form.projects.{{ $index }}.totalUnits" readonly /></td>
                                                                                    <td><flux:input wire:model="form.projects.{{ $index }}.salesPercentage" readonly /></td>
                                                                                </tr>
                                                                            </tbody>
                                                                        </table>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>

                                                        {{-- Resumo Financeiro --}}
                                                        <div>
                                                            <div class="cf-subsection-label">
                                                                <span class="cf-dot"></span>
                                                                Resumo Financeiro
                                                            </div>
                                                            <div class="cf-table-wrap">
                                                                <div class="table-responsive">
                                                                    <table class="table-premium">
                                                                        <thead>
                                                                            <tr>
                                                                                @foreach (['Custo Incorrido', 'Custo a Incorrer', 'Custo Total', 'Estágio da Obra (%)'] as $col)
                                                                                    <th class="bg-[color-mix(in_oklab,var(--brand)_8%,var(--surface)_92%)] text-[var(--brand)] text-[0.76rem] font-bold tracking-[0.08em] uppercase">{{ $col }}</th>
                                                                                @endforeach
                                                                            </tr>
                                                                        </thead>
                                                                        <tbody>
                                                                            <tr>
                                                                                <td>
                                                                                    <div class="custom-input-wrap">
                                                                <span class="custom-input-prefix">R$</span>
                                                                <flux:input class="rounded-l-none!" style="border:none;box-shadow:none;border-radius:0;flex:1;background:transparent" inputmode="decimal" wire:model.live.debounce.500ms="form.projects.{{ $index }}.incurredCost" mask:dynamic="$money($input, ',', '.', 2)" />
                                                                                    </div>
                                                                                </td>
                                                                                <td>
                                                                                    <div class="custom-input-wrap">
                                                                <span class="custom-input-prefix">R$</span>
                                                                <flux:input class="rounded-l-none!" style="border:none;box-shadow:none;border-radius:0;flex:1;background:transparent" inputmode="decimal" wire:model.live.debounce.500ms="form.projects.{{ $index }}.costToIncur" mask:dynamic="$money($input, ',', '.', 2)" />
                                                                                    </div>
                                                                                </td>
                                                                                <td>
                                                                                    <div class="custom-input-wrap">
                                                                <span class="custom-input-prefix">R$</span>
                                                                <flux:input class="rounded-l-none!" style="border:none;box-shadow:none;border-radius:0;flex:1;background:transparent" wire:model="form.projects.{{ $index }}.totalCost" readonly />
                                                                                    </div>
                                                                                </td>
                                                                                <td><flux:input wire:model="form.projects.{{ $index }}.workStagePercentage" readonly /></td>
                                                                            </tr>
                                                                        </tbody>
                                                                    </table>
                                                                </div>
                                                            </div>
                                                        </div>

                                                        {{-- Valores de Venda --}}
                                                        <div>
                                                            <div class="cf-subsection-label">
                                                                <span class="cf-dot"></span>
                                                                Valores de Venda
                                                            </div>
                                                            <div class="cf-table-wrap">
                                                                <div class="table-responsive">
                                                                    <table class="table-premium">
                                                                        <thead>
                                                                            <tr>
                                                                                @foreach (['Quitadas', 'Não Quitadas', 'Estoque', 'VGV Total'] as $col)
                                                                                    <th class="bg-[color-mix(in_oklab,var(--brand)_8%,var(--surface)_92%)] text-[var(--brand)] text-[0.76rem] font-bold tracking-[0.08em] uppercase">{{ $col }}</th>
                                                                                @endforeach
                                                                            </tr>
                                                                        </thead>
                                                                        <tbody>
                                                                            <tr>
                                                                                @foreach (['paidSalesValue', 'unpaidSalesValue', 'stockSalesValue'] as $field)
                                                                                    <td>
                                                                                        <div class="custom-input-wrap">
                                                                <span class="custom-input-prefix">R$</span>
                                                                <flux:input class="rounded-l-none!" style="border:none;box-shadow:none;border-radius:0;flex:1;background:transparent" inputmode="decimal" wire:model.live.debounce.500ms="form.projects.{{ $index }}.{{ $field }}" mask:dynamic="$money($input, ',', '.', 2)" />
                                                                                        </div>
                                                                                    </td>
                                                                                @endforeach
                                                                                <td>
                                                                                    <div class="custom-input-wrap">
                                                                <span class="custom-input-prefix">R$</span>
                                                                <flux:input class="rounded-l-none!" style="border:none;box-shadow:none;border-radius:0;flex:1;background:transparent" wire:model="form.projects.{{ $index }}.grossSalesValue" readonly />
                                                                                    </div>
                                                                                </td>
                                                                            </tr>
                                                                        </tbody>
                                                                    </table>
                                                                </div>
                                                            </div>
                                                        </div>

                                                        {{-- Fluxo de Pagamento --}}
                                                        <div>
                                                            <div class="cf-subsection-label">
                                                                <span class="cf-dot"></span>
                                                                Fluxo de Pagamento
                                                            </div>
                                                            <div class="cf-table-wrap">
                                                                <div class="table-responsive">
                                                                    <table class="table-premium">
                                                                        <thead>
                                                                            <tr>
                                                                                @foreach (['Já Recebido', 'Até Chaves', 'Chaves + Pós Chaves'] as $col)
                                                                                    <th class="bg-[color-mix(in_oklab,var(--brand)_8%,var(--surface)_92%)] text-[var(--brand)] text-[0.76rem] font-bold tracking-[0.08em] uppercase">{{ $col }}</th>
                                                                                @endforeach
                                                                            </tr>
                                                                        </thead>
                                                                        <tbody>
                                                                            <tr>
                                                                                @foreach (['receivedValue', 'valueUntilKeys', 'valueAfterKeys'] as $field)
                                                                                    <td>
                                                                                        <div class="custom-input-wrap">
                                                                <span class="custom-input-prefix">R$</span>
                                                                <flux:input class="rounded-l-none!" style="border:none;box-shadow:none;border-radius:0;flex:1;background:transparent" inputmode="decimal" wire:model.blur="form.projects.{{ $index }}.{{ $field }}" mask:dynamic="$money($input, ',', '.', 2)" />
                                                                                        </div>
                                                                                    </td>
                                                                                @endforeach
                                                                            </tr>
                                                                        </tbody>
                                                                    </table>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>

                                    <div class="col-12"><hr class="my-2 border-[var(--border)] opacity-100"></div>

                                    {{-- Section: Características --}}
                                    <div class="col-12 d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3">
                                        <div>
                                            <div class="cf-kicker">Características</div>
                                            <h2 class="cf-section-title">Características do Empreendimento</h2>
                                            <p class="cf-section-subtitle">Configuração física do produto e dados das tipologias da operação. Adicione um ou mais tipos conforme necessário.</p>
                                        </div>
                                        <button type="button" class="cf-btn cf-btn-outline" wire:click="addUnitType">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14" /></svg>
                                            Adicionar Tipo
                                        </button>
                                    </div>

                                    <div class="col-12">
                                        <div class="cf-project-card">
                                            <div class="cf-subsection-label">
                                                <span class="cf-dot"></span>
                                                Configuração dos blocos
                                            </div>
                                            <div class="row g-3 mb-4 cf-blocks-grid">
                                                <div class="col-md-2">
                                                    <flux:field>
                                                        <flux:label>Blocos *</flux:label>
                                                        <flux:input type="number" min="1" wire:model.live.debounce.300ms="form.blockCount" />
                                                        <flux:error name="form.blockCount" />
                                                    </flux:field>
                                                </div>

                                                <div class="col-md-2">
                                                    <flux:field>
                                                        <flux:label>Pavimentos *</flux:label>
                                                        <flux:input type="number" min="1" wire:model.live.debounce.300ms="form.floorCount" />
                                                        <flux:error name="form.floorCount" />
                                                    </flux:field>
                                                </div>

                                                <div class="col-md-3">
                                                    <flux:field>
                                                        <flux:label>Andares Tipo *</flux:label>
                                                        <flux:input type="number" min="1" wire:model.live.debounce.300ms="form.typicalFloorCount" />
                                                        <flux:error name="form.typicalFloorCount" />
                                                    </flux:field>
                                                </div>

                                                <div class="col-md-3">
                                                    <flux:field>
                                                        <flux:label>Unidades/Andar *</flux:label>
                                                        <flux:input type="number" min="1" wire:model.live.debounce.300ms="form.unitsPerFloor" />
                                                        <flux:error name="form.unitsPerFloor" />
                                                    </flux:field>
                                                </div>

                                                <div class="col-md-2">
                                                    <flux:field>
                                                        <flux:label>Total</flux:label>
                                                        <flux:input type="number" wire:model="form.totalUnits" readonly />
                                                    </flux:field>
                                                </div>
                                            </div>

                                            <div class="cf-subsection-label">
                                                <span class="cf-dot"></span>
                                                Tipologias das unidades
                                            </div>
                                            <div class="cf-table-wrap">
                                                <div class="table-responsive">
                                                    <table class="table-premium cf-typology-table">
                                                        <thead>
                                                            <tr>
                                                                <th class="bg-[color-mix(in_oklab,var(--brand)_8%,var(--surface)_92%)] text-[var(--brand)] text-[0.76rem] font-bold tracking-[0.08em] uppercase">&nbsp;</th>
                                                                @foreach ($unitTypes as $typeIndex => $typeRow)
                                                                    <th class="bg-[color-mix(in_oklab,var(--brand)_8%,var(--surface)_92%)] text-[var(--brand)] text-[0.76rem] font-bold tracking-[0.08em] uppercase" wire:key="type-header-{{ $typeIndex }}">
                                                                        <div class="d-flex justify-content-between align-items-center gap-2">
                                                                            <span>Tipo {{ $typeIndex + 1 }}</span>
                                                                            @if (count($unitTypes) > 1)
                                                                                <button type="button" class="cf-btn cf-btn-danger cf-btn-sm" wire:click="removeUnitType({{ $typeIndex }})">
                                                                                    Remover
                                                                                </button>
                                                                            @endif
                                                                        </div>
                                                                    </th>
                                                                @endforeach
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <tr>
                                                                <th class="text-[var(--muted)] text-[0.76rem] font-bold uppercase tracking-[0.08em]">Total *</th>
                                                                @foreach ($unitTypes as $typeIndex => $typeRow)
                                                                    <td wire:key="type-total-{{ $typeIndex }}">
                                                                        <flux:input type="number" min="1" wire:model.live.debounce.300ms="form.unitTypes.{{ $typeIndex }}.totalUnits" />
                                                                    </td>
                                                                @endforeach
                                                            </tr>
                                                            <tr>
                                                                <th class="text-[var(--muted)] text-[0.76rem] font-bold uppercase tracking-[0.08em]">Dormitórios *</th>
                                                                @foreach ($unitTypes as $typeIndex => $typeRow)
                                                                    <td wire:key="type-bedrooms-{{ $typeIndex }}">
                                                                        <flux:input wire:model.blur="form.unitTypes.{{ $typeIndex }}.bedrooms" />
                                                                    </td>
                                                                @endforeach
                                                            </tr>
                                                            <tr>
                                                                <th class="text-[var(--muted)] text-[0.76rem] font-bold uppercase tracking-[0.08em]">Vagas *</th>
                                                                @foreach ($unitTypes as $typeIndex => $typeRow)
                                                                    <td wire:key="type-parking-{{ $typeIndex }}">
                                                                        <flux:input wire:model.blur="form.unitTypes.{{ $typeIndex }}.parkingSpaces" />
                                                                    </td>
                                                                @endforeach
                                                            </tr>
                                                            <tr>
                                                                <th class="text-[var(--muted)] text-[0.76rem] font-bold uppercase tracking-[0.08em]">Área Útil (m²) *</th>
                                                                @foreach ($unitTypes as $typeIndex => $typeRow)
                                                                    <td wire:key="type-area-{{ $typeIndex }}">
                                                                        <flux:input type="number" step="0.01" wire:model.live.debounce.300ms="form.unitTypes.{{ $typeIndex }}.usableArea" />
                                                                    </td>
                                                                @endforeach
                                                            </tr>
                                                            <tr>
                                                                <th class="text-[var(--muted)] text-[0.76rem] font-bold uppercase tracking-[0.08em]">Preço Médio *</th>
                                                                @foreach ($unitTypes as $typeIndex => $typeRow)
                                                                    <td wire:key="type-average-price-{{ $typeIndex }}">
                                                                        <div class="custom-input-wrap">
                                                                <span class="custom-input-prefix">R$</span>
                                                                <flux:input class="rounded-l-none!" style="border:none;box-shadow:none;border-radius:0;flex:1;background:transparent" inputmode="decimal" wire:model.live.debounce.500ms="form.unitTypes.{{ $typeIndex }}.averagePrice" mask:dynamic="$money($input, ',', '.', 2)" />
                                                                        </div>
                                                                    </td>
                                                                @endforeach
                                                            </tr>
                                                            <tr>
                                                                <th class="text-[var(--muted)] text-[0.76rem] font-bold uppercase tracking-[0.08em]">Preço / m²</th>
                                                                @foreach ($unitTypes as $typeIndex => $typeRow)
                                                                    <td wire:key="type-price-per-m2-{{ $typeIndex }}">
                                                                        <div class="custom-input-wrap">
                                                                <span class="custom-input-prefix">R$</span>
                                                                <flux:input class="rounded-l-none!" style="border:none;box-shadow:none;border-radius:0;flex:1;background:transparent" wire:model="form.unitTypes.{{ $typeIndex }}.pricePerSquareMeter" readonly />
                                                                        </div>
                                                                    </td>
                                                                @endforeach
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    {{-- File Upload --}}
                                    <div class="col-12 mt-4">
                                        <div class="premium-card">
                                            <div class="section-header border-0 mb-4 pb-0">
                                                <div class="section-kicker">Documentação</div>
                                                <h2 class="form-section-title">Arquivos do Empreendimento</h2>
                                                <p class="form-section-subtitle">Envie imagens, plantas e documentos complementares.</p>
                                            </div>

                                            <div class="file-upload-box">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="mx-auto h-12 w-12 text-zinc-400 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                                                </svg>
                                                <p class="text-zinc-600 font-semibold mb-1">Clique para selecionar os arquivos</p>
                                                <p class="text-zinc-500 text-sm">ou arraste para esta área</p>
                                                <input
                                                    type="file"
                                                    wire:model="form.uploads"
                                                    multiple
                                                >
                                            </div>
                                            <flux:error name="form.uploads.*" />
                                            <p class="text-[var(--muted)] text-sm mt-1" wire:loading wire:target="form.uploads">Carregando arquivos para envio...</p>

                                        @if ($uploads !== [])
                                            <p class="text-[var(--muted)] mt-2 mb-3">Arquivos selecionados para o próximo envio.</p>
                                            <div class="d-flex flex-column gap-2">
                                                @foreach ($uploads as $upload)
                                                    <div class="flex items-center justify-between gap-4 p-[1.15rem_1.2rem] border border-[var(--border)] rounded-[10px] bg-[color-mix(in_oklab,var(--surface)_95%,var(--brand)_5%)]">
                                                        <div>
                                                            <div class="text-[var(--brand)] font-bold">{{ $upload->getClientOriginalName() }}</div>
                                                            <div class="text-[var(--muted)] small">Pronto para envio</div>
                                                        </div>
                                                        <span class="text-[var(--brand)] text-[0.88rem] font-bold whitespace-nowrap">Novo arquivo</span>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif

                                        @if ($attachmentSummaries !== [])
                                            <p class="text-[var(--muted)] mt-3 mb-3">Arquivos já enviados permanecem disponíveis abaixo. Novos uploads serão adicionados ao histórico da proposta.</p>
                                            <div class="d-flex flex-column gap-2">
                                                @foreach ($attachmentSummaries as $attachment)
                                                    <a
                                                        class="flex items-center justify-between gap-4 p-[1.15rem_1.2rem] border border-[var(--border)] rounded-[10px] bg-[color-mix(in_oklab,var(--surface)_95%,var(--brand)_5%)] no-underline text-[var(--text)]"
                                                        href="{{ $attachment['url'] }}"
                                                    >
                                                        <div>
                                                            <div class="text-[var(--brand)] font-bold">{{ $attachment['original_name'] }}</div>
                                                            <div class="text-[var(--muted)] small">{{ $attachment['meta'] }}</div>
                                                        </div>
                                                        <span class="text-[var(--brand)] text-[0.88rem] font-bold whitespace-nowrap">Baixar arquivo</span>
                                                    </a>
                                                @endforeach
                                            </div>
                                        @endif
                                        </div>
                                    </div>

                                    {{-- Submit --}}
                                    <div class="col-12 d-flex flex-column flex-sm-row gap-4 justify-content-between align-items-sm-center premium-card mt-4" style="margin-bottom: 0;">
                                        <div>
                                            <h3 class="form-section-title" style="font-size: 1.25rem;">Finalizar e Enviar</h3>
                                            <p class="form-section-subtitle mb-0">Após salvar, os dados seguirão para análise comercial interna.</p>
                                        </div>
                                        <button
                                            type="submit"
                                            class="btn-primary-premium"
                                            wire:loading.attr="disabled"
                                            wire:target="save,uploads"
                                        >
                                            <span wire:loading.remove wire:target="save">Salvar Empreendimento(s)</span>
                                            <span wire:loading wire:target="save">Salvando...</span>
                                        </button>
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

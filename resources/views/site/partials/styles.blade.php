    <style>
        :root {
            --brand: #091b23;
            --brand-strong: #06151c;
            --brand-soft: #22424c;
            --gold: #a06e28;
            --gold-soft: #e8dcc7;
            --bg: #ece9e8;
            --surface: #e6e4e4;
            --surface-alt: #f2efee;
            --text: #091b23;
            --muted: #4d5b60;
            --border: #c5cacb;
            --shadow-soft: 0 4px 12px rgba(9, 27, 35, 0.04);
            --shadow-hover: 0 8px 24px rgba(9, 27, 35, 0.08);
            --nav-bg: var(--brand);
            --brand-outline: var(--brand);
            --brand-outline-border: color-mix(in srgb, var(--brand) 18%, var(--border));
            --radius-shell: 0px;
            --radius-card: 0px;
            --radius-control: 0px;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            letter-spacing: -0.01em;
        }

        a {
            color: inherit;
            transition: color 0.2s ease, opacity 0.2s ease, transform 0.2s ease;
        }

        a:focus-visible,
        button:focus-visible,
        .btn:focus-visible,
        .nav-link:focus-visible,
        .mega-link:focus-visible,
        .footer-link:focus-visible,
        .navbar-toggler:focus-visible,
        .form-control:focus-visible,
        .form-select:focus-visible {
            outline: none;
            box-shadow: 0 0 0 0.24rem rgba(9, 27, 35, 0.14), 0 0 0 0.42rem rgba(160, 110, 40, 0.18) !important;
        }

        img {
            max-width: 100%;
        }

        .text-muted {
            color: var(--muted) !important;
        }

        .text-brand {
            color: var(--brand) !important;
        }

        .bg-brand-subtle {
            background: color-mix(in srgb, var(--brand) 7%, var(--surface));
        }

        .border-brand-subtle {
            border-color: color-mix(in srgb, var(--brand) 14%, var(--border)) !important;
        }

        .site-main {
            position: relative;
            flex: 1 1 auto;
            overflow: hidden;
        }

        .site-main::before {
            content: "";
            position: absolute;
            inset: 0 0 auto 0;
            height: 180px;
            background: linear-gradient(180deg, rgba(230, 228, 228, 0.16), transparent);
            pointer-events: none;
        }

        .section-copy {
            color: var(--muted);
            line-height: 1.75;
        }

        .section-divider {
            width: 64px;
            height: 3px;
            border-radius: 999px;
            background: linear-gradient(90deg, var(--gold), color-mix(in srgb, var(--gold) 35%, var(--brand) 65%), var(--brand));
        }

        .section-kicker,
        .kicker {
            color: var(--gold);
            font-weight: 700;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            font-size: 0.78rem;
        }

        .surface-card,
        .card {
            background: color-mix(in srgb, var(--surface) 97%, var(--brand) 3%);
            border: 1px solid var(--border);
            border-radius: var(--radius-card);
            color: var(--text);
            box-shadow: var(--shadow-soft);
        }

        .card-opea,
        .surface-card-soft {
            background: color-mix(in srgb, var(--surface-alt) 94%, var(--brand) 6%);
        }

        .surface-card-dark {
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.08), rgba(255, 255, 255, 0.04));
            border: 1px solid rgba(255, 255, 255, 0.12);
            color: #e6e4e4;
            border-radius: var(--radius-card);
        }

        .hero-metric-value {
            font-size: clamp(1.5rem, 1.2rem + 0.9vw, 2rem);
            line-height: 1.08;
            letter-spacing: -0.04em;
            max-width: 100%;
            overflow-wrap: anywhere;
            text-wrap: balance;
        }

        .card-hover,
        .hover-lift,
        .emission-card,
        .ri-item {
            transition: transform 0.25s ease, box-shadow 0.25s ease, border-color 0.25s ease, background 0.25s ease;
        }

        .card-hover:hover,
        .hover-lift:hover,
        .emission-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-hover) !important;
            border-color: color-mix(in srgb, var(--gold) 22%, var(--border));
        }

        .ri-item:hover {
            background: color-mix(in srgb, var(--brand) 3%, var(--surface)) !important;
        }

        .badge {
            border-radius: 999px;
            font-weight: 700;
            letter-spacing: 0.05em;
        }

        .badge-soft {
            background: var(--gold);
            border: 1px solid var(--gold);
            color: var(--brand);
            font-weight: 700;
        }

        .badge-type-cri {
            background: var(--gold);
            border: 1px solid var(--gold);
            color: var(--brand);
        }

        .badge-type-cra {
            background: #0d9488;
            border: 1px solid #0d9488;
            color: #fff;
        }

        .badge-type-cr {
            background: #4f46e5;
            border: 1px solid #4f46e5;
            color: #fff;
        }

        .badge-status-active {
            background: rgba(34, 197, 94, 0.12);
            border: 1px solid rgba(34, 197, 94, 0.28);
            color: #15803d;
        }

        .badge-status-closed {
            background: rgba(100, 116, 139, 0.12);
            border: 1px solid rgba(100, 116, 139, 0.28);
            color: #475569;
        }

        .badge-status-default {
            background: rgba(239, 68, 68, 0.12);
            border: 1px solid rgba(239, 68, 68, 0.28);
            color: #b91c1c;
        }

        .btn {
            border-radius: var(--radius-control);
            padding: 0.72rem 1.5rem;
            font-weight: 600;
            letter-spacing: 0.02em;
            text-transform: uppercase;
            font-size: 0.8rem;
            transition: transform 0.2s ease, box-shadow 0.2s ease, opacity 0.2s ease, border-color 0.2s ease, background 0.2s ease;
        }

        .btn:hover {
            transform: translateY(-1px);
        }

        .btn-brand {
            background: var(--brand);
            border: 1px solid var(--brand);
            color: #e6e4e4;
            box-shadow: 0 4px 12px rgba(9, 27, 35, 0.08);
        }

        .btn-brand:hover,
        .btn-brand:focus {
            color: #fff;
            background: var(--brand-strong);
            border-color: var(--brand-strong);
        }

        .btn-outline-brand {
            border-color: var(--brand-outline-border);
            color: var(--brand-outline);
            background: color-mix(in srgb, var(--surface) 88%, transparent);
        }

        .btn-outline-brand:hover,
        .btn-outline-brand:focus {
            color: var(--text);
            border-color: color-mix(in srgb, var(--gold) 45%, var(--brand));
            background: color-mix(in srgb, var(--gold) 14%, var(--surface));
        }

        .btn-outline-gold {
            border-color: color-mix(in srgb, var(--gold) 45%, transparent);
            color: var(--gold);
            background: transparent;
        }

        .btn-outline-gold:hover,
        .btn-outline-gold:focus {
            color: var(--brand);
            border-color: var(--gold);
            background: var(--gold);
        }

        .btn-light {
            background: color-mix(in srgb, var(--surface) 92%, white 8%);
            color: var(--brand);
            border-color: color-mix(in srgb, var(--border) 85%, white);
            box-shadow: 0 8px 22px rgba(0, 0, 0, 0.05);
        }

        .btn-light:hover,
        .btn-light:focus {
            color: var(--brand);
            border-color: color-mix(in srgb, var(--gold) 30%, var(--border));
            background: color-mix(in srgb, var(--surface) 76%, var(--gold) 24%);
        }

        .form-control,
        .form-select,
        textarea.form-control {
            border-radius: var(--radius-control);
            border: 1px solid color-mix(in srgb, var(--border) 86%, var(--brand) 14%);
            background: color-mix(in srgb, var(--surface) 96%, var(--brand) 4%);
            color: var(--text);
            padding: 0.82rem 0.95rem;
            box-shadow: none !important;
        }

        .form-control::placeholder,
        .form-select,
        textarea.form-control::placeholder {
            color: color-mix(in srgb, var(--muted) 84%, transparent);
        }

        .form-control:focus,
        .form-select:focus,
        textarea.form-control:focus {
            border-color: color-mix(in srgb, var(--gold) 30%, var(--brand) 70%);
            background: color-mix(in srgb, var(--surface) 98%, white 2%);
            box-shadow: 0 0 0 0.22rem rgba(9, 27, 35, 0.08) !important;
            color: var(--text);
        }

        .input-group-text {
            border-radius: var(--radius-control);
            border: 1px solid color-mix(in srgb, var(--border) 86%, var(--brand) 14%);
            background: color-mix(in srgb, var(--surface) 96%, var(--brand) 4%);
            color: var(--muted);
        }

        .form-label {
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--muted);
        }

        .list-group-item {
            color: var(--text);
        }

        .table {
            --bs-table-bg: transparent;
            --bs-table-border-color: var(--border);
            color: var(--text);
        }

        .table > :not(caption) > * > * {
            padding: 1rem 1.1rem;
        }

        .table thead th {
            background: color-mix(in srgb, var(--brand) 7%, var(--surface));
            color: var(--brand);
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            border-bottom-width: 1px;
        }

        .table-shell {
            overflow: hidden;
            border-radius: var(--radius-card);
            border: 1px solid var(--border);
            background: color-mix(in srgb, var(--surface) 96%, var(--brand) 4%);
            box-shadow: var(--shadow-soft);
        }

        .pagination {
            gap: 0.55rem;
            margin-top: 2rem;
            justify-content: center;
        }

        .pagination .page-item .page-link {
            width: 44px;
            height: 44px;
            border-radius: 14px !important;
            border: 1px solid var(--border);
            background: color-mix(in srgb, var(--surface) 95%, var(--brand) 5%);
            color: var(--muted);
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: none;
            font-weight: 600;
        }

        .pagination .page-item:first-child .page-link,
        .pagination .page-item:last-child .page-link {
            width: auto;
            min-width: 44px;
            padding-inline: 1rem;
        }

        .pagination .page-item.active .page-link {
            background: var(--brand);
            border-color: var(--brand);
            color: #fff;
            box-shadow: 0 12px 30px rgba(9, 27, 35, 0.18);
        }

        .pagination .page-item:not(.active) .page-link:hover {
            border-color: color-mix(in srgb, var(--gold) 35%, var(--brand));
            color: var(--brand);
            background: color-mix(in srgb, var(--gold) 12%, var(--surface));
        }

        .pagination .page-item.disabled .page-link {
            background: color-mix(in srgb, var(--surface) 88%, var(--border) 12%);
            color: color-mix(in srgb, var(--muted) 68%, transparent);
        }

        .result-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            border-radius: 999px;
            padding: 0.55rem 0.85rem;
            border: 1px solid color-mix(in srgb, var(--brand) 10%, var(--border));
            background: color-mix(in srgb, var(--surface) 94%, var(--brand) 6%);
            color: var(--brand);
            font-size: 0.76rem;
            font-weight: 700;
            letter-spacing: 0.03em;
        }

        .navbar {
            background: var(--nav-bg);
            border: none;
            border-bottom: 1px solid color-mix(in srgb, var(--gold) 60%, transparent);
            box-shadow: 0 4px 16px rgba(9, 27, 35, 0.1);
            border-radius: 0;
            max-width: 100%;
            margin: 0;
            top: 0;
            padding: 0.5rem 1rem;
        }

        .navbar .container {
            max-width: 1200px;
        }

        .navbar-brand {
            color: var(--surface) !important;
            letter-spacing: -0.03em;
            filter: invert(1) brightness(2); /* Se for logo escura, invertemos pra ficar clara */
        }

        .navbar-brand img {
            max-height: 40px;
            width: auto;
        }

        .navbar-toggler {
            border: 1px solid color-mix(in srgb, var(--surface) 20%, transparent);
            border-radius: var(--radius-control);
            padding: 0.45rem 0.65rem;
        }

        .navbar-toggler:focus {
            box-shadow: 0 0 0 0.2rem rgba(230, 228, 228, 0.1);
        }

        .navbar-toggler-icon {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3E%3Cpath stroke='rgba(230, 228, 228, 0.85)' stroke-width='2' stroke-linecap='round' d='M4 7h22M4 15h22M4 23h22'/%3E%3C/svg%3E");
        }

        .nav-link {
            position: relative;
            color: color-mix(in srgb, var(--surface) 86%, transparent) !important;
            font-weight: 500;
            padding: 0.7rem 0.85rem !important;
            border-radius: 0px;
            transition: color 0.2s ease, background 0.22s ease, transform 0.18s ease;
        }

        .nav-link:hover,
        .nav-link.active {
            color: var(--gold) !important;
            background: transparent;
            transform: translateY(-1px);
        }

        .nav-link.active::after {
            content: "";
            position: absolute;
            left: 0.85rem;
            right: 0.85rem;
            bottom: 0.2rem;
            height: 1px;
            background: var(--gold);
        }

        .dropdown-mega {
            position: static;
        }

        .mega-menu {
            width: min(1080px, 96vw);
            margin-top: 0.8rem;
            border: 1px solid var(--border);
            background: var(--surface);
            border-radius: var(--radius-card);
            padding: 1.5rem;
            box-shadow: 0 20px 50px rgba(9, 27, 35, 0.12);
        }

        .mega-menu .col-lg-4 {
            border-radius: 0;
            background: transparent;
            border: none;
            border-right: 1px solid color-mix(in srgb, var(--brand) 6%, transparent);
            padding: 1rem 1.5rem !important;
        }

        .mega-menu .col-lg-4:last-child {
            border-right: none;
        }

        .mega-title {
            font-weight: 700;
            font-size: 0.95rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.5rem;
            color: var(--brand);
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }

        .mega-title::after {
            content: "";
            height: 2px;
            width: 24px;
            background: var(--gold);
            border-radius: 2px;
            display: inline-block;
        }

        .mega-kicker {
            font-size: 0.86rem;
            color: var(--muted);
            margin-bottom: 1.25rem;
            line-height: 1.5;
        }

        .mega-link {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            color: var(--text);
            text-decoration: none;
            font-weight: 600;
            margin-bottom: 0.25rem;
            transition: all 0.2s ease;
            background: transparent;
        }

        .mega-link::after {
            content: "→";
            opacity: 0;
            transform: translateX(-10px);
            transition: all 0.2s ease;
            color: var(--gold);
            font-size: 1.2rem;
            line-height: 1;
        }

        .mega-link:hover {
            background: color-mix(in srgb, var(--brand) 4%, transparent);
            color: var(--brand);
            transform: translateX(4px);
        }

        .mega-link:hover::after {
            opacity: 1;
            transform: translateX(0);
        }

        .hero {
            position: relative;
            overflow: hidden;
            background: var(--brand) !important;
            color: #e6e4e4;
            border-bottom: 1px solid color-mix(in srgb, var(--gold) 20%, var(--brand));
            padding-top: 6rem;
            padding-bottom: 6rem;
        }

        .hero::before {
            display: none;
        }

        .hero::after {
            display: none;
        }

        .hero > .container,
        .hero > .position-relative {
            position: relative;
            z-index: 1;
        }

        .hero .badge {
            border: 1px solid var(--gold);
            color: var(--gold);
            background: transparent;
            letter-spacing: 0.12em;
            border-radius: 0px;
        }

        .hero .display-3,
        .hero .display-4,
        .hero h1 {
            letter-spacing: -0.04em;
        }

        .hero .lead {
            color: rgba(230, 228, 228, 0.86) !important;
            line-height: 1.7;
        }

        .hero .text-muted {
            color: #bcc9cd !important;
        }

        .emission-detail-tabs {
            display: inline-flex;
            flex-wrap: nowrap;
            gap: 0.35rem;
            width: fit-content;
            max-width: 100%;
            overflow-x: auto;
            padding: 0.35rem;
            padding-bottom: 0.35rem;
            border: 1px solid color-mix(in srgb, var(--brand) 8%, var(--border));
            border-radius: 14px;
            background: color-mix(in srgb, #fff 38%, var(--surface-alt));
            scrollbar-width: none;
        }

        .emission-detail-tabs::-webkit-scrollbar {
            display: none;
        }

        .emission-detail-tabs .nav-item {
            flex: 0 0 auto;
        }

        .emission-detail-tabs .nav-link {
            white-space: nowrap;
            border: 1px solid transparent;
            background: transparent;
            color: color-mix(in srgb, var(--text) 76%, transparent) !important;
            transition: background-color 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease, color 0.2s ease;
        }

        .emission-detail-tabs .nav-link:hover {
            color: var(--brand) !important;
            background: color-mix(in srgb, var(--brand) 4%, #fff);
        }

        .emission-detail-tabs.nav-pills .nav-link.active,
        .emission-detail-tabs.nav-pills .show > .nav-link {
            color: var(--brand) !important;
            background: linear-gradient(180deg, #fff, color-mix(in srgb, var(--surface) 88%, #fff 12%));
            border-color: color-mix(in srgb, var(--brand) 12%, var(--border));
            box-shadow: 0 10px 22px rgba(9, 27, 35, 0.08);
        }

        .emission-detail-tabs .nav-link.active::after,
        .emission-detail-tabs .show > .nav-link::after {
            left: 0.9rem;
            right: 0.9rem;
            bottom: 0.38rem;
            background: linear-gradient(90deg, color-mix(in srgb, var(--gold) 82%, #fff), var(--brand-soft));
        }

        .emission-doc-card {
            border-radius: var(--radius-card);
        }

        .section-dark {
            background:
                radial-gradient(900px 420px at 15% 0%, rgba(160, 110, 40, 0.12), transparent 60%),
                #091b23;
            color: #e6e4e4;
        }

        .section-dark .text-muted {
            color: #b4c0c4 !important;
        }

        .section-dark .card {
            background: rgba(255, 255, 255, 0.04);
            border-color: rgba(255, 255, 255, 0.08);
            color: #e6e4e4;
        }

        .footer {
            position: relative;
            overflow: hidden;
            margin-top: 5rem;
            border-top: 1px solid var(--gold);
            background: var(--brand);
            color: color-mix(in srgb, var(--surface) 70%, transparent);
        }

        .footer::before {
            display: none;
        }

        .footer-heading {
            margin-bottom: 1.25rem;
            font-size: 0.85rem;
            font-weight: 600;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: var(--surface);
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }

        .footer-heading::after {
            content: "";
            height: 1px;
            flex-grow: 1;
            background: color-mix(in srgb, var(--gold) 40%, transparent);
        }

        .footer-link {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: color-mix(in srgb, var(--surface) 70%, transparent);
            text-decoration: none;
            padding: 0.25rem 0;
            transition: all 0.2s ease;
            font-weight: 400;
        }

        .footer-link:hover {
            color: var(--gold);
        }

        .footer-legal-link {
            color: color-mix(in srgb, var(--surface) 70%, transparent);
            text-decoration: none;
            transition: color 0.2s ease;
        }

        .footer-legal-link:hover {
            color: var(--gold);
        }

        .footer-seal {
            display: inline-flex;
            flex-direction: column;
            align-items: center;
            gap: 1rem;
            min-width: 220px;
            padding: 1.25rem 1.5rem;
            border: 1px solid color-mix(in srgb, var(--gold) 25%, transparent);
            border-radius: var(--radius-card);
            background: var(--surface);
            box-shadow: 0 10px 30px rgba(9, 27, 35, 0.05);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .footer-seal:hover {
            transform: translateY(-4px);
            box-shadow: 0 15px 40px rgba(9, 27, 35, 0.08);
        }

        .footer-seal-label {
            font-size: 0.75rem;
            font-weight: 800;
            letter-spacing: 0.15em;
            text-transform: uppercase;
            color: var(--brand);
        }

        .footer .d-flex.gap-3 a {
            transition: all 0.2s ease;
        }

        .footer .d-flex.gap-3 a:hover {
            color: var(--brand) !important;
            transform: translateY(-3px);
        }

        .site-pagination-mobile {
            width: 100%;
        }

        .site-pagination-mobile-list {
            width: 100%;
            max-width: 24rem;
            margin: 0 auto;
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.75rem;
        }

        .site-pagination-mobile-list .page-item {
            margin: 0;
        }

        .site-pagination-mobile-link {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 3rem;
            width: 100%;
            margin-left: 0 !important;
            padding: 0.75rem 1rem;
            border-radius: 16px !important;
            font-weight: 600;
            text-align: center;
        }

        .site-pagination-mobile-list .page-item.disabled .site-pagination-mobile-link {
            opacity: 0.72;
        }

        @media (max-width: 991.98px) {
            .navbar {
                margin: 0.9rem 0.85rem 0;
                border-radius: 16px;
            }

            .navbar .container {
                max-width: 100%;
            }

            .navbar-collapse {
                padding: 1rem 0.2rem 0.2rem;
            }

            .mega-menu {
                width: 100%;
                margin-top: 0.75rem;
            }

            .hero {
                padding-top: 5rem;
                padding-bottom: 4.5rem;
            }
        }

        @media (max-width: 767.98px) {
            .hero {
                padding-top: 4.5rem;
                padding-bottom: 4rem;
            }

            .surface-card,
            .card {
                border-radius: 14px;
            }

            .btn {
                width: auto;
            }

            .result-chip {
                font-size: 0.72rem;
                padding: 0.5rem 0.8rem;
            }

            .pagination .page-item .page-link {
                height: 42px;
                min-width: 42px;
            }

            .emission-doc-card .btn {
                width: 100%;
            }
        }

        @media (max-width: 399.98px) {
            .site-pagination-mobile-list {
                grid-template-columns: 1fr;
            }
        }
    </style>

<?php $__env->startSection('title', 'Contato — BSI Capital'); ?>

<?php $__env->startSection('content'); ?>
<?php ($mapsUrl = 'https://www.google.com/maps/search/?api=1&query=BSI+Capital+Securitizadora+S%2FA'); ?>

<div class="contact-page">
    <style>
        .contact-page .hero {
            overflow: hidden;
            background: #091B23 !important;
            padding: 10rem 0 8rem;
            position: relative;
        }

        .contact-page .contact-hero-panel {
            position: relative;
            overflow: hidden;
            background: linear-gradient(135deg, rgba(9, 27, 35, 0.95), rgba(9, 27, 35, 0.85));
            border: 1px solid rgba(160, 110, 40, 0.3);
            border-radius: 24px;
            box-shadow: 0 30px 60px rgba(0, 0, 0, 0.4);
            backdrop-filter: blur(20px);
            margin-bottom: -150px; /* Overlap effect */
            z-index: 10;
            transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .contact-page .contact-hero-panel:hover {
            transform: translateY(-8px);
            border-color: rgba(160, 110, 40, 0.5);
            box-shadow: 0 40px 80px rgba(0, 0, 0, 0.6);
        }

        .contact-page .contact-hero-panel::before {
            content: "";
            position: absolute;
            inset: auto -15% -35% auto;
            width: 240px;
            height: 240px;
            background: radial-gradient(circle, rgba(160, 110, 40, 0.25), transparent 70%);
            pointer-events: none;
        }

        .contact-page .contact-hero-stat {
            padding: 1.2rem;
            border-radius: 16px;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.05);
            transition: all 0.3s ease;
        }

        .contact-page .contact-hero-stat:hover {
            background: rgba(160, 110, 40, 0.05);
            border-color: rgba(160, 110, 40, 0.25);
        }

        .contact-page .contact-hero-stat-value {
            display: block;
            color: #ffffff;
            font-size: 1.4rem;
            font-weight: 800;
            line-height: 1.1;
        }

        .contact-page .contact-hero-stat-label {
            display: block;
            margin-top: 0.4rem;
            color: #E6E4E4;
            font-size: 0.75rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            opacity: 0.8;
        }

        .contact-page .contact-hero-list li {
            display: flex;
            gap: 1rem;
            align-items: flex-start;
            padding: 1.1rem 0;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
        }

        .contact-page .contact-hero-list li:first-child {
            padding-top: 0;
            border-top: 0;
        }

        .contact-page .contact-hero-marker {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            color: #A06E28;
            background: rgba(160, 110, 40, 0.15);
            border: 1px solid rgba(160, 110, 40, 0.3);
            font-size: 0.85rem;
            font-weight: 700;
            letter-spacing: 0.05em;
            transition: all 0.3s ease;
        }

        .contact-page .contact-hero-list li:hover .contact-hero-marker {
            background: #A06E28;
            color: #091B23;
            border-color: #A06E28;
            box-shadow: 0 0 12px rgba(160, 110, 40, 0.4);
            transform: scale(1.05);
        }

        .contact-page .contact-info-card {
            position: relative;
            overflow: hidden;
            background: rgba(255, 255, 255, 0.02) !important;
            border: 1px solid rgba(255, 255, 255, 0.06) !important;
            border-radius: 20px;
            transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .contact-page .contact-info-card:hover {
            transform: translateY(-8px);
            background: rgba(255, 255, 255, 0.04) !important;
            border-color: rgba(160, 110, 40, 0.4) !important;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.35);
        }

        .contact-page .contact-icon {
            width: 3.5rem;
            height: 3.5rem;
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            color: #A06E28;
            background: rgba(160, 110, 40, 0.1);
            border: 1px solid rgba(160, 110, 40, 0.25);
            transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .contact-page .contact-info-card:hover .contact-icon {
            background: #A06E28;
            color: #091B23;
            transform: scale(1.1) rotate(5deg);
            border-color: #A06E28;
        }

        .contact-page .contact-process-step {
            display: flex;
            gap: 1.2rem;
            padding: 1.2rem 1.3rem;
            border-radius: 18px;
            border: 1px solid rgba(255, 255, 255, 0.05);
            background: rgba(255, 255, 255, 0.02);
            transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .contact-page .contact-process-step:hover {
            border-color: rgba(160, 110, 40, 0.3);
            background: rgba(255, 255, 255, 0.04);
            transform: translateX(6px);
        }

        .contact-page .contact-step-index {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            background: rgba(160, 110, 40, 0.15);
            color: #A06E28;
            font-size: 0.9rem;
            font-weight: 700;
            border: 1px solid rgba(160, 110, 40, 0.3);
            transition: all 0.4s ease;
        }

        .contact-page .contact-process-step:hover .contact-step-index {
            background: #A06E28;
            color: #091B23;
            border-color: #A06E28;
            box-shadow: 0 0 15px rgba(160, 110, 40, 0.4);
        }

        .contact-page .contact-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            padding: 0.65rem 0.9rem;
            border-radius: 999px;
            border: 1px solid rgba(160, 110, 40, 0.25);
            background: rgba(160, 110, 40, 0.06);
            color: #E6E4E4;
            font-size: 0.82rem;
            font-weight: 600;
            line-height: 1;
            transition: all 0.3s ease;
        }

        .contact-page .contact-chip:hover {
            border-color: #A06E28;
            background: rgba(160, 110, 40, 0.15);
            color: #ffffff;
            transform: scale(1.05);
        }

        .contact-page .contact-chip::before {
            content: "";
            width: 0.45rem;
            height: 0.45rem;
            border-radius: 999px;
            background: #A06E28;
            flex-shrink: 0;
        }

        .contact-page .contact-form-shell {
            background: linear-gradient(135deg, rgba(9, 27, 35, 0.95), rgba(9, 27, 35, 0.85)) !important;
            border: 1px solid rgba(160, 110, 40, 0.2) !important;
            border-radius: 24px;
            box-shadow: 0 40px 80px rgba(0, 0, 0, 0.5) !important;
            backdrop-filter: blur(20px);
            position: relative;
            scroll-margin-top: 9rem;
        }

        .contact-page .contact-form-shell::after {
            content: "";
            position: absolute;
            top: 0;
            right: 0;
            width: 150px;
            height: 150px;
            background: radial-gradient(circle, rgba(160, 110, 40, 0.15), transparent 70%);
            pointer-events: none;
            border-radius: 24px;
        }

        .contact-page .form-control,
        .contact-page .form-select {
            background: rgba(255, 255, 255, 0.03) !important;
            border: 1px solid rgba(255, 255, 255, 0.1) !important;
            color: #E6E4E4 !important;
            border-radius: 12px;
            padding: 0.85rem 1.1rem;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .contact-page .form-control::placeholder {
            color: rgba(230, 228, 228, 0.4) !important;
        }

        .contact-page .form-control:focus,
        .contact-page .form-select:focus {
            background: rgba(255, 255, 255, 0.06) !important;
            border-color: #A06E28 !important;
            box-shadow: 0 0 0 4px rgba(160, 110, 40, 0.15) !important;
            color: #ffffff !important;
        }

        .contact-page .form-label {
            color: #E6E4E4;
            font-weight: 500;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
            letter-spacing: 0.02em;
        }

        .contact-page .btn-brand {
            background: #A06E28 !important;
            border-color: #A06E28 !important;
            color: #091B23 !important;
            font-weight: 700;
            letter-spacing: 0.03em;
            border-radius: 12px;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .contact-page .btn-brand:hover {
            background: #be8637 !important;
            border-color: #be8637 !important;
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(160, 110, 40, 0.25) !important;
        }

        .contact-page .immersive-map-section {
            position: relative;
            width: 100vw;
            left: 50%;
            right: 50%;
            margin-left: -50vw;
            margin-right: -50vw;
            height: 650px;
            background: #091B23;
            overflow: hidden;
            border-top: 1px solid rgba(160, 110, 40, 0.15);
            border-bottom: 1px solid rgba(160, 110, 40, 0.15);
        }

        .contact-page .immersive-map-iframe {
            width: 100%;
            height: 100%;
            border: 0;
            filter: grayscale(1) invert(0.92) contrast(1.25) hue-rotate(180deg) brightness(0.95);
            opacity: 0.75;
            transition: all 0.5s ease;
        }

        .contact-page .immersive-map-section:hover .immersive-map-iframe {
            opacity: 0.9;
            filter: grayscale(0.85) invert(0.92) contrast(1.15) hue-rotate(185deg) brightness(1);
        }

        .contact-page .immersive-map-floating-card {
            position: absolute;
            top: 60px;
            left: 10%;
            width: 420px;
            max-width: 90%;
            background: linear-gradient(135deg, rgba(9, 27, 35, 0.96), rgba(9, 27, 35, 0.88));
            border: 1px solid rgba(160, 110, 40, 0.3);
            border-radius: 24px;
            box-shadow: 0 35px 70px rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(15px);
            z-index: 5;
            padding: 3rem;
            color: #E6E4E4;
            transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .contact-page .immersive-map-floating-card:hover {
            transform: translateY(-5px);
            border-color: rgba(160, 110, 40, 0.5);
            box-shadow: 0 45px 90px rgba(0, 0, 0, 0.7);
        }

        .contact-page .contact-map-list li {
            position: relative;
            padding-left: 1.5rem;
            color: #E6E4E4;
            opacity: 0.85;
        }

        .contact-page .contact-map-list li + li {
            margin-top: 1rem;
        }

        .contact-page .contact-map-list li::before {
            content: "";
            position: absolute;
            top: 0.6rem;
            left: 0;
            width: 0.5rem;
            height: 0.5rem;
            border-radius: 50%;
            background: #A06E28;
            box-shadow: 0 0 8px rgba(160, 110, 40, 0.5);
        }

        @media (max-width: 991.98px) {
            .contact-page .hero {
                min-height: auto !important;
                padding: 8rem 0 4rem;
            }

            .contact-page .contact-hero-panel {
                margin-bottom: 0;
            }

            .contact-page .immersive-map-section {
                height: auto;
                display: flex;
                flex-direction: column;
                margin-left: 0;
                margin-right: 0;
                width: 100%;
                left: auto;
                right: auto;
                border-bottom: 0;
            }

            .contact-page .immersive-map-floating-card {
                position: relative;
                top: auto;
                left: auto;
                width: 100%;
                max-width: 100%;
                border-radius: 0;
                border-left: 0;
                border-right: 0;
                border-bottom: 0;
                box-shadow: none;
                backdrop-filter: none;
                background: #091B23;
                padding: 2.5rem 1.5rem;
            }

            .contact-page .immersive-map-iframe {
                height: 380px;
                order: 2;
            }
        }

        /* Custom Select Component Refinements */
        .contact-page .custom-select-wrapper {
            position: relative;
            width: 100%;
        }

        .contact-page .custom-select-trigger {
            width: 100%;
            background: rgba(255, 255, 255, 0.03) !important;
            border: 1px solid rgba(255, 255, 255, 0.1) !important;
            color: #E6E4E4 !important;
            border-radius: 12px;
            padding: 0.85rem 1.1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            text-align: left;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            outline: none;
            box-shadow: none;
        }

        .contact-page .custom-select-trigger:focus,
        .contact-page .custom-select-wrapper.open .custom-select-trigger {
            background: rgba(255, 255, 255, 0.06) !important;
            border-color: #A06E28 !important;
            box-shadow: 0 0 0 4px rgba(160, 110, 40, 0.15) !important;
            color: #ffffff !important;
        }

        .contact-page .custom-select-wrapper.is-invalid-custom .custom-select-trigger {
            border-color: #ff6b6b !important;
        }

        .contact-page .custom-select-wrapper.is-invalid-custom .custom-select-trigger:focus {
            box-shadow: 0 0 0 4px rgba(255, 107, 107, 0.15) !important;
        }

        .contact-page .custom-select-trigger.placeholder-active .trigger-text {
            color: rgba(230, 228, 228, 0.4) !important;
        }

        .contact-page .custom-select-trigger .chevron-icon {
            color: #A06E28;
            transition: transform 0.35s cubic-bezier(0.16, 1, 0.3, 1);
            flex-shrink: 0;
            margin-left: 1rem;
        }

        .contact-page .custom-select-wrapper.open .custom-select-trigger .chevron-icon {
            transform: rotate(180deg);
        }

        .contact-page .custom-select-options {
            position: absolute;
            top: calc(100% + 8px);
            left: 0;
            right: 0;
            background: #091B23;
            border: 1px solid rgba(160, 110, 40, 0.35);
            border-radius: 12px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.6);
            padding: 0.5rem 0;
            z-index: 100;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px) scale(0.98);
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            backdrop-filter: blur(20px);
        }

        .contact-page .custom-select-wrapper.open .custom-select-options {
            opacity: 1;
            visibility: visible;
            transform: translateY(0) scale(1);
        }

        .contact-page .custom-select-option {
            padding: 0.8rem 1.25rem;
            color: #E6E4E4;
            font-size: 0.95rem;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: space-between;
            outline: none;
        }

        .contact-page .custom-select-option:hover {
            background: #A06E28;
            color: #091B23;
        }

        .contact-page .custom-select-option.active {
            background: rgba(160, 110, 40, 0.12);
            color: #ffffff;
            font-weight: 600;
            border-left: 3px solid #A06E28;
            padding-left: calc(1.25rem - 3px);
        }

        .contact-page .custom-select-option.active:hover {
            background: #A06E28;
            color: #091B23;
        }
    </style>

    <section class="hero position-relative d-flex align-items-center">
        <div class="position-absolute top-0 start-0 w-100 h-100" style="opacity: 0.1; background: url('<?php echo e(asset('images/compliance.png')); ?>') center/cover; mix-blend-mode: luminosity;"></div>
        <div class="container position-relative z-1">
            <div class="row align-items-center g-4 g-lg-5">
                <div class="col-lg-7">
                    <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid #A06E28; color: #A06E28; background: rgba(160, 110, 40, 0.1); letter-spacing: 0.1em; font-weight: 600;">Atendimento institucional</span>
                    <h1 class="display-3 fw-bold mb-4" style="color: #ffffff; letter-spacing: -0.04em; font-size: clamp(2.5rem, 5.5vw, 4.5rem); line-height: 1.05;">
                        Entre em contato com a <span style="color: #A06E28;">BSI Capital</span>
                    </h1>
                    <p class="lead mb-0" style="color: #E6E4E4; max-width: 760px;">
                        Estamos à disposição para avaliar novas teses de operação ou suportar demandas institucionais. Nosso atendimento prioriza o rigor técnico e a viabilidade fiduciária exigidos pelo mercado.
                    </p>

                    <div class="d-grid gap-3 d-sm-flex justify-content-sm-start mt-4 pt-2">
                        <a href="#form-contato" class="btn btn-brand btn-lg d-inline-flex align-items-center justify-content-center gap-2 px-5 py-3 shadow-lg">
                            Iniciar atendimento
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                        </a>
                        <a href="mailto:contato@bsicapital.com.br" class="btn btn-lg d-inline-flex align-items-center justify-content-center gap-2 px-5 py-3" style="border: 1px solid rgba(230,228,228,0.35); color: #E6E4E4; background: rgba(230,228,228,0.08); transition: all 0.3s ease;">
                            E-mail institucional
                        </a>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="contact-hero-panel p-4 p-xl-5">
                        <div class="section-kicker mb-2" style="color: #A06E28; font-weight: 600;">Primeiro retorno estruturado</div>
                        <h2 class="h4 fw-bold text-white mb-3">Canal direto para a frente certa</h2>
                        <p class="mb-4" style="color: #E6E4E4; opacity: 0.85; line-height: 1.75;">
                            Consolidamos o contexto inicial e direcionamos sua mensagem para o núcleo responsável sem perder tempo com repasses internos desnecessários.
                        </p>

                        <div class="row g-3 mb-4">
                            <div class="col-sm-6">
                                <div class="contact-hero-stat h-100">
                                    <span class="contact-hero-stat-value">24h úteis</span>
                                    <span class="contact-hero-stat-label">Retorno inicial</span>
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <div class="contact-hero-stat h-100">
                                    <span class="contact-hero-stat-value">3 frentes</span>
                                    <span class="contact-hero-stat-label">Triagem prioritária</span>
                                </div>
                            </div>
                        </div>

                        <ul class="list-unstyled contact-hero-list mb-0">
                            <li>
                                <span class="contact-hero-marker">01</span>
                                <div>
                                    <div class="fw-semibold text-white">Comercial e novos negócios</div>
                                    <div class="small" style="color: #E6E4E4; opacity: 0.75;">Estruturação, viabilidade preliminar e novas teses de operação.</div>
                                </div>
                            </li>
                            <li>
                                <span class="contact-hero-marker">02</span>
                                <div>
                                    <div class="fw-semibold text-white">Relacionamento institucional</div>
                                    <div class="small" style="color: #E6E4E4; opacity: 0.75;">Investidores, documentos públicos e comunicações corporativas.</div>
                                </div>
                            </li>
                            <li>
                                <span class="contact-hero-marker">03</span>
                                <div>
                                    <div class="fw-semibold text-white">Compliance e ética</div>
                                    <div class="small" style="color: #E6E4E4; opacity: 0.75;">Conformidade, governança, proteção de dados e canais sensíveis.</div>
                                </div>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="py-5" style="background: #091B23;">
        <div class="container py-lg-5">
            <div class="row g-4 align-items-stretch mb-5">
                <div class="col-md-4">
                    <div class="surface-card contact-info-card h-100 p-4">
                        <div class="d-flex align-items-start gap-3">
                            <div class="contact-icon">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16v16H4z"></path><path d="m22 6-10 7L2 6"></path></svg>
                            </div>
                            <div>
                                <div class="section-kicker mb-2" style="color: #A06E28; font-weight: 600;">Canal institucional</div>
                                <h2 class="h4 fw-bold text-white mb-2">E-mail</h2>
                                <p class="mb-3" style="color: #E6E4E4; opacity: 0.8; font-size: 0.9rem;">Para demandas institucionais, comerciais, operacionais e documentação pública.</p>
                                <a href="mailto:contato@bsicapital.com.br" class="fw-semibold text-decoration-none" style="color: #A06E28; transition: all 0.3s ease;">contato@bsicapital.com.br</a>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="surface-card contact-info-card h-100 p-4">
                        <div class="d-flex align-items-start gap-3">
                            <div class="contact-icon">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.8 19.8 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.12 4.18 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72l.38 2.65a2 2 0 0 1-.57 1.72L7.2 9.8a16 16 0 0 0 7 7l1.71-1.72a2 2 0 0 1 1.72-.57l2.65.38A2 2 0 0 1 22 16.92z"></path></svg>
                            </div>
                            <div>
                                <div class="section-kicker mb-2" style="color: #A06E28; font-weight: 600;">Atendimento</div>
                                <h2 class="h4 fw-bold text-white mb-2">Telefone</h2>
                                <p class="mb-3" style="color: #E6E4E4; opacity: 0.8; font-size: 0.9rem;">Suporte corporativo em dias úteis, das 09h às 18h, para alinhamentos rápidos.</p>
                                <a href="tel:+551123678793" class="fw-semibold text-decoration-none" style="color: #A06E28; transition: all 0.3s ease;">+55 (11) 2367-8793</a>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="surface-card contact-info-card h-100 p-4">
                        <div class="d-flex align-items-start gap-3">
                            <div class="contact-icon">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-6-4.35-6-10a6 6 0 1 1 12 0c0 5.65-6 10-6 10z"></path><circle cx="12" cy="11" r="2.5"></circle></svg>
                            </div>
                            <div>
                                <div class="section-kicker mb-2" style="color: #A06E28; font-weight: 600;">Base operacional</div>
                                <h2 class="h4 fw-bold text-white mb-2">São Paulo</h2>
                                <p class="mb-3" style="color: #E6E4E4; opacity: 0.8; font-size: 0.9rem;">
                                    Avenida das Nações Unidas, 14.401<br>
                                    Tarumã Tower, Salas 712 e 713<br>
                                    Chácara Santo Antônio, São Paulo - SP
                                </p>
                                <a href="<?php echo e($mapsUrl); ?>" target="_blank" rel="noopener" class="fw-semibold text-decoration-none" style="color: #A06E28; transition: all 0.3s ease;">Abrir localização</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4 align-items-stretch">
                <div class="col-lg-5">
                    <div class="surface-card h-100 p-4 p-lg-5" style="background: rgba(255, 255, 255, 0.02); border: 1px solid rgba(255, 255, 255, 0.06); border-radius: 24px;">
                        <div class="section-kicker mb-2" style="color: #A06E28; font-weight: 600;">Primeiro retorno</div>
                        <h2 class="h3 fw-bold text-white mb-3">O que acontece após o envio</h2>
                        <p class="mb-4" style="color: #E6E4E4; opacity: 0.8; font-size: 0.95rem; line-height: 1.6;">
                            Cada mensagem entra em uma triagem objetiva para que o primeiro retorno já traga direção, contexto e próximos passos compatíveis com a natureza da demanda.
                        </p>

                        <div class="d-flex flex-column gap-3 mb-4">
                            <div class="contact-process-step">
                                <span class="contact-step-index">01</span>
                                <div>
                                    <div class="fw-semibold text-white mb-1">Recebimento com contexto</div>
                                    <div class="mb-0" style="color: #E6E4E4; opacity: 0.75; font-size: 0.88rem;">Lemos o assunto, a mensagem e os dados de contato para registrar corretamente o escopo inicial.</div>
                                </div>
                            </div>
                            <div class="contact-process-step">
                                <span class="contact-step-index">02</span>
                                <div>
                                    <div class="fw-semibold text-white mb-1">Triagem por área responsável</div>
                                    <div class="mb-0" style="color: #E6E4E4; opacity: 0.75; font-size: 0.88rem;">Direcionamos o conteúdo para o núcleo comercial, institucional ou de compliance conforme a natureza da solicitação.</div>
                                </div>
                            </div>
                            <div class="contact-process-step">
                                <span class="contact-step-index">03</span>
                                <div>
                                    <div class="fw-semibold text-white mb-1">Resposta e próximos passos</div>
                                    <div class="mb-0" style="color: #E6E4E4; opacity: 0.75; font-size: 0.88rem;">O retorno inicial prioriza clareza técnica, proteção informacional e encaminhamento objetivo da conversa.</div>
                                </div>
                            </div>
                        </div>

                        <div class="p-4" style="border-radius: 18px; background: rgba(255, 255, 255, 0.03); border: 1px solid rgba(255, 255, 255, 0.05);">
                            <div class="small text-uppercase fw-semibold mb-3" style="color: #A06E28; letter-spacing: 0.1em; font-size: 0.75rem;">Frentes mais recorrentes</div>
                            <div class="d-flex flex-wrap gap-2">
                                <span class="contact-chip">Operações estruturadas</span>
                                <span class="contact-chip">Documentos públicos</span>
                                <span class="contact-chip">Compliance e ética</span>
                                <span class="contact-chip">Relações com investidores</span>
                                <span class="contact-chip">Parcerias e novos negócios</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-7">
                    <div class="surface-card contact-form-shell h-100 p-4 p-lg-5" id="form-contato">
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(session('contact_success')): ?>
                            <div class="alert d-flex align-items-center gap-3 mb-4" role="alert" style="background: rgba(160, 110, 40, 0.15); border: 1px solid #A06E28; color: #E6E4E4;">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#A06E28" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                                <span>Mensagem enviada com sucesso. Nossa equipe retornará em até 24 horas úteis.</span>
                            </div>
                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

                        <div class="mb-4">
                            <div class="section-kicker mb-2" style="color: #A06E28; font-weight: 600;">Formulário</div>
                            <h2 class="h3 fw-bold text-white mb-2">Envie sua mensagem</h2>
                            <p class="mb-0" style="color: #E6E4E4; opacity: 0.8;">As informações abaixo permitem um direcionamento técnico e seguro da sua demanda para a área responsável.</p>
                        </div>

                        <div class="d-flex flex-wrap gap-2 mb-4">
                            <span class="contact-chip">Retorno inicial em até 24h úteis</span>
                            <span class="contact-chip">Triagem por núcleo responsável</span>
                            <span class="contact-chip">Tratamento sigiloso</span>
                        </div>

                        <form action="<?php echo e(route('site.contact.submit')); ?>" method="POST" class="row g-3">
                            <?php echo csrf_field(); ?>
                            <div class="col-md-6">
                                <label class="form-label">Nome</label>
                                <input type="text" name="name" class="form-control <?php $__errorArgs = ['name'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> is-invalid <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>" placeholder="Informe seu nome completo" value="<?php echo e(old('name')); ?>" required>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['name'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><div class="invalid-feedback" style="color: #ff6b6b;"><?php echo e($message); ?></div><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">E-mail</label>
                                <input type="email" name="email" class="form-control <?php $__errorArgs = ['email'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> is-invalid <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>" placeholder="Informe seu e-mail corporativo" value="<?php echo e(old('email')); ?>" required>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['email'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><div class="invalid-feedback" style="color: #ff6b6b;"><?php echo e($message); ?></div><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Telefone</label>
                                <input type="tel" name="phone" id="phone" class="form-control" placeholder="(00) 00000-0000" value="<?php echo e(old('phone')); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Assunto</label>
                                <div class="custom-select-wrapper <?php $__errorArgs = ['subject'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> is-invalid-custom <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>">
                                    <!-- Native select hidden from view but focusable for native validation -->
                                    <select name="subject" id="subject-native-select" required style="position: absolute; width: 0; height: 0; opacity: 0; pointer-events: none; z-index: -1;">
                                        <option value="" selected disabled>Selecione a área de interesse</option>
                                        <option value="Relações com investidores" <?php if(old('subject') === 'Relações com investidores'): echo 'selected'; endif; ?>>Relações com investidores</option>
                                        <option value="Comercial e novos negócios" <?php if(old('subject') === 'Comercial e novos negócios'): echo 'selected'; endif; ?>>Comercial e novos negócios</option>
                                        <option value="Compliance e canal de ética" <?php if(old('subject') === 'Compliance e canal de ética'): echo 'selected'; endif; ?>>Compliance e canal de ética</option>
                                        <option value="Carreiras / Trabalhe conosco" <?php if(old('subject') === 'Carreiras / Trabalhe conosco'): echo 'selected'; endif; ?>>Carreiras / Trabalhe conosco</option>
                                        <option value="Assuntos institucionais" <?php if(old('subject') === 'Assuntos institucionais'): echo 'selected'; endif; ?>>Assuntos institucionais</option>
                                    </select>
                                    
                                    <!-- Custom Trigger Button -->
                                    <button type="button" class="custom-select-trigger <?php if(!old('subject')): ?> placeholder-active <?php endif; ?>" id="subject-custom-trigger" aria-haspopup="listbox" aria-expanded="false" aria-controls="subject-custom-options">
                                        <span class="trigger-text"><?php echo e(old('subject') ?: 'Selecione a área de interesse'); ?></span>
                                        <svg class="chevron-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                            <polyline points="6 9 12 15 18 9"></polyline>
                                        </svg>
                                    </button>
                                    
                                    <!-- Custom Options Menu -->
                                    <div class="custom-select-options" id="subject-custom-options" role="listbox">
                                        <div class="custom-select-option <?php if(old('subject') === 'Relações com investidores'): ?> active <?php endif; ?>" data-value="Relações com investidores" role="option">Relações com investidores</div>
                                        <div class="custom-select-option <?php if(old('subject') === 'Comercial e novos negócios'): ?> active <?php endif; ?>" data-value="Comercial e novos negócios" role="option">Comercial e novos negócios</div>
                                        <div class="custom-select-option <?php if(old('subject') === 'Compliance e canal de ética'): ?> active <?php endif; ?>" data-value="Compliance e canal de ética" role="option">Compliance e canal de ética</div>
                                        <div class="custom-select-option <?php if(old('subject') === 'Carreiras / Trabalhe conosco'): ?> active <?php endif; ?>" data-value="Carreiras / Trabalhe conosco" role="option">Carreiras / Trabalhe conosco</div>
                                        <div class="custom-select-option <?php if(old('subject') === 'Assuntos institucionais'): ?> active <?php endif; ?>" data-value="Assuntos institucionais" role="option">Assuntos institucionais</div>
                                    </div>
                                </div>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['subject'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><div class="invalid-feedback d-block" style="color: #ff6b6b; margin-top: 0.5rem;"><?php echo e($message); ?></div><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Mensagem</label>
                                <textarea name="message" class="form-control <?php $__errorArgs = ['message'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> is-invalid <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>" rows="5" placeholder="Descreva brevemente sua demanda ou tese de operação" required><?php echo e(old('message')); ?></textarea>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['message'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><div class="invalid-feedback" style="color: #ff6b6b;"><?php echo e($message); ?></div><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </div>
                            <div class="col-12 pt-2">
                                <button type="submit" class="btn btn-brand btn-lg px-5 mb-3">Iniciar atendimento</button>
                                <p class="small mb-0" style="font-size: 0.75rem; line-height: 1.4; color: #E6E4E4; opacity: 0.6;">
                                    As informações fornecidas são protegidas por protocolos de sigilo em conformidade com a LGPD e nossa política de integridade corporativa.
                                </p>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="immersive-map-section">
        <div class="immersive-map-floating-card">
            <div class="section-kicker mb-2" style="color: #A06E28; font-weight: 600;">Localização</div>
            <h2 class="h3 fw-bold mb-3 text-white">Sede institucional</h2>
            <p class="mb-4" style="color: #E6E4E4; opacity: 0.85; font-size: 0.95rem; line-height: 1.6;">
                Nossa base em São Paulo concentra a inteligência estratégica, operacional e fiduciária da BSI Capital.
            </p>

            <div class="p-4 mb-4" style="border-radius: 18px; background: rgba(255, 255, 255, 0.03); border: 1px solid rgba(255, 255, 255, 0.06);">
                <div class="small text-uppercase fw-semibold mb-2" style="color: #A06E28; letter-spacing: 0.12em; font-size: 0.75rem;">Endereço</div>
                <div class="fw-semibold mb-1" style="color: #ffffff;">Avenida das Nações Unidas, 14.401</div>
                <div class="mb-1" style="color: #E6E4E4; opacity: 0.9; font-size: 0.9rem;">Tarumã Tower, Salas 712 e 713</div>
                <div style="color: #E6E4E4; opacity: 0.9; font-size: 0.9rem;">Chácara Santo Antônio, São Paulo - SP</div>
            </div>

            <ul class="list-unstyled contact-map-list mb-4">
                <li>Atendimento corporativo em dias úteis, das 09h às 18h.</li>
                <li>Visitas institucionais mediante alinhamento prévio com a equipe.</li>
                <li>Base próxima aos principais eixos empresariais da Zona Sul de São Paulo.</li>
            </ul>

            <a href="<?php echo e($mapsUrl); ?>" target="_blank" rel="noopener" class="btn btn-brand d-inline-flex align-items-center gap-2 px-4 py-3 w-100 justify-content-center">
                Abrir no Google Maps
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 3h7v7"></path><path d="M10 14 21 3"></path><path d="M21 14v4a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path></svg>
            </a>
        </div>
        <iframe
            class="immersive-map-iframe"
            src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3655.4502951281343!2d-46.70595342358573!3d-23.624039663899975!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x94ce42360bb98d7f%3A0xa4ab8704821d7133!2sBSI%20Capital%20Securitizadora%20S%2FA!5e0!3m2!1spt-BR!2sbr!4v1774380432797!5m2!1spt-BR!2sbr"
            allowfullscreen=""
            loading="lazy"
            referrerpolicy="no-referrer-when-downgrade">
        </iframe>
    </section>
</div>

<?php $__env->startPush('scripts'); ?>
<script nonce="<?php echo e(\Illuminate\Support\Facades\Vite::cspNonce()); ?>">
    document.addEventListener('DOMContentLoaded', function() {
        // Phone formatting logic
        const phoneInput = document.getElementById('phone');
        if (phoneInput) {
            phoneInput.addEventListener('input', function(e) {
                let raw = e.target.value.replace(/\D/g, "").substring(0, 11);
                let formatted = raw;

                if (raw.length > 2) {
                    formatted = '(' + raw.substring(0, 2) + ') ' + raw.substring(2);
                }

                if (raw.length > 6) {
                    if (raw.length === 11) {
                        formatted = '(' + raw.substring(0, 2) + ') ' + raw.substring(2, 7) + '-' + raw.substring(7);
                    } else {
                        formatted = '(' + raw.substring(0, 2) + ') ' + raw.substring(2, 6) + '-' + raw.substring(6);
                    }
                }

                e.target.value = formatted;
            });
        }

        // Custom Select Dropdown logic
        const selectWrapper = document.querySelector('.custom-select-wrapper');
        if (selectWrapper) {
            const trigger = document.getElementById('subject-custom-trigger');
            const optionsContainer = document.getElementById('subject-custom-options');
            const options = optionsContainer.querySelectorAll('.custom-select-option');
            const nativeSelect = document.getElementById('subject-native-select');
            
            // Toggle dropdown open/close
            trigger.addEventListener('click', function(e) {
                e.stopPropagation();
                const isOpen = selectWrapper.classList.contains('open');
                closeAllSelects();
                if (!isOpen) {
                    selectWrapper.classList.add('open');
                    trigger.setAttribute('aria-expanded', 'true');
                    
                    // Focus the active option if exists, otherwise first
                    const activeOption = optionsContainer.querySelector('.custom-select-option.active');
                    if (activeOption) {
                        activeOption.focus();
                    }
                }
            });
            
            // Select custom option
            options.forEach(option => {
                option.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const val = this.getAttribute('data-value');
                    const text = this.textContent;
                    
                    // Update trigger text & placeholder color state
                    trigger.querySelector('.trigger-text').textContent = text;
                    trigger.classList.remove('placeholder-active');
                    
                    // Update native select
                    nativeSelect.value = val;
                    nativeSelect.dispatchEvent(new Event('change', { bubbles: true }));
                    
                    // Update active option styling
                    options.forEach(opt => opt.classList.remove('active'));
                    this.classList.add('active');
                    
                    // Close dropdown
                    selectWrapper.classList.remove('open');
                    trigger.setAttribute('aria-expanded', 'false');
                    trigger.focus();
                });
            });
            
            // Close dropdown when clicking outside
            document.addEventListener('click', function() {
                closeAllSelects();
            });
            
            // Close all select instances
            function closeAllSelects() {
                selectWrapper.classList.remove('open');
                trigger.setAttribute('aria-expanded', 'false');
            }
            
            // Form validation error triggers visual states
            nativeSelect.addEventListener('invalid', function() {
                selectWrapper.classList.add('is-invalid-custom');
            });
            
            nativeSelect.addEventListener('change', function() {
                if (this.value) {
                    selectWrapper.classList.remove('is-invalid-custom');
                }
            });
            
            // Trigger Keyboard navigation
            trigger.addEventListener('keydown', function(e) {
                if (e.key === 'ArrowDown' || e.key === 'ArrowUp' || e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    if (!selectWrapper.classList.contains('open')) {
                        trigger.click();
                    } else {
                        const activeOption = optionsContainer.querySelector('.custom-select-option.active') || options[0];
                        activeOption.focus();
                    }
                }
            });
            
            // Custom options Keyboard navigation
            options.forEach((option, index) => {
                option.setAttribute('tabindex', '-1');
                
                option.addEventListener('keydown', function(e) {
                    if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        const nextOption = options[index + 1] || options[0];
                        nextOption.focus();
                    } else if (e.key === 'ArrowUp') {
                        e.preventDefault();
                        const prevOption = options[index - 1] || options[options.length - 1];
                        prevOption.focus();
                    } else if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        this.click();
                    } else if (e.key === 'Escape') {
                        e.preventDefault();
                        closeAllSelects();
                        trigger.focus();
                    }
                });
            });
        }
    });
</script>
<?php $__env->stopPush(); ?>

<?php $__env->stopSection(); ?>

<?php echo $__env->make('site.layout', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /var/www/html/resources/views/site/contact.blade.php ENDPATH**/ ?>
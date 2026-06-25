@extends('site.layout')
@section('title', 'Contato — BSI Capital')

@section('content')
@php($mapsUrl = 'https://www.google.com/maps/search/?api=1&query=BSI+Capital+Securitizadora+S%2FA')

<div class="contact-page">
    <style>
        /* General */
        .contact-page {
            color: #091B23;
        }

        /* Hero Section */
        .contact-hero {
            background-color: #091B23;
            color: #E6E4E4;
            padding: 10rem 0 7rem;
            position: relative;
        }

        .contact-hero h1 {
            color: #ffffff;
            font-size: clamp(2.5rem, 5vw, 4rem);
            font-weight: 400;
            letter-spacing: -0.02em;
        }

        .contact-hero h1 span {
            color: #A06E28;
            font-weight: 600;
        }

        .contact-hero .lead {
            font-size: 1.15rem;
            max-width: 800px;
            font-weight: 300;
            opacity: 0.9;
        }

        /* Buttons */
        .btn-brand-primary {
            background-color: #A06E28;
            color: #091B23;
            border: 1px solid #A06E28;
            font-weight: 500;
            padding: 0.85rem 2rem;
            border-radius: 0;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }

        .btn-brand-primary:hover {
            background-color: #be8637;
            border-color: #be8637;
            color: #091B23;
        }

        .btn-brand-outline {
            background-color: transparent;
            color: #E6E4E4;
            border: 1px solid rgba(230, 228, 228, 0.4);
            font-weight: 500;
            padding: 0.85rem 2rem;
            border-radius: 0;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }

        .btn-brand-outline:hover {
            border-color: #A06E28;
            color: #A06E28;
            background-color: transparent;
        }

        /* Contact Channels */
        .contact-channels {
            padding: 6rem 0;
            background-color: #ffffff;
        }

        .channel-card {
            border: 1px solid rgba(9, 27, 35, 0.1);
            padding: 3rem 2.5rem;
            height: 100%;
            transition: border-color 0.3s ease;
            background: #ffffff;
        }

        .channel-card:hover {
            border-color: #A06E28;
        }

        .channel-icon {
            color: #A06E28;
            margin-bottom: 1.5rem;
        }

        .channel-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: #091B23;
        }

        .channel-text {
            color: rgba(9, 27, 35, 0.7);
            font-size: 0.95rem;
            margin-bottom: 1.5rem;
            line-height: 1.6;
        }

        .channel-link {
            color: #091B23;
            font-weight: 500;
            text-decoration: none;
            border-bottom: 1px solid #A06E28;
            padding-bottom: 0.2rem;
            transition: color 0.3s ease;
            font-size: 1.05rem;
            display: inline-block;
        }

        .channel-link:hover {
            color: #A06E28;
        }

        /* Form and Process Section */
        .form-section {
            background-color: #E6E4E4;
            padding: 6rem 0;
        }

        .institutional-form {
            background: #ffffff;
            padding: 4rem;
            border: 1px solid rgba(9, 27, 35, 0.08);
        }

        .institutional-form .form-label {
            color: #091B23;
            font-weight: 500;
            font-size: 0.9rem;
            margin-bottom: 0.6rem;
        }

        .institutional-form .form-control, 
        .institutional-form .form-select {
            border: 1px solid rgba(9, 27, 35, 0.2);
            border-radius: 0;
            padding: 0.9rem 1rem;
            background-color: #ffffff;
            color: #091B23;
            transition: all 0.3s ease;
            font-size: 0.95rem;
        }

        .institutional-form .form-control:focus, 
        .institutional-form .form-select:focus {
            border-color: #A06E28;
            box-shadow: none;
            outline: none;
        }

        .institutional-form .form-control::placeholder {
            color: rgba(9, 27, 35, 0.4);
        }

        .process-title {
            font-size: 1.6rem;
            font-weight: 600;
            color: #091B23;
            margin-bottom: 1.5rem;
        }

        .process-text {
            color: rgba(9, 27, 35, 0.75);
            line-height: 1.7;
            margin-bottom: 2rem;
        }

        .process-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .process-list li {
            position: relative;
            padding-left: 2rem;
            margin-bottom: 1.2rem;
            color: #091B23;
            font-weight: 500;
        }

        .process-list li::before {
            content: "";
            position: absolute;
            left: 0;
            top: 0.5rem;
            width: 6px;
            height: 6px;
            background-color: #A06E28;
        }

        .direction-list {
            list-style: none;
            padding: 0;
            margin: 0;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.2rem 1rem;
        }

        .direction-list li {
            color: rgba(9, 27, 35, 0.8);
            font-size: 0.95rem;
            padding-left: 1.5rem;
            position: relative;
        }

        .direction-list li::before {
            content: "—";
            position: absolute;
            left: 0;
            color: #A06E28;
        }

        /* Location Section */
        .location-section {
            background-color: #ffffff;
        }

        .location-card {
            background-color: #091B23;
            color: #E6E4E4;
            padding: 5rem 4rem;
            height: 100%;
        }

        .location-card h2 {
            color: #ffffff;
            font-size: 2rem;
            font-weight: 400;
            margin-bottom: 1.5rem;
        }

        .location-card .btn-brand-primary {
            margin-top: 2rem;
        }

        .map-container {
            height: 100%;
            min-height: 400px;
            position: relative;
        }

        .map-container iframe {
            width: 100%;
            height: 100%;
            border: none;
            filter: grayscale(100%) contrast(1.1) brightness(0.9);
        }

        @media (max-width: 991.98px) {
            .contact-hero {
                padding: 6rem 0 4rem;
            }
            
            .institutional-form {
                padding: 2.5rem 1.5rem;
            }

            .location-card {
                padding: 4rem 2rem;
            }
            
            .direction-list {
                grid-template-columns: 1fr;
            }
            
            .map-container {
                height: 400px;
            }
        }
    </style>

    <!-- Hero Section -->
    <section class="contact-hero">
        <div class="container">
            <div class="row">
                <div class="col-lg-8">
                    <h1 class="mb-4">
                        Contato <span>Institucional</span>
                    </h1>
                    <p class="lead mb-5">
                        Entre em contato com a BSI Capital para demandas institucionais, relacionamento com investidores, apresentação de operações, documentos públicos, compliance ou parcerias estratégicas.
                    </p>
                    <div class="d-flex flex-wrap gap-3">
                        <a href="#formulario" class="btn-brand-primary">
                            Enviar mensagem institucional
                        </a>
                        <a href="mailto:contato@bsicapital.com.br" class="btn-brand-outline">
                            Falar com RI
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Canais de Atendimento -->
    <section class="contact-channels">
        <div class="container">
            <div class="row g-4">
                <div class="col-md-4">
                    <div class="channel-card">
                        <div class="channel-icon">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16v16H4z"></path><path d="m22 6-10 7L2 6"></path></svg>
                        </div>
                        <h3 class="channel-title">E-mail institucional</h3>
                        <p class="channel-text">Para demandas institucionais, comerciais, operacionais e documentação pública.</p>
                        <a href="mailto:contato@bsicapital.com.br" class="channel-link">contato@bsicapital.com.br</a>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="channel-card">
                        <div class="channel-icon">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.8 19.8 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.12 4.18 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72l.38 2.65a2 2 0 0 1-.57 1.72L7.2 9.8a16 16 0 0 0 7 7l1.71-1.72a2 2 0 0 1 1.72-.57l2.65.38A2 2 0 0 1 22 16.92z"></path></svg>
                        </div>
                        <h3 class="channel-title">Atendimento telefônico</h3>
                        <p class="channel-text">Suporte corporativo em dias úteis, das 09h às 18h, para alinhamentos rápidos.</p>
                        <a href="tel:+551123678793" class="channel-link">+55 (11) 2367-8793</a>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="channel-card">
                        <div class="channel-icon">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-6-4.35-6-10a6 6 0 1 1 12 0c0 5.65-6 10-6 10z"></path><circle cx="12" cy="11" r="2.5"></circle></svg>
                        </div>
                        <h3 class="channel-title">Sede institucional</h3>
                        <p class="channel-text">Avenida das Nações Unidas, 14.401<br>Tarumã Tower, Salas 712 e 713<br>São Paulo - SP</p>
                        <a href="{{ $mapsUrl }}" target="_blank" rel="noopener" class="channel-link">Abrir no Google Maps</a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Formulário e Fluxo -->
    <section class="form-section" id="formulario">
        <div class="container">
            <div class="row g-5">
                <!-- Coluna do Formulário -->
                <div class="col-lg-7">
                    <div class="institutional-form">
                        <div class="mb-5">
                            <h2 class="process-title">Envie sua mensagem</h2>
                            <p class="process-text mb-0">Preencha os dados abaixo para que sua solicitação seja direcionada à área responsável.</p>
                        </div>

                        @if(session('contact_success'))
                            <div class="alert mb-4" style="background-color: #ffffff; border: 1px solid #A06E28; color: #091B23; padding: 1rem 1.5rem;">
                                <strong>Mensagem enviada com sucesso.</strong> Nossa equipe retornará conforme a natureza da solicitação.
                            </div>
                        @endif

                        <form action="{{ route('site.contact.submit') }}" method="POST">
                            @csrf
                            <div class="row g-4">
                                <div class="col-md-6">
                                    <label class="form-label">Nome</label>
                                    <input type="text" name="name" class="form-control @error('name') is-invalid @enderror" placeholder="Informe seu nome completo" value="{{ old('name') }}" required>
                                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">E-mail</label>
                                    <input type="email" name="email" class="form-control @error('email') is-invalid @enderror" placeholder="Informe seu e-mail corporativo" value="{{ old('email') }}" required>
                                    @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Telefone</label>
                                    <input type="tel" name="phone" id="phone" class="form-control" placeholder="(00) 00000-0000" value="{{ old('phone') }}">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Assunto</label>
                                    <select name="subject" class="form-select @error('subject') is-invalid @enderror" required>
                                        <option value="" selected disabled>Selecione a área de interesse</option>
                                        <option value="Relações com investidores" @selected(old('subject') === 'Relações com investidores')>Relações com investidores</option>
                                        <option value="Comercial e novos negócios" @selected(old('subject') === 'Comercial e novos negócios')>Comercial e novos negócios</option>
                                        <option value="Compliance e ética" @selected(old('subject') === 'Compliance e ética')>Compliance e ética</option>
                                        <option value="Documentos públicos" @selected(old('subject') === 'Documentos públicos')>Documentos públicos</option>
                                        <option value="Parcerias estratégicas" @selected(old('subject') === 'Parcerias estratégicas')>Parcerias estratégicas</option>
                                        <option value="Carreiras / Trabalhe conosco" @selected(old('subject') === 'Carreiras / Trabalhe conosco')>Carreiras</option>
                                    </select>
                                    @error('subject')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Mensagem</label>
                                    <textarea name="message" class="form-control @error('message') is-invalid @enderror" rows="5" placeholder="Descreva sua demanda" required>{{ old('message') }}</textarea>
                                    @error('message')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-12 mt-5">
                                    <button type="submit" class="btn-brand-primary w-100 mb-4 border-0 py-3" style="font-size: 1.05rem;">Enviar mensagem institucional</button>
                                    <p class="small text-muted" style="font-size: 0.8rem; line-height: 1.5; color: rgba(9, 27, 35, 0.6) !important;">
                                        As informações enviadas serão tratadas conforme a Política de Privacidade da BSI Capital, as normas aplicáveis e as rotinas internas de confidencialidade e governança da informação.
                                    </p>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Coluna de Direcionamento -->
                <div class="col-lg-5">
                    <div class="mb-5 pb-2">
                        <h2 class="process-title">Direcionamento da demanda</h2>
                        <p class="process-text">
                            Sua mensagem será direcionada à área responsável conforme o assunto informado, preservando contexto, confidencialidade e clareza nos próximos passos.
                        </p>
                        
                        <ul class="direction-list mt-4 pt-2">
                            <li>Comercial e novos negócios</li>
                            <li>Relações com investidores</li>
                            <li>Compliance e ética</li>
                            <li>Documentos públicos</li>
                            <li>Parcerias estratégicas</li>
                            <li>Carreiras</li>
                        </ul>
                    </div>

                    <div class="pt-5" style="border-top: 1px solid rgba(9, 27, 35, 0.1);">
                        <h2 class="process-title mb-4">O que acontece após o envio</h2>
                        <p class="process-text mb-4">
                            Após o envio, sua solicitação será registrada e encaminhada à área responsável. O retorno considera a natureza da demanda, a disponibilidade da equipe e os procedimentos internos aplicáveis.
                        </p>
                        <ul class="process-list">
                            <li>Recebimento da solicitação</li>
                            <li>Direcionamento à área responsável</li>
                            <li>Retorno com próximos passos</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Sede e Mapa -->
    <section class="location-section p-0">
        <div class="container-fluid p-0">
            <div class="row g-0">
                <div class="col-lg-5 col-xl-4 order-2 order-lg-1">
                    <div class="location-card d-flex flex-column justify-content-center">
                        <h2>Sede institucional</h2>
                        <p style="font-size: 1.05rem; line-height: 1.6; margin-bottom: 2.5rem; opacity: 0.9;">
                            Nossa sede em São Paulo concentra as frentes estratégicas, operacionais e institucionais da BSI Capital.
                        </p>
                        
                        <div class="mb-4">
                            <h4 style="font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.1em; color: #A06E28; margin-bottom: 1rem; font-weight: 600;">Endereço</h4>
                            <p style="margin-bottom: 0.3rem; font-size: 1.1rem; color: #ffffff;">Avenida das Nações Unidas, 14.401</p>
                            <p style="margin-bottom: 0.3rem; opacity: 0.8;">Tarumã Tower, Salas 712 e 713</p>
                            <p style="opacity: 0.8;">Chácara Santo Antônio, São Paulo - SP</p>
                        </div>

                        <div class="mt-4">
                            <a href="{{ $mapsUrl }}" target="_blank" rel="noopener" class="btn-brand-primary" style="display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; width: fit-content;">
                                Abrir no Google Maps
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 3h7v7"></path><path d="M10 14 21 3"></path><path d="M21 14v4a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path></svg>
                            </a>
                        </div>
                    </div>
                </div>
                <div class="col-lg-7 col-xl-8 order-1 order-lg-2">
                    <div class="map-container">
                        <iframe
                            src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3655.4502951281343!2d-46.70595342358573!3d-23.624039663899975!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x94ce42360bb98d7f%3A0xa4ab8704821d7133!2sBSI%20Capital%20Securitizadora%20S%2FA!5e0!3m2!1spt-BR!2sbr!4v1774380432797!5m2!1spt-BR!2sbr"
                            allowfullscreen=""
                            loading="lazy"
                            referrerpolicy="no-referrer-when-downgrade">
                        </iframe>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

@push('scripts')
<script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
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
    });
</script>
@endpush

@endsection


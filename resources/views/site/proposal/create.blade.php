@extends('site.layout')

@section('title', 'Envie sua Proposta — BSI Capital')

@section('content')
<!-- Hero Section -->
<section class="hero position-relative d-flex align-items-center" style="min-height: 40vh; overflow: hidden; background: #001233;">
    <div class="container position-relative z-1 text-center text-lg-start">
        <div class="row">
            <div class="col-lg-8">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--gold); color: var(--gold); background: rgba(212,175,55, 0.1); letter-spacing: 0.1em; font-weight: 600;">Oportunidade</span>
                <h1 class="display-4 fw-bold mb-3" style="color: #ffffff; letter-spacing: -0.02em;">
                    Envie sua <span style="color: var(--gold);">Proposta</span>
                </h1>
                <p class="lead mb-0" style="color: #a5b4fc; max-width: 80%;">
                    Seja para securitização, estruturação ou novos negócios, preencha o formulário abaixo para iniciarmos uma análise preliminar.
                </p>
            </div>
        </div>
    </div>
</section>

<!-- Form Content -->
<section class="py-5" style="background-color: var(--bg);">
    <div class="container py-lg-5">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="card border-0 shadow-lg rounded-4 overflow-hidden">
                    <div class="card-body p-4 p-md-5">
                        
                        @if(session('success'))
                            <div class="alert alert-success alert-dismissible fade show mb-4 rounded-3 border-0 shadow-sm" role="alert">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 me-3">
                                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                    </div>
                                    <div>{{ session('success') }}</div>
                                </div>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        @endif

                        @if(session('error'))
                            <div class="alert alert-danger alert-dismissible fade show mb-4 rounded-3 border-0 shadow-sm" role="alert">
                                {{ session('error') }}
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        @endif

                        <form action="{{ route('site.proposal.store') }}" method="POST" class="needs-validation" novalidate>
                            @csrf
                            
                            <!-- Dados da Empresa -->
                            <div class="mb-5">
                                <h3 class="h5 fw-bold mb-4 d-flex align-items-center gap-2" style="color: var(--brand);">
                                    <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-light text-brand" style="width: 32px; height: 32px; font-size: 0.9rem;">1</span>
                                    Dados da Empresa
                                </h3>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label small fw-semibold text-muted">CNPJ</label>
                                        <input type="text" name="cnpj" id="cnpj" class="form-control border-light shadow-none bg-light ps-3 py-2 @error('cnpj') is-invalid @enderror" placeholder="00.000.000/0000-00" value="{{ old('cnpj') }}" required>
                                        @error('cnpj') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-semibold text-muted">Nome da Empresa</label>
                                        <input type="text" name="nome_empresa" id="nome_empresa" class="form-control border-light shadow-none bg-light ps-3 py-2 @error('nome_empresa') is-invalid @enderror" placeholder="Razão Social" value="{{ old('nome_empresa') }}" required readonly style="cursor: not-allowed; background-color: #f8f9fa !important;">
                                        @error('nome_empresa') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-semibold text-muted">Inscrição Estadual</label>
                                        <input type="text" name="ie" id="ie" class="form-control border-light shadow-none bg-light ps-3 py-2" placeholder="IE (opcional)" value="{{ old('ie') }}" readonly style="cursor: not-allowed; background-color: #f8f9fa !important;">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-semibold text-muted">Site</label>
                                        <input type="url" name="site" class="form-control border-light shadow-none bg-light ps-3 py-2" placeholder="https://" value="{{ old('site') }}">
                                    </div>
                                    <div class="col-12 mt-3">
                                        <label class="form-label small fw-semibold text-muted d-block mb-3">Setores de Atuação</label>
                                        <div class="d-flex flex-wrap gap-3">
                                            @foreach($sectors as $sector)
                                                <div class="form-check custom-chip">
                                                    <input class="form-check-input d-none" type="radio" name="setores[]" value="{{ $sector->id }}" id="sector{{ $sector->id }}" {{ in_array($sector->id, old('setores', [])) ? 'checked' : '' }}>
                                                    <label class="form-check-label px-3 py-2 rounded-pill border text-muted small fw-medium text-uppercase cursor-pointer" for="sector{{ $sector->id }}" style="transition: all 0.2s ease; cursor: pointer; display: inline-block;">
                                                        {{ $sector->name }}
                                                    </label>
                                                </div>
                                            @endforeach
                                        </div>
                                        @error('setores') <div class="text-danger small mt-2">{{ $message }}</div> @enderror
                                    </div>
                                </div>
                            </div>

                            <hr class="my-5 opacity-10">

                            <!-- Localização -->
                            <div class="mb-5">
                                <h3 class="h5 fw-bold mb-4 d-flex align-items-center gap-2" style="color: var(--brand);">
                                    <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-light text-brand" style="width: 32px; height: 32px; font-size: 0.9rem;">2</span>
                                    Localização
                                </h3>
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <label class="form-label small fw-semibold text-muted">CEP</label>
                                        <input type="text" name="cep" id="cep" class="form-control border-light shadow-none bg-light ps-3 py-2" placeholder="00000-000" value="{{ old('cep') }}" required>
                                    </div>
                                    <div class="col-md-7">
                                        <label class="form-label small fw-semibold text-muted">Logradouro</label>
                                        <input type="text" name="logradouro" id="logradouro" class="form-control border-light shadow-none bg-light ps-3 py-2" placeholder="Rua, Avenida..." value="{{ old('logradouro') }}" required readonly style="cursor: not-allowed; background-color: #f8f9fa !important;">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label small fw-semibold text-muted">Número</label>
                                        <input type="text" name="numero" id="numero" class="form-control border-light shadow-none bg-light ps-3 py-2" placeholder="Nº" value="{{ old('numero') }}" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small fw-semibold text-muted">Complemento</label>
                                        <input type="text" name="complemento" id="complemento" class="form-control border-light shadow-none bg-light ps-3 py-2" placeholder="Apto, Sala..." value="{{ old('complemento') }}">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small fw-semibold text-muted">Bairro</label>
                                        <input type="text" name="bairro" id="bairro" class="form-control border-light shadow-none bg-light ps-3 py-2" placeholder="Bairro" value="{{ old('bairro') }}" required readonly style="cursor: not-allowed; background-color: #f8f9fa !important;">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label small fw-semibold text-muted">Cidade</label>
                                        <input type="text" name="cidade" id="cidade" class="form-control border-light shadow-none bg-light ps-3 py-2" placeholder="Cidade" value="{{ old('cidade') }}" required readonly style="cursor: not-allowed; background-color: #f8f9fa !important;">
                                    </div>
                                    <div class="col-md-1">
                                        <label class="form-label small fw-semibold text-muted">UF</label>
                                        <input type="text" name="estado" id="estado" class="form-control border-light shadow-none bg-light ps-3 py-2 text-center" placeholder="UF" maxlength="2" value="{{ old('estado') }}" required readonly style="cursor: not-allowed; background-color: #f8f9fa !important;">
                                    </div>
                                </div>
                            </div>

                            <hr class="my-5 opacity-10">

                            <!-- Dados de Contato -->
                            <div class="mb-5">
                                <h3 class="h5 fw-bold mb-4 d-flex align-items-center gap-2" style="color: var(--brand);">
                                    <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-light text-brand" style="width: 32px; height: 32px; font-size: 0.9rem;">3</span>
                                    Dados de Contato
                                </h3>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label small fw-semibold text-muted">Nome do Contato</label>
                                        <input type="text" name="nome_contato" class="form-control border-light shadow-none bg-light ps-3 py-2" placeholder="Nome Completo" value="{{ old('nome_contato') }}" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-semibold text-muted">E-mail</label>
                                        <input type="email" name="email" class="form-control border-light shadow-none bg-light ps-3 py-2" placeholder="email@empresa.com.br" value="{{ old('email') }}" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small fw-semibold text-muted">Telefone Pessoal / Celular</label>
                                        <input type="text" name="telefone_pessoal" id="phone_personal" class="form-control border-light shadow-none bg-light ps-3 py-2" placeholder="(00) 00000-0000" value="{{ old('telefone_pessoal') }}" required>
                                    </div>
                                    <div class="col-md-2 d-flex align-items-end px-3 py-2">
                                        <div class="form-check mb-2">
                                            <input class="form-check-input" type="checkbox" name="whatsapp" id="whatsapp" value="1" {{ old('whatsapp') ? 'checked' : '' }}>
                                            <label class="form-check-label small text-muted" for="whatsapp">Tem WhatsApp?</label>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label small fw-semibold text-muted">Telefone da Empresa</label>
                                        <input type="text" name="telefone_empresa" id="phone_company" class="form-control border-light shadow-none bg-light ps-3 py-2" placeholder="(00) 0000-0000" value="{{ old('telefone_empresa') }}">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label small fw-semibold text-muted">Cargo</label>
                                        <input type="text" name="cargo" class="form-control border-light shadow-none bg-light ps-3 py-2" placeholder="Ex: Diretor Financeiro" value="{{ old('cargo') }}">
                                    </div>
                                </div>
                            </div>

                            <hr class="my-5 opacity-10">

                            <!-- Outras Informações -->
                            <div class="mb-5">
                                <h3 class="h5 fw-bold mb-4 d-flex align-items-center gap-2" style="color: var(--brand);">
                                    <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-light text-brand" style="width: 32px; height: 32px; font-size: 0.9rem;">4</span>
                                    Assunto e Observações
                                </h3>
                                <div class="col-12">
                                    <label class="form-label small fw-semibold text-muted">Descreva brevemente sua proposta ou dúvida</label>
                                    <textarea name="observacoes" rows="4" class="form-control border-light shadow-none bg-light ps-3 py-2" placeholder="Mais detalhes sobre o empreendimento ou operação...">{{ old('observacoes') }}</textarea>
                                </div>
                            </div>

                            <div class="text-end mt-5">
                                <button type="submit" class="btn btn-brand btn-lg px-5 shadow-sm">
                                    Enviar Proposta
                                </button>
                            </div>
                        </form>

                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<style>
    .custom-chip input:checked + label {
        background: var(--brand) !important;
        border-color: var(--brand) !important;
        color: #fff !important;
        box-shadow: 0 4px 12px rgba(0,32,91,0.2);
    }
    
    .cursor-pointer { cursor: pointer; }
</style>

@endsection

@push('scripts')
<script src="https://unpkg.com/imask"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        // Masks
        const cnpjMask = IMask(document.getElementById('cnpj'), { mask: '00.000.000/0000-00' });
        IMask(document.getElementById('cep'), { mask: '00000-000' });
        IMask(document.getElementById('phone_personal'), { mask: '(00) 00000-0000' });
        IMask(document.getElementById('phone_company'), { mask: '(00) 0000-0000' });

        // CEP Lookup
        const cepInput = document.getElementById('cep');
        cepInput.addEventListener('blur', function() {
            const cep = this.value.replace(/\D/g, '');
            if (cep.length === 8) {
                fetch(`https://viacep.com.br/ws/${cep}/json/`)
                    .then(res => res.json())
                    .then(data => {
                        if (!data.erro) {
                            document.getElementById('logradouro').value = data.logradouro;
                            document.getElementById('bairro').value = data.bairro;
                            document.getElementById('cidade').value = data.localidade;
                            document.getElementById('estado').value = data.uf;
                        }
                    });
            }
        });

        // CNPJ Lookup (cnpj.ws Public API)
        let lastCheckedCnpj = '';
        cnpjMask.on('accept', function() {
            const cnpj = cnpjMask.unmaskedValue;
            if (cnpj.length === 14 && cnpj !== lastCheckedCnpj) {
                lastCheckedCnpj = cnpj;
                const cnpjInput = document.getElementById('cnpj');
                
                // Visual Indicator
                const originalBg = cnpjInput.style.background;
                cnpjInput.style.background = 'rgba(212,175,55, 0.1)';

                fetch(`https://publica.cnpj.ws/cnpj/${cnpj}`)
                    .then(res => {
                        if (!res.ok) throw new Error('API Error');
                        return res.json();
                    })
                    .then(data => {
                        // Company Name (Razão Social)
                        if (data.razao_social) {
                            document.getElementById('nome_empresa').value = data.razao_social;
                        }

                        // State Registration (Inscrição Estadual)
                        if (data.estabelecimento && data.estabelecimento.inscricoes_estaduais && data.estabelecimento.inscricoes_estaduais.length > 0) {
                            // Find first valid state registration
                            const ieObj = data.estabelecimento.inscricoes_estaduais.find(i => i.inscricao_estadual && i.inscricao_estadual !== '');
                            if (ieObj) {
                                document.getElementById('ie').value = ieObj.inscricao_estadual;
                            }
                        }

                        // Address Fill (bonus for better UX)
                        if (data.estabelecimento) {
                            const est = data.estabelecimento;
                            if (est.cep) {
                                document.getElementById('cep').value = est.cep.replace(/^(\d{5})(\d{3})$/, '$1-$2'); // Formatting mask
                            }
                            if (est.logradouro) document.getElementById('logradouro').value = est.logradouro;
                            if (est.numero) document.getElementById('numero').value = est.numero;
                            if (est.complemento) document.getElementById('complemento').value = est.complemento;
                            if (est.bairro) document.getElementById('bairro').value = est.bairro;
                            if (est.cidade && est.cidade.nome) document.getElementById('cidade').value = est.cidade.nome;
                            if (est.estado && est.estado.sigla) document.getElementById('estado').value = est.estado.sigla;
                            if (est.site) {
                                // Add https:// if missing
                                let site = est.site.toLowerCase();
                                if (!site.startsWith('http')) site = 'https://' + site;
                                document.querySelector('input[name="site"]').value = site;
                            }
                        }
                    })
                    .catch(err => {
                        console.warn('CNPJ lookup failed:', err.message);
                    })
                    .finally(() => {
                        cnpjInput.style.background = originalBg;
                    });
            }
        });
    });
</script>
@endpush

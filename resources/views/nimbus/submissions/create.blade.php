@extends('nimbus.layouts.portal')
@section('title', 'Nova Solicitação')

@section('content')

@php
    $submissionDocumentsTotalMaxBytes = (int) config('uploads.submission.total_max_bytes', 100 * 1024 * 1024);
    $submissionDocumentsTotalMaxMb = (int) ceil($submissionDocumentsTotalMaxBytes / 1024 / 1024);
    $submissionDocumentsTotalErrorMessage = "O tamanho total de todos os arquivos nao pode ultrapassar {$submissionDocumentsTotalMaxMb} MB.";
@endphp

<!-- Header -->
<div class="d-flex align-items-center justify-content-between mb-5">
    <h1 class="h3 fw-bold text-dark mb-0">Nova Solicitação</h1>
    <a href="{{ route('nimbus.dashboard') }}" class="btn btn-light bg-white border shadow-sm text-secondary hover-dark rounded-pill px-4">Cancelar</a>
</div>

@if (request('upload_error') === 'too-large')
    <div class="alert alert-danger shadow-sm rounded-4 border-0 mb-4">
        <i class="bi bi-x-circle-fill me-2"></i>{{ $submissionDocumentsTotalErrorMessage }}
    </div>
@endif

<form method="post" action="{{ route('nimbus.submissions.store') }}" enctype="multipart/form-data" id="submissionForm">
    @csrf
    <input type="hidden" name="shareholders" id="shareholdersData">

    <!-- Stepper -->
    <div class="position-relative mb-5 mx-auto" style="max-width: 600px;">
        <div class="position-absolute top-50 start-0 w-100 translate-middle-y bg-light" style="height: 2px; z-index: 0;"></div>
        <!-- Progress Bar (Dynamic width based on active step) -->
        <div class="position-absolute top-50 start-0 translate-middle-y bg-warning transition-fast" style="height: 2px; width: 0; z-index: 0;" id="stepperProgress"></div>
        
        <div class="d-flex justify-content-between position-relative" style="z-index: 1;">
            <!-- Step 1 -->
            <div class="nd-step-item text-center active" data-target="1">
                <div class="nd-step-box bg-white border border-2 border-warning text-warning fw-bold d-flex align-items-center justify-content-center shadow-sm mb-2 mx-auto transition-fast" 
                     style="width: 48px; height: 48px; font-size: 1.25rem; border-radius: 50%;">1</div>
                <div class="nd-step-label small fw-bold text-dark transition-fast">Dados Iniciais</div>
            </div>
            <!-- Step 2 -->
            <div class="nd-step-item text-center" data-target="2">
                <div class="nd-step-box bg-white border border-2 border-light-subtle text-muted fw-bold d-flex align-items-center justify-content-center mb-2 mx-auto transition-fast" 
                     style="width: 48px; height: 48px; font-size: 1.25rem; border-radius: 50%;">2</div>
                <div class="nd-step-label small text-muted transition-fast">Sócios</div>
            </div>
             <!-- Step 3 -->
            <div class="nd-step-item text-center" data-target="3">
                <div class="nd-step-box bg-white border border-2 border-light-subtle text-muted fw-bold d-flex align-items-center justify-content-center mb-2 mx-auto transition-fast" 
                     style="width: 48px; height: 48px; font-size: 1.25rem; border-radius: 50%;">3</div>
                <div class="nd-step-label small text-muted transition-fast">Documentos</div>
            </div>
        </div>
    </div>

    <!-- STEP 1: Dados -->
    <div class="wizard-step active" data-step="1">
        <!-- Dados da Empresa -->
        <div class="nd-card mb-4 border-0 shadow-sm rounded-4 bg-white">
            <div class="nd-card-header bg-white border-bottom p-4">
                <h5 class="nd-card-title fw-bold text-dark mb-0">Dados da Empresa</h5>
            </div>
            <div class="nd-card-body p-4">
                <div class="row g-4">
                    <div class="col-md-6">
                        <label class="form-label small fw-bold text-secondary text-uppercase ls-1">Nome do Responsável <span class="text-danger">*</span></label>
                        <input type="text" class="form-control bg-light border-0 py-3 px-3 rounded-3 @error('responsible_name') is-invalid @enderror"
                            id="responsible_name" name="responsible_name" required
                            placeholder="Nome completo do responsável"
                            value="{{ old('responsible_name') }}">
                        @error('responsible_name')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-md-6">
                        <label class="form-label small fw-bold text-secondary text-uppercase ls-1">CNPJ <span class="text-danger">*</span></label>
                        <div class="position-relative">
                            <input type="text" class="form-control bg-light border-0 py-3 px-3 rounded-3 pe-5 @error('company_cnpj') is-invalid @enderror"
                                id="company_cnpj" name="company_cnpj" required
                                placeholder="00.000.000/0000-00"
                                value="{{ old('company_cnpj') }}">
                            <button class="btn btn-link position-absolute top-50 end-0 translate-middle-y text-decoration-none pe-3 fw-bold small" 
                                    type="button" id="btnSearchCnpj" style="z-index: 5;">
                                <i class="bi bi-search me-1"></i> Preencher
                            </button>
                        </div>
                        @error('company_cnpj')
                            <div class="d-block invalid-feedback mt-1">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-md-6">
                        <label class="form-label small fw-bold text-secondary text-uppercase ls-1">Nome da Empresa <span class="text-danger">*</span></label>
                        <input type="text" class="form-control bg-light border-0 py-3 px-3 rounded-3 text-muted @error('company_name') is-invalid @enderror"
                            id="company_name" name="company_name" required readonly
                            placeholder="Razão Social"
                            value="{{ old('company_name') }}">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label small fw-bold text-secondary text-uppercase ls-1">Atividade Principal</label>
                        <input type="text" class="form-control bg-light border-0 py-3 px-3 rounded-3 text-muted" id="main_activity" name="main_activity" readonly
                            placeholder="CNAE ou descrição"
                            value="{{ old('main_activity') }}">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label small fw-bold text-secondary text-uppercase ls-1">Telefone <span class="text-danger">*</span></label>
                        <input type="text" class="form-control bg-light border-0 py-3 px-3 rounded-3 @error('phone') is-invalid @enderror"
                            id="phone" name="phone" required
                            placeholder="(00) 0000-0000"
                            value="{{ old('phone') }}">
                    </div>

                    <div class="col-md-8">
                        <label class="form-label small fw-bold text-secondary text-uppercase ls-1">Site</label>
                        <input type="url" class="form-control bg-light border-0 py-3 px-3 rounded-3" id="website" name="website"
                            placeholder="https://www.exemplo.com.br"
                            value="{{ old('website') }}">
                    </div>

                     <div class="col-md-6">
                        <label class="form-label small fw-bold text-secondary text-uppercase ls-1">Patrimônio Líquido <span class="text-danger">*</span></label>
                        <input type="text" class="form-control bg-light border-0 py-3 px-3 rounded-3 money @error('net_worth') is-invalid @enderror"
                            id="net_worth" name="net_worth" required
                            placeholder="R$ 0,00"
                            value="{{ old('net_worth') }}">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label small fw-bold text-secondary text-uppercase ls-1">Último Faturamento Anual <span class="text-danger">*</span></label>
                        <input type="text" class="form-control bg-light border-0 py-3 px-3 rounded-3 money @error('annual_revenue') is-invalid @enderror"
                            id="annual_revenue" name="annual_revenue" required
                            placeholder="R$ 0,00"
                            value="{{ old('annual_revenue') }}">
                    </div>
                </div>
            </div>
        </div>

        <!-- Seus Dados -->
        <div class="nd-card mb-4 border-0 shadow-sm rounded-4 bg-white">
            <div class="nd-card-header bg-white border-bottom p-4">
                <h5 class="nd-card-title fw-bold text-dark mb-0">Seus Dados</h5>
            </div>
            <div class="nd-card-body p-4">
                <div class="row g-4">
                    <div class="col-md-6">
                        <label class="form-label small fw-bold text-secondary text-uppercase ls-1">Nome Completo <span class="text-danger">*</span></label>
                        <input type="text" class="form-control bg-light border-0 py-3 px-3 rounded-3 @error('registrant_name') is-invalid @enderror"
                            id="registrant_name" name="registrant_name" required
                            placeholder="Seu nome completo"
                            value="{{ old('registrant_name') }}">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label small fw-bold text-secondary text-uppercase ls-1">Cargo</label>
                        <input type="text" class="form-control bg-light border-0 py-3 px-3 rounded-3" id="registrant_position" name="registrant_position"
                            placeholder="Seu cargo na empresa"
                            value="{{ old('registrant_position') }}">
                    </div>

                    <div class="col-md-6">
                         <label class="form-label small fw-bold text-secondary text-uppercase ls-1">RG</label>
                         <input type="text" class="form-control bg-light border-0 py-3 px-3 rounded-3" id="registrant_rg" name="registrant_rg"
                            placeholder="00.000.000-0"
                            value="{{ old('registrant_rg') }}">
                    </div>

                    <div class="col-md-6">
                         <label class="form-label small fw-bold text-secondary text-uppercase ls-1">CPF <span class="text-danger">*</span></label>
                         <input type="text" class="form-control bg-light border-0 py-3 px-3 rounded-3 @error('registrant_cpf') is-invalid @enderror"
                            id="registrant_cpf" name="registrant_cpf" required
                            placeholder="000.000.000-00"
                            value="{{ old('registrant_cpf') }}">
                    </div>
                </div>
            </div>
        </div>

        <!-- Declarações -->
        <div class="nd-card mb-5 border-0 shadow-sm rounded-4 bg-white">
            <div class="nd-card-header bg-white border-bottom p-4">
                <h5 class="nd-card-title fw-bold text-dark mb-0">Declarações <span class="text-danger">*</span></h5>
            </div>
            <div class="nd-card-body p-4">
                 <div class="d-flex gap-4 flex-wrap">
                    <div class="form-check nd-checkbox">
                        <input class="form-check-input" type="checkbox" id="is_us_person" name="is_us_person" value="1"
                            {{ old('is_us_person') ? 'checked' : '' }}>
                        <label class="form-check-label text-secondary fw-medium" for="is_us_person">
                            Sou US Person
                        </label>
                    </div>
                    <div class="form-check nd-checkbox">
                        <input class="form-check-input" type="checkbox" id="is_pep" name="is_pep" value="1"
                            {{ old('is_pep') ? 'checked' : '' }}>
                        <label class="form-check-label text-secondary fw-medium" for="is_pep">
                            Sou PEP (Pessoa Exposta Politicamente)
                        </label>
                    </div>
                    <div class="form-check nd-checkbox">
                        <input class="form-check-input" type="checkbox" id="is_none_compliant" name="is_none_compliant" value="1"
                            {{ old('is_none_compliant') ? 'checked' : '' }}>
                        <label class="form-check-label text-secondary fw-medium" for="is_none_compliant">
                            Não me enquadro nas opções
                        </label>
                    </div>
                </div>
                 <div id="complianceError" class="text-danger small mt-2 fw-bold" role="alert" style="display: none;">
                    <i class="bi bi-exclamation-circle me-1"></i> Selecione pelo menos uma opção.
                </div>

                <div class="mt-4 pt-4 border-top">
                    <label class="form-label small fw-bold text-secondary text-uppercase ls-1 d-block mb-3">
                        Você é filiado à Anbima? <span class="text-danger">*</span>
                    </label>
                    <div class="d-flex gap-4 flex-wrap">
                        <div class="form-check">
                            <input
                                class="form-check-input @error('is_anbima_affiliated') is-invalid @enderror"
                                type="radio"
                                id="is_anbima_affiliated_yes"
                                name="is_anbima_affiliated"
                                value="1"
                                {{ old('is_anbima_affiliated') == '1' ? 'checked' : '' }}
                            >
                            <label class="form-check-label text-secondary fw-medium" for="is_anbima_affiliated_yes">
                                Sim
                            </label>
                        </div>
                        <div class="form-check">
                            <input
                                class="form-check-input @error('is_anbima_affiliated') is-invalid @enderror"
                                type="radio"
                                id="is_anbima_affiliated_no"
                                name="is_anbima_affiliated"
                                value="0"
                                {{ old('is_anbima_affiliated') == '0' ? 'checked' : '' }}
                            >
                            <label class="form-check-label text-secondary fw-medium" for="is_anbima_affiliated_no">
                                Não
                            </label>
                        </div>
                    </div>
                    <div
                        id="anbimaAffiliationError"
                        class="text-danger small mt-2 fw-bold"
                        role="alert"
                        style="{{ $errors->has('is_anbima_affiliated') ? 'display: block;' : 'display: none;' }}"
                    >
                        <i class="bi bi-exclamation-circle me-1"></i>
                        @error('is_anbima_affiliated')
                            {{ $message }}
                        @else
                            Selecione uma opção.
                        @enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-end mb-5">
            <button type="button" class="nd-btn nd-btn-gold shadow px-5 py-3 rounded-pill btn-next hover-scale" data-next="2">
                <span class="fw-bold text-uppercase ls-1">Próximo Passo</span> <i class="bi bi-arrow-right ms-2"></i>
            </button>
        </div>
    </div>

    <!-- STEP 2: Socios -->
    <div class="wizard-step" data-step="2">
         <div class="nd-card mb-4 border-0 shadow-sm rounded-4 bg-white">
            <div class="nd-card-header bg-white border-bottom p-4 d-flex justify-content-between align-items-center">
                <h5 class="nd-card-title fw-bold text-dark mb-0">Composição Societária</h5>
                <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3" id="btnAddShareholder">
                    <i class="bi bi-plus-lg me-1"></i> Incluir Sócio
                </button>
            </div>
            <div class="nd-card-body p-4">
                 <div id="shareholdersList" class="d-flex flex-column gap-3"></div>

                 <div class="mt-4 p-4 bg-light rounded-4 border border-light-subtle d-flex justify-content-between align-items-center">
                    <span class="text-secondary fw-bold text-uppercase ls-1 small">Total da Participação</span>
                    <div class="text-end">
                        <div class="display-6 fw-bold text-dark me-2"><span id="totalPercentage">0.00</span><span class="fs-4">%</span></div>
                        <div id="percentageWarning" class="text-danger x-small fw-bold mt-1" style="display: none;">
                            <i class="bi bi-exclamation-circle me-1"></i>A soma deve ser 100%
                        </div>
                    </div>
                </div>
            </div>
         </div>

        <div class="d-flex justify-content-between mb-5">
            <button type="button" class="btn btn-outline-secondary rounded-pill px-4 py-3 btn-prev fw-bold text-uppercase ls-1" data-prev="1">
                <i class="bi bi-arrow-left me-2"></i> Voltar
            </button>
            <button type="button" class="nd-btn nd-btn-gold shadow px-5 py-3 rounded-pill btn-next hover-scale" data-next="3">
                <span class="fw-bold text-uppercase ls-1">Próximo Passo</span> <i class="bi bi-arrow-right ms-2"></i>
            </button>
        </div>
    </div>

    <!-- STEP 3: Documentos -->
    <div class="wizard-step" data-step="3">
         <div class="nd-card mb-4 border-0 shadow-sm rounded-4 bg-white">
            <div class="nd-card-header bg-white border-bottom p-4">
                <h5 class="nd-card-title fw-bold text-dark mb-0">Documentos (PDF)</h5>
            </div>
            <div class="nd-card-body p-4">
                <div class="alert alert-warning border-0 rounded-4 mb-4">
                    <div class="fw-bold mb-1">Tamanho maximo total dos arquivos</div>
                    <div>{{ $submissionDocumentsTotalErrorMessage }}</div>
                </div>

                <div class="d-flex flex-column gap-2 mb-4">
                    <div class="small text-secondary fw-medium">
                        Total selecionado:
                        <span class="fw-bold text-dark" id="documentsTotalSizeValue">0 MB</span>
                        de {{ $submissionDocumentsTotalMaxMb }} MB.
                    </div>

                    <div
                        id="documentsTotalSizeError"
                        data-has-server-error="{{ $errors->has('documents_total_size') || request('upload_error') === 'too-large' ? 'true' : 'false' }}"
                        class="small fw-bold text-danger"
                        role="alert"
                        style="{{ $errors->has('documents_total_size') || request('upload_error') === 'too-large' ? 'display: block;' : 'display: none;' }}"
                    >
                        <i class="bi bi-exclamation-circle me-1"></i>
                        @error('documents_total_size')
                            {{ $message }}
                        @else
                            {{ $submissionDocumentsTotalErrorMessage }}
                        @enderror
                    </div>
                </div>

                <div class="row g-4">
                    @php
                    $docs = [
                        'ultimo_balanco' => ['label' => 'Último Balanço', 'required' => true],
                        'dre' => ['label' => 'DRE (Demonstração do Resultado do Exercício)', 'required' => true],
                        'politicas' => ['label' => 'Políticas', 'required' => true],
                        'cartao_cnpj' => ['label' => 'Cartão CNPJ', 'required' => true],
                        'procuracao' => ['label' => 'Procuração (Caso houver)', 'required' => false],
                        'ata' => ['label' => 'Ata de eleição de diretoria', 'required' => false],
                        'contrato_social' => ['label' => 'Contrato Social', 'required' => true],
                        'estatuto' => ['label' => 'Estatuto', 'required' => true],
                    ];
                    @endphp
                    @foreach ($docs as $name => $document)
                    <div class="col-md-6">
                        <label class="form-label small fw-bold text-secondary text-uppercase ls-1 mb-2">
                            {{ $document['label'] }}
                            @if ($document['required'])
                                <span class="text-danger">*</span>
                            @endif
                        </label>
                        <input type="file" class="form-control bg-light border-0 py-3 px-3 rounded-3 @error($name) is-invalid @enderror"
                            id="{{ $name }}" name="{{ $name }}" accept=".pdf" data-submission-document="true" @required($document['required'])>
                        @error($name)
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    @endforeach
                </div>
            </div>
         </div>

        <div class="d-flex justify-content-between mb-5">
             <button type="button" class="btn btn-outline-secondary rounded-pill px-4 py-3 btn-prev fw-bold text-uppercase ls-1" data-prev="2">
                <i class="bi bi-arrow-left me-2"></i> Voltar
            </button>
            <div class="d-flex gap-3">
                 <a href="{{ route('nimbus.submissions.index') }}" class="btn btn-light bg-white border text-muted shadow-sm rounded-pill px-4 py-3 fw-bold text-uppercase ls-1">Cancelar</a>
                <button type="submit" class="nd-btn nd-btn-gold shadow px-5 py-3 rounded-pill btn-next hover-scale">
                    <i class="bi bi-send me-2"></i> <span class="fw-bold text-uppercase ls-1">Enviar Solicitação</span>
                </button>
            </div>
        </div>
    </div>

</form>

@endsection

@push('scripts')
<!-- jQuery, InputMask para funcionamento do wizard de submissão -->
<script src="{{ asset('assets/nimbus/js/nimbusdocs-utils.js') }}"></script>
<script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
    window.SubmissionConfig = {
        shareholders: {!! json_encode(session('old_shareholders', [])) !!},
        csrfToken: "{{ csrf_token() }}",
        cnpjLookupUrl: "{{ route('nimbus.submissions.cnpj-lookup') }}",
        submissionDocumentTotalMaxBytes: {{ $submissionDocumentsTotalMaxBytes }},
        submissionDocumentTotalErrorMessage: @json($submissionDocumentsTotalErrorMessage)
    };
</script>
<script src="{{ asset('assets/nimbus/js/submission-wizard.js') }}"></script>
@endpush

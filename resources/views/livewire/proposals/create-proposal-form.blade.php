<section class="py-5 bg-transparent">
    <div class="container py-4">
        <!-- BREADCRUMB -->
        <div class="mb-4">
            <a href="{{ route('site.partnerships') }}" class="d-inline-flex align-items-center gap-2 text-decoration-none fw-semibold" style="color: var(--brand);">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg>
                Parcerias
            </a>
        </div>

        <!-- HEADER CARD -->
        <div class="surface-card p-4 p-lg-5 mb-5 border-0 shadow-sm" style="background: var(--brand-strong); color: #fff;">
            <div class="row align-items-center g-5">
                <div class="col-lg-7">
                    <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--gold); color: var(--gold); background: rgba(212,175,55, 0.1); letter-spacing: 0.1em; font-weight: 600;">Oportunidade</span>
                    <h1 class="display-3 fw-bold mb-4" style="color: #ffffff; letter-spacing: -0.02em;">
                        Solicitar Análise de <span style="color: var(--gold);">Operação</span>
                    </h1>
                    <p class="lead mb-0" style="color: #E6E4E4; max-width: 600px;">
                        Preencha as informações iniciais da empresa e da oportunidade para que a BSI Capital avalie o enquadramento preliminar da operação. Após o envio, você receberá um link seguro para complementar os dados e anexar documentos, quando aplicável.
                    </p>
                </div>
                <div class="col-lg-5">
                    <div class="d-flex flex-column gap-3">
                        <div class="p-4" style="background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 16px;">
                            <div class="d-flex align-items-center gap-3 mb-2">
                                <div class="badge bg-gold text-brand-strong rounded-circle p-0 d-flex align-items-center justify-content-center" style="width: 24px; height: 24px;">1</div>
                                <div class="fs-6 fw-bold">Cadastro inicial da empresa</div>
                            </div>
                            <div class="d-flex align-items-center gap-3 mb-2">
                                <div class="badge bg-gold text-brand-strong rounded-circle p-0 d-flex align-items-center justify-content-center" style="width: 24px; height: 24px;">2</div>
                                <div class="fs-6 fw-bold">Triagem preliminar</div>
                            </div>
                            <div class="d-flex align-items-center gap-3 mb-2">
                                <div class="badge bg-gold text-brand-strong rounded-circle p-0 d-flex align-items-center justify-content-center" style="width: 24px; height: 24px;">3</div>
                                <div class="fs-6 fw-bold">Complementação por link seguro</div>
                            </div>
                            <div class="d-flex align-items-center gap-3 mb-3">
                                <div class="badge bg-gold text-brand-strong rounded-circle p-0 d-flex align-items-center justify-content-center" style="width: 24px; height: 24px;">4</div>
                                <div class="fs-6 fw-bold">Avaliação técnica da operação</div>
                            </div>
                            <div class="small" style="color: #E6E4E4;">
                                O envio inicial permite registrar a empresa e direcionar a oportunidade para triagem. A continuidade da análise dependerá do enquadramento preliminar, das informações complementares e dos documentos enviados.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- NOTIFICATIONS -->
        @if (session('success'))
            <div class="alert alert-success d-flex align-items-center mb-4" role="alert" style="border-radius: 16px; border: 1px solid rgba(25, 135, 84, 0.2); background: rgba(25, 135, 84, 0.05);">
                <div>{{ session('success') }}</div>
            </div>
        @endif

        @error('submission')
            <div class="alert alert-danger d-flex align-items-center mb-4" role="alert" style="border-radius: 16px; border: 1px solid rgba(220, 53, 69, 0.2); background: rgba(220, 53, 69, 0.05);">
                <div>{{ $message }}</div>
            </div>
        @enderror

        @if ($errors->any() && ! $errors->has('submission'))
            <div class="alert alert-danger d-flex align-items-center mb-4" role="alert" style="border-radius: 16px; border: 1px solid rgba(220, 53, 69, 0.2); background: rgba(220, 53, 69, 0.05);">
                <div class="fw-semibold">Revise os campos destacados antes de continuar.</div>
            </div>
        @endif

        <!-- FORM -->
        <form wire:submit="save">
            <!-- ANTES DE ENVIAR -->
            <div class="surface-card p-4 p-lg-4 mb-4 border-0 shadow-sm" style="background: rgba(0, 32, 91, 0.03); border-left: 4px solid var(--brand) !important;">
                <h2 class="h5 fw-bold text-brand mb-2">Antes de enviar sua operação</h2>
                <p class="small text-muted mb-0">
                    Este formulário é destinado à apresentação inicial de oportunidades relacionadas a securitização, crédito estruturado, CRI, CRA, CR, recebíveis, operações imobiliárias, agronegócio, infraestrutura, empresas ou parcerias estratégicas. O envio não representa aprovação, compromisso de estruturação ou garantia de continuidade da operação.
                </p>
            </div>
            <!-- SECTION 1: DADOS DA EMPRESA -->
            <div class="surface-card p-4 p-lg-5 mb-4 border-0">
                <div class="mb-4 pb-4 border-bottom border-brand-subtle d-flex flex-column flex-md-row align-items-md-center justify-content-md-between gap-3">
                    <div>
                        <div class="section-kicker mb-2">Etapa 1</div>
                        <h2 class="h3 fw-bold text-brand mb-0">Dados iniciais da empresa</h2>
                    </div>
                    <p class="text-muted mb-0" style="max-width: 400px; font-size: 0.9rem;">
                        Informe o CNPJ para apoiar o preenchimento inicial dos dados cadastrais. As informações poderão ser revisadas antes do envio.
                    </p>
                </div>

                <div class="row g-4">
                    <div class="col-md-6">
                        <label class="form-label">CNPJ</label>
                        <input type="text" class="form-control" wire:model.live.debounce.500ms="form.cnpj" x-mask="99.999.999/9999-99" inputmode="numeric" placeholder="00.000.000/0000-00">
                        <div wire:loading wire:target="form.cnpj" class="form-text text-brand">Consultando dados públicos do CNPJ...</div>
                        @error('form.cnpj') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Nome da Empresa</label>
                        <input type="text" class="form-control" wire:model.blur="form.companyName" placeholder="Razão Social">
                        @error('form.companyName') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Inscrição Estadual</label>
                        <input type="text" class="form-control" wire:model.blur="form.stateRegistration" placeholder="IE (opcional)">
                        @error('form.stateRegistration') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Site</label>
                        <input type="url" class="form-control" wire:model.blur="form.website" placeholder="https://">
                        @error('form.website') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-12">
                        <label class="form-label">Setor de Atuação</label>
                        <div class="form-text mt-0 mb-3">Selecione o setor ligado à sua proposta.</div>

                        <div class="d-flex flex-wrap gap-2">
                            @foreach ($sectors as $sector)
                                <input type="radio" class="btn-check" name="sectorId" id="sector-{{ $sector->id }}" value="{{ $sector->id }}" wire:model.live="form.sectorId">
                                <label class="btn btn-outline-brand rounded-pill" for="sector-{{ $sector->id }}" style="font-weight: 600;">
                                    {{ $sector->name }}
                                </label>
                            @endforeach
                        </div>

                        @error('form.sectorId') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                    </div>
                </div>
            </div>

            <!-- SECTION 2: LOCALIZAÇÃO -->
            <div class="surface-card p-4 p-lg-5 mb-4 border-0">
                <div class="mb-4 pb-4 border-bottom border-brand-subtle d-flex flex-column flex-md-row align-items-md-center justify-content-md-between gap-3">
                    <div>
                        <div class="section-kicker mb-2">Etapa 2</div>
                        <h2 class="h3 fw-bold text-brand mb-0">Localização</h2>
                    </div>
                    <p class="text-muted mb-0" style="max-width: 400px; font-size: 0.9rem;">
                        Informe o CEP para apoiar o preenchimento do endereço da empresa. Os dados poderão ser ajustados manualmente antes do envio.
                    </p>
                </div>

                <div class="row g-4">
                    <div class="col-md-3">
                        <label class="form-label">CEP</label>
                        <input type="text" class="form-control" wire:model.live.debounce.500ms="form.postalCode" x-mask="99999-999" inputmode="numeric" placeholder="00000-000">
                        <div wire:loading wire:target="form.postalCode" class="form-text text-brand">Buscando endereço pelo CEP...</div>
                        @error('form.postalCode') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Logradouro</label>
                        <input type="text" class="form-control" wire:model.blur="form.street" placeholder="Rua, Avenida...">
                        @error('form.street') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Número</label>
                        <input type="text" class="form-control" wire:model.blur="form.addressNumber" placeholder="Nº">
                        @error('form.addressNumber') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Complemento</label>
                        <input type="text" class="form-control" wire:model.blur="form.addressComplement" placeholder="Apto, Sala...">
                        @error('form.addressComplement') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Bairro</label>
                        <input type="text" class="form-control" wire:model.blur="form.neighborhood" placeholder="Bairro">
                        @error('form.neighborhood') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Cidade</label>
                        <input type="text" class="form-control" wire:model.blur="form.city" placeholder="Cidade">
                        @error('form.city') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-md-1">
                        <label class="form-label">UF</label>
                        <input type="text" class="form-control" wire:model.blur="form.state" maxlength="2" placeholder="UF" style="text-transform: uppercase;">
                        @error('form.state') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                    </div>
                </div>
            </div>

            <!-- SECTION 3: DADOS DE CONTATO -->
            <div class="surface-card p-4 p-lg-5 mb-4 border-0">
                <div class="mb-4 pb-4 border-bottom border-brand-subtle d-flex flex-column flex-md-row align-items-md-center justify-content-md-between gap-3">
                    <div>
                        <div class="section-kicker mb-2">Etapa 3</div>
                        <h2 class="h3 fw-bold text-brand mb-0">Contato responsável pela proposta</h2>
                    </div>
                    <p class="text-muted mb-0" style="max-width: 400px; font-size: 0.9rem;">
                        Este contato será utilizado para o envio do link seguro e para eventuais comunicações relacionadas à análise preliminar.
                    </p>
                </div>

                <div class="row g-4">
                    <div class="col-md-6">
                        <label class="form-label">Nome do Contato</label>
                        <input type="text" class="form-control" wire:model.blur="form.contactName" placeholder="Nome completo">
                        @error('form.contactName') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">E-mail</label>
                        <input type="email" class="form-control" wire:model.blur="form.email" placeholder="email@empresa.com.br">
                        @error('form.email') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Celular para contato</label>
                        <input type="tel" class="form-control" wire:model.blur="form.personalPhone" data-phone-mask inputmode="tel" placeholder="(00) 00000-0000">
                        @error('form.personalPhone') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Telefone da Empresa</label>
                        <input type="tel" class="form-control" wire:model.blur="form.companyPhone" data-phone-mask inputmode="tel" placeholder="(00) 0000-0000">
                        @error('form.companyPhone') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Cargo</label>
                        <input type="text" class="form-control" wire:model.blur="form.jobTitle" placeholder="Ex: Diretor Financeiro">
                        @error('form.jobTitle') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-12 mt-4 mt-md-5">
                        <div class="p-4" style="border-radius: 16px; background: color-mix(in srgb, var(--surface-alt) 50%, transparent); border: 1px solid var(--border);">
                            <div class="d-flex align-items-center gap-3">
                                <div class="form-check form-switch fs-4 mb-0 pb-0">
                                    <input class="form-check-input" type="checkbox" role="switch" id="hasWhatsappCheck" wire:model.live="form.hasWhatsapp">
                                </div>
                                <label class="form-check-label ms-1" for="hasWhatsappCheck" style="cursor: pointer;">
                                    <div class="fw-bold text-text mb-0" style="line-height:1.2;">Autorizo que a BSI Capital utilize este número para contato relacionado à proposta enviada.</div>
                                    <div class="text-muted small mt-1">Essa autorização é opcional e poderá ser utilizada apenas para comunicações relacionadas à oportunidade apresentada.</div>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SECTION 4: ASSUNTO E OBSERVAÇÕES -->
            <div class="surface-card p-4 p-lg-5 mb-4 border-0">
                <div class="mb-4 pb-4 border-bottom border-brand-subtle d-flex flex-column flex-md-row align-items-md-center justify-content-md-between gap-3">
                    <div>
                        <div class="section-kicker mb-2">Etapa 4</div>
                        <h2 class="h3 fw-bold text-brand mb-0">Informações iniciais da oportunidade</h2>
                    </div>
                </div>

                <div>
                    <label class="form-label">Descreva brevemente a operação, o ativo ou a oportunidade apresentada</label>
                    <textarea class="form-control" wire:model.blur="form.observations" rows="5" placeholder="Informe o tipo de operação, setor, valor estimado, estágio atual, lastro ou recebíveis envolvidos e principais pontos que devem ser avaliados. Ex: Existe originador envolvido? Há documentos preliminares?"></textarea>
                    @error('form.observations') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                </div>
            </div>

            <!-- SUBMIT BUTTON -->
            <div class="surface-card p-4 p-lg-5 mb-5 border-0" style="background: var(--brand-strong); color: #fff;">
                <div class="row align-items-center g-4">
                    <div class="col-lg-8">
                        <div class="section-kicker mb-2">Próximo passo</div>
                        <h2 class="h3 fw-bold text-white mb-3">Enviar para análise preliminar</h2>
                        <p class="mb-3" style="color: #E6E4E4; font-size: 1.05rem;">
                            Após o envio, você receberá um link seguro para complementar as informações da oportunidade e anexar documentos, quando aplicável. A continuidade da análise dependerá do enquadramento preliminar e da documentação disponibilizada.
                        </p>
                        <p class="small text-white-50 mb-0" style="font-size: 0.8rem; line-height: 1.4;">
                            As informações enviadas serão tratadas conforme a Política de Privacidade da BSI Capital, as normas aplicáveis e as rotinas internas de confidencialidade e governança da informação.
                        </p>
                    </div>
                    <div class="col-lg-4 text-lg-end mt-4 mt-lg-0">
                        <button type="submit" class="btn px-4 py-3 w-100 w-lg-auto" wire:loading.attr="disabled" wire:target="save" style="background: var(--gold); border-color: var(--brand-strong); color: var(--brand-strong); font-weight: 700; border-radius: 999px;">
                            <span wire:loading.remove wire:target="save">Enviar para análise preliminar</span>
                            <span wire:loading wire:target="save">Registrando solicitação...</span>
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</section>

<script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
    document.addEventListener('input', function(e) {
        if (e.target.hasAttribute('data-phone-mask')) {
            let v = e.target.value.replace(/\D/g, '').substring(0, 11);
            v = v.replace(/^(\d{2})(\d)/g, '($1) $2');
            v = v.replace(/(\d)(\d{4})$/, '$1-$2');
            e.target.value = v;
        }
    });
</script>

<section class="py-5 bg-transparent">
    <div class="container py-4">
        <!-- HEADER CARD -->
        <div class="surface-card p-4 p-lg-5 mb-5 border-0 shadow-sm" style="background: linear-gradient(135deg, var(--brand-strong), #0b1f4f); color: #fff;">
            <div class="row align-items-center g-5">
                <div class="col-lg-7">
                    <span class="badge badge-soft px-3 py-2 mb-3">OPORTUNIDADE</span>
                    <h1 class="display-5 fw-bold mb-3">
                        Envie sua <span style="color: var(--gold);">Proposta</span>
                    </h1>
                    <p class="lead text-white-50 mb-0" style="font-size: 1.1rem; max-width: 600px;">
                        Seja para securitização, estruturação ou novos negócios, preencha o formulário abaixo para iniciarmos uma análise preliminar.
                    </p>
                </div>
                <div class="col-lg-5">
                    <div class="d-flex flex-column gap-3">
                        <div class="p-4" style="background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 16px;">
                            <div class="kicker mb-2" style="color: var(--gold);">FLUXO</div>
                            <div class="fs-5 fw-bold mb-1">Cadastro inicial</div>
                            <div class="small text-white-50">Os dados da empresa e do contato são registrados nesta primeira etapa.</div>
                        </div>
                        <div class="p-4" style="background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 16px;">
                            <div class="kicker mb-2" style="color: var(--gold);">CONTINUAÇÃO</div>
                            <div class="fs-5 fw-bold mb-1">Link seguro por e-mail</div>
                            <div class="small text-white-50">Depois do envio, você recebe um acesso protegido para complementar as informações.</div>
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
            <!-- SECTION 1: DADOS DA EMPRESA -->
            <div class="surface-card p-4 p-lg-5 mb-4 border-0">
                <div class="mb-4 pb-4 border-bottom border-brand-subtle d-flex flex-column flex-md-row align-items-md-center justify-content-md-between gap-3">
                    <div>
                        <div class="section-kicker mb-2">Etapa 1</div>
                        <h2 class="h3 fw-bold text-brand mb-0">Dados da Empresa</h2>
                    </div>
                    <p class="text-muted mb-0" style="max-width: 400px; font-size: 0.9rem;">
                        Informe o CNPJ para preencher a base inicial e ajuste os dados manualmente se necessário.
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
                        Ao informar o CEP, o endereço é completado automaticamente e pode ser ajustado antes do envio.
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
                        <h2 class="h3 fw-bold text-brand mb-0">Dados de Contato</h2>
                    </div>
                    <p class="text-muted mb-0" style="max-width: 400px; font-size: 0.9rem;">
                        Este contato receberá o link seguro para continuar o preenchimento da proposta.
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
                        <label class="form-label">Telefone Pessoal / Celular</label>
                        <input type="tel" class="form-control" wire:model.blur="form.personalPhone" x-mask:dynamic="$input.replace(/\D/g, '').length > 10 ? '(99) 99999-9999' : '(99) 9999-9999'" inputmode="tel" placeholder="(00) 00000-0000">
                        @error('form.personalPhone') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Telefone da Empresa</label>
                        <input type="tel" class="form-control" wire:model.blur="form.companyPhone" x-mask:dynamic="$input.replace(/\D/g, '').length > 10 ? '(99) 99999-9999' : '(99) 9999-9999'" inputmode="tel" placeholder="(00) 0000-0000">
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
                                    <div class="fw-bold text-text mb-0" style="line-height:1.2;">Este contato recebe mensagens por WhatsApp</div>
                                    <div class="text-muted small mt-1">Use esta opção para facilitar o retorno do time comercial.</div>
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
                        <h2 class="h3 fw-bold text-brand mb-0">Assunto e Observações</h2>
                    </div>
                </div>

                <div>
                    <label class="form-label">Descreva brevemente sua proposta ou dúvida</label>
                    <textarea class="form-control" wire:model.blur="form.observations" rows="5" placeholder="Mais detalhes sobre o empreendimento ou operação..."></textarea>
                    @error('form.observations') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                </div>
            </div>

            <!-- SUBMIT BUTTON -->
            <div class="surface-card p-4 p-lg-5 mb-5 border-0" style="background: linear-gradient(135deg, var(--brand-strong), #0b1f4f); color: #fff;">
                <div class="row align-items-center g-4">
                    <div class="col-lg-8">
                        <div class="kicker mb-2" style="color: var(--gold);">PRÓXIMO PASSO</div>
                        <p class="mb-0 text-white-50" style="font-size: 1.05rem;">
                            Após o envio, você receberá um link seguro para complementar os dados do empreendimento e anexar os documentos necessários.
                        </p>
                    </div>
                    <div class="col-lg-4 text-lg-end">
                        <button type="submit" class="btn px-4 py-2 w-100 w-lg-auto" wire:loading.attr="disabled" wire:target="save" style="background: var(--gold); border-color: var(--gold); color: var(--brand-strong); font-weight: 700; border-radius: 999px;">
                            <span wire:loading.remove wire:target="save">Enviar Proposta</span>
                            <span wire:loading wire:target="save">Enviando...</span>
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</section>


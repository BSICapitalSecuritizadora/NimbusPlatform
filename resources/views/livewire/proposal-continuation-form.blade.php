<section class="proposal-page py-5">
    <div class="container py-lg-4">
        <div class="row justify-content-center">
            <div class="col-xl-10">
                <div class="d-flex flex-column gap-4">
                    @if ($successMessage || session('success'))
                        <div class="alert alert-success border-0 shadow-sm rounded-4 px-4 py-3 mb-0">
                            {{ $successMessage ?? session('success') }}
                        </div>
                    @endif

                    @if ($errors->any() && ! $showReadonlySummary)
                        <div class="alert alert-danger border-0 shadow-sm rounded-4 px-4 py-3 mb-0">
                            <strong class="d-block mb-2">Revise os campos destacados antes de salvar.</strong>

                            <ul class="mb-0 ps-3">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <div class="card proposal-card proposal-hero-card border-0">
                        <div class="card-body p-4 p-lg-5">
                            <div class="row g-4 align-items-start">
                                <div class="col-lg-8">
                                    <div class="proposal-eyebrow mb-3">Portal da Proposta</div>
                                    <h1 class="proposal-title mb-2">Formulário de Empreendimento</h1>
                                    <div class="proposal-subtitle fs-5">{{ $proposal->company->name }} • {{ $proposal->company->cnpj }}</div>
                                    <div class="proposal-subtitle mt-3">
                                        Acompanhe os dados enviados e, quando necessário, complemente as informações do empreendimento no mesmo padrão visual da plataforma.
                                    </div>
                                </div>

                                <div class="col-lg-4 text-lg-end">
                                    <div class="status-label">Status Atual</div>
                                    <span class="status-pill">{{ $proposal->status_label }}</span>
                                </div>
                            </div>

                            <div class="row g-3 mt-2">
                                <div class="col-md-4">
                                    <div class="hero-meta-card">
                                        <div class="meta-label">Empreendimentos</div>
                                        <div class="meta-value">{{ $projectCount }}</div>
                                        <div class="meta-caption small">Itens vinculados à proposta atual.</div>
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <div class="hero-meta-card">
                                        <div class="meta-label">Arquivos Enviados</div>
                                        <div class="meta-value">{{ $fileCount }}</div>
                                        <div class="meta-caption small">Documentos compartilhados no fluxo.</div>
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <div class="hero-meta-card">
                                        <div class="meta-label">Última Atualização</div>
                                        <div class="meta-value">{{ $proposal->completed_at?->format('d/m/Y H:i') ?? 'Em preenchimento' }}</div>
                                        <div class="meta-caption small">Registro mais recente disponível nesta proposta.</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    @if ($showReadonlySummary)
                        <div class="card proposal-card border-0">
                            <div class="card-body p-4 p-lg-5">
                                <div class="row g-4 align-items-center">
                                    <div class="col-lg-7">
                                        <div class="section-kicker mb-2">Acompanhamento</div>
                                        <h2 class="section-title">Resumo do envio</h2>
                                        <p class="section-copy mb-0">
                                            Recebemos as informações da sua proposta. A equipe comercial seguirá com a análise interna e manterá o acompanhamento pelos próximos passos do processo.
                                        </p>
                                    </div>

                                    <div class="col-lg-5">
                                        <div class="summary-highlight">
                                            <strong class="d-block mb-2">Ambiente seguro para o proponente</strong>
                                            <p>Os dados de análise comercial, indicadores internos e parâmetros do time de vendas permanecem restritos ao painel administrativo.</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card proposal-card border-0">
                            <div class="card-body p-4 p-lg-5">
                                <div class="row g-4 align-items-start mb-4">
                                    <div class="col-lg-7">
                                        <div class="section-kicker mb-2">Etapa 1</div>
                                        <h2 class="section-title">Cadastro Inicial</h2>
                                        <p class="section-copy mb-0">Dados institucionais e de contato compartilhados no primeiro envio da proposta.</p>
                                    </div>
                                </div>

                                <div class="row g-4">
                                    <div class="col-lg-6">
                                        <div class="panel-title">Empresa</div>
                                        <ul class="list-group proposal-list">
                                            <li class="list-group-item"><strong>Razão social:</strong> {{ $proposal->company->name }}</li>
                                            <li class="list-group-item"><strong>CNPJ:</strong> {{ $proposal->company->cnpj }}</li>
                                            <li class="list-group-item"><strong>IE:</strong> {{ $proposal->company->ie ?: '—' }}</li>
                                            <li class="list-group-item"><strong>Setores:</strong> {{ $proposal->company->sectors->pluck('name')->join(', ') ?: '—' }}</li>
                                            <li class="list-group-item"><strong>Site:</strong> {{ $proposal->company->site ?: '—' }}</li>
                                            <li class="list-group-item"><strong>Endereço:</strong> {{ $companyAddress ?: '—' }}</li>
                                            <li class="list-group-item"><strong>Localidade:</strong> {{ $companyRegion ?: '—' }}</li>
                                        </ul>
                                    </div>

                                    <div class="col-lg-6">
                                        <div class="panel-title">Contato e observações</div>
                                        <ul class="list-group proposal-list">
                                            <li class="list-group-item"><strong>Contato:</strong> {{ $proposal->contact->name }}</li>
                                            <li class="list-group-item"><strong>E-mail:</strong> {{ $proposal->contact->email }}</li>
                                            <li class="list-group-item"><strong>Telefones:</strong> {{ $contactPhones ?: '—' }}</li>
                                            <li class="list-group-item"><strong>Cargo:</strong> {{ $proposal->contact->cargo ?: '—' }}</li>
                                            <li class="list-group-item"><strong>Observações:</strong><br>{{ $proposal->observations ?: 'Sem observações.' }}</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card proposal-card border-0">
                            <div class="card-body p-4 p-lg-5">
                                <div class="row g-4 align-items-start mb-4">
                                    <div class="col-lg-7">
                                        <div class="section-kicker mb-2">Etapa 2</div>
                                        <h2 class="section-title">Dados Gerais da Operação</h2>
                                        <p class="section-copy mb-0">Informações macro da operação, com foco em valor, terreno, cronograma e prazo do empreendimento.</p>
                                    </div>
                                </div>

                                <div class="row g-3">
                                    @foreach ($operationDetails as $detail)
                                        <div class="col-md-6 col-xl-4">
                                            <div class="detail-tile">
                                                <div class="detail-label">{{ $detail['label'] }}</div>
                                                <div class="detail-value">{{ $detail['value'] }}</div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>

                        @foreach ($projectSummaries as $projectSummary)
                            <div class="card proposal-card project-card border-0">
                                <div class="card-body p-4 p-lg-5">
                                    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-start gap-3 mb-4">
                                        <div>
                                            <div class="section-kicker mb-2">Empreendimento {{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</div>
                                            <h2 class="project-title mb-1">{{ $projectSummary['name'] }}</h2>
                                            <div class="project-subtitle">{{ $projectSummary['region'] }}</div>
                                        </div>

                                        <span class="project-chip">Informações enviadas pelo proponente</span>
                                    </div>

                                    <div class="row g-3 mb-4">
                                        @foreach ($projectSummary['metrics'] as $metric)
                                            <div class="col-md-6 col-xl-3">
                                                <div class="metric-card">
                                                    <div class="metric-label">{{ $metric['label'] }}</div>
                                                    <div class="metric-value">{{ $metric['value'] }}</div>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>

                                    <div class="row g-4">
                                        <div class="col-lg-6">
                                            <div class="panel-title">Endereço do Empreendimento</div>
                                            <ul class="list-group proposal-list">
                                                <li class="list-group-item"><strong>Endereço:</strong> {{ $projectSummary['address'] ?: '—' }}</li>
                                                <li class="list-group-item"><strong>Localidade:</strong> {{ $projectSummary['region'] ?: '—' }}</li>
                                                <li class="list-group-item"><strong>Site do empreendimento:</strong> {{ $projectSummary['site'] }}</li>
                                            </ul>
                                        </div>

                                        <div class="col-lg-6">
                                            <div class="panel-title">Resumo das Unidades</div>
                                            <ul class="list-group proposal-list">
                                                @foreach ($projectSummary['unit_summary'] as $item)
                                                    <li class="list-group-item"><strong>{{ $item['label'] }}:</strong> {{ $item['value'] }}</li>
                                                @endforeach
                                            </ul>
                                        </div>

                                        <div class="col-lg-6">
                                            <div class="panel-title">Resumo Financeiro</div>
                                            <ul class="list-group proposal-list">
                                                @foreach ($projectSummary['financial_summary'] as $item)
                                                    <li class="list-group-item"><strong>{{ $item['label'] }}:</strong> {{ $item['value'] }}</li>
                                                @endforeach
                                            </ul>
                                        </div>

                                        <div class="col-lg-6">
                                            <div class="panel-title">Valores de Venda</div>
                                            <ul class="list-group proposal-list">
                                                @foreach ($projectSummary['sales_values'] as $item)
                                                    <li class="list-group-item"><strong>{{ $item['label'] }}:</strong> {{ $item['value'] }}</li>
                                                @endforeach
                                            </ul>
                                        </div>

                                        <div class="col-12">
                                            <div class="panel-title">Fluxo de Pagamento</div>
                                            <ul class="list-group proposal-list">
                                                @foreach ($projectSummary['payment_flow'] as $item)
                                                    <li class="list-group-item"><strong>{{ $item['label'] }}:</strong> {{ $item['value'] }}</li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    </div>

                                    @if ($projectSummary['characteristics'])
                                        <div class="mt-5 pt-4 border-top">
                                            <div class="row g-4 align-items-start mb-4">
                                                <div class="col-lg-8">
                                                    <div class="section-kicker mb-2">Composição da Torre</div>
                                                    <h3 class="section-title h4 mb-1">Características do Empreendimento</h3>
                                                    <p class="section-copy mb-0">Visão consolidada da configuração do produto e das tipologias cadastradas.</p>
                                                </div>
                                            </div>

                                            <div class="row g-3 mb-4">
                                                <div class="col-sm-6 col-xl">
                                                    <div class="detail-tile">
                                                        <div class="detail-label">Blocos</div>
                                                        <div class="detail-value">{{ $projectSummary['characteristics']['blocks'] }}</div>
                                                    </div>
                                                </div>

                                                <div class="col-sm-6 col-xl">
                                                    <div class="detail-tile">
                                                        <div class="detail-label">Pavimentos</div>
                                                        <div class="detail-value">{{ $projectSummary['characteristics']['floors'] }}</div>
                                                    </div>
                                                </div>

                                                <div class="col-sm-6 col-xl">
                                                    <div class="detail-tile">
                                                        <div class="detail-label">Andares Tipo</div>
                                                        <div class="detail-value">{{ $projectSummary['characteristics']['typical_floors'] }}</div>
                                                    </div>
                                                </div>

                                                <div class="col-sm-6 col-xl">
                                                    <div class="detail-tile">
                                                        <div class="detail-label">Unidades por Andar</div>
                                                        <div class="detail-value">{{ $projectSummary['characteristics']['units_per_floor'] }}</div>
                                                    </div>
                                                </div>

                                                <div class="col-sm-6 col-xl">
                                                    <div class="detail-tile">
                                                        <div class="detail-label">Total</div>
                                                        <div class="detail-value">{{ $projectSummary['characteristics']['total_units'] }}</div>
                                                    </div>
                                                </div>
                                            </div>

                                            @if ($projectSummary['characteristics']['unit_types'] !== [])
                                                <div class="table-shell">
                                                    <div class="table-responsive">
                                                        <table class="table align-middle mb-0">
                                                            <thead>
                                                                <tr>
                                                                    <th>Tipo</th>
                                                                    <th>Unidades</th>
                                                                    <th>Dormitórios</th>
                                                                    <th>Vagas</th>
                                                                    <th>Área Útil</th>
                                                                    <th>Preço Médio</th>
                                                                    <th>Preço / m²</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                @foreach ($projectSummary['characteristics']['unit_types'] as $unitType)
                                                                    <tr>
                                                                        <td>Tipo {{ $unitType['order'] }}</td>
                                                                        <td>{{ $unitType['total_units'] }}</td>
                                                                        <td>{{ $unitType['bedrooms'] }}</td>
                                                                        <td>{{ $unitType['parking_spaces'] }}</td>
                                                                        <td>{{ $unitType['useful_area'] }}</td>
                                                                        <td>{{ $unitType['average_price'] }}</td>
                                                                        <td>{{ $unitType['price_per_m2'] }}</td>
                                                                    </tr>
                                                                @endforeach
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                </div>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endforeach

                        @if ($attachmentSummaries !== [])
                            <div class="card proposal-card border-0">
                                <div class="card-body p-4 p-lg-5">
                                    <div class="row g-4 align-items-start mb-4">
                                        <div class="col-lg-7">
                                            <div class="section-kicker mb-2">Documentos</div>
                                            <h2 class="section-title">Arquivos Anexados</h2>
                                            <p class="section-copy mb-0">Documentos enviados ao longo do fluxo para apoio à análise da proposta.</p>
                                        </div>
                                    </div>

                                    <div class="d-flex flex-column gap-3">
                                        @foreach ($attachmentSummaries as $attachment)
                                            <a class="attachment-card" href="{{ $attachment['url'] }}">
                                                <div>
                                                    <div class="attachment-name">{{ $attachment['original_name'] }}</div>
                                                    <div class="attachment-meta small">{{ $attachment['meta'] }}</div>
                                                </div>
                                                <span class="attachment-cta">Baixar arquivo</span>
                                            </a>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        @endif
                    @else
                        <div class="card proposal-card border-0">
                            <div class="card-body p-4 p-lg-5">
                                <div class="row g-4 align-items-center">
                                    <div class="col-lg-7">
                                        <div class="section-kicker mb-2">Próxima Etapa</div>
                                        <h2 class="section-title">
                                            {{ $proposal->status === \App\Models\Proposal::STATUS_AWAITING_INFORMATION ? 'Atualize as informações solicitadas' : 'Complementar informações do empreendimento' }}
                                        </h2>
                                        <p class="section-copy mb-0">
                                            {{ $proposal->status === \App\Models\Proposal::STATUS_AWAITING_INFORMATION
                                                ? 'O time comercial solicitou novos dados. Revise os campos abaixo, atualize o que for necessário e salve novamente a proposta.'
                                                : 'Preencha os dados abaixo com atenção. Essa etapa organiza o empreendimento, unidades, cronograma, fluxo financeiro e documentos complementares.' }}
                                        </p>
                                    </div>

                                    <div class="col-lg-5">
                                        <div class="summary-highlight">
                                            <strong class="d-block mb-2">Antes de enviar</strong>
                                            <p>Revise os dados gerais da operação, preencha cada empreendimento com identificação clara e anexe os documentos que apoiam a análise.</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card proposal-card proposal-form-card border-0">
                            <div class="card-body p-4 p-lg-5">
                                <form wire:submit="save" class="row g-4">
                                    <div class="col-12">
                                        <div class="section-kicker mb-2">Dados Gerais</div>
                                        <h2 class="section-title h4 mb-1">Informações da operação</h2>
                                        <p class="section-copy mb-0">Dados principais para identificação da operação, cronograma e endereço do empreendimento.</p>
                                    </div>

                                    <div class="col-md-5">
                                        <label class="form-label" for="operation_nome">Nome do Empreendimento *</label>
                                        <input
                                            id="operation_nome"
                                            type="text"
                                            class="form-control @error('operation.nome') is-invalid @enderror"
                                            wire:model.blur="operation.nome"
                                        >
                                        @error('operation.nome') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label" for="operation_site">Site</label>
                                        <input
                                            id="operation_site"
                                            type="url"
                                            class="form-control @error('operation.site') is-invalid @enderror"
                                            wire:model.blur="operation.site"
                                        >
                                        @error('operation.site') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>

                                    <div class="col-md-3">
                                        <label class="form-label" for="operation_valor_solicitado">Valor Solicitado *</label>
                                        <div class="input-group">
                                            <span class="input-group-text">R$</span>
                                            <input
                                                id="operation_valor_solicitado"
                                                type="text"
                                                inputmode="decimal"
                                                class="form-control @error('operation.valor_solicitado') is-invalid @enderror"
                                                wire:model.blur="operation.valor_solicitado"
                                            >
                                        </div>
                                        @error('operation.valor_solicitado') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label" for="operation_valor_mercado_terreno">Valor atual de mercado do terreno</label>
                                        <div class="input-group">
                                            <span class="input-group-text">R$</span>
                                            <input
                                                id="operation_valor_mercado_terreno"
                                                type="text"
                                                inputmode="decimal"
                                                class="form-control @error('operation.valor_mercado_terreno') is-invalid @enderror"
                                                wire:model.blur="operation.valor_mercado_terreno"
                                            >
                                        </div>
                                        @error('operation.valor_mercado_terreno') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label" for="operation_area_terreno">Área do Terreno (m²) *</label>
                                        <input
                                            id="operation_area_terreno"
                                            type="number"
                                            step="0.01"
                                            class="form-control @error('operation.area_terreno') is-invalid @enderror"
                                            wire:model.blur="operation.area_terreno"
                                        >
                                        @error('operation.area_terreno') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label" for="operation_data_lancamento">Lançamento *</label>
                                        <input
                                            id="operation_data_lancamento"
                                            type="month"
                                            class="form-control @error('operation.data_lancamento') is-invalid @enderror"
                                            wire:model.blur="operation.data_lancamento"
                                        >
                                        @error('operation.data_lancamento') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>

                                    <div class="col-md-3">
                                        <label class="form-label" for="operation_lancamento_vendas">Lançamento das Vendas *</label>
                                        <input
                                            id="operation_lancamento_vendas"
                                            type="month"
                                            class="form-control @error('operation.lancamento_vendas') is-invalid @enderror"
                                            wire:model.blur="operation.lancamento_vendas"
                                        >
                                        @error('operation.lancamento_vendas') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>

                                    <div class="col-md-3">
                                        <label class="form-label" for="operation_inicio_obras">Início das Obras *</label>
                                        <input
                                            id="operation_inicio_obras"
                                            type="month"
                                            class="form-control @error('operation.inicio_obras') is-invalid @enderror"
                                            wire:model.blur="operation.inicio_obras"
                                        >
                                        @error('operation.inicio_obras') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>

                                    <div class="col-md-3">
                                        <label class="form-label" for="operation_previsao_entrega">Previsão de Entrega *</label>
                                        <input
                                            id="operation_previsao_entrega"
                                            type="month"
                                            class="form-control @error('operation.previsao_entrega') is-invalid @enderror"
                                            wire:model.blur="operation.previsao_entrega"
                                        >
                                        @error('operation.previsao_entrega') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>

                                    <div class="col-md-3">
                                        <label class="form-label" for="operation_prazo_remanescente">Prazo Remanescente (meses)</label>
                                        <input
                                            id="operation_prazo_remanescente"
                                            type="number"
                                            class="form-control"
                                            wire:model="operation.prazo_remanescente"
                                            readonly
                                        >
                                    </div>

                                    <div class="col-md-3">
                                        <label class="form-label" for="operation_cep">CEP *</label>
                                        <input
                                            id="operation_cep"
                                            type="text"
                                            class="form-control @error('operation.cep') is-invalid @enderror"
                                            wire:model.blur="operation.cep"
                                            wire:blur="lookupCep"
                                        >
                                        @error('operation.cep') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                        <div class="form-text" wire:loading wire:target="lookupCep">Buscando endereço pelo CEP...</div>
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label" for="operation_logradouro">Rua *</label>
                                        <input
                                            id="operation_logradouro"
                                            type="text"
                                            class="form-control @error('operation.logradouro') is-invalid @enderror"
                                            wire:model.blur="operation.logradouro"
                                        >
                                        @error('operation.logradouro') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>

                                    <div class="col-md-3">
                                        <label class="form-label" for="operation_complemento">Complemento</label>
                                        <input
                                            id="operation_complemento"
                                            type="text"
                                            class="form-control @error('operation.complemento') is-invalid @enderror"
                                            wire:model.blur="operation.complemento"
                                        >
                                        @error('operation.complemento') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>

                                    <div class="col-md-3">
                                        <label class="form-label" for="operation_numero">Número *</label>
                                        <input
                                            id="operation_numero"
                                            type="text"
                                            class="form-control @error('operation.numero') is-invalid @enderror"
                                            wire:model.blur="operation.numero"
                                        >
                                        @error('operation.numero') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label" for="operation_bairro">Bairro *</label>
                                        <input
                                            id="operation_bairro"
                                            type="text"
                                            class="form-control @error('operation.bairro') is-invalid @enderror"
                                            wire:model.blur="operation.bairro"
                                        >
                                        @error('operation.bairro') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label" for="operation_cidade">Cidade *</label>
                                        <input
                                            id="operation_cidade"
                                            type="text"
                                            class="form-control @error('operation.cidade') is-invalid @enderror"
                                            wire:model.blur="operation.cidade"
                                        >
                                        @error('operation.cidade') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>

                                    <div class="col-md-2">
                                        <label class="form-label" for="operation_estado">Estado *</label>
                                        <input
                                            id="operation_estado"
                                            type="text"
                                            maxlength="2"
                                            class="form-control @error('operation.estado') is-invalid @enderror"
                                            wire:model.blur="operation.estado"
                                        >
                                        @error('operation.estado') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>

                                    <div class="col-12">
                                        <hr class="my-2">
                                    </div>

                                    <div class="col-12">
                                        <div class="section-kicker mb-2">Empreendimentos</div>
                                        <h2 class="section-title h4 mb-1">Cadastro das torres e blocos</h2>
                                        <p class="section-copy mb-0">Se houver mais de um empreendimento na mesma operação, adicione quantos blocos forem necessários.</p>
                                    </div>

                                    <div class="col-12 d-flex justify-content-end">
                                        <button type="button" class="btn btn-outline-brand" wire:click="addProject">Adicionar Empreendimento</button>
                                    </div>

                                    <div class="col-12 d-flex flex-column gap-4">
                                        @foreach ($projects as $index => $project)
                                            <div class="bloco-dinamico" wire:key="proposal-project-{{ $index }}">
                                                <div class="proposal-list p-3 p-lg-4">
                                                    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-4">
                                                        <div>
                                                            <div class="section-kicker mb-2">Empreendimento {{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</div>
                                                            <h3 class="section-title h5 mb-0">Resumo operacional e financeiro</h3>
                                                        </div>

                                                        @if ($projectCount > 1)
                                                            <button type="button" class="btn btn-outline-danger btn-sm" wire:click="removeProject({{ $index }})">
                                                                Remover
                                                            </button>
                                                        @endif
                                                    </div>

                                                    <input type="hidden" wire:model="projects.{{ $index }}.id">

                                                    <div class="mb-4">
                                                        <label class="form-label" for="project_name_{{ $index }}">Identificação do Empreendimento *</label>
                                                        <input
                                                            id="project_name_{{ $index }}"
                                                            type="text"
                                                            class="form-control @error("projects.$index.name") is-invalid @enderror"
                                                            wire:model.blur="projects.{{ $index }}.name"
                                                        >
                                                        @error("projects.$index.name") <div class="invalid-feedback">{{ $message }}</div> @enderror
                                                    </div>

                                                    <div class="d-flex flex-column gap-4">
                                                        <div>
                                                            <div class="panel-title">Resumo das Unidades</div>
                                                            <div class="table-shell">
                                                                <div class="table-responsive">
                                                                    <table class="table table-bordered">
                                                                        <thead>
                                                                            <tr>
                                                                                <th>Permutadas</th>
                                                                                <th>Quitadas</th>
                                                                                <th>Não Quitadas</th>
                                                                                <th>Estoque</th>
                                                                                <th>Total</th>
                                                                                <th>% Vendidas</th>
                                                                            </tr>
                                                                        </thead>
                                                                        <tbody>
                                                                            <tr>
                                                                                <td><input type="number" min="0" class="form-control @error("projects.$index.units_exchanged") is-invalid @enderror" wire:model.live.debounce.300ms="projects.{{ $index }}.units_exchanged"></td>
                                                                                <td><input type="number" min="0" class="form-control @error("projects.$index.units_paid") is-invalid @enderror" wire:model.live.debounce.300ms="projects.{{ $index }}.units_paid"></td>
                                                                                <td><input type="number" min="0" class="form-control @error("projects.$index.units_unpaid") is-invalid @enderror" wire:model.live.debounce.300ms="projects.{{ $index }}.units_unpaid"></td>
                                                                                <td><input type="number" min="0" class="form-control @error("projects.$index.units_stock") is-invalid @enderror" wire:model.live.debounce.300ms="projects.{{ $index }}.units_stock"></td>
                                                                                <td><input type="number" class="form-control" wire:model="projects.{{ $index }}.units_total" readonly></td>
                                                                                <td><input type="text" class="form-control" wire:model="projects.{{ $index }}.sales_percentage" readonly></td>
                                                                            </tr>
                                                                        </tbody>
                                                                    </table>
                                                                </div>
                                                            </div>
                                                        </div>

                                                        <div>
                                                            <div class="panel-title">Resumo Financeiro</div>
                                                            <div class="table-shell">
                                                                <div class="table-responsive">
                                                                    <table class="table table-bordered">
                                                                        <thead>
                                                                            <tr>
                                                                                <th>Custo Incorrido</th>
                                                                                <th>Custo a Incorrer</th>
                                                                                <th>Custo Total</th>
                                                                                <th>Estágio da Obra (%)</th>
                                                                            </tr>
                                                                        </thead>
                                                                        <tbody>
                                                                            <tr>
                                                                                <td>
                                                                                    <div class="input-group">
                                                                                        <span class="input-group-text">R$</span>
                                                                                        <input type="text" inputmode="decimal" class="form-control @error("projects.$index.cost_incurred") is-invalid @enderror" wire:model.blur="projects.{{ $index }}.cost_incurred">
                                                                                    </div>
                                                                                </td>
                                                                                <td>
                                                                                    <div class="input-group">
                                                                                        <span class="input-group-text">R$</span>
                                                                                        <input type="text" inputmode="decimal" class="form-control @error("projects.$index.cost_to_incur") is-invalid @enderror" wire:model.blur="projects.{{ $index }}.cost_to_incur">
                                                                                    </div>
                                                                                </td>
                                                                                <td>
                                                                                    <div class="input-group">
                                                                                        <span class="input-group-text">R$</span>
                                                                                        <input type="text" class="form-control" wire:model="projects.{{ $index }}.cost_total" readonly>
                                                                                    </div>
                                                                                </td>
                                                                                <td><input type="text" class="form-control" wire:model="projects.{{ $index }}.work_stage_percentage" readonly></td>
                                                                            </tr>
                                                                        </tbody>
                                                                    </table>
                                                                </div>
                                                            </div>
                                                        </div>

                                                        <div>
                                                            <div class="panel-title">Valores de Venda</div>
                                                            <div class="table-shell">
                                                                <div class="table-responsive">
                                                                    <table class="table table-bordered">
                                                                        <thead>
                                                                            <tr>
                                                                                <th>Quitadas</th>
                                                                                <th>Não Quitadas</th>
                                                                                <th>Estoque</th>
                                                                                <th>Total Venda</th>
                                                                            </tr>
                                                                        </thead>
                                                                        <tbody>
                                                                            <tr>
                                                                                <td>
                                                                                    <div class="input-group">
                                                                                        <span class="input-group-text">R$</span>
                                                                                        <input type="text" inputmode="decimal" class="form-control @error("projects.$index.value_paid") is-invalid @enderror" wire:model.blur="projects.{{ $index }}.value_paid">
                                                                                    </div>
                                                                                </td>
                                                                                <td>
                                                                                    <div class="input-group">
                                                                                        <span class="input-group-text">R$</span>
                                                                                        <input type="text" inputmode="decimal" class="form-control @error("projects.$index.value_unpaid") is-invalid @enderror" wire:model.blur="projects.{{ $index }}.value_unpaid">
                                                                                    </div>
                                                                                </td>
                                                                                <td>
                                                                                    <div class="input-group">
                                                                                        <span class="input-group-text">R$</span>
                                                                                        <input type="text" inputmode="decimal" class="form-control @error("projects.$index.value_stock") is-invalid @enderror" wire:model.blur="projects.{{ $index }}.value_stock">
                                                                                    </div>
                                                                                </td>
                                                                                <td>
                                                                                    <div class="input-group">
                                                                                        <span class="input-group-text">R$</span>
                                                                                        <input type="text" class="form-control" wire:model="projects.{{ $index }}.value_total_sale" readonly>
                                                                                    </div>
                                                                                </td>
                                                                            </tr>
                                                                        </tbody>
                                                                    </table>
                                                                </div>
                                                            </div>
                                                        </div>

                                                        <div>
                                                            <div class="panel-title">Fluxo de Pagamento</div>
                                                            <div class="table-shell">
                                                                <div class="table-responsive">
                                                                    <table class="table table-bordered">
                                                                        <thead>
                                                                            <tr>
                                                                                <th>Já Recebido</th>
                                                                                <th>Até Chaves</th>
                                                                                <th>Chaves + Pós Chaves</th>
                                                                            </tr>
                                                                        </thead>
                                                                        <tbody>
                                                                            <tr>
                                                                                <td>
                                                                                    <div class="input-group">
                                                                                        <span class="input-group-text">R$</span>
                                                                                        <input type="text" inputmode="decimal" class="form-control @error("projects.$index.value_received") is-invalid @enderror" wire:model.blur="projects.{{ $index }}.value_received">
                                                                                    </div>
                                                                                </td>
                                                                                <td>
                                                                                    <div class="input-group">
                                                                                        <span class="input-group-text">R$</span>
                                                                                        <input type="text" inputmode="decimal" class="form-control @error("projects.$index.value_until_keys") is-invalid @enderror" wire:model.blur="projects.{{ $index }}.value_until_keys">
                                                                                    </div>
                                                                                </td>
                                                                                <td>
                                                                                    <div class="input-group">
                                                                                        <span class="input-group-text">R$</span>
                                                                                        <input type="text" inputmode="decimal" class="form-control @error("projects.$index.value_post_keys") is-invalid @enderror" wire:model.blur="projects.{{ $index }}.value_post_keys">
                                                                                    </div>
                                                                                </td>
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

                                    <div class="col-12">
                                        <hr class="my-2">
                                    </div>

                                    <div class="col-12 d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3">
                                        <div>
                                            <div class="section-kicker mb-2">Características</div>
                                            <h2 class="section-title h4 mb-1">Características do Empreendimento</h2>
                                            <p class="section-copy mb-0">Configuração física do produto e dados das tipologias da operação. Adicione um ou mais tipos conforme necessário.</p>
                                        </div>

                                        <button type="button" class="btn btn-outline-brand" wire:click="addUnitType">Adicionar Tipo</button>
                                    </div>

                                    <div class="col-12">
                                        <div class="proposal-list p-3 p-lg-4">
                                            <div class="row g-3 mb-4">
                                                <div class="col-md-2">
                                                    <label class="form-label" for="characteristics_blocks">Blocos *</label>
                                                    <input id="characteristics_blocks" type="number" min="1" class="form-control @error('characteristics.blocks') is-invalid @enderror" wire:model.live.debounce.300ms="characteristics.blocks">
                                                    @error('characteristics.blocks') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                                </div>

                                                <div class="col-md-2">
                                                    <label class="form-label" for="characteristics_floors">Pavimentos *</label>
                                                    <input id="characteristics_floors" type="number" min="1" class="form-control @error('characteristics.floors') is-invalid @enderror" wire:model.live.debounce.300ms="characteristics.floors">
                                                    @error('characteristics.floors') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                                </div>

                                                <div class="col-md-3">
                                                    <label class="form-label" for="characteristics_typical_floors">Andares Tipo *</label>
                                                    <input id="characteristics_typical_floors" type="number" min="1" class="form-control @error('characteristics.typical_floors') is-invalid @enderror" wire:model.live.debounce.300ms="characteristics.typical_floors">
                                                    @error('characteristics.typical_floors') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                                </div>

                                                <div class="col-md-3">
                                                    <label class="form-label" for="characteristics_units_per_floor">Unidades/Andar *</label>
                                                    <input id="characteristics_units_per_floor" type="number" min="1" class="form-control @error('characteristics.units_per_floor') is-invalid @enderror" wire:model.live.debounce.300ms="characteristics.units_per_floor">
                                                    @error('characteristics.units_per_floor') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                                </div>

                                                <div class="col-md-2">
                                                    <label class="form-label" for="characteristics_total_units">Total</label>
                                                    <input id="characteristics_total_units" type="number" class="form-control" wire:model="characteristics.total_units" readonly>
                                                </div>
                                            </div>

                                            <div class="table-shell">
                                                <div class="table-responsive">
                                                    <table class="table table-bordered align-middle">
                                                        <thead>
                                                            <tr>
                                                                <th>&nbsp;</th>
                                                                @foreach ($unitTypes as $typeIndex => $typeRow)
                                                                    <th class="tipo-coluna-header" wire:key="type-header-{{ $typeIndex }}">
                                                                        <div class="d-flex justify-content-between align-items-center gap-2">
                                                                            <span>Tipo {{ $typeIndex + 1 }}</span>

                                                                            @if (count($unitTypes) > 1)
                                                                                <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none text-danger" wire:click="removeUnitType({{ $typeIndex }})">
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
                                                                <th>Total *</th>
                                                                @foreach ($unitTypes as $typeIndex => $typeRow)
                                                                    <td wire:key="type-total-{{ $typeIndex }}">
                                                                        <input type="number" min="1" class="form-control @error("unitTypes.$typeIndex.total") is-invalid @enderror" wire:model.live.debounce.300ms="unitTypes.{{ $typeIndex }}.total">
                                                                    </td>
                                                                @endforeach
                                                            </tr>

                                                            <tr>
                                                                <th>Dormitórios *</th>
                                                                @foreach ($unitTypes as $typeIndex => $typeRow)
                                                                    <td wire:key="type-bedrooms-{{ $typeIndex }}">
                                                                        <input type="text" class="form-control @error("unitTypes.$typeIndex.bedrooms") is-invalid @enderror" wire:model.blur="unitTypes.{{ $typeIndex }}.bedrooms">
                                                                    </td>
                                                                @endforeach
                                                            </tr>

                                                            <tr>
                                                                <th>Vagas *</th>
                                                                @foreach ($unitTypes as $typeIndex => $typeRow)
                                                                    <td wire:key="type-parking-{{ $typeIndex }}">
                                                                        <input type="text" class="form-control @error("unitTypes.$typeIndex.parking_spaces") is-invalid @enderror" wire:model.blur="unitTypes.{{ $typeIndex }}.parking_spaces">
                                                                    </td>
                                                                @endforeach
                                                            </tr>

                                                            <tr>
                                                                <th>Área Útil (m²) *</th>
                                                                @foreach ($unitTypes as $typeIndex => $typeRow)
                                                                    <td wire:key="type-area-{{ $typeIndex }}">
                                                                        <input type="number" step="0.01" class="form-control @error("unitTypes.$typeIndex.useful_area") is-invalid @enderror" wire:model.live.debounce.300ms="unitTypes.{{ $typeIndex }}.useful_area">
                                                                    </td>
                                                                @endforeach
                                                            </tr>

                                                            <tr>
                                                                <th>Preço Médio *</th>
                                                                @foreach ($unitTypes as $typeIndex => $typeRow)
                                                                    <td wire:key="type-average-price-{{ $typeIndex }}">
                                                                        <div class="input-group">
                                                                            <span class="input-group-text">R$</span>
                                                                            <input type="text" inputmode="decimal" class="form-control @error("unitTypes.$typeIndex.average_price") is-invalid @enderror" wire:model.blur="unitTypes.{{ $typeIndex }}.average_price">
                                                                        </div>
                                                                    </td>
                                                                @endforeach
                                                            </tr>

                                                            <tr>
                                                                <th>Preço / m²</th>
                                                                @foreach ($unitTypes as $typeIndex => $typeRow)
                                                                    <td wire:key="type-price-per-m2-{{ $typeIndex }}">
                                                                        <div class="input-group">
                                                                            <span class="input-group-text">R$</span>
                                                                            <input type="text" class="form-control" wire:model="unitTypes.{{ $typeIndex }}.price_per_m2" readonly>
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

                                    <div class="col-12">
                                        <label class="form-label" for="proposal_uploads">Arquivos do Empreendimento</label>
                                        <input
                                            id="proposal_uploads"
                                            type="file"
                                            class="form-control @error('uploads.*') is-invalid @enderror"
                                            wire:model="uploads"
                                            multiple
                                        >
                                        @error('uploads.*') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                                        <div class="form-text" wire:loading wire:target="uploads">Carregando arquivos para envio...</div>

                                        @if ($uploads !== [])
                                            <div class="section-copy mt-2">Arquivos selecionados para o próximo envio.</div>
                                            <div class="d-flex flex-column gap-2 mt-3">
                                                @foreach ($uploads as $upload)
                                                    <div class="attachment-card">
                                                        <div>
                                                            <div class="attachment-name">{{ $upload->getClientOriginalName() }}</div>
                                                            <div class="attachment-meta small">Pronto para envio</div>
                                                        </div>
                                                        <span class="attachment-cta">Novo arquivo</span>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif

                                        @if ($attachmentSummaries !== [])
                                            <div class="section-copy mt-3">Arquivos já enviados permanecem disponíveis abaixo. Novos uploads serão adicionados ao histórico da proposta.</div>
                                            <div class="d-flex flex-column gap-2 mt-3">
                                                @foreach ($attachmentSummaries as $attachment)
                                                    <a class="attachment-card" href="{{ $attachment['url'] }}">
                                                        <div>
                                                            <div class="attachment-name">{{ $attachment['original_name'] }}</div>
                                                            <div class="attachment-meta small">{{ $attachment['meta'] }}</div>
                                                        </div>
                                                        <span class="attachment-cta">Baixar arquivo</span>
                                                    </a>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>

                                    <div class="col-12 d-flex flex-column flex-sm-row gap-3 justify-content-between align-items-sm-center">
                                        <div class="section-copy mb-0">Após salvar, os dados seguirão para análise comercial interna.</div>

                                        <button
                                            type="submit"
                                            class="btn btn-brand"
                                            wire:loading.attr="disabled"
                                            wire:target="save,uploads,lookupCep"
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

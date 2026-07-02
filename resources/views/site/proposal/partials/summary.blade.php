<div class="bsi-investor-form-card mb-4">
    <div class="p-4 p-lg-5">
        <div class="row g-4 align-items-center">
            <div class="col-lg-7">
                <div class="bsi-kicker mb-2">Acompanhamento</div>
                <h2 class="text-2xl bsi-heading mb-[0.35rem]">Resumo do envio</h2>
                <p class="bsi-copy mb-0">
                    Recebemos as informações da sua proposta. A equipe comercial seguirá com a análise interna e manterá o acompanhamento pelos próximos passos do processo.
                </p>
            </div>

            <div class="col-lg-5">
                <div class="p-5 rounded-3xl border border-gold-200 bg-gradient-to-br from-brand-50 to-gold-50">
                    <strong class="d-block mb-2 text-brand-800 font-bold">Ambiente seguro para o proponente</strong>
                    <p class="mb-0 leading-7 bsi-copy">Os dados de análise comercial, indicadores internos e parâmetros do time de vendas permanecem restritos ao painel administrativo.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="bsi-investor-form-card mb-4">
    <div class="p-4 p-lg-5">
        <div class="row g-4 align-items-start mb-4">
            <div class="col-lg-7">
                <div class="bsi-kicker mb-2">Etapa 1</div>
                <h2 class="text-2xl bsi-heading mb-[0.35rem]">Cadastro Inicial</h2>
                <p class="bsi-copy mb-0">Dados institucionais e de contato compartilhados no primeiro envio da proposta.</p>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-6">
                <div class="flex items-center gap-[0.7rem] mb-4 text-brand-800 font-bold">
                    <span class="inline-block w-2.5 h-2.5 rounded-full bg-gold-500 shadow-[0_0_0_0.3rem_rgba(160,110,40,0.15)]"></span>
                    Empresa
                </div>
                <ul class="list-group overflow-hidden bsi-shell-card-soft">
                    <li class="list-group-item bg-transparent border-zinc-200/80 p-4"><strong class="text-brand-800">Razão social:</strong> {{ $proposal->company->name }}</li>
                    <li class="list-group-item bg-transparent border-zinc-200/80 p-4"><strong class="text-brand-800">CNPJ:</strong> {{ $proposal->company->cnpj }}</li>
                    <li class="list-group-item bg-transparent border-zinc-200/80 p-4"><strong class="text-brand-800">IE:</strong> {{ $proposal->company->ie ?: '—' }}</li>
                    <li class="list-group-item bg-transparent border-zinc-200/80 p-4"><strong class="text-brand-800">Setores:</strong> {{ $proposal->company->sectors->pluck('name')->join(', ') ?: '—' }}</li>
                    <li class="list-group-item bg-transparent border-zinc-200/80 p-4"><strong class="text-brand-800">Site:</strong> {{ $proposal->company->site ?: '—' }}</li>
                    <li class="list-group-item bg-transparent border-zinc-200/80 p-4"><strong class="text-brand-800">Endereço:</strong> {{ $companyAddress ?: '—' }}</li>
                    <li class="list-group-item bg-transparent border-zinc-200/80 p-4"><strong class="text-brand-800">Localidade:</strong> {{ $companyRegion ?: '—' }}</li>
                </ul>
            </div>

            <div class="col-lg-6">
                <div class="flex items-center gap-[0.7rem] mb-4 text-brand-800 font-bold">
                    <span class="inline-block w-2.5 h-2.5 rounded-full bg-gold-500 shadow-[0_0_0_0.3rem_rgba(160,110,40,0.15)]"></span>
                    Contato e observações
                </div>
                <ul class="list-group overflow-hidden bsi-shell-card-soft">
                    <li class="list-group-item bg-transparent border-zinc-200/80 p-4"><strong class="text-brand-800">Contato:</strong> {{ $proposal->contact->name }}</li>
                    <li class="list-group-item bg-transparent border-zinc-200/80 p-4"><strong class="text-brand-800">E-mail:</strong> {{ $proposal->contact->email }}</li>
                    <li class="list-group-item bg-transparent border-zinc-200/80 p-4"><strong class="text-brand-800">Telefones:</strong> {{ $contactPhones ?: '—' }}</li>
                    <li class="list-group-item bg-transparent border-zinc-200/80 p-4"><strong class="text-brand-800">Cargo:</strong> {{ $proposal->contact->cargo ?: '—' }}</li>
                    <li class="list-group-item bg-transparent border-zinc-200/80 p-4"><strong class="text-brand-800">Observações:</strong><br>{{ $proposal->observations ?: 'Sem observações.' }}</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<div class="bsi-investor-form-card mb-4">
    <div class="p-4 p-lg-5">
        <div class="row g-4 align-items-start mb-4">
            <div class="col-lg-7">
                <div class="bsi-kicker mb-2">Etapa 2</div>
                <h2 class="text-2xl bsi-heading mb-[0.35rem]">Dados Gerais da Operação</h2>
                <p class="bsi-copy mb-0">Informações macro da operação, com foco em valor, terreno, cronograma e prazo do empreendimento.</p>
            </div>
        </div>

        <div class="row g-3">
            @foreach ($operationDetails as $detail)
                <div class="col-md-6 col-xl-4">
                    <div class="h-full p-5 bsi-shell-card-soft">
                        <div class="mb-[0.45rem] text-[0.76rem] font-bold tracking-[0.08em] uppercase bsi-copy">{{ $detail['label'] }}</div>
                        <div class="text-brand-800 font-bold">{{ $detail['value'] }}</div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>

@foreach ($projectSummaries as $projectSummary)
    <div class="relative overflow-hidden bsi-investor-form-card mb-4">
        <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-gold-500 to-transparent"></div>
        <div class="p-4 p-lg-5">
            <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-start gap-3 mb-4">
                <div>
                    <div class="bsi-kicker mb-2">
                        Empreendimento {{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}
                    </div>
                    <h2 class="text-2xl bsi-heading mb-1">{{ $projectSummary['name'] }}</h2>
                    <div class="bsi-copy">{{ $projectSummary['region'] }}</div>
                </div>

                <span class="inline-flex items-center px-4 py-3 rounded-full border border-gold-200 bg-gold-50 font-bold text-brand-800">
                    Informações enviadas pelo proponente
                </span>
            </div>

            <div class="row g-3 mb-4">
                @foreach ($projectSummary['metrics'] as $metric)
                    <div class="col-md-6 col-xl-3">
                        <div class="h-full p-5 bsi-shell-card-soft">
                            <div class="mb-[0.45rem] text-[0.76rem] font-bold tracking-[0.08em] uppercase bsi-copy">{{ $metric['label'] }}</div>
                            <div class="text-[1.45rem] font-extrabold tracking-[-0.03em] text-brand-800">{{ $metric['value'] }}</div>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="row g-4">
                <div class="col-lg-6">
                    <div class="flex items-center gap-[0.7rem] mb-4 text-brand-800 font-bold">
                        <span class="inline-block w-2.5 h-2.5 rounded-full bg-gold-500 shadow-[0_0_0_0.3rem_rgba(160,110,40,0.15)]"></span>
                        Endereço do Empreendimento
                    </div>
                    <ul class="list-group overflow-hidden bsi-shell-card-soft">
                        <li class="list-group-item bg-transparent border-zinc-200/80 p-4"><strong class="text-brand-800">Endereço:</strong> {{ $projectSummary['address'] ?: '—' }}</li>
                        <li class="list-group-item bg-transparent border-zinc-200/80 p-4"><strong class="text-brand-800">Localidade:</strong> {{ $projectSummary['region'] ?: '—' }}</li>
                        <li class="list-group-item bg-transparent border-zinc-200/80 p-4"><strong class="text-brand-800">Site do empreendimento:</strong> {{ $projectSummary['site'] }}</li>
                    </ul>
                </div>

                <div class="col-lg-6">
                    <div class="flex items-center gap-[0.7rem] mb-4 text-brand-800 font-bold">
                        <span class="inline-block w-2.5 h-2.5 rounded-full bg-gold-500 shadow-[0_0_0_0.3rem_rgba(160,110,40,0.15)]"></span>
                        Resumo das Unidades
                    </div>
                    <ul class="list-group overflow-hidden bsi-shell-card-soft">
                        @foreach ($projectSummary['unit_summary'] as $item)
                            <li class="list-group-item bg-transparent border-zinc-200/80 p-4"><strong class="text-brand-800">{{ $item['label'] }}:</strong> {{ $item['value'] }}</li>
                        @endforeach
                    </ul>
                </div>

                <div class="col-lg-6">
                    <div class="flex items-center gap-[0.7rem] mb-4 text-brand-800 font-bold">
                        <span class="inline-block w-2.5 h-2.5 rounded-full bg-gold-500 shadow-[0_0_0_0.3rem_rgba(160,110,40,0.15)]"></span>
                        Resumo Financeiro
                    </div>
                    <ul class="list-group overflow-hidden bsi-shell-card-soft">
                        @foreach ($projectSummary['financial_summary'] as $item)
                            <li class="list-group-item bg-transparent border-zinc-200/80 p-4"><strong class="text-brand-800">{{ $item['label'] }}:</strong> {{ $item['value'] }}</li>
                        @endforeach
                    </ul>
                </div>

                <div class="col-lg-6">
                    <div class="flex items-center gap-[0.7rem] mb-4 text-brand-800 font-bold">
                        <span class="inline-block w-2.5 h-2.5 rounded-full bg-gold-500 shadow-[0_0_0_0.3rem_rgba(160,110,40,0.15)]"></span>
                        Valores de Venda
                    </div>
                    <ul class="list-group overflow-hidden bsi-shell-card-soft">
                        @foreach ($projectSummary['sales_values'] as $item)
                            <li class="list-group-item bg-transparent border-zinc-200/80 p-4"><strong class="text-brand-800">{{ $item['label'] }}:</strong> {{ $item['value'] }}</li>
                        @endforeach
                    </ul>
                </div>

                <div class="col-12">
                    <div class="flex items-center gap-[0.7rem] mb-4 text-brand-800 font-bold">
                        <span class="inline-block w-2.5 h-2.5 rounded-full bg-gold-500 shadow-[0_0_0_0.3rem_rgba(160,110,40,0.15)]"></span>
                        Fluxo de Pagamento
                    </div>
                    <ul class="list-group overflow-hidden bsi-shell-card-soft">
                        @foreach ($projectSummary['payment_flow'] as $item)
                            <li class="list-group-item bg-transparent border-zinc-200/80 p-4"><strong class="text-brand-800">{{ $item['label'] }}:</strong> {{ $item['value'] }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>

            @if ($projectSummary['characteristics'])
                <div class="mt-5 pt-4 border-t border-zinc-200/80">
                    <div class="row g-4 align-items-start mb-4">
                        <div class="col-lg-8">
                            <div class="bsi-kicker mb-2">Composição da Torre</div>
                            <h3 class="text-xl bsi-heading mb-1">Características do Empreendimento</h3>
                            <p class="bsi-copy mb-0">Visão consolidada da configuração do produto e das tipologias cadastradas.</p>
                        </div>
                    </div>

                    <div class="row g-3 mb-4">
                        @foreach ([
                            'Blocos' => $projectSummary['characteristics']['blocks'],
                            'Pavimentos' => $projectSummary['characteristics']['floors'],
                            'Andares Tipo' => $projectSummary['characteristics']['typical_floors'],
                            'Unidades por Andar' => $projectSummary['characteristics']['units_per_floor'],
                            'Total' => $projectSummary['characteristics']['total_units'],
                        ] as $label => $value)
                            <div class="col-sm-6 col-xl">
                                <div class="h-full p-5 bsi-shell-card-soft">
                                    <div class="mb-[0.45rem] text-[0.76rem] font-bold tracking-[0.08em] uppercase bsi-copy">{{ $label }}</div>
                                    <div class="text-brand-800 font-bold">{{ $value }}</div>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    @if ($projectSummary['characteristics']['unit_types'] !== [])
                        <div class="overflow-hidden bsi-shell-card-soft">
                            <div class="table-responsive">
                                <table class="table align-middle mb-0">
                                    <thead>
                                        <tr>
                                            @foreach (['Tipo', 'Unidades', 'Dormitórios', 'Vagas', 'Área Útil', 'Preço Médio', 'Preço / m²'] as $col)
                                                <th class="bg-brand-50 text-brand-700 text-xs font-bold tracking-wider uppercase">{{ $col }}</th>
                                            @endforeach
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($projectSummary['characteristics']['unit_types'] as $unitType)
                                            <tr>
                                                <td>Tipo {{ $unitType['order'] }}</td>
                                                <td>{{ $unitType['total_units'] }}</td>
                                                <td>{{ $unitType['bedrooms'] }}</td>
                                                <td>{{ $unitType['parking_spaces'] }}</td>
                                                <td>{{ $unitType['usable_area'] }}</td>
                                                <td>{{ $unitType['average_price'] }}</td>
                                                <td>{{ $unitType['price_per_square_meter'] }}</td>
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
    <div class="bsi-investor-form-card mb-4">
        <div class="p-4 p-lg-5">
            <div class="row g-4 align-items-start mb-4">
                <div class="col-lg-7">
                    <div class="bsi-kicker mb-2">Documentos</div>
                    <h2 class="text-2xl bsi-heading mb-[0.35rem]">Arquivos Anexados</h2>
                    <p class="bsi-copy mb-0">Documentos enviados ao longo do fluxo para apoio à análise da proposta.</p>
                </div>
            </div>

            <div class="d-flex flex-column gap-3">
                @foreach ($attachmentSummaries as $attachment)
                    <a
                        class="flex items-center justify-between gap-4 p-5 border border-zinc-200/80 rounded-[22px] bg-brand-50/50 no-underline text-brand-900 hover:border-gold-500/50 hover:bg-gold-400/10 hover:shadow-sm transition-all"
                        href="{{ $attachment['url'] }}"
                    >
                        <div>
                            <div class="text-brand-800 font-bold">{{ $attachment['original_name'] }}</div>
                            <div class="bsi-copy small">{{ $attachment['meta'] }}</div>
                        </div>
                        <span class="text-brand-800 text-[0.88rem] font-bold whitespace-nowrap">Baixar arquivo</span>
                    </a>
                @endforeach
            </div>
        </div>
    </div>
@endif


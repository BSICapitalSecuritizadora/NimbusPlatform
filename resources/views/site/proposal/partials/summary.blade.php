<div class="rounded-[30px] border border-[var(--border)] shadow-[0_20px_45px_rgba(0,32,91,0.08)] bg-[var(--surface,#fff)]">
    <div class="p-4 p-lg-5">
        <div class="row g-4 align-items-center">
            <div class="col-lg-7">
                <div class="text-[0.78rem] font-bold tracking-[0.12em] uppercase text-[var(--gold)] mb-2">Acompanhamento</div>
                <h2 class="text-[1.65rem] font-extrabold tracking-[-0.03em] text-[var(--brand)] mb-[0.35rem]">Resumo do envio</h2>
                <p class="text-[var(--muted)] mb-0">
                    Recebemos as informações da sua proposta. A equipe comercial seguirá com a análise interna e manterá o acompanhamento pelos próximos passos do processo.
                </p>
            </div>

            <div class="col-lg-5">
                <div class="p-[1.2rem_1.25rem] rounded-3xl border border-[color-mix(in_oklab,var(--gold)_18%,var(--border)_82%)] bg-gradient-to-br from-[color-mix(in_oklab,var(--brand)_8%,var(--surface)_92%)] to-[color-mix(in_oklab,var(--gold)_10%,var(--surface)_90%)]">
                    <strong class="d-block mb-2 text-[var(--brand)] font-bold">Ambiente seguro para o proponente</strong>
                    <p class="mb-0 leading-7 text-[var(--muted)]">Os dados de análise comercial, indicadores internos e parâmetros do time de vendas permanecem restritos ao painel administrativo.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="rounded-[30px] border border-[var(--border)] shadow-[0_20px_45px_rgba(0,32,91,0.08)] bg-[var(--surface,#fff)]">
    <div class="p-4 p-lg-5">
        <div class="row g-4 align-items-start mb-4">
            <div class="col-lg-7">
                <div class="text-[0.78rem] font-bold tracking-[0.12em] uppercase text-[var(--gold)] mb-2">Etapa 1</div>
                <h2 class="text-[1.65rem] font-extrabold tracking-[-0.03em] text-[var(--brand)] mb-[0.35rem]">Cadastro Inicial</h2>
                <p class="text-[var(--muted)] mb-0">Dados institucionais e de contato compartilhados no primeiro envio da proposta.</p>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-6">
                <div class="flex items-center gap-[0.7rem] mb-4 text-[var(--brand)] font-bold">
                    <span class="inline-block w-2.5 h-2.5 rounded-full bg-[var(--gold)] shadow-[0_0_0_0.3rem_rgba(212,175,55,0.15)]"></span>
                    Empresa
                </div>
                <ul class="list-group overflow-hidden border border-[var(--border)] rounded-[22px] bg-[color-mix(in_oklab,var(--surface)_96%,var(--brand)_4%)]">
                    <li class="list-group-item bg-transparent border-[var(--border)] p-[1rem_1.1rem]"><strong class="text-[var(--brand)]">Razão social:</strong> {{ $proposal->company->name }}</li>
                    <li class="list-group-item bg-transparent border-[var(--border)] p-[1rem_1.1rem]"><strong class="text-[var(--brand)]">CNPJ:</strong> {{ $proposal->company->cnpj }}</li>
                    <li class="list-group-item bg-transparent border-[var(--border)] p-[1rem_1.1rem]"><strong class="text-[var(--brand)]">IE:</strong> {{ $proposal->company->ie ?: '—' }}</li>
                    <li class="list-group-item bg-transparent border-[var(--border)] p-[1rem_1.1rem]"><strong class="text-[var(--brand)]">Setores:</strong> {{ $proposal->company->sectors->pluck('name')->join(', ') ?: '—' }}</li>
                    <li class="list-group-item bg-transparent border-[var(--border)] p-[1rem_1.1rem]"><strong class="text-[var(--brand)]">Site:</strong> {{ $proposal->company->site ?: '—' }}</li>
                    <li class="list-group-item bg-transparent border-[var(--border)] p-[1rem_1.1rem]"><strong class="text-[var(--brand)]">Endereço:</strong> {{ $companyAddress ?: '—' }}</li>
                    <li class="list-group-item bg-transparent border-[var(--border)] p-[1rem_1.1rem]"><strong class="text-[var(--brand)]">Localidade:</strong> {{ $companyRegion ?: '—' }}</li>
                </ul>
            </div>

            <div class="col-lg-6">
                <div class="flex items-center gap-[0.7rem] mb-4 text-[var(--brand)] font-bold">
                    <span class="inline-block w-2.5 h-2.5 rounded-full bg-[var(--gold)] shadow-[0_0_0_0.3rem_rgba(212,175,55,0.15)]"></span>
                    Contato e observações
                </div>
                <ul class="list-group overflow-hidden border border-[var(--border)] rounded-[22px] bg-[color-mix(in_oklab,var(--surface)_96%,var(--brand)_4%)]">
                    <li class="list-group-item bg-transparent border-[var(--border)] p-[1rem_1.1rem]"><strong class="text-[var(--brand)]">Contato:</strong> {{ $proposal->contact->name }}</li>
                    <li class="list-group-item bg-transparent border-[var(--border)] p-[1rem_1.1rem]"><strong class="text-[var(--brand)]">E-mail:</strong> {{ $proposal->contact->email }}</li>
                    <li class="list-group-item bg-transparent border-[var(--border)] p-[1rem_1.1rem]"><strong class="text-[var(--brand)]">Telefones:</strong> {{ $contactPhones ?: '—' }}</li>
                    <li class="list-group-item bg-transparent border-[var(--border)] p-[1rem_1.1rem]"><strong class="text-[var(--brand)]">Cargo:</strong> {{ $proposal->contact->cargo ?: '—' }}</li>
                    <li class="list-group-item bg-transparent border-[var(--border)] p-[1rem_1.1rem]"><strong class="text-[var(--brand)]">Observações:</strong><br>{{ $proposal->observations ?: 'Sem observações.' }}</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<div class="rounded-[30px] border border-[var(--border)] shadow-[0_20px_45px_rgba(0,32,91,0.08)] bg-[var(--surface,#fff)]">
    <div class="p-4 p-lg-5">
        <div class="row g-4 align-items-start mb-4">
            <div class="col-lg-7">
                <div class="text-[0.78rem] font-bold tracking-[0.12em] uppercase text-[var(--gold)] mb-2">Etapa 2</div>
                <h2 class="text-[1.65rem] font-extrabold tracking-[-0.03em] text-[var(--brand)] mb-[0.35rem]">Dados Gerais da Operação</h2>
                <p class="text-[var(--muted)] mb-0">Informações macro da operação, com foco em valor, terreno, cronograma e prazo do empreendimento.</p>
            </div>
        </div>

        <div class="row g-3">
            @foreach ($operationDetails as $detail)
                <div class="col-md-6 col-xl-4">
                    <div class="h-full p-[1.15rem_1.2rem] border border-[var(--border)] rounded-[22px] bg-[color-mix(in_oklab,var(--surface)_94%,var(--brand)_6%)]">
                        <div class="mb-[0.45rem] text-[0.76rem] font-bold tracking-[0.08em] uppercase text-[var(--muted)]">{{ $detail['label'] }}</div>
                        <div class="text-[var(--brand)] font-bold">{{ $detail['value'] }}</div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>

@foreach ($projectSummaries as $projectSummary)
    <div class="relative overflow-hidden rounded-[30px] border border-[var(--border)] shadow-[0_20px_45px_rgba(0,32,91,0.08)] bg-[var(--surface,#fff)]">
        <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-[color-mix(in_oklab,var(--gold)_55%,var(--brand)_45%)] to-transparent"></div>
        <div class="p-4 p-lg-5">
            <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-start gap-3 mb-4">
                <div>
                    <div class="text-[0.78rem] font-bold tracking-[0.12em] uppercase text-[var(--gold)] mb-2">
                        Empreendimento {{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}
                    </div>
                    <h2 class="text-[1.7rem] font-extrabold tracking-[-0.03em] text-[var(--brand)] mb-1">{{ $projectSummary['name'] }}</h2>
                    <div class="text-[var(--muted)]">{{ $projectSummary['region'] }}</div>
                </div>

                <span class="inline-flex items-center px-4 py-3 rounded-full border border-[color-mix(in_oklab,var(--gold)_30%,var(--border)_70%)] bg-[color-mix(in_oklab,var(--gold)_10%,var(--surface)_90%)] text-[var(--brand)] font-bold">
                    Informações enviadas pelo proponente
                </span>
            </div>

            <div class="row g-3 mb-4">
                @foreach ($projectSummary['metrics'] as $metric)
                    <div class="col-md-6 col-xl-3">
                        <div class="h-full p-[1.15rem_1.2rem] border border-[var(--border)] rounded-[22px] bg-[color-mix(in_oklab,var(--surface)_94%,var(--brand)_6%)]">
                            <div class="mb-[0.45rem] text-[0.76rem] font-bold tracking-[0.08em] uppercase text-[var(--muted)]">{{ $metric['label'] }}</div>
                            <div class="text-[1.45rem] font-extrabold tracking-[-0.03em] text-[var(--brand)]">{{ $metric['value'] }}</div>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="row g-4">
                <div class="col-lg-6">
                    <div class="flex items-center gap-[0.7rem] mb-4 text-[var(--brand)] font-bold">
                        <span class="inline-block w-2.5 h-2.5 rounded-full bg-[var(--gold)] shadow-[0_0_0_0.3rem_rgba(212,175,55,0.15)]"></span>
                        Endereço do Empreendimento
                    </div>
                    <ul class="list-group overflow-hidden border border-[var(--border)] rounded-[22px] bg-[color-mix(in_oklab,var(--surface)_96%,var(--brand)_4%)]">
                        <li class="list-group-item bg-transparent border-[var(--border)] p-[1rem_1.1rem]"><strong class="text-[var(--brand)]">Endereço:</strong> {{ $projectSummary['address'] ?: '—' }}</li>
                        <li class="list-group-item bg-transparent border-[var(--border)] p-[1rem_1.1rem]"><strong class="text-[var(--brand)]">Localidade:</strong> {{ $projectSummary['region'] ?: '—' }}</li>
                        <li class="list-group-item bg-transparent border-[var(--border)] p-[1rem_1.1rem]"><strong class="text-[var(--brand)]">Site do empreendimento:</strong> {{ $projectSummary['site'] }}</li>
                    </ul>
                </div>

                <div class="col-lg-6">
                    <div class="flex items-center gap-[0.7rem] mb-4 text-[var(--brand)] font-bold">
                        <span class="inline-block w-2.5 h-2.5 rounded-full bg-[var(--gold)] shadow-[0_0_0_0.3rem_rgba(212,175,55,0.15)]"></span>
                        Resumo das Unidades
                    </div>
                    <ul class="list-group overflow-hidden border border-[var(--border)] rounded-[22px] bg-[color-mix(in_oklab,var(--surface)_96%,var(--brand)_4%)]">
                        @foreach ($projectSummary['unit_summary'] as $item)
                            <li class="list-group-item bg-transparent border-[var(--border)] p-[1rem_1.1rem]"><strong class="text-[var(--brand)]">{{ $item['label'] }}:</strong> {{ $item['value'] }}</li>
                        @endforeach
                    </ul>
                </div>

                <div class="col-lg-6">
                    <div class="flex items-center gap-[0.7rem] mb-4 text-[var(--brand)] font-bold">
                        <span class="inline-block w-2.5 h-2.5 rounded-full bg-[var(--gold)] shadow-[0_0_0_0.3rem_rgba(212,175,55,0.15)]"></span>
                        Resumo Financeiro
                    </div>
                    <ul class="list-group overflow-hidden border border-[var(--border)] rounded-[22px] bg-[color-mix(in_oklab,var(--surface)_96%,var(--brand)_4%)]">
                        @foreach ($projectSummary['financial_summary'] as $item)
                            <li class="list-group-item bg-transparent border-[var(--border)] p-[1rem_1.1rem]"><strong class="text-[var(--brand)]">{{ $item['label'] }}:</strong> {{ $item['value'] }}</li>
                        @endforeach
                    </ul>
                </div>

                <div class="col-lg-6">
                    <div class="flex items-center gap-[0.7rem] mb-4 text-[var(--brand)] font-bold">
                        <span class="inline-block w-2.5 h-2.5 rounded-full bg-[var(--gold)] shadow-[0_0_0_0.3rem_rgba(212,175,55,0.15)]"></span>
                        Valores de Venda
                    </div>
                    <ul class="list-group overflow-hidden border border-[var(--border)] rounded-[22px] bg-[color-mix(in_oklab,var(--surface)_96%,var(--brand)_4%)]">
                        @foreach ($projectSummary['sales_values'] as $item)
                            <li class="list-group-item bg-transparent border-[var(--border)] p-[1rem_1.1rem]"><strong class="text-[var(--brand)]">{{ $item['label'] }}:</strong> {{ $item['value'] }}</li>
                        @endforeach
                    </ul>
                </div>

                <div class="col-12">
                    <div class="flex items-center gap-[0.7rem] mb-4 text-[var(--brand)] font-bold">
                        <span class="inline-block w-2.5 h-2.5 rounded-full bg-[var(--gold)] shadow-[0_0_0_0.3rem_rgba(212,175,55,0.15)]"></span>
                        Fluxo de Pagamento
                    </div>
                    <ul class="list-group overflow-hidden border border-[var(--border)] rounded-[22px] bg-[color-mix(in_oklab,var(--surface)_96%,var(--brand)_4%)]">
                        @foreach ($projectSummary['payment_flow'] as $item)
                            <li class="list-group-item bg-transparent border-[var(--border)] p-[1rem_1.1rem]"><strong class="text-[var(--brand)]">{{ $item['label'] }}:</strong> {{ $item['value'] }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>

            @if ($projectSummary['characteristics'])
                <div class="mt-5 pt-4 border-t border-[var(--border)]">
                    <div class="row g-4 align-items-start mb-4">
                        <div class="col-lg-8">
                            <div class="text-[0.78rem] font-bold tracking-[0.12em] uppercase text-[var(--gold)] mb-2">Composição da Torre</div>
                            <h3 class="h4 text-[var(--brand)] font-extrabold tracking-[-0.03em] mb-1">Características do Empreendimento</h3>
                            <p class="text-[var(--muted)] mb-0">Visão consolidada da configuração do produto e das tipologias cadastradas.</p>
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
                                <div class="h-full p-[1.15rem_1.2rem] border border-[var(--border)] rounded-[22px] bg-[color-mix(in_oklab,var(--surface)_94%,var(--brand)_6%)]">
                                    <div class="mb-[0.45rem] text-[0.76rem] font-bold tracking-[0.08em] uppercase text-[var(--muted)]">{{ $label }}</div>
                                    <div class="text-[var(--brand)] font-bold">{{ $value }}</div>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    @if ($projectSummary['characteristics']['unit_types'] !== [])
                        <div class="overflow-hidden border border-[var(--border)] rounded-[22px] bg-[color-mix(in_oklab,var(--surface)_96%,var(--brand)_4%)]">
                            <div class="table-responsive">
                                <table class="table align-middle mb-0">
                                    <thead>
                                        <tr>
                                            @foreach (['Tipo', 'Unidades', 'Dormitórios', 'Vagas', 'Área Útil', 'Preço Médio', 'Preço / m²'] as $col)
                                                <th class="bg-[color-mix(in_oklab,var(--brand)_8%,var(--surface)_92%)] text-[var(--brand)] text-[0.76rem] font-bold tracking-[0.08em] uppercase">{{ $col }}</th>
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
    <div class="rounded-[30px] border border-[var(--border)] shadow-[0_20px_45px_rgba(0,32,91,0.08)] bg-[var(--surface,#fff)]">
        <div class="p-4 p-lg-5">
            <div class="row g-4 align-items-start mb-4">
                <div class="col-lg-7">
                    <div class="text-[0.78rem] font-bold tracking-[0.12em] uppercase text-[var(--gold)] mb-2">Documentos</div>
                    <h2 class="text-[1.65rem] font-extrabold tracking-[-0.03em] text-[var(--brand)] mb-[0.35rem]">Arquivos Anexados</h2>
                    <p class="text-[var(--muted)] mb-0">Documentos enviados ao longo do fluxo para apoio à análise da proposta.</p>
                </div>
            </div>

            <div class="d-flex flex-column gap-3">
                @foreach ($attachmentSummaries as $attachment)
                    <a
                        class="flex items-center justify-between gap-4 p-[1.15rem_1.2rem] border border-[var(--border)] rounded-[22px] bg-[color-mix(in_oklab,var(--surface)_95%,var(--brand)_5%)] no-underline text-[var(--text)] hover:border-[color-mix(in_oklab,var(--gold)_35%,var(--border)_65%)] hover:shadow-[0_18px_34px_rgba(0,32,91,0.08)] transition-all"
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
        </div>
    </div>
@endif


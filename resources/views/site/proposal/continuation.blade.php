@extends('site.layout')

@section('title', 'Formulário de Empreendimento')

@section('content')
@php
    $firstProject = $proposal->projects->first();
@endphp
<section class="py-5" style="background:#eef2f7;">
    <div class="container"><div class="row justify-content-center"><div class="col-xl-10">
        @if (session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif
        <div class="card border-0 shadow-sm rounded-4 mb-4"><div class="card-body p-4 d-flex flex-column flex-lg-row justify-content-between gap-3">
            <div><h1 class="h3 fw-bold mb-1">Formulário de Empreendimento</h1><div class="text-muted">{{ $proposal->company->name }} • {{ $proposal->company->cnpj }}</div></div>
            <div class="text-lg-end"><div class="small text-uppercase text-muted fw-semibold">Status</div><div class="fw-semibold">{{ $proposal->status_label }}</div></div>
        </div></div>

        @if ($proposal->projects->isNotEmpty())
            @php
                $company = $proposal->company;
                $contact = $proposal->contact;
                $companyAddress = collect([
                    trim(implode(', ', array_filter([$company->logradouro, $company->numero]))),
                    $company->complemento,
                ])->filter()->implode(', ');
                $companyRegion = collect([
                    $company->bairro,
                    trim(implode(' - ', array_filter([$company->cidade, $company->estado]))),
                    $company->cep ? 'CEP '.$company->cep : null,
                ])->filter()->implode(' • ');
                $contactPhones = collect([
                    $contact->phone_personal ? 'Pessoal: '.$contact->phone_personal.($contact->whatsapp ? ' (WhatsApp)' : '') : null,
                    $contact->phone_company ? 'Empresa: '.$contact->phone_company : null,
                ])->filter()->implode(' • ');
            @endphp

            <div class="card border-0 shadow-sm rounded-4 mb-4"><div class="card-body p-4">
                <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 align-items-lg-center mb-3">
                    <div>
                        <h2 class="h5 fw-bold mb-1">Resumo do envio</h2>
                        <div class="text-muted">Recebemos as informações da sua proposta e o time comercial seguirá com a análise interna.</div>
                    </div>
                    <div class="rounded-4 px-3 py-2 small fw-semibold" style="background:#f8fafc;border:1px solid #dbe4f0;">
                        Situação atual: {{ $proposal->status_label }}
                    </div>
                </div>
                <div class="small text-muted">Os dados de análise comercial e indicadores internos permanecem restritos ao painel administrativo.</div>
            </div></div>

            <div class="card border-0 shadow-sm rounded-4 mb-4"><div class="card-body p-4">
                <h2 class="h5 fw-bold mb-3">Cadastro Inicial</h2>
                <div class="row g-4">
                    <div class="col-lg-6">
                        <div class="h6 fw-bold">Empresa</div>
                        <ul class="list-group">
                            <li class="list-group-item"><strong>Razão social:</strong> {{ $company->name }}</li>
                            <li class="list-group-item"><strong>CNPJ:</strong> {{ $company->cnpj }}</li>
                            <li class="list-group-item"><strong>IE:</strong> {{ $company->ie ?: '—' }}</li>
                            <li class="list-group-item"><strong>Setores:</strong> {{ $company->sectors->pluck('name')->join(', ') ?: '—' }}</li>
                            <li class="list-group-item"><strong>Site:</strong> {{ $company->site ?: '—' }}</li>
                            <li class="list-group-item"><strong>Endereço:</strong> {{ $companyAddress ?: '—' }}</li>
                            <li class="list-group-item"><strong>Localidade:</strong> {{ $companyRegion ?: '—' }}</li>
                        </ul>
                    </div>
                    <div class="col-lg-6">
                        <div class="h6 fw-bold">Contato e observações</div>
                        <ul class="list-group">
                            <li class="list-group-item"><strong>Contato:</strong> {{ $contact->name }}</li>
                            <li class="list-group-item"><strong>E-mail:</strong> {{ $contact->email }}</li>
                            <li class="list-group-item"><strong>Telefones:</strong> {{ $contactPhones ?: '—' }}</li>
                            <li class="list-group-item"><strong>Cargo:</strong> {{ $contact->cargo ?: '—' }}</li>
                            <li class="list-group-item"><strong>Observações:</strong><br>{{ $proposal->observations ?: 'Sem observações.' }}</li>
                        </ul>
                    </div>
                </div>
            </div></div>

            <div class="card border-0 shadow-sm rounded-4 mb-4"><div class="card-body p-4">
                <h2 class="h5 fw-bold mb-3">Dados Gerais da Operação</h2>
                <div class="row g-3">
                    <div class="col-md-4"><strong>Nome do Empreendimento:</strong> {{ $firstProject->company_name }}</div>
                    <div class="col-md-4"><strong>Site:</strong> {{ $firstProject->site ?: '—' }}</div>
                    <div class="col-md-4"><strong>Valor Solicitado:</strong> R$ {{ number_format((float) $firstProject->value_requested, 2, ',', '.') }}</div>
                    <div class="col-md-4"><strong>Valor de Mercado do Terreno:</strong> R$ {{ number_format((float) $firstProject->land_market_value, 2, ',', '.') }}</div>
                    <div class="col-md-4"><strong>Área do Terreno:</strong> {{ number_format((float) $firstProject->land_area, 2, ',', '.') }} m²</div>
                    <div class="col-md-4"><strong>Lançamento:</strong> {{ $firstProject->launch_date?->format('m/Y') }}</div>
                    <div class="col-md-4"><strong>Lançamento das Vendas:</strong> {{ $firstProject->sales_launch_date?->format('m/Y') }}</div>
                    <div class="col-md-4"><strong>Início das Obras:</strong> {{ $firstProject->construction_start_date?->format('m/Y') }}</div>
                    <div class="col-md-4"><strong>Previsão de Entrega:</strong> {{ $firstProject->delivery_forecast_date?->format('m/Y') }}</div>
                    <div class="col-md-4"><strong>Prazo Remanescente:</strong> {{ (int) $firstProject->remaining_months }} meses</div>
                </div>
            </div></div>

            @foreach ($proposal->projects as $project)
                @php
                    $projectAddress = collect([
                        trim(implode(', ', array_filter([$project->logradouro, $project->numero]))),
                        $project->complemento,
                    ])->filter()->implode(', ');
                    $projectRegion = collect([
                        $project->bairro,
                        trim(implode(' - ', array_filter([$project->cidade, $project->estado]))),
                        $project->cep ? 'CEP '.$project->cep : null,
                    ])->filter()->implode(' • ');
                    $paymentFlowTotal = \App\Models\ProposalProject::calculatePaymentFlowTotal(
                        $project->value_received,
                        $project->value_until_keys,
                        $project->value_post_keys,
                    );
                @endphp

                <div class="card border-0 shadow-sm rounded-4 mb-4"><div class="card-body p-4">
                    <div class="d-flex flex-column flex-lg-row justify-content-between gap-2 mb-3">
                        <div class="h5 fw-bold mb-0">Empreendimento: <span class="text-primary">{{ $project->name }}</span></div>
                        <div class="small text-muted">Informações enviadas pelo proponente</div>
                    </div>
                    <div class="row g-4">
                        <div class="col-lg-6"><div class="h6 fw-bold">Endereço do Empreendimento</div><ul class="list-group">
                            <li class="list-group-item"><strong>Endereço:</strong> {{ $projectAddress ?: '—' }}</li>
                            <li class="list-group-item"><strong>Localidade:</strong> {{ $projectRegion ?: '—' }}</li>
                            <li class="list-group-item"><strong>Site do empreendimento:</strong> {{ $project->site ?: '—' }}</li>
                        </ul></div>
                        <div class="col-lg-6"><div class="h6 fw-bold">Resumo das Unidades</div><ul class="list-group">
                            <li class="list-group-item"><strong>Permutadas:</strong> {{ $project->units_exchanged }}</li>
                            <li class="list-group-item"><strong>Quitadas:</strong> {{ $project->units_paid }}</li>
                            <li class="list-group-item"><strong>Não Quitadas:</strong> {{ $project->units_unpaid }}</li>
                            <li class="list-group-item"><strong>Estoque:</strong> {{ $project->units_stock }}</li>
                            <li class="list-group-item"><strong>Total:</strong> {{ $project->units_total }}</li>
                            <li class="list-group-item"><strong>% Vendidas:</strong> {{ number_format((float) $project->sales_percentage, 2, ',', '.') }}%</li>
                        </ul></div>
                        <div class="col-lg-6"><div class="h6 fw-bold">Resumo Financeiro</div><ul class="list-group">
                            <li class="list-group-item"><strong>Custo Incorrido:</strong> R$ {{ number_format((float) $project->cost_incurred, 2, ',', '.') }}</li>
                            <li class="list-group-item"><strong>Custo a Incorrer:</strong> R$ {{ number_format((float) $project->cost_to_incur, 2, ',', '.') }}</li>
                            <li class="list-group-item"><strong>Custo Total:</strong> R$ {{ number_format((float) $project->cost_total, 2, ',', '.') }}</li>
                            <li class="list-group-item"><strong>Estágio da Obra:</strong> {{ number_format((float) $project->work_stage_percentage, 2, ',', '.') }}%</li>
                            <li class="list-group-item"><strong>VGV Total:</strong> R$ {{ number_format((float) $project->value_total_sale, 2, ',', '.') }}</li>
                            <li class="list-group-item"><strong>Recebíveis:</strong> R$ {{ number_format((float) $paymentFlowTotal, 2, ',', '.') }}</li>
                        </ul></div>
                    </div>
                    <div class="row g-4 mt-1">
                        <div class="col-lg-6"><div class="h6 fw-bold">Valores de Venda</div><ul class="list-group">
                            <li class="list-group-item"><strong>Quitadas:</strong> R$ {{ number_format((float) $project->value_paid, 2, ',', '.') }}</li>
                            <li class="list-group-item"><strong>Vendidas:</strong> R$ {{ number_format((float) $project->value_unpaid, 2, ',', '.') }}</li>
                            <li class="list-group-item"><strong>Estoque:</strong> R$ {{ number_format((float) $project->value_stock, 2, ',', '.') }}</li>
                            <li class="list-group-item"><strong>VGV Total:</strong> R$ {{ number_format((float) $project->value_total_sale, 2, ',', '.') }}</li>
                        </ul></div>
                        <div class="col-lg-6"><div class="h6 fw-bold">Fluxo de Pagamento</div><ul class="list-group">
                            <li class="list-group-item"><strong>Valor já Recebido:</strong> R$ {{ number_format((float) $project->value_received, 2, ',', '.') }}</li>
                            <li class="list-group-item"><strong>A receber até as chaves:</strong> R$ {{ number_format((float) $project->value_until_keys, 2, ',', '.') }}</li>
                            <li class="list-group-item"><strong>A receber pós chaves:</strong> R$ {{ number_format((float) $project->value_post_keys, 2, ',', '.') }}</li>
                            <li class="list-group-item"><strong>Total:</strong> R$ {{ number_format((float) $paymentFlowTotal, 2, ',', '.') }}</li>
                        </ul></div>
                    </div>
                    @if ($project->characteristics)
                        <div class="mt-4"><div class="h6 fw-bold">Características do Empreendimento</div>
                            <div class="row g-3 mb-3">
                                <div class="col-md-2"><strong>Blocos:</strong> {{ $project->characteristics->blocks }}</div>
                                <div class="col-md-2"><strong>Pavimentos:</strong> {{ $project->characteristics->floors }}</div>
                                <div class="col-md-3"><strong>Andares Tipo:</strong> {{ $project->characteristics->typical_floors }}</div>
                                <div class="col-md-3"><strong>Unidades/Andar:</strong> {{ $project->characteristics->units_per_floor }}</div>
                                <div class="col-md-2"><strong>Total:</strong> {{ $project->characteristics->total_units }}</div>
                            </div>
                            @if ($project->characteristics->unitTypes->isNotEmpty())
                                <div class="table-responsive">
                                    <table class="table table-bordered align-middle mb-0">
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
                                            @foreach ($project->characteristics->unitTypes as $unitType)
                                                <tr>
                                                    <td>Tipo {{ $unitType->order }}</td>
                                                    <td>{{ $unitType->total_units }}</td>
                                                    <td>{{ $unitType->bedrooms ?: '—' }}</td>
                                                    <td>{{ $unitType->parking_spaces ?: '—' }}</td>
                                                    <td>{{ number_format((float) $unitType->useful_area, 2, ',', '.') }} m²</td>
                                                    <td>R$ {{ number_format((float) $unitType->average_price, 2, ',', '.') }}</td>
                                                    <td>R$ {{ number_format((float) $unitType->price_per_m2, 2, ',', '.') }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>
                    @endif
                </div></div>
            @endforeach

            @if ($proposal->files->isNotEmpty())
                <div class="card border-0 shadow-sm rounded-4">
                    <div class="card-body p-4">
                        <div class="h5 fw-bold mb-3">Arquivos Anexados</div>
                        <ul class="list-group">
                            @foreach ($proposal->files as $file)
                                <li class="list-group-item">
                                    <a href="{{ route('site.proposal.continuation.files.download', [$access, $file]) }}">
                                        {{ $file->original_name }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endif
        @else
            <div class="card border-0 shadow-sm rounded-4"><div class="card-body p-4 p-lg-5">
                <form method="POST" action="{{ route('site.proposal.continuation.store', $access) }}" class="row g-3" id="formEmpreendimento" enctype="multipart/form-data">@csrf
                    <div class="col-md-5"><label class="form-label">Nome do Empreendimento *</label><input type="text" name="nome" class="form-control" value="{{ old('nome') }}" required></div>
                    <div class="col-md-4"><label class="form-label">Site</label><input type="url" name="site" class="form-control" value="{{ old('site') }}"></div>
                    <div class="col-md-3"><label class="form-label">Valor Solicitado *</label><div class="input-group"><span class="input-group-text">R$</span><input type="text" name="valor_solicitado" class="form-control money" value="{{ old('valor_solicitado') }}" required></div></div>
                    <div class="col-md-4"><label class="form-label">Valor atual de mercado do terreno</label><div class="input-group"><span class="input-group-text">R$</span><input type="text" name="valor_mercado_terreno" class="form-control money" value="{{ old('valor_mercado_terreno') }}"></div></div>
                    <div class="col-md-4"><label class="form-label">Área do Terreno (m²) *</label><input type="number" step="0.01" name="area_terreno" class="form-control" value="{{ old('area_terreno') }}" required></div>
                    <div class="col-md-4"><label class="form-label">Lançamento *</label><input type="month" name="data_lancamento" class="form-control" value="{{ old('data_lancamento') }}" required></div>
                    <div class="col-md-3"><label class="form-label">Lançamento das Vendas *</label><input type="month" name="lancamento_vendas" class="form-control" value="{{ old('lancamento_vendas') }}" required></div>
                    <div class="col-md-3"><label class="form-label">Início das Obras *</label><input type="month" name="inicio_obras" id="inicio_obras" class="form-control" value="{{ old('inicio_obras') }}" required></div>
                    <div class="col-md-3"><label class="form-label">Previsão de Entrega *</label><input type="month" name="previsao_entrega" id="previsao_entrega" class="form-control" value="{{ old('previsao_entrega') }}" required></div>
                    <div class="col-md-3"><label class="form-label">Prazo Remanescente (meses)</label><input type="number" name="prazo_remanescente" id="prazo_remanescente" class="form-control" value="{{ old('prazo_remanescente') }}" readonly></div>
                    <div class="col-md-3"><label class="form-label">CEP *</label><input type="text" name="cep" id="cep" class="form-control" value="{{ old('cep') }}" required></div>
                    <div class="col-md-6"><label class="form-label">Rua</label><input type="text" name="logradouro" id="logradouro" class="form-control" value="{{ old('logradouro') }}" readonly></div>
                    <div class="col-md-3"><label class="form-label">Complemento</label><input type="text" name="complemento" class="form-control" value="{{ old('complemento') }}"></div>
                    <div class="col-md-3"><label class="form-label">Número *</label><input type="text" name="numero" class="form-control" value="{{ old('numero') }}" required></div>
                    <div class="col-md-4"><label class="form-label">Bairro</label><input type="text" name="bairro" id="bairro" class="form-control" value="{{ old('bairro') }}" readonly></div>
                    <div class="col-md-4"><label class="form-label">Cidade</label><input type="text" name="cidade" id="cidade" class="form-control" value="{{ old('cidade') }}" readonly></div>
                    <div class="col-md-1"><label class="form-label">Estado</label><input type="text" name="estado" id="estado" class="form-control" value="{{ old('estado') }}" readonly></div>
                    <div class="col-12"><hr></div>
                    <div class="col-12" id="blocos-empreendimento"><div class="bloco-dinamico">
                        <div class="mb-3"><label class="form-label"><strong>Identificação do Empreendimento</strong></label><input type="text" name="nome_empreendimento[]" class="form-control" placeholder="Ex: Torre Madrid" required></div>
                        <table class="table table-bordered"><thead><tr><th>Permutadas</th><th>Quitadas</th><th>Não Quitadas</th><th>Estoque</th><th>Total</th><th>% Vendidas</th></tr></thead><tbody><tr><td><input type="number" name="unidades_permutadas[]" class="form-control unidade-campo" min="0"></td><td><input type="number" name="unidades_quitadas[]" class="form-control unidade-campo" min="0"></td><td><input type="number" name="unidades_nao_quitadas[]" class="form-control unidade-campo" min="0"></td><td><input type="number" name="unidades_estoque[]" class="form-control unidade-campo" min="0"></td><td><input type="number" name="unidades_total[]" class="form-control" readonly></td><td><input type="number" name="percentual_vendas[]" class="form-control" readonly></td></tr></tbody></table>
                        <table class="table table-bordered"><thead><tr><th>Custo Incorrido</th><th>Custo a Incorrer</th><th>Custo Total</th><th>Estágio da Obra (%)</th></tr></thead><tbody><tr><td><div class="input-group"><span class="input-group-text">R$</span><input type="text" name="custo_incidido[]" class="form-control money"></div></td><td><div class="input-group"><span class="input-group-text">R$</span><input type="text" name="custo_a_incorrer[]" class="form-control money"></div></td><td><div class="input-group"><span class="input-group-text">R$</span><input type="text" name="custo_total[]" class="form-control money" readonly></div></td><td><input type="number" name="estagio_obra[]" class="form-control" readonly></td></tr></tbody></table>
                        <table class="table table-bordered"><thead><tr><th>Quitadas</th><th>Não Quitadas</th><th>Estoque</th><th>Total Venda</th></tr></thead><tbody><tr><td><div class="input-group"><span class="input-group-text">R$</span><input type="text" name="valor_quitadas[]" class="form-control money"></div></td><td><div class="input-group"><span class="input-group-text">R$</span><input type="text" name="valor_nao_quitadas[]" class="form-control money"></div></td><td><div class="input-group"><span class="input-group-text">R$</span><input type="text" name="valor_estoque[]" class="form-control money"></div></td><td><div class="input-group"><span class="input-group-text">R$</span><input type="text" name="valor_total_venda[]" class="form-control money" readonly></div></td></tr></tbody></table>
                        <table class="table table-bordered"><thead><tr><th>Já Recebido</th><th>Até Chaves</th><th>Chaves + Pós Chaves</th></tr></thead><tbody><tr><td><div class="input-group"><span class="input-group-text">R$</span><input type="text" name="valor_ja_recebido[]" class="form-control money"></div></td><td><div class="input-group"><span class="input-group-text">R$</span><input type="text" name="valor_ate_chaves[]" class="form-control money"></div></td><td><div class="input-group"><span class="input-group-text">R$</span><input type="text" name="valor_chaves_pos[]" class="form-control money"></div></td></tr></tbody></table>
                    </div></div>
                    <div class="col-12"><hr></div>
                    <div class="col-12"><h5>Características do Empreendimento</h5><div class="row g-3 mb-3"><div class="col-md-2"><label class="form-label"><b>Blocos</b></label><input type="number" min="1" name="car_bloco" id="car_bloco" class="form-control" required></div><div class="col-md-2"><label class="form-label"><b>Pavimentos</b></label><input type="number" min="1" name="car_pavimentos" id="car_pavimentos" class="form-control" required></div><div class="col-md-2"><label class="form-label"><b>Andares Tipo</b></label><input type="number" min="1" name="car_andares_tipo" id="car_andares_tipo" class="form-control" required></div><div class="col-md-2"><label class="form-label"><b>Unidades/Andar</b></label><input type="number" min="1" name="car_unidades_andar" id="car_unidades_andar" class="form-control" required></div><div class="col-md-2"><label class="form-label"><b>Total</b></label><input type="number" name="car_total" id="car_total" class="form-control" readonly></div></div>
                        <table class="table table-bordered align-middle"><thead><tr><th>&nbsp;</th><th>Tipo 1</th></tr></thead><tbody><tr><th>Total</th><td><input type="number" name="tipo_total[]" class="form-control" min="1" required></td></tr><tr><th>Dormitórios</th><td><input type="text" name="tipo_dormitorios[]" class="form-control" required></td></tr><tr><th>Vagas</th><td><input type="text" name="tipo_vagas[]" class="form-control" required></td></tr><tr><th>Área Útil (m²)</th><td><input type="number" step="0.01" name="tipo_area[]" class="form-control tipo-area" required></td></tr><tr><th>Preço Médio</th><td><div class="input-group"><span class="input-group-text">R$</span><input type="text" name="tipo_preco_medio[]" class="form-control tipo-preco-medio money" required></div></td></tr><tr><th>Preço / m²</th><td><div class="input-group"><span class="input-group-text">R$</span><input type="text" name="tipo_preco_m2[]" class="form-control tipo-preco-m2" readonly></div></td></tr></tbody></table>
                    </div>
                    <div class="col-12"><label class="form-label">Arquivos do Empreendimento</label><input type="file" name="arquivos[]" class="form-control" multiple></div>
                    <div class="col-12"><button type="button" id="addEmpreendimento" class="btn btn-outline-primary">Adicionar Empreendimento</button></div>
                    <div class="col-12"><button type="submit" class="btn btn-brand">Salvar Empreendimento(s)</button></div>
                </form>
            </div></div>
        @endif
    </div></div></div>
</section>
@endsection

@push('scripts')
<script src="https://unpkg.com/imask"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const cepInput = document.getElementById('cep'), inicioObras = document.getElementById('inicio_obras'), previsaoEntrega = document.getElementById('previsao_entrega'), prazoRemanescente = document.getElementById('prazo_remanescente');
    const formatMoney = (input) => { let value = input.value.replace(/\D/g, ''); let floatValue = parseFloat(value) / 100; input.value = isNaN(floatValue) ? '' : floatValue.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
    const parseMoney = (value) => value ? (parseFloat(value.replace(/[^\d,-]/g, '').replace(/\./g, '').replace(',', '.')) || 0) : 0;
    document.querySelectorAll('.money').forEach((input) => input.addEventListener('input', (event) => formatMoney(event.target)));
    document.querySelectorAll('.bloco-dinamico').forEach((bloco) => bindBlock(bloco));
    ['car_bloco', 'car_andares_tipo', 'car_unidades_andar'].forEach((id) => document.getElementById(id)?.addEventListener('input', updateCharacteristics));
    [inicioObras, previsaoEntrega].forEach((input) => input?.addEventListener('change', updateRemainingMonths));
    document.addEventListener('input', (event) => { if (event.target.classList.contains('tipo-area') || event.target.classList.contains('tipo-preco-medio')) updatePricePerM2(); });
    document.getElementById('addEmpreendimento')?.addEventListener('click', function () { const container = document.getElementById('blocos-empreendimento'); const base = container.querySelector('.bloco-dinamico'); const clone = base.cloneNode(true); clone.querySelectorAll('input').forEach((input) => input.value = ''); container.appendChild(clone); clone.querySelectorAll('.money').forEach((input) => input.addEventListener('input', (event) => formatMoney(event.target))); bindBlock(clone); });
    if (cepInput) { IMask(cepInput, { mask: '00000-000' }); cepInput.addEventListener('blur', function () { const cep = this.value.replace(/\D/g, ''); if (cep.length !== 8) return; fetch(`https://viacep.com.br/ws/${cep}/json/`).then((response) => response.json()).then((data) => { if (!data.erro) { document.getElementById('logradouro').value = data.logradouro || ''; document.getElementById('bairro').value = data.bairro || ''; document.getElementById('cidade').value = data.localidade || ''; document.getElementById('estado').value = data.uf || ''; } }); }); }
    function bindBlock(bloco) { const unidadeCampos = bloco.querySelectorAll('.unidade-campo'), totalUnidades = bloco.querySelector('input[name="unidades_total[]"]'), percentualVendas = bloco.querySelector('input[name="percentual_vendas[]"]'), custoIncidido = bloco.querySelector('input[name="custo_incidido[]"]'), custoAIncorrer = bloco.querySelector('input[name="custo_a_incorrer[]"]'), custoTotal = bloco.querySelector('input[name="custo_total[]"]'), estagioObra = bloco.querySelector('input[name="estagio_obra[]"]'), valorQuitadas = bloco.querySelector('input[name="valor_quitadas[]"]'), valorNaoQuitadas = bloco.querySelector('input[name="valor_nao_quitadas[]"]'), valorEstoque = bloco.querySelector('input[name="valor_estoque[]"]'), valorTotalVenda = bloco.querySelector('input[name="valor_total_venda[]"]'); unidadeCampos.forEach((input) => input.addEventListener('input', function () { const values = Array.from(unidadeCampos).map((field) => parseInt(field.value || '0', 10) || 0), total = values.reduce((acc, item) => acc + item, 0), quitadas = parseInt(bloco.querySelector('input[name="unidades_quitadas[]"]').value || '0', 10) || 0, naoQuitadas = parseInt(bloco.querySelector('input[name="unidades_nao_quitadas[]"]').value || '0', 10) || 0, permutadas = parseInt(bloco.querySelector('input[name="unidades_permutadas[]"]').value || '0', 10) || 0, base = total - permutadas; totalUnidades.value = total; percentualVendas.value = base > 0 ? (((quitadas + naoQuitadas) / base) * 100).toFixed(2) : '0.00'; })); [custoIncidido, custoAIncorrer, valorQuitadas, valorNaoQuitadas, valorEstoque].forEach((input) => input.addEventListener('input', function () { const totalCost = parseMoney(custoIncidido.value) + parseMoney(custoAIncorrer.value), totalSale = parseMoney(valorQuitadas.value) + parseMoney(valorNaoQuitadas.value) + parseMoney(valorEstoque.value); custoTotal.value = totalCost ? totalCost.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : ''; estagioObra.value = totalCost > 0 ? ((parseMoney(custoIncidido.value) / totalCost) * 100).toFixed(2) : '0.00'; valorTotalVenda.value = totalSale ? totalSale.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : ''; })); }
    function updateRemainingMonths() { if (!inicioObras || !previsaoEntrega || !prazoRemanescente || !inicioObras.value || !previsaoEntrega.value) return; const [sy, sm] = inicioObras.value.split('-').map(Number), [ey, em] = previsaoEntrega.value.split('-').map(Number); prazoRemanescente.value = ((ey - sy) * 12) + (em - sm); }
    function updateCharacteristics() { const total = (parseInt(document.getElementById('car_bloco')?.value || '0', 10) || 0) * (parseInt(document.getElementById('car_andares_tipo')?.value || '0', 10) || 0) * (parseInt(document.getElementById('car_unidades_andar')?.value || '0', 10) || 0); const field = document.getElementById('car_total'); if (field) field.value = total || ''; }
    function updatePricePerM2() { const areas = document.querySelectorAll('.tipo-area'), prices = document.querySelectorAll('.tipo-preco-medio'), pricePerM2 = document.querySelectorAll('.tipo-preco-m2'); prices.forEach((field, index) => { const area = parseFloat(areas[index]?.value || '0'), price = parseMoney(field.value); pricePerM2[index].value = area > 0 ? (price / area).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : ''; }); }
});
</script>
@endpush

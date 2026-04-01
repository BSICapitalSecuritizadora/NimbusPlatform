<section class="py-5" style="min-height: 70vh; background: linear-gradient(180deg, rgba(255,255,255,0.55), transparent 180px), radial-gradient(1100px 420px at 50% -8%, rgba(0,32,91,0.10), transparent 72%), var(--bg);">
    <div class="container py-lg-4">
        <div class="row justify-content-center">
            <div class="col-xl-10">
                <div class="d-flex flex-column gap-4">

                    {{-- Alerts --}}
                    @if ($successMessage || session('success'))
                        <div class="rounded-2xl border-0 shadow-sm px-4 py-3 mb-0 bg-green-50 text-green-800 border border-green-200">
                            {{ $successMessage ?? session('success') }}
                        </div>
                    @endif

                    @if ($errors->any() && ! $showReadonlySummary)
                        <div class="rounded-2xl border-0 shadow-sm px-4 py-3 mb-0 bg-red-50 text-red-800 border border-red-200">
                            <strong class="d-block mb-2">Revise os campos destacados antes de salvar.</strong>
                            <ul class="mb-0 ps-3">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    {{-- Hero Card --}}
                    <div class="relative overflow-hidden rounded-[30px] border border-[var(--border)] shadow-[0_20px_45px_rgba(0,32,91,0.08)]"
                         style="background: linear-gradient(145deg, color-mix(in oklab, var(--surface) 95%, white 5%), color-mix(in oklab, var(--surface) 88%, var(--brand) 12%));">
                        <div class="absolute inset-y-0 left-0 w-[6px] bg-gradient-to-b from-[var(--gold)] to-[var(--brand)]"></div>
                        <div class="p-4 p-lg-5">
                            <div class="row g-4 align-items-start">
                                <div class="col-lg-8">
                                    <div class="text-[0.78rem] font-bold tracking-[0.12em] uppercase text-[var(--gold)] mb-3">Portal da Proposta</div>
                                    <h1 class="text-[clamp(2rem,3vw,2.8rem)] font-extrabold tracking-[-0.03em] text-[var(--brand)] mb-2">Formulário de Empreendimento</h1>
                                    <div class="text-[var(--muted)] fs-5">{{ $proposal->company->name }} • {{ $proposal->company->cnpj }}</div>
                                    <div class="text-[var(--muted)] mt-3">
                                        Acompanhe os dados enviados e, quando necessário, complemente as informações do empreendimento no mesmo padrão visual da plataforma.
                                    </div>
                                </div>

                                <div class="col-lg-4 text-lg-end">
                                    <div class="mb-[0.45rem] text-[0.76rem] font-bold tracking-[0.08em] uppercase text-[var(--muted)]">Status Atual</div>
                                    <span class="inline-flex items-center gap-[0.65rem] px-4 py-3 rounded-full border font-bold text-[var(--brand)]"
                                          style="border-color: color-mix(in oklab, var(--gold) 30%, var(--border) 70%); background: color-mix(in oklab, var(--gold) 10%, var(--surface) 90%);">
                                        <span class="inline-block w-[0.7rem] h-[0.7rem] rounded-full bg-[var(--gold)] shadow-[0_0_0_0.35rem_rgba(212,175,55,0.18)]"></span>
                                        {{ $proposal->status_label }}
                                    </span>
                                </div>
                            </div>

                            <div class="row g-3 mt-2">
                                @foreach ([
                                    ['Empreendimentos', $projectCount, 'Itens vinculados à proposta atual.'],
                                    ['Arquivos Enviados', $fileCount, 'Documentos compartilhados no fluxo.'],
                                    ['Última Atualização', $proposal->completed_at?->format('d/m/Y H:i') ?? 'Em preenchimento', 'Registro mais recente disponível nesta proposta.'],
                                ] as [$metaLabel, $metaValue, $metaCaption])
                                    <div class="col-md-4">
                                        <div class="h-full p-[1.15rem_1.2rem] border border-[var(--border)] rounded-[22px] bg-[color-mix(in_oklab,var(--surface)_94%,var(--brand)_6%)]">
                                            <div class="mb-[0.45rem] text-[0.76rem] font-bold tracking-[0.08em] uppercase text-[var(--muted)]">{{ $metaLabel }}</div>
                                            <div class="text-[var(--brand)] font-bold">{{ $metaValue }}</div>
                                            <div class="text-[var(--muted)] small">{{ $metaCaption }}</div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    {{-- Summary (readonly) or Form --}}
                    @if ($showReadonlySummary)
                        @include('site.proposal.partials.summary')
                    @else
                        {{-- Intro Card --}}
                        <div class="rounded-[30px] border border-[var(--border)] shadow-[0_20px_45px_rgba(0,32,91,0.08)] bg-[var(--surface,#fff)]">
                            <div class="p-4 p-lg-5">
                                <div class="row g-4 align-items-center">
                                    <div class="col-lg-7">
                                        <div class="text-[0.78rem] font-bold tracking-[0.12em] uppercase text-[var(--gold)] mb-2">Próxima Etapa</div>
                                        <h2 class="text-[1.65rem] font-extrabold tracking-[-0.03em] text-[var(--brand)] mb-[0.35rem]">
                                            {{ $proposal->status === \App\Models\Proposal::STATUS_AWAITING_INFORMATION
                                                ? 'Atualize as informações solicitadas'
                                                : 'Complementar informações do empreendimento' }}
                                        </h2>
                                        <p class="text-[var(--muted)] mb-0">
                                            {{ $proposal->status === \App\Models\Proposal::STATUS_AWAITING_INFORMATION
                                                ? 'O time comercial solicitou novos dados. Revise os campos abaixo, atualize o que for necessário e salve novamente a proposta.'
                                                : 'Preencha os dados abaixo com atenção. Essa etapa organiza o empreendimento, unidades, cronograma, fluxo financeiro e documentos complementares.' }}
                                        </p>
                                    </div>

                                    <div class="col-lg-5">
                                        <div class="p-[1.2rem_1.25rem] rounded-3xl border border-[color-mix(in_oklab,var(--gold)_18%,var(--border)_82%)] bg-gradient-to-br from-[color-mix(in_oklab,var(--brand)_8%,var(--surface)_92%)] to-[color-mix(in_oklab,var(--gold)_10%,var(--surface)_90%)]">
                                            <strong class="d-block mb-2 text-[var(--brand)] font-bold">Antes de enviar</strong>
                                            <p class="mb-0 text-[var(--muted)]">Revise os dados gerais da operação, preencha cada empreendimento com identificação clara e anexe os documentos que apoiam a análise.</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Form Card --}}
                        <div class="rounded-[30px] border border-[var(--border)] shadow-[0_20px_45px_rgba(0,32,91,0.08)] bg-[var(--surface,#fff)]">
                            <div class="p-4 p-lg-5">
                                <form wire:submit="save" class="row g-4">

                                    {{-- Section: Dados Gerais --}}
                                    <div class="col-12">
                                        <div class="text-[0.78rem] font-bold tracking-[0.12em] uppercase text-[var(--gold)] mb-2">Dados Gerais</div>
                                        <h2 class="h4 text-[var(--brand)] font-extrabold tracking-[-0.03em] mb-1">Informações da operação</h2>
                                        <p class="text-[var(--muted)] mb-0">Dados principais para identificação da operação, cronograma e endereço do empreendimento.</p>
                                    </div>

                                    <div class="col-md-5">
                                        <flux:field>
                                            <flux:label>Nome do Empreendimento *</flux:label>
                                            <flux:input wire:model.blur="developmentName" />
                                            <flux:error name="developmentName" />
                                        </flux:field>
                                    </div>

                                    <div class="col-md-4">
                                        <flux:field>
                                            <flux:label>Site</flux:label>
                                            <flux:input type="url" wire:model.blur="websiteUrl" />
                                            <flux:error name="websiteUrl" />
                                        </flux:field>
                                    </div>

                                    <div class="col-md-3">
                                        <flux:field>
                                            <flux:label>Valor Solicitado *</flux:label>
                                            <div class="flex">
                                                <span class="inline-flex items-center px-3 border border-r-0 border-zinc-300 dark:border-zinc-600 rounded-l-lg bg-zinc-50 dark:bg-zinc-800 text-zinc-500 dark:text-zinc-400 text-sm">R$</span>
                                                <flux:input class="rounded-l-none!" wire:model.blur="requestedAmount" mask:dynamic="$money($input, ',', '.', 2)" inputmode="decimal" />
                                            </div>
                                            <flux:error name="requestedAmount" />
                                        </flux:field>
                                    </div>

                                    <div class="col-md-4">
                                        <flux:field>
                                            <flux:label>Valor atual de mercado do terreno</flux:label>
                                            <div class="flex">
                                                <span class="inline-flex items-center px-3 border border-r-0 border-zinc-300 dark:border-zinc-600 rounded-l-lg bg-zinc-50 dark:bg-zinc-800 text-zinc-500 dark:text-zinc-400 text-sm">R$</span>
                                                <flux:input class="rounded-l-none!" wire:model.blur="landMarketValue" mask:dynamic="$money($input, ',', '.', 2)" inputmode="decimal" />
                                            </div>
                                            <flux:error name="landMarketValue" />
                                        </flux:field>
                                    </div>

                                    <div class="col-md-4">
                                        <flux:field>
                                            <flux:label>Área do Terreno (m²) *</flux:label>
                                            <flux:input type="number" step="0.01" wire:model.blur="landArea" />
                                            <flux:error name="landArea" />
                                        </flux:field>
                                    </div>

                                    <div class="col-md-4">
                                        <flux:field>
                                            <flux:label>Lançamento *</flux:label>
                                            <flux:input type="month" wire:model.blur="launchDate" />
                                            <flux:error name="launchDate" />
                                        </flux:field>
                                    </div>

                                    <div class="col-md-3">
                                        <flux:field>
                                            <flux:label>Lançamento das Vendas *</flux:label>
                                            <flux:input type="month" wire:model.blur="salesLaunchDate" />
                                            <flux:error name="salesLaunchDate" />
                                        </flux:field>
                                    </div>

                                    <div class="col-md-3">
                                        <flux:field>
                                            <flux:label>Início das Obras *</flux:label>
                                            <flux:input type="month" wire:model.blur="constructionStartDate" />
                                            <flux:error name="constructionStartDate" />
                                        </flux:field>
                                    </div>

                                    <div class="col-md-3">
                                        <flux:field>
                                            <flux:label>Previsão de Entrega *</flux:label>
                                            <flux:input type="month" wire:model.blur="deliveryForecastDate" />
                                            <flux:error name="deliveryForecastDate" />
                                        </flux:field>
                                    </div>

                                    <div class="col-md-3">
                                        <flux:field>
                                            <flux:label>Prazo Remanescente (meses)</flux:label>
                                            <flux:input type="number" wire:model="remainingMonths" readonly />
                                        </flux:field>
                                    </div>

                                    <div class="col-md-3">
                                        <flux:field>
                                            <flux:label>CEP *</flux:label>
                                            <flux:input wire:model.blur="zipCode" mask="99999-999" inputmode="numeric" />
                                            <flux:error name="zipCode" />
                                            <flux:description wire:loading wire:target="zipCode">Buscando endereço pelo CEP...</flux:description>
                                        </flux:field>
                                    </div>

                                    <div class="col-md-6">
                                        <flux:field>
                                            <flux:label>Rua *</flux:label>
                                            <flux:input wire:model.blur="street" />
                                            <flux:error name="street" />
                                        </flux:field>
                                    </div>

                                    <div class="col-md-3">
                                        <flux:field>
                                            <flux:label>Complemento</flux:label>
                                            <flux:input wire:model.blur="addressComplement" />
                                            <flux:error name="addressComplement" />
                                        </flux:field>
                                    </div>

                                    <div class="col-md-3">
                                        <flux:field>
                                            <flux:label>Número *</flux:label>
                                            <flux:input wire:model.blur="addressNumber" />
                                            <flux:error name="addressNumber" />
                                        </flux:field>
                                    </div>

                                    <div class="col-md-4">
                                        <flux:field>
                                            <flux:label>Bairro *</flux:label>
                                            <flux:input wire:model.blur="neighborhood" />
                                            <flux:error name="neighborhood" />
                                        </flux:field>
                                    </div>

                                    <div class="col-md-4">
                                        <flux:field>
                                            <flux:label>Cidade *</flux:label>
                                            <flux:input wire:model.blur="city" />
                                            <flux:error name="city" />
                                        </flux:field>
                                    </div>

                                    <div class="col-md-2">
                                        <flux:field>
                                            <flux:label>Estado *</flux:label>
                                            <flux:input maxlength="2" wire:model.blur="state" />
                                            <flux:error name="state" />
                                        </flux:field>
                                    </div>

                                    <div class="col-12"><hr class="my-2 border-[var(--border)] opacity-100"></div>

                                    {{-- Section: Empreendimentos --}}
                                    <div class="col-12 d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3">
                                        <div>
                                            <div class="text-[0.78rem] font-bold tracking-[0.12em] uppercase text-[var(--gold)] mb-2">Empreendimentos</div>
                                            <h2 class="h4 text-[var(--brand)] font-extrabold tracking-[-0.03em] mb-1">Cadastro das torres e blocos</h2>
                                            <p class="text-[var(--muted)] mb-0">Se houver mais de um empreendimento na mesma operação, adicione quantos blocos forem necessários.</p>
                                        </div>
                                        <flux:button type="button" variant="outline" wire:click="addProject">Adicionar Empreendimento</flux:button>
                                    </div>

                                    <div class="col-12 d-flex flex-column gap-4">
                                        @foreach ($projects as $index => $project)
                                            <div wire:key="proposal-project-{{ $index }}">
                                                <div class="overflow-hidden border border-[var(--border)] rounded-[22px] bg-[color-mix(in_oklab,var(--surface)_96%,var(--brand)_4%)] p-3 p-lg-4">
                                                    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-4">
                                                        <div>
                                                            <div class="text-[0.78rem] font-bold tracking-[0.12em] uppercase text-[var(--gold)] mb-2">
                                                                Empreendimento {{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}
                                                            </div>
                                                            <h3 class="h5 text-[var(--brand)] font-extrabold tracking-[-0.03em] mb-0">Resumo operacional e financeiro</h3>
                                                        </div>

                                                        @if ($projectCount > 1)
                                                            <flux:button type="button" variant="ghost" class="text-red-600!" wire:click="removeProject({{ $index }})">
                                                                Remover
                                                            </flux:button>
                                                        @endif
                                                    </div>

                                                    <input type="hidden" wire:model="projects.{{ $index }}.id">

                                                    <div class="mb-4">
                                                        <flux:field>
                                                            <flux:label>Identificação do Empreendimento *</flux:label>
                                                            <flux:input wire:model.blur="projects.{{ $index }}.name" />
                                                            <flux:error name="projects.{{ $index }}.name" />
                                                        </flux:field>
                                                    </div>

                                                    <div class="d-flex flex-column gap-4">
                                                        {{-- Resumo das Unidades --}}
                                                        <div>
                                                            <div class="flex items-center gap-[0.7rem] mb-3 text-[var(--brand)] font-bold">
                                                                <span class="inline-block w-2.5 h-2.5 rounded-full bg-[var(--gold)] shadow-[0_0_0_0.3rem_rgba(212,175,55,0.15)]"></span>
                                                                Resumo das Unidades
                                                            </div>
                                                            <div class="overflow-hidden border border-[var(--border)] rounded-[22px] bg-[color-mix(in_oklab,var(--surface)_96%,var(--brand)_4%)]">
                                                                <div class="table-responsive">
                                                                    <table class="table table-bordered mb-0">
                                                                        <thead>
                                                                            <tr>
                                                                                @foreach (['Permutadas', 'Quitadas', 'Não Quitadas', 'Estoque', 'Total', '% Vendidas'] as $col)
                                                                                    <th class="bg-[color-mix(in_oklab,var(--brand)_8%,var(--surface)_92%)] text-[var(--brand)] text-[0.76rem] font-bold tracking-[0.08em] uppercase">{{ $col }}</th>
                                                                                @endforeach
                                                                            </tr>
                                                                        </thead>
                                                                        <tbody>
                                                                            <tr>
                                                                                <td><flux:input type="number" min="0" wire:model.live.debounce.300ms="projects.{{ $index }}.exchangedUnits" /></td>
                                                                                <td><flux:input type="number" min="0" wire:model.live.debounce.300ms="projects.{{ $index }}.paidUnits" /></td>
                                                                                <td><flux:input type="number" min="0" wire:model.live.debounce.300ms="projects.{{ $index }}.unpaidUnits" /></td>
                                                                                <td><flux:input type="number" min="0" wire:model.live.debounce.300ms="projects.{{ $index }}.stockUnits" /></td>
                                                                                <td><flux:input type="number" wire:model="projects.{{ $index }}.totalUnits" readonly /></td>
                                                                                <td><flux:input wire:model="projects.{{ $index }}.salesPercentage" readonly /></td>
                                                                            </tr>
                                                                        </tbody>
                                                                    </table>
                                                                </div>
                                                            </div>
                                                        </div>

                                                        {{-- Resumo Financeiro --}}
                                                        <div>
                                                            <div class="flex items-center gap-[0.7rem] mb-3 text-[var(--brand)] font-bold">
                                                                <span class="inline-block w-2.5 h-2.5 rounded-full bg-[var(--gold)] shadow-[0_0_0_0.3rem_rgba(212,175,55,0.15)]"></span>
                                                                Resumo Financeiro
                                                            </div>
                                                            <div class="overflow-hidden border border-[var(--border)] rounded-[22px] bg-[color-mix(in_oklab,var(--surface)_96%,var(--brand)_4%)]">
                                                                <div class="table-responsive">
                                                                    <table class="table table-bordered mb-0">
                                                                        <thead>
                                                                            <tr>
                                                                                @foreach (['Custo Incorrido', 'Custo a Incorrer', 'Custo Total', 'Estágio da Obra (%)'] as $col)
                                                                                    <th class="bg-[color-mix(in_oklab,var(--brand)_8%,var(--surface)_92%)] text-[var(--brand)] text-[0.76rem] font-bold tracking-[0.08em] uppercase">{{ $col }}</th>
                                                                                @endforeach
                                                                            </tr>
                                                                        </thead>
                                                                        <tbody>
                                                                            <tr>
                                                                                <td>
                                                                                    <div class="flex">
                                                                                        <span class="inline-flex items-center px-3 border border-r-0 border-zinc-300 dark:border-zinc-600 rounded-l-lg bg-zinc-50 dark:bg-zinc-800 text-zinc-500 dark:text-zinc-400 text-sm">R$</span>
                                                                                        <flux:input class="rounded-l-none!" inputmode="decimal" wire:model.blur="projects.{{ $index }}.incurredCost" mask:dynamic="$money($input, ',', '.', 2)" />
                                                                                    </div>
                                                                                </td>
                                                                                <td>
                                                                                    <div class="flex">
                                                                                        <span class="inline-flex items-center px-3 border border-r-0 border-zinc-300 dark:border-zinc-600 rounded-l-lg bg-zinc-50 dark:bg-zinc-800 text-zinc-500 dark:text-zinc-400 text-sm">R$</span>
                                                                                        <flux:input class="rounded-l-none!" inputmode="decimal" wire:model.blur="projects.{{ $index }}.costToIncur" mask:dynamic="$money($input, ',', '.', 2)" />
                                                                                    </div>
                                                                                </td>
                                                                                <td>
                                                                                    <div class="flex">
                                                                                        <span class="inline-flex items-center px-3 border border-r-0 border-zinc-300 dark:border-zinc-600 rounded-l-lg bg-zinc-50 dark:bg-zinc-800 text-zinc-500 dark:text-zinc-400 text-sm">R$</span>
                                                                                        <flux:input class="rounded-l-none!" wire:model="projects.{{ $index }}.totalCost" readonly />
                                                                                    </div>
                                                                                </td>
                                                                                <td><flux:input wire:model="projects.{{ $index }}.workStagePercentage" readonly /></td>
                                                                            </tr>
                                                                        </tbody>
                                                                    </table>
                                                                </div>
                                                            </div>
                                                        </div>

                                                        {{-- Valores de Venda --}}
                                                        <div>
                                                            <div class="flex items-center gap-[0.7rem] mb-3 text-[var(--brand)] font-bold">
                                                                <span class="inline-block w-2.5 h-2.5 rounded-full bg-[var(--gold)] shadow-[0_0_0_0.3rem_rgba(212,175,55,0.15)]"></span>
                                                                Valores de Venda
                                                            </div>
                                                            <div class="overflow-hidden border border-[var(--border)] rounded-[22px] bg-[color-mix(in_oklab,var(--surface)_96%,var(--brand)_4%)]">
                                                                <div class="table-responsive">
                                                                    <table class="table table-bordered mb-0">
                                                                        <thead>
                                                                            <tr>
                                                                                @foreach (['Quitadas', 'Não Quitadas', 'Estoque', 'VGV Total'] as $col)
                                                                                    <th class="bg-[color-mix(in_oklab,var(--brand)_8%,var(--surface)_92%)] text-[var(--brand)] text-[0.76rem] font-bold tracking-[0.08em] uppercase">{{ $col }}</th>
                                                                                @endforeach
                                                                            </tr>
                                                                        </thead>
                                                                        <tbody>
                                                                            <tr>
                                                                                @foreach (['paidSalesValue', 'unpaidSalesValue', 'stockSalesValue'] as $field)
                                                                                    <td>
                                                                                        <div class="flex">
                                                                                            <span class="inline-flex items-center px-3 border border-r-0 border-zinc-300 dark:border-zinc-600 rounded-l-lg bg-zinc-50 dark:bg-zinc-800 text-zinc-500 dark:text-zinc-400 text-sm">R$</span>
                                                                                            <flux:input class="rounded-l-none!" inputmode="decimal" wire:model.blur="projects.{{ $index }}.{{ $field }}" mask:dynamic="$money($input, ',', '.', 2)" />
                                                                                        </div>
                                                                                    </td>
                                                                                @endforeach
                                                                                <td>
                                                                                    <div class="flex">
                                                                                        <span class="inline-flex items-center px-3 border border-r-0 border-zinc-300 dark:border-zinc-600 rounded-l-lg bg-zinc-50 dark:bg-zinc-800 text-zinc-500 dark:text-zinc-400 text-sm">R$</span>
                                                                                        <flux:input class="rounded-l-none!" wire:model="projects.{{ $index }}.grossSalesValue" readonly />
                                                                                    </div>
                                                                                </td>
                                                                            </tr>
                                                                        </tbody>
                                                                    </table>
                                                                </div>
                                                            </div>
                                                        </div>

                                                        {{-- Fluxo de Pagamento --}}
                                                        <div>
                                                            <div class="flex items-center gap-[0.7rem] mb-3 text-[var(--brand)] font-bold">
                                                                <span class="inline-block w-2.5 h-2.5 rounded-full bg-[var(--gold)] shadow-[0_0_0_0.3rem_rgba(212,175,55,0.15)]"></span>
                                                                Fluxo de Pagamento
                                                            </div>
                                                            <div class="overflow-hidden border border-[var(--border)] rounded-[22px] bg-[color-mix(in_oklab,var(--surface)_96%,var(--brand)_4%)]">
                                                                <div class="table-responsive">
                                                                    <table class="table table-bordered mb-0">
                                                                        <thead>
                                                                            <tr>
                                                                                @foreach (['Já Recebido', 'Até Chaves', 'Chaves + Pós Chaves'] as $col)
                                                                                    <th class="bg-[color-mix(in_oklab,var(--brand)_8%,var(--surface)_92%)] text-[var(--brand)] text-[0.76rem] font-bold tracking-[0.08em] uppercase">{{ $col }}</th>
                                                                                @endforeach
                                                                            </tr>
                                                                        </thead>
                                                                        <tbody>
                                                                            <tr>
                                                                                @foreach (['receivedValue', 'valueUntilKeys', 'valueAfterKeys'] as $field)
                                                                                    <td>
                                                                                        <div class="flex">
                                                                                            <span class="inline-flex items-center px-3 border border-r-0 border-zinc-300 dark:border-zinc-600 rounded-l-lg bg-zinc-50 dark:bg-zinc-800 text-zinc-500 dark:text-zinc-400 text-sm">R$</span>
                                                                                            <flux:input class="rounded-l-none!" inputmode="decimal" wire:model.blur="projects.{{ $index }}.{{ $field }}" mask:dynamic="$money($input, ',', '.', 2)" />
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
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>

                                    <div class="col-12"><hr class="my-2 border-[var(--border)] opacity-100"></div>

                                    {{-- Section: Características --}}
                                    <div class="col-12 d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3">
                                        <div>
                                            <div class="text-[0.78rem] font-bold tracking-[0.12em] uppercase text-[var(--gold)] mb-2">Características</div>
                                            <h2 class="h4 text-[var(--brand)] font-extrabold tracking-[-0.03em] mb-1">Características do Empreendimento</h2>
                                            <p class="text-[var(--muted)] mb-0">Configuração física do produto e dados das tipologias da operação. Adicione um ou mais tipos conforme necessário.</p>
                                        </div>
                                        <flux:button type="button" variant="outline" wire:click="addUnitType">Adicionar Tipo</flux:button>
                                    </div>

                                    <div class="col-12">
                                        <div class="overflow-hidden border border-[var(--border)] rounded-[22px] bg-[color-mix(in_oklab,var(--surface)_96%,var(--brand)_4%)] p-3 p-lg-4">
                                            <div class="row g-3 mb-4">
                                                <div class="col-md-2">
                                                    <flux:field>
                                                        <flux:label>Blocos *</flux:label>
                                                        <flux:input type="number" min="1" wire:model.live.debounce.300ms="blockCount" />
                                                        <flux:error name="blockCount" />
                                                    </flux:field>
                                                </div>

                                                <div class="col-md-2">
                                                    <flux:field>
                                                        <flux:label>Pavimentos *</flux:label>
                                                        <flux:input type="number" min="1" wire:model.live.debounce.300ms="floorCount" />
                                                        <flux:error name="floorCount" />
                                                    </flux:field>
                                                </div>

                                                <div class="col-md-3">
                                                    <flux:field>
                                                        <flux:label>Andares Tipo *</flux:label>
                                                        <flux:input type="number" min="1" wire:model.live.debounce.300ms="typicalFloorCount" />
                                                        <flux:error name="typicalFloorCount" />
                                                    </flux:field>
                                                </div>

                                                <div class="col-md-3">
                                                    <flux:field>
                                                        <flux:label>Unidades/Andar *</flux:label>
                                                        <flux:input type="number" min="1" wire:model.live.debounce.300ms="unitsPerFloor" />
                                                        <flux:error name="unitsPerFloor" />
                                                    </flux:field>
                                                </div>

                                                <div class="col-md-2">
                                                    <flux:field>
                                                        <flux:label>Total</flux:label>
                                                        <flux:input type="number" wire:model="totalUnits" readonly />
                                                    </flux:field>
                                                </div>
                                            </div>

                                            <div class="overflow-hidden border border-[var(--border)] rounded-[22px] bg-[color-mix(in_oklab,var(--surface)_96%,var(--brand)_4%)]">
                                                <div class="table-responsive">
                                                    <table class="table table-bordered align-middle mb-0">
                                                        <thead>
                                                            <tr>
                                                                <th class="bg-[color-mix(in_oklab,var(--brand)_8%,var(--surface)_92%)] text-[var(--brand)] text-[0.76rem] font-bold tracking-[0.08em] uppercase">&nbsp;</th>
                                                                @foreach ($unitTypes as $typeIndex => $typeRow)
                                                                    <th class="bg-[color-mix(in_oklab,var(--brand)_8%,var(--surface)_92%)] text-[var(--brand)] text-[0.76rem] font-bold tracking-[0.08em] uppercase" wire:key="type-header-{{ $typeIndex }}">
                                                                        <div class="d-flex justify-content-between align-items-center gap-2">
                                                                            <span>Tipo {{ $typeIndex + 1 }}</span>
                                                                            @if (count($unitTypes) > 1)
                                                                                <flux:button type="button" variant="ghost" size="sm" class="text-red-500! p-0!" wire:click="removeUnitType({{ $typeIndex }})">
                                                                                    Remover
                                                                                </flux:button>
                                                                            @endif
                                                                        </div>
                                                                    </th>
                                                                @endforeach
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <tr>
                                                                <th class="text-[var(--muted)] text-[0.76rem] font-bold uppercase tracking-[0.08em]">Total *</th>
                                                                @foreach ($unitTypes as $typeIndex => $typeRow)
                                                                    <td wire:key="type-total-{{ $typeIndex }}">
                                                                        <flux:input type="number" min="1" wire:model.live.debounce.300ms="unitTypes.{{ $typeIndex }}.totalUnits" />
                                                                    </td>
                                                                @endforeach
                                                            </tr>
                                                            <tr>
                                                                <th class="text-[var(--muted)] text-[0.76rem] font-bold uppercase tracking-[0.08em]">Dormitórios *</th>
                                                                @foreach ($unitTypes as $typeIndex => $typeRow)
                                                                    <td wire:key="type-bedrooms-{{ $typeIndex }}">
                                                                        <flux:input wire:model.blur="unitTypes.{{ $typeIndex }}.bedrooms" />
                                                                    </td>
                                                                @endforeach
                                                            </tr>
                                                            <tr>
                                                                <th class="text-[var(--muted)] text-[0.76rem] font-bold uppercase tracking-[0.08em]">Vagas *</th>
                                                                @foreach ($unitTypes as $typeIndex => $typeRow)
                                                                    <td wire:key="type-parking-{{ $typeIndex }}">
                                                                        <flux:input wire:model.blur="unitTypes.{{ $typeIndex }}.parkingSpaces" />
                                                                    </td>
                                                                @endforeach
                                                            </tr>
                                                            <tr>
                                                                <th class="text-[var(--muted)] text-[0.76rem] font-bold uppercase tracking-[0.08em]">Área Útil (m²) *</th>
                                                                @foreach ($unitTypes as $typeIndex => $typeRow)
                                                                    <td wire:key="type-area-{{ $typeIndex }}">
                                                                        <flux:input type="number" step="0.01" wire:model.live.debounce.300ms="unitTypes.{{ $typeIndex }}.usableArea" />
                                                                    </td>
                                                                @endforeach
                                                            </tr>
                                                            <tr>
                                                                <th class="text-[var(--muted)] text-[0.76rem] font-bold uppercase tracking-[0.08em]">Preço Médio *</th>
                                                                @foreach ($unitTypes as $typeIndex => $typeRow)
                                                                    <td wire:key="type-average-price-{{ $typeIndex }}">
                                                                        <div class="flex">
                                                                            <span class="inline-flex items-center px-3 border border-r-0 border-zinc-300 dark:border-zinc-600 rounded-l-lg bg-zinc-50 dark:bg-zinc-800 text-zinc-500 dark:text-zinc-400 text-sm">R$</span>
                                                                            <flux:input class="rounded-l-none!" inputmode="decimal" wire:model.blur="unitTypes.{{ $typeIndex }}.averagePrice" mask:dynamic="$money($input, ',', '.', 2)" />
                                                                        </div>
                                                                    </td>
                                                                @endforeach
                                                            </tr>
                                                            <tr>
                                                                <th class="text-[var(--muted)] text-[0.76rem] font-bold uppercase tracking-[0.08em]">Preço / m²</th>
                                                                @foreach ($unitTypes as $typeIndex => $typeRow)
                                                                    <td wire:key="type-price-per-m2-{{ $typeIndex }}">
                                                                        <div class="flex">
                                                                            <span class="inline-flex items-center px-3 border border-r-0 border-zinc-300 dark:border-zinc-600 rounded-l-lg bg-zinc-50 dark:bg-zinc-800 text-zinc-500 dark:text-zinc-400 text-sm">R$</span>
                                                                            <flux:input class="rounded-l-none!" wire:model="unitTypes.{{ $typeIndex }}.pricePerSquareMeter" readonly />
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

                                    {{-- File Upload --}}
                                    <div class="col-12">
                                        <flux:field>
                                            <flux:label>Arquivos do Empreendimento</flux:label>
                                            <input
                                                type="file"
                                                class="block w-full text-sm text-zinc-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-zinc-100 file:text-zinc-700 hover:file:bg-zinc-200"
                                                wire:model="uploads"
                                                multiple
                                            >
                                            <flux:error name="uploads.*" />
                                            <p class="text-[var(--muted)] text-sm mt-1" wire:loading wire:target="uploads">Carregando arquivos para envio...</p>
                                        </flux:field>

                                        @if ($uploads !== [])
                                            <p class="text-[var(--muted)] mt-2 mb-3">Arquivos selecionados para o próximo envio.</p>
                                            <div class="d-flex flex-column gap-2">
                                                @foreach ($uploads as $upload)
                                                    <div class="flex items-center justify-between gap-4 p-[1.15rem_1.2rem] border border-[var(--border)] rounded-[22px] bg-[color-mix(in_oklab,var(--surface)_95%,var(--brand)_5%)]">
                                                        <div>
                                                            <div class="text-[var(--brand)] font-bold">{{ $upload->getClientOriginalName() }}</div>
                                                            <div class="text-[var(--muted)] small">Pronto para envio</div>
                                                        </div>
                                                        <span class="text-[var(--brand)] text-[0.88rem] font-bold whitespace-nowrap">Novo arquivo</span>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif

                                        @if ($attachmentSummaries !== [])
                                            <p class="text-[var(--muted)] mt-3 mb-3">Arquivos já enviados permanecem disponíveis abaixo. Novos uploads serão adicionados ao histórico da proposta.</p>
                                            <div class="d-flex flex-column gap-2">
                                                @foreach ($attachmentSummaries as $attachment)
                                                    <a
                                                        class="flex items-center justify-between gap-4 p-[1.15rem_1.2rem] border border-[var(--border)] rounded-[22px] bg-[color-mix(in_oklab,var(--surface)_95%,var(--brand)_5%)] no-underline text-[var(--text)]"
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
                                        @endif
                                    </div>

                                    {{-- Submit --}}
                                    <div class="col-12 d-flex flex-column flex-sm-row gap-3 justify-content-between align-items-sm-center">
                                        <p class="text-[var(--muted)] mb-0">Após salvar, os dados seguirão para análise comercial interna.</p>
                                        <flux:button
                                            type="submit"
                                            variant="primary"
                                            wire:loading.attr="disabled"
                                            wire:target="save,uploads"
                                        >
                                            <span wire:loading.remove wire:target="save">Salvar Empreendimento(s)</span>
                                            <span wire:loading wire:target="save">Salvando...</span>
                                        </flux:button>
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

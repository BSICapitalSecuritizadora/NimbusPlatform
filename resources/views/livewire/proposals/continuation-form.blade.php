<section class="py-5 min-h-[70vh] [background:linear-gradient(180deg,_rgba(255,255,255,0.55),_transparent_180px),radial-gradient(1100px_420px_at_50%_-8%,_rgba(9,27,35,0.10),_transparent_72%),var(--bg)]">
    {{-- Institutional visual layer (aligned with the public site palette in site/partials/styles).
         Defines classes referenced in this view and keeps everything on the --brand / --gold tokens. --}}
    <style>
        .cf-shell {
            --cf-radius-lg: 14px;
            --cf-radius-md: 10px;
            --cf-radius-sm: 6px;
            --cf-shadow: 0 6px 18px rgba(9, 27, 35, 0.05);
            --cf-shadow-hover: 0 10px 28px rgba(9, 27, 35, 0.09);
        }

        .premium-card {
            position: relative;
            background: var(--surface, #fff);
            border: 1px solid var(--border);
            border-radius: var(--cf-radius-lg);
            box-shadow: var(--cf-shadow);
            padding: 1.75rem;
        }

        @media (min-width: 992px) {
            .premium-card {
                padding: 2.5rem;
            }
        }

        .section-header {
            margin-bottom: 1.5rem;
        }

        .form-section-title {
            font-size: 1.6rem;
            font-weight: 800;
            letter-spacing: -0.03em;
            line-height: 1.15;
            color: var(--brand);
            margin: 0.15rem 0 0.4rem;
        }

        .form-section-subtitle {
            margin: 0;
            color: var(--muted);
            line-height: 1.6;
        }

        /* Primary action — mirrors the public site .btn-brand */
        .btn-primary-premium {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            background: var(--brand);
            color: #e6e4e4;
            border: 1px solid var(--brand);
            border-radius: var(--cf-radius-sm);
            padding: 0.85rem 1.9rem;
            font-weight: 700;
            font-size: 0.82rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            box-shadow: 0 4px 12px rgba(9, 27, 35, 0.08);
            transition: transform 0.2s ease, background 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease, opacity 0.2s ease;
            cursor: pointer;
        }

        .btn-primary-premium:hover:not(:disabled) {
            background: var(--brand-strong, #06151c);
            border-color: var(--brand-strong, #06151c);
            color: #fff;
            transform: translateY(-1px);
            box-shadow: var(--cf-shadow-hover);
        }

        .btn-primary-premium:disabled {
            opacity: 0.65;
            cursor: not-allowed;
        }

        /* Prefixed money inputs */
        .custom-input-wrap {
            display: flex;
            align-items: stretch;
            overflow: hidden;
            border: 1px solid color-mix(in srgb, var(--border) 86%, var(--brand) 14%);
            border-radius: var(--cf-radius-sm);
            background: color-mix(in srgb, var(--surface) 96%, var(--brand) 4%);
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .custom-input-wrap:focus-within {
            border-color: color-mix(in srgb, var(--gold) 30%, var(--brand) 70%);
            box-shadow: 0 0 0 0.2rem rgba(9, 27, 35, 0.08);
        }

        .custom-input-prefix {
            display: inline-flex;
            align-items: center;
            padding: 0 0.75rem;
            font-weight: 700;
            font-size: 0.85rem;
            color: var(--muted);
            background: color-mix(in srgb, var(--brand) 6%, var(--surface));
            border-right: 1px solid color-mix(in srgb, var(--border) 86%, var(--brand) 14%);
        }

        /* Data tables */
        .table-premium {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
        }

        .table-premium th,
        .table-premium td {
            padding: 0.7rem 0.85rem;
            border-bottom: 1px solid var(--border);
            text-align: left;
            vertical-align: middle;
        }

        .table-premium tbody tr:last-child th,
        .table-premium tbody tr:last-child td {
            border-bottom: 0;
        }

        /* File upload dropzone */
        .file-upload-box {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 2.5rem 1.5rem;
            border: 1.5px dashed color-mix(in srgb, var(--brand) 22%, var(--border));
            border-radius: var(--cf-radius-md);
            background: color-mix(in srgb, var(--surface) 96%, var(--brand) 4%);
            transition: border-color 0.2s ease, background 0.2s ease;
        }

        .file-upload-box:hover {
            border-color: color-mix(in srgb, var(--gold) 45%, var(--brand));
            background: color-mix(in srgb, var(--gold) 8%, var(--surface));
        }

        .file-upload-box svg {
            width: 44px;
            height: 44px;
            flex: none;
            margin-bottom: 0.85rem;
            color: color-mix(in srgb, var(--brand) 55%, var(--muted));
        }

        .file-upload-box p {
            margin-bottom: 0.15rem;
        }

        /* Gold accent divider — public site .section-divider signature */
        .cf-divider {
            width: 64px;
            height: 3px;
            border-radius: 999px;
            margin: 0 0 0.9rem;
            background: linear-gradient(90deg, var(--gold), color-mix(in srgb, var(--gold) 35%, var(--brand) 65%), var(--brand));
        }

        /* Section bullet dots (robust sizing, independent of utility build) */
        .cf-dot {
            display: inline-block;
            width: 10px;
            height: 10px;
            flex: none;
            border-radius: 999px;
            background: var(--gold);
            box-shadow: 0 0 0 0.3rem rgba(160, 110, 40, 0.15);
        }

        .file-upload-box input[type="file"] {
            margin-top: 1rem;
            width: 100%;
            max-width: 22rem;
            font-size: 0.85rem;
            color: var(--muted);
        }

        .file-upload-box input[type="file"]::file-selector-button {
            margin-right: 0.85rem;
            padding: 0.5rem 1rem;
            border: 1px solid var(--brand);
            border-radius: var(--cf-radius-sm);
            background: var(--brand);
            color: #e6e4e4;
            font-weight: 600;
            font-size: 0.78rem;
            letter-spacing: 0.03em;
            cursor: pointer;
            transition: background 0.2s ease;
        }

        .file-upload-box input[type="file"]::file-selector-button:hover {
            background: var(--brand-strong, #06151c);
        }
    </style>

    <div class="cf-shell container py-lg-4">
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
                    <div class="relative overflow-hidden rounded-[14px] border border-[var(--border)] shadow-[0_20px_45px_rgba(9,27,35,0.06)] [background:linear-gradient(145deg,_color-mix(in_oklab,_var(--surface)_95%,_white_5%),_color-mix(in_oklab,_var(--surface)_88%,_var(--brand)_12%))]">
                        <div class="absolute inset-y-0 left-0 w-[6px] bg-gradient-to-b from-[var(--gold)] to-[var(--brand)]"></div>
                        <div class="p-4 p-lg-5">
                            <div class="row g-4 align-items-start">
                                <div class="col-lg-8">
                                    <div class="text-[0.78rem] font-bold tracking-[0.12em] uppercase text-[var(--gold)] mb-3">Portal da Proposta</div>
                                    <h1 class="text-[clamp(2rem,3vw,2.8rem)] font-extrabold tracking-[-0.03em] text-[var(--brand)] mb-2">Formulário de Empreendimento</h1>
                                    <div class="cf-divider"></div>
                                    <div class="text-[var(--muted)] fs-5">{{ $proposal->company->name }} • {{ $proposal->company->cnpj }}</div>
                                    <div class="text-[var(--muted)] mt-3">
                                        Acompanhe os dados enviados e, quando necessário, complemente as informações do empreendimento no mesmo padrão visual da plataforma.
                                    </div>
                                </div>

                                <div class="col-lg-4 text-lg-end">
                                    <div class="mb-[0.45rem] text-[0.76rem] font-bold tracking-[0.08em] uppercase text-[var(--muted)]">Status Atual</div>
                                    <span class="inline-flex items-center gap-[0.65rem] px-4 py-3 rounded-full border border-[color-mix(in_oklab,var(--gold)_30%,var(--border)_70%)] bg-[color-mix(in_oklab,var(--gold)_10%,var(--surface)_90%)] font-bold text-[var(--brand)]">
                                        <span class="cf-dot"></span>
                                        {{ \App\Enums\ProposalStatus::labelFor($proposal->status) }}
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
                                        <div class="h-full p-[1.15rem_1.2rem] border border-[var(--border)] rounded-[10px] bg-[color-mix(in_oklab,var(--surface)_94%,var(--brand)_6%)]">
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
                        <div class="rounded-[14px] border border-[var(--border)] shadow-[0_20px_45px_rgba(9,27,35,0.06)] bg-[var(--surface,#fff)]">
                            <div class="p-4 p-lg-5">
                                <div class="row g-4 align-items-center">
                                    <div class="col-lg-7">
                                        <div class="text-[0.78rem] font-bold tracking-[0.12em] uppercase text-[var(--gold)] mb-2">Próxima Etapa</div>
                                        <h2 class="text-[1.65rem] font-extrabold tracking-[-0.03em] text-[var(--brand)] mb-[0.35rem]">
                                            {{ $proposal->status === \App\Enums\ProposalStatus::AwaitingInformation->value
                                                ? 'Atualize as informações solicitadas'
                                                : 'Complementar informações do empreendimento' }}
                                        </h2>
                                        <p class="text-[var(--muted)] mb-0">
                                            {{ $proposal->status === \App\Enums\ProposalStatus::AwaitingInformation->value
                                                ? 'O time comercial solicitou novos dados. Revise os campos abaixo, atualize o que for necessário e salve novamente a proposta.'
                                                : 'Preencha os dados abaixo com atenção. Essa etapa organiza o empreendimento, unidades, cronograma, fluxo financeiro e documentos complementares.' }}
                                        </p>
                                    </div>

                                    <div class="col-lg-5">
                                        <div class="p-[1.2rem_1.25rem] rounded-[10px] border border-[color-mix(in_oklab,var(--gold)_18%,var(--border)_82%)] bg-gradient-to-br from-[color-mix(in_oklab,var(--brand)_8%,var(--surface)_92%)] to-[color-mix(in_oklab,var(--gold)_10%,var(--surface)_90%)]">
                                            <strong class="d-block mb-2 text-[var(--brand)] font-bold">Antes de enviar</strong>
                                            <p class="mb-0 text-[var(--muted)]">Revise os dados gerais da operação, preencha cada empreendimento com identificação clara e anexe os documentos que apoiam a análise.</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Form Card --}}
                        <div class="rounded-[14px] border border-[var(--border)] shadow-[0_20px_45px_rgba(9,27,35,0.06)] bg-[var(--surface,#fff)]">
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
                                            <flux:input wire:model.blur="form.developmentName" />
                                            <flux:error name="form.developmentName" />
                                        </flux:field>
                                    </div>

                                    <div class="col-md-4">
                                        <flux:field>
                                            <flux:label>Site</flux:label>
                                            <flux:input type="url" wire:model.blur="form.websiteUrl" />
                                            <flux:error name="form.websiteUrl" />
                                        </flux:field>
                                    </div>

                                    <div class="col-md-3">
                                        <flux:field>
                                            <flux:label>Valor Solicitado *</flux:label>
                                            <div class="custom-input-wrap">
                                                                <span class="custom-input-prefix">R$</span>
                                                                <flux:input class="rounded-l-none!" style="border:none;box-shadow:none;border-radius:0;flex:1;background:transparent" wire:model.blur="form.requestedAmount" mask:dynamic="$money($input, ',', '.', 2)" inputmode="decimal" />
                                            </div>
                                            <flux:error name="form.requestedAmount" />
                                        </flux:field>
                                    </div>

                                    <div class="col-md-4">
                                        <flux:field>
                                            <flux:label>Valor atual de mercado do terreno</flux:label>
                                            <div class="custom-input-wrap">
                                                                <span class="custom-input-prefix">R$</span>
                                                                <flux:input class="rounded-l-none!" style="border:none;box-shadow:none;border-radius:0;flex:1;background:transparent" wire:model.blur="form.landMarketValue" mask:dynamic="$money($input, ',', '.', 2)" inputmode="decimal" />
                                            </div>
                                            <flux:error name="form.landMarketValue" />
                                        </flux:field>
                                    </div>

                                    <div class="col-md-4">
                                        <flux:field>
                                            <flux:label>Área do Terreno (m²) *</flux:label>
                                            <flux:input type="number" step="0.01" wire:model.blur="form.landArea" />
                                            <flux:error name="form.landArea" />
                                        </flux:field>
                                    </div>

                                    <div class="col-md-4">
                                        <flux:field>
                                            <flux:label>Lançamento *</flux:label>
                                            <flux:input type="month" wire:model.blur="form.launchDate" />
                                            <flux:error name="form.launchDate" />
                                        </flux:field>
                                    </div>

                                    <div class="col-md-3">
                                        <flux:field>
                                            <flux:label>Lançamento das Vendas *</flux:label>
                                            <flux:input type="month" wire:model.blur="form.salesLaunchDate" />
                                            <flux:error name="form.salesLaunchDate" />
                                        </flux:field>
                                    </div>

                                    <div class="col-md-3">
                                        <flux:field>
                                            <flux:label>Início das Obras *</flux:label>
                                            <flux:input type="month" wire:model.blur="form.constructionStartDate" />
                                            <flux:error name="form.constructionStartDate" />
                                        </flux:field>
                                    </div>

                                    <div class="col-md-3">
                                        <flux:field>
                                            <flux:label>Previsão de Entrega *</flux:label>
                                            <flux:input type="month" wire:model.blur="form.deliveryForecastDate" />
                                            <flux:error name="form.deliveryForecastDate" />
                                        </flux:field>
                                    </div>

                                    <div class="col-md-3">
                                        <flux:field>
                                            <flux:label>Prazo Remanescente (meses)</flux:label>
                                            <flux:input type="number" wire:model="form.remainingMonths" readonly />
                                        </flux:field>
                                    </div>

                                    <div class="col-md-3">
                                        <flux:field>
                                            <flux:label>CEP *</flux:label>
                                            <flux:input wire:model.blur="form.zipCode" mask="99999-999" inputmode="numeric" />
                                            <flux:error name="form.zipCode" />
                                            <flux:description wire:loading wire:target="form.zipCode">Buscando endereço pelo CEP...</flux:description>
                                        </flux:field>
                                    </div>

                                    <div class="col-md-6">
                                        <flux:field>
                                            <flux:label>Rua *</flux:label>
                                            <flux:input wire:model.blur="form.street" />
                                            <flux:error name="form.street" />
                                        </flux:field>
                                    </div>

                                    <div class="col-md-3">
                                        <flux:field>
                                            <flux:label>Complemento</flux:label>
                                            <flux:input wire:model.blur="form.addressComplement" />
                                            <flux:error name="form.addressComplement" />
                                        </flux:field>
                                    </div>

                                    <div class="col-md-3">
                                        <flux:field>
                                            <flux:label>Número *</flux:label>
                                            <flux:input wire:model.blur="form.addressNumber" />
                                            <flux:error name="form.addressNumber" />
                                        </flux:field>
                                    </div>

                                    <div class="col-md-4">
                                        <flux:field>
                                            <flux:label>Bairro *</flux:label>
                                            <flux:input wire:model.blur="form.neighborhood" />
                                            <flux:error name="form.neighborhood" />
                                        </flux:field>
                                    </div>

                                    <div class="col-md-4">
                                        <flux:field>
                                            <flux:label>Cidade *</flux:label>
                                            <flux:input wire:model.blur="form.city" />
                                            <flux:error name="form.city" />
                                        </flux:field>
                                    </div>

                                    <div class="col-md-2">
                                        <flux:field>
                                            <flux:label>Estado *</flux:label>
                                            <flux:input maxlength="2" wire:model.blur="form.state" />
                                            <flux:error name="form.state" />
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
                                                <div class="overflow-hidden border border-[var(--border)] rounded-[10px] bg-[color-mix(in_oklab,var(--surface)_96%,var(--brand)_4%)] p-3 p-lg-4">
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

                                                    <input type="hidden" wire:model="form.projects.{{ $index }}.id">

                                                    <div class="mb-4">
                                                        <flux:field>
                                                            <flux:label>Identificação do Empreendimento *</flux:label>
                                                            <flux:input wire:model.blur="form.projects.{{ $index }}.name" />
                                                            <flux:error name="form.projects.{{ $index }}.name" />
                                                        </flux:field>
                                                    </div>

                                                    <div class="d-flex flex-column gap-4">
                                                        {{-- Resumo das Unidades --}}
                                                        <div>
                                                            <div class="flex items-center gap-[0.7rem] mb-3 text-[var(--brand)] font-bold">
                                                                <span class="cf-dot"></span>
                                                                Resumo das Unidades
                                                            </div>
                                                            <div class="overflow-hidden border border-[var(--border)] rounded-[10px] bg-[color-mix(in_oklab,var(--surface)_96%,var(--brand)_4%)]">
                                                                <div class="table-responsive">
                                                                    <table class="table-premium">
                                                                        <thead>
                                                                            <tr>
                                                                                @foreach (['Permutadas', 'Quitadas', 'Não Quitadas', 'Estoque', 'Total', '% Vendidas'] as $col)
                                                                                    <th class="bg-[color-mix(in_oklab,var(--brand)_8%,var(--surface)_92%)] text-[var(--brand)] text-[0.76rem] font-bold tracking-[0.08em] uppercase">{{ $col }}</th>
                                                                                @endforeach
                                                                            </tr>
                                                                        </thead>
                                                                        <tbody>
                                                                            <tr>
                                                                                <td><flux:input type="number" min="0" wire:model.live.debounce.300ms="form.projects.{{ $index }}.exchangedUnits" /></td>
                                                                                <td><flux:input type="number" min="0" wire:model.live.debounce.300ms="form.projects.{{ $index }}.paidUnits" /></td>
                                                                                <td><flux:input type="number" min="0" wire:model.live.debounce.300ms="form.projects.{{ $index }}.unpaidUnits" /></td>
                                                                                <td><flux:input type="number" min="0" wire:model.live.debounce.300ms="form.projects.{{ $index }}.stockUnits" /></td>
                                                                                <td><flux:input type="number" wire:model="form.projects.{{ $index }}.totalUnits" readonly /></td>
                                                                                <td><flux:input wire:model="form.projects.{{ $index }}.salesPercentage" readonly /></td>
                                                                            </tr>
                                                                        </tbody>
                                                                    </table>
                                                                </div>
                                                            </div>
                                                        </div>

                                                        {{-- Resumo Financeiro --}}
                                                        <div>
                                                            <div class="flex items-center gap-[0.7rem] mb-3 text-[var(--brand)] font-bold">
                                                                <span class="cf-dot"></span>
                                                                Resumo Financeiro
                                                            </div>
                                                            <div class="overflow-hidden border border-[var(--border)] rounded-[10px] bg-[color-mix(in_oklab,var(--surface)_96%,var(--brand)_4%)]">
                                                                <div class="table-responsive">
                                                                    <table class="table-premium">
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
                                                                                    <div class="custom-input-wrap">
                                                                <span class="custom-input-prefix">R$</span>
                                                                <flux:input class="rounded-l-none!" style="border:none;box-shadow:none;border-radius:0;flex:1;background:transparent" inputmode="decimal" wire:model.blur="form.projects.{{ $index }}.incurredCost" mask:dynamic="$money($input, ',', '.', 2)" />
                                                                                    </div>
                                                                                </td>
                                                                                <td>
                                                                                    <div class="custom-input-wrap">
                                                                <span class="custom-input-prefix">R$</span>
                                                                <flux:input class="rounded-l-none!" style="border:none;box-shadow:none;border-radius:0;flex:1;background:transparent" inputmode="decimal" wire:model.blur="form.projects.{{ $index }}.costToIncur" mask:dynamic="$money($input, ',', '.', 2)" />
                                                                                    </div>
                                                                                </td>
                                                                                <td>
                                                                                    <div class="custom-input-wrap">
                                                                <span class="custom-input-prefix">R$</span>
                                                                <flux:input class="rounded-l-none!" style="border:none;box-shadow:none;border-radius:0;flex:1;background:transparent" wire:model="form.projects.{{ $index }}.totalCost" readonly />
                                                                                    </div>
                                                                                </td>
                                                                                <td><flux:input wire:model="form.projects.{{ $index }}.workStagePercentage" readonly /></td>
                                                                            </tr>
                                                                        </tbody>
                                                                    </table>
                                                                </div>
                                                            </div>
                                                        </div>

                                                        {{-- Valores de Venda --}}
                                                        <div>
                                                            <div class="flex items-center gap-[0.7rem] mb-3 text-[var(--brand)] font-bold">
                                                                <span class="cf-dot"></span>
                                                                Valores de Venda
                                                            </div>
                                                            <div class="overflow-hidden border border-[var(--border)] rounded-[10px] bg-[color-mix(in_oklab,var(--surface)_96%,var(--brand)_4%)]">
                                                                <div class="table-responsive">
                                                                    <table class="table-premium">
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
                                                                                        <div class="custom-input-wrap">
                                                                <span class="custom-input-prefix">R$</span>
                                                                <flux:input class="rounded-l-none!" style="border:none;box-shadow:none;border-radius:0;flex:1;background:transparent" inputmode="decimal" wire:model.blur="form.projects.{{ $index }}.{{ $field }}" mask:dynamic="$money($input, ',', '.', 2)" />
                                                                                        </div>
                                                                                    </td>
                                                                                @endforeach
                                                                                <td>
                                                                                    <div class="custom-input-wrap">
                                                                <span class="custom-input-prefix">R$</span>
                                                                <flux:input class="rounded-l-none!" style="border:none;box-shadow:none;border-radius:0;flex:1;background:transparent" wire:model="form.projects.{{ $index }}.grossSalesValue" readonly />
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
                                                                <span class="cf-dot"></span>
                                                                Fluxo de Pagamento
                                                            </div>
                                                            <div class="overflow-hidden border border-[var(--border)] rounded-[10px] bg-[color-mix(in_oklab,var(--surface)_96%,var(--brand)_4%)]">
                                                                <div class="table-responsive">
                                                                    <table class="table-premium">
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
                                                                                        <div class="custom-input-wrap">
                                                                <span class="custom-input-prefix">R$</span>
                                                                <flux:input class="rounded-l-none!" style="border:none;box-shadow:none;border-radius:0;flex:1;background:transparent" inputmode="decimal" wire:model.blur="form.projects.{{ $index }}.{{ $field }}" mask:dynamic="$money($input, ',', '.', 2)" />
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
                                        <div class="overflow-hidden border border-[var(--border)] rounded-[10px] bg-[color-mix(in_oklab,var(--surface)_96%,var(--brand)_4%)] p-3 p-lg-4">
                                            <div class="row g-3 mb-4">
                                                <div class="col-md-2">
                                                    <flux:field>
                                                        <flux:label>Blocos *</flux:label>
                                                        <flux:input type="number" min="1" wire:model.live.debounce.300ms="form.blockCount" />
                                                        <flux:error name="form.blockCount" />
                                                    </flux:field>
                                                </div>

                                                <div class="col-md-2">
                                                    <flux:field>
                                                        <flux:label>Pavimentos *</flux:label>
                                                        <flux:input type="number" min="1" wire:model.live.debounce.300ms="form.floorCount" />
                                                        <flux:error name="form.floorCount" />
                                                    </flux:field>
                                                </div>

                                                <div class="col-md-3">
                                                    <flux:field>
                                                        <flux:label>Andares Tipo *</flux:label>
                                                        <flux:input type="number" min="1" wire:model.live.debounce.300ms="form.typicalFloorCount" />
                                                        <flux:error name="form.typicalFloorCount" />
                                                    </flux:field>
                                                </div>

                                                <div class="col-md-3">
                                                    <flux:field>
                                                        <flux:label>Unidades/Andar *</flux:label>
                                                        <flux:input type="number" min="1" wire:model.live.debounce.300ms="form.unitsPerFloor" />
                                                        <flux:error name="form.unitsPerFloor" />
                                                    </flux:field>
                                                </div>

                                                <div class="col-md-2">
                                                    <flux:field>
                                                        <flux:label>Total</flux:label>
                                                        <flux:input type="number" wire:model="form.totalUnits" readonly />
                                                    </flux:field>
                                                </div>
                                            </div>

                                            <div class="overflow-hidden border border-[var(--border)] rounded-[10px] bg-[color-mix(in_oklab,var(--surface)_96%,var(--brand)_4%)]">
                                                <div class="table-responsive">
                                                    <table class="table-premium">
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
                                                                        <flux:input type="number" min="1" wire:model.live.debounce.300ms="form.unitTypes.{{ $typeIndex }}.totalUnits" />
                                                                    </td>
                                                                @endforeach
                                                            </tr>
                                                            <tr>
                                                                <th class="text-[var(--muted)] text-[0.76rem] font-bold uppercase tracking-[0.08em]">Dormitórios *</th>
                                                                @foreach ($unitTypes as $typeIndex => $typeRow)
                                                                    <td wire:key="type-bedrooms-{{ $typeIndex }}">
                                                                        <flux:input wire:model.blur="form.unitTypes.{{ $typeIndex }}.bedrooms" />
                                                                    </td>
                                                                @endforeach
                                                            </tr>
                                                            <tr>
                                                                <th class="text-[var(--muted)] text-[0.76rem] font-bold uppercase tracking-[0.08em]">Vagas *</th>
                                                                @foreach ($unitTypes as $typeIndex => $typeRow)
                                                                    <td wire:key="type-parking-{{ $typeIndex }}">
                                                                        <flux:input wire:model.blur="form.unitTypes.{{ $typeIndex }}.parkingSpaces" />
                                                                    </td>
                                                                @endforeach
                                                            </tr>
                                                            <tr>
                                                                <th class="text-[var(--muted)] text-[0.76rem] font-bold uppercase tracking-[0.08em]">Área Útil (m²) *</th>
                                                                @foreach ($unitTypes as $typeIndex => $typeRow)
                                                                    <td wire:key="type-area-{{ $typeIndex }}">
                                                                        <flux:input type="number" step="0.01" wire:model.live.debounce.300ms="form.unitTypes.{{ $typeIndex }}.usableArea" />
                                                                    </td>
                                                                @endforeach
                                                            </tr>
                                                            <tr>
                                                                <th class="text-[var(--muted)] text-[0.76rem] font-bold uppercase tracking-[0.08em]">Preço Médio *</th>
                                                                @foreach ($unitTypes as $typeIndex => $typeRow)
                                                                    <td wire:key="type-average-price-{{ $typeIndex }}">
                                                                        <div class="custom-input-wrap">
                                                                <span class="custom-input-prefix">R$</span>
                                                                <flux:input class="rounded-l-none!" style="border:none;box-shadow:none;border-radius:0;flex:1;background:transparent" inputmode="decimal" wire:model.blur="form.unitTypes.{{ $typeIndex }}.averagePrice" mask:dynamic="$money($input, ',', '.', 2)" />
                                                                        </div>
                                                                    </td>
                                                                @endforeach
                                                            </tr>
                                                            <tr>
                                                                <th class="text-[var(--muted)] text-[0.76rem] font-bold uppercase tracking-[0.08em]">Preço / m²</th>
                                                                @foreach ($unitTypes as $typeIndex => $typeRow)
                                                                    <td wire:key="type-price-per-m2-{{ $typeIndex }}">
                                                                        <div class="custom-input-wrap">
                                                                <span class="custom-input-prefix">R$</span>
                                                                <flux:input class="rounded-l-none!" style="border:none;box-shadow:none;border-radius:0;flex:1;background:transparent" wire:model="form.unitTypes.{{ $typeIndex }}.pricePerSquareMeter" readonly />
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
                                    <div class="col-12 mt-4">
                                        <div class="premium-card">
                                            <div class="section-header border-0 mb-4 pb-0">
                                                <div class="section-kicker">Documentação</div>
                                                <h2 class="form-section-title">Arquivos do Empreendimento</h2>
                                                <p class="form-section-subtitle">Envie imagens, plantas e documentos complementares.</p>
                                            </div>
                                            
                                            <div class="file-upload-box">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="mx-auto h-12 w-12 text-zinc-400 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                                                </svg>
                                                <p class="text-zinc-600 font-semibold mb-1">Clique para selecionar os arquivos</p>
                                                <p class="text-zinc-500 text-sm">ou arraste para esta área</p>
                                                <input
                                                    type="file"
                                                    wire:model="form.uploads"
                                                    multiple
                                                >
                                            </div>
                                            <flux:error name="form.uploads.*" />
                                            <p class="text-[var(--muted)] text-sm mt-1" wire:loading wire:target="form.uploads">Carregando arquivos para envio...</p>

                                        @if ($uploads !== [])
                                            <p class="text-[var(--muted)] mt-2 mb-3">Arquivos selecionados para o próximo envio.</p>
                                            <div class="d-flex flex-column gap-2">
                                                @foreach ($uploads as $upload)
                                                    <div class="flex items-center justify-between gap-4 p-[1.15rem_1.2rem] border border-[var(--border)] rounded-[10px] bg-[color-mix(in_oklab,var(--surface)_95%,var(--brand)_5%)]">
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
                                                        class="flex items-center justify-between gap-4 p-[1.15rem_1.2rem] border border-[var(--border)] rounded-[10px] bg-[color-mix(in_oklab,var(--surface)_95%,var(--brand)_5%)] no-underline text-[var(--text)]"
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
                                    </div>

                                    {{-- Submit --}}
                                    <div class="col-12 d-flex flex-column flex-sm-row gap-4 justify-content-between align-items-sm-center premium-card mt-4" style="margin-bottom: 0;">
                                        <div>
                                            <h3 class="form-section-title" style="font-size: 1.25rem;">Finalizar e Enviar</h3>
                                            <p class="form-section-subtitle mb-0">Após salvar, os dados seguirão para análise comercial interna.</p>
                                        </div>
                                        <button
                                            type="submit"
                                            class="btn-primary-premium"
                                            wire:loading.attr="disabled"
                                            wire:target="save,uploads"
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


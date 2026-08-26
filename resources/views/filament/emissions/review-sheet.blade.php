<div class="bsi-review-sheet w-full space-y-6">
    <!-- Header de Orientação -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 p-4 sm:p-5 rounded-xl bg-gradient-to-r from-[#091b23] to-[#0d2733] border border-amber-500/25 shadow-lg">
        <div class="space-y-1">
            <div class="flex items-center gap-2">
                <span class="inline-flex items-center justify-center size-6 rounded-md bg-amber-500/15 border border-amber-500/30 text-amber-400 text-xs font-bold">
                    ✓
                </span>
                <h3 class="text-base sm:text-lg font-bold text-[#fbfaf8] tracking-tight">
                    Ficha de Conferência da Operação
                </h3>
            </div>
            <p class="text-xs sm:text-sm text-white/70">
                Confira atentamente os dados da operação antes de concluir o cadastro. Você pode retornar a qualquer etapa para realizar ajustes clicando em <strong class="text-amber-300 font-semibold">Editar</strong>.
            </p>
        </div>
        <div class="shrink-0 flex items-center gap-2">
            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-emerald-500/15 border border-emerald-500/30 text-emerald-400">
                <span class="size-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                Revisão Final
            </span>
        </div>
    </div>

    <!-- 1. Resumo Executivo da Operação -->
    <div class="p-5 rounded-xl bg-[#091b23] border border-amber-500/20 shadow-md space-y-4">
        <div class="flex items-center justify-between border-b border-white/10 pb-3">
            <h4 class="text-xs font-bold uppercase tracking-wider text-amber-400 flex items-center gap-2">
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25H12" />
                </svg>
                Resumo da Operação
            </h4>
            <span class="text-[11px] text-white/50">Visão Geral Executiva</span>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6">
            <div class="space-y-1">
                <span class="text-[10px] font-bold uppercase tracking-wider text-white/45 block">Denominação</span>
                <span class="text-sm sm:text-base font-bold text-[#fbfaf8] block truncate" title="{{ $summary['name'] ?? '' }}">
                    {{ $summary['name'] ?: 'Não informada' }}
                </span>
            </div>

            <div class="space-y-1">
                <span class="text-[10px] font-bold uppercase tracking-wider text-white/45 block">Tipo de Título</span>
                <span class="text-sm sm:text-base font-bold text-amber-400 block">
                    {{ $summary['type'] ?: '—' }}
                </span>
            </div>

            <div class="space-y-1">
                <span class="text-[10px] font-bold uppercase tracking-wider text-white/45 block">Volume Total Emitido</span>
                <span class="text-sm sm:text-base font-bold text-emerald-400 block tabular-nums">
                    {{ $summary['issued_volume'] ? 'R$ ' . $summary['issued_volume'] : 'R$ 0,00' }}
                </span>
            </div>

            <div class="space-y-1">
                <span class="text-[10px] font-bold uppercase tracking-wider text-white/45 block">Emissor / Securitizadora</span>
                <span class="text-sm sm:text-base font-bold text-[#fbfaf8] block truncate" title="{{ $summary['issuer'] ?? '' }}">
                    {{ $summary['issuer'] ?: 'Não informado' }}
                </span>
            </div>

            <div class="space-y-1">
                <span class="text-[10px] font-bold uppercase tracking-wider text-white/45 block">Status da Operação</span>
                <span class="inline-flex items-center gap-1.5 text-xs font-semibold px-2.5 py-0.5 rounded-full bg-white/5 text-white/90 border border-white/10">
                    {{ $summary['status_label'] ?: 'Em Elaboração' }}
                </span>
            </div>

            <div class="space-y-1">
                <span class="text-[10px] font-bold uppercase tracking-wider text-white/45 block">Data de Emissão</span>
                <span class="text-xs sm:text-sm font-semibold text-[#fbfaf8] block tabular-nums">
                    {{ $summary['issue_date'] ?: 'Não informada' }}
                </span>
            </div>

            <div class="space-y-1">
                <span class="text-[10px] font-bold uppercase tracking-wider text-white/45 block">Data de Vencimento</span>
                <span class="text-xs sm:text-sm font-semibold text-[#fbfaf8] block tabular-nums">
                    {{ $summary['maturity_date'] ?: 'Não informada' }}
                </span>
            </div>

            <div class="space-y-1">
                <span class="text-[10px] font-bold uppercase tracking-wider text-white/45 block">Remuneração / Spread</span>
                <span class="text-xs sm:text-sm font-semibold text-amber-300 block tabular-nums">
                    {{ $summary['remuneration_summary'] ?: 'Não especificada' }}
                </span>
            </div>
        </div>
    </div>

    <!-- 2. Seções Detalhadas por Etapa do Wizard -->
    <div class="space-y-4">
        <!-- 01. DADOS BÁSICOS -->
        <div class="rounded-xl bg-[#06151c] border border-white/10 shadow-sm overflow-hidden">
            <div class="flex items-center justify-between px-5 py-3.5 bg-white/[0.03] border-b border-white/5">
                <div class="flex items-center gap-2.5">
                    <span class="text-xs font-bold text-amber-400 font-mono">01</span>
                    <h4 class="text-sm font-bold text-[#fbfaf8]">Dados básicos</h4>
                    @if($steps_validity['dados_basicos'])
                        <span class="inline-flex items-center gap-1 text-[11px] font-medium text-emerald-400">
                            <svg class="size-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd"/></svg>
                            Preenchido
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1 text-[11px] font-medium text-amber-400">
                            Atenção
                        </span>
                    @endif
                </div>

                <button
                    type="button"
                    @click="document.querySelectorAll('.fi-sc-wizard-header-step')[{{ $steps_indexes['dados_basicos'] }}]?.click(); window.scrollTo({ top: 0, behavior: 'smooth' });"
                    class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold text-amber-400 hover:text-amber-300 bg-amber-500/10 hover:bg-amber-500/20 border border-amber-500/25 rounded-lg transition-colors cursor-pointer"
                >
                    <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" />
                    </svg>
                    Editar
                </button>
            </div>

            <div class="p-5 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-white/45 block">Denominação</span>
                    <span class="text-xs sm:text-sm font-medium text-[#fbfaf8] block">{{ $dados_basicos['name'] ?: '—' }}</span>
                </div>
                <div>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-white/45 block">Tipo de Título</span>
                    <span class="text-xs sm:text-sm font-medium text-[#fbfaf8] block">{{ $dados_basicos['type'] ?: '—' }}</span>
                </div>
                <div>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-white/45 block">Status</span>
                    <span class="text-xs sm:text-sm font-medium text-[#fbfaf8] block">{{ $dados_basicos['status_label'] ?: '—' }}</span>
                </div>
                <div>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-white/45 block">Registrada na CVM</span>
                    <span class="text-xs sm:text-sm font-medium text-[#fbfaf8] block">{{ $dados_basicos['registered_with_cvm_label'] ?: '—' }}</span>
                </div>
                <div>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-white/45 block">Situação da Emissora</span>
                    <span class="text-xs sm:text-sm font-medium text-[#fbfaf8] block">{{ $dados_basicos['issuer_situation_label'] ?: '—' }}</span>
                </div>
                <div>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-white/45 block">Código IF</span>
                    <span class="text-xs sm:text-sm font-mono font-medium text-[#fbfaf8] block">{{ $dados_basicos['if_code'] ?: '—' }}</span>
                </div>
                <div>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-white/45 block">Código ISIN</span>
                    <span class="text-xs sm:text-sm font-mono font-medium text-[#fbfaf8] block">{{ $dados_basicos['isin_code'] ?: '—' }}</span>
                </div>
                <div>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-white/45 block">Código BSI</span>
                    <span class="text-xs sm:text-sm font-mono font-medium text-amber-300 block">{{ $dados_basicos['bsi_code'] ?: 'Gerado ao salvar' }}</span>
                </div>
            </div>
        </div>

        <!-- 02. EMPREENDIMENTOS (Se no cadastro) -->
        @if($is_create)
        <div class="rounded-xl bg-[#06151c] border border-white/10 shadow-sm overflow-hidden">
            <div class="flex items-center justify-between px-5 py-3.5 bg-white/[0.03] border-b border-white/5">
                <div class="flex items-center gap-2.5">
                    <span class="text-xs font-bold text-amber-400 font-mono">02</span>
                    <h4 class="text-sm font-bold text-[#fbfaf8]">Empreendimentos</h4>
                    @if($steps_validity['empreendimentos'])
                        <span class="inline-flex items-center gap-1 text-[11px] font-medium text-emerald-400">
                            <svg class="size-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd"/></svg>
                            Preenchido
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1 text-[11px] font-medium text-amber-400">
                            Pendente
                        </span>
                    @endif
                </div>

                <button
                    type="button"
                    @click="document.querySelectorAll('.fi-sc-wizard-header-step')[{{ $steps_indexes['empreendimentos'] }}]?.click(); window.scrollTo({ top: 0, behavior: 'smooth' });"
                    class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold text-amber-400 hover:text-amber-300 bg-amber-500/10 hover:bg-amber-500/20 border border-amber-500/25 rounded-lg transition-colors cursor-pointer"
                >
                    <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" />
                    </svg>
                    Editar
                </button>
            </div>

            <div class="p-5">
                @if(count($constructions) > 0)
                    <div class="space-y-2.5">
                        @foreach($constructions as $const)
                            <div class="flex items-center justify-between p-3 rounded-lg bg-white/[0.02] border border-white/5">
                                <div class="flex items-center gap-2">
                                    <svg class="size-4 text-amber-400/80 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.75a1.5 1.5 0 0 1 1.5-1.5h1.5a1.5 1.5 0 0 1 1.5 1.5V21" />
                                    </svg>
                                    <span class="text-xs sm:text-sm font-semibold text-[#fbfaf8]">{{ $const['name'] }}</span>
                                </div>
                                <span class="text-xs text-white/60 font-mono">{{ $const['details'] }}</span>
                            </div>
                        @endforeach
                    </div>
                @else
                    <span class="text-xs text-amber-400 italic">Nenhum empreendimento cadastrado.</span>
                @endif
            </div>
        </div>
        @endif

        <!-- 03. PARTICIPANTES -->
        <div class="rounded-xl bg-[#06151c] border border-white/10 shadow-sm overflow-hidden">
            <div class="flex items-center justify-between px-5 py-3.5 bg-white/[0.03] border-b border-white/5">
                <div class="flex items-center gap-2.5">
                    <span class="text-xs font-bold text-amber-400 font-mono">03</span>
                    <h4 class="text-sm font-bold text-[#fbfaf8]">Participantes</h4>
                    @if($steps_validity['participantes'])
                        <span class="inline-flex items-center gap-1 text-[11px] font-medium text-emerald-400">
                            <svg class="size-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd"/></svg>
                            Preenchido
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1 text-[11px] font-medium text-amber-400">
                            Atenção
                        </span>
                    @endif
                </div>

                <button
                    type="button"
                    @click="document.querySelectorAll('.fi-sc-wizard-header-step')[{{ $steps_indexes['participantes'] }}]?.click(); window.scrollTo({ top: 0, behavior: 'smooth' });"
                    class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold text-amber-400 hover:text-amber-300 bg-amber-500/10 hover:bg-amber-500/20 border border-amber-500/25 rounded-lg transition-colors cursor-pointer"
                >
                    <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" />
                    </svg>
                    Editar
                </button>
            </div>

            <div class="p-5 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-white/45 block">Emissor</span>
                    <span class="text-xs sm:text-sm font-medium text-[#fbfaf8] block">{{ $participantes['issuer'] ?: '—' }}</span>
                </div>
                <div>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-white/45 block">Coordenador Líder</span>
                    <span class="text-xs sm:text-sm font-medium text-[#fbfaf8] block">{{ $participantes['lead_coordinator'] ?: '—' }}</span>
                </div>
                <div>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-white/45 block">Banco Liquidante</span>
                    <span class="text-xs sm:text-sm font-medium text-[#fbfaf8] block">{{ $participantes['settlement_bank'] ?: '—' }}</span>
                </div>
                <div>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-white/45 block">Escriturador</span>
                    <span class="text-xs sm:text-sm font-medium text-[#fbfaf8] block">{{ $participantes['registrar'] ?: '—' }}</span>
                </div>
                <div>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-white/45 block">Distribuidor</span>
                    <span class="text-xs sm:text-sm font-medium text-[#fbfaf8] block">{{ $participantes['distributor'] ?: '—' }}</span>
                </div>
                <div>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-white/45 block">Agente Fiduciário</span>
                    <span class="text-xs sm:text-sm font-medium text-[#fbfaf8] block">{{ $participantes['trustee_agent'] ?: '—' }}</span>
                </div>
                <div>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-white/45 block">Devedor</span>
                    <span class="text-xs sm:text-sm font-medium text-[#fbfaf8] block">{{ $participantes['debtor'] ?: '—' }}</span>
                </div>
                <div>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-white/45 block">Escritório de Advocacia</span>
                    <span class="text-xs sm:text-sm font-medium text-[#fbfaf8] block">{{ $participantes['law_firm'] ?: '—' }}</span>
                </div>
            </div>
        </div>

        <!-- 04. CARACTERÍSTICAS FINANCEIRAS -->
        <div class="rounded-xl bg-[#06151c] border border-white/10 shadow-sm overflow-hidden">
            <div class="flex items-center justify-between px-5 py-3.5 bg-white/[0.03] border-b border-white/5">
                <div class="flex items-center gap-2.5">
                    <span class="text-xs font-bold text-amber-400 font-mono">04</span>
                    <h4 class="text-sm font-bold text-[#fbfaf8]">Características financeiras</h4>
                    @if($steps_validity['caracteristicas_financeiras'])
                        <span class="inline-flex items-center gap-1 text-[11px] font-medium text-emerald-400">
                            <svg class="size-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd"/></svg>
                            Preenchido
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1 text-[11px] font-medium text-amber-400">
                            Atenção
                        </span>
                    @endif
                </div>

                <button
                    type="button"
                    @click="document.querySelectorAll('.fi-sc-wizard-header-step')[{{ $steps_indexes['caracteristicas_financeiras'] }}]?.click(); window.scrollTo({ top: 0, behavior: 'smooth' });"
                    class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold text-amber-400 hover:text-amber-300 bg-amber-500/10 hover:bg-amber-500/20 border border-amber-500/25 rounded-lg transition-colors cursor-pointer"
                >
                    <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" />
                    </svg>
                    Editar
                </button>
            </div>

            <div class="p-5 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-white/45 block">Data de Emissão</span>
                    <span class="text-xs sm:text-sm font-medium text-[#fbfaf8] block tabular-nums">{{ $caracteristicas_financeiras['issue_date'] ?: '—' }}</span>
                </div>
                <div>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-white/45 block">Data de Vencimento</span>
                    <span class="text-xs sm:text-sm font-medium text-[#fbfaf8] block tabular-nums">{{ $caracteristicas_financeiras['maturity_date'] ?: '—' }}</span>
                </div>
                <div>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-white/45 block">Série / Número</span>
                    <span class="text-xs sm:text-sm font-medium text-[#fbfaf8] block">{{ $caracteristicas_financeiras['series_number'] ?: '—' }}</span>
                </div>
                <div>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-white/45 block">Regime Fiduciário</span>
                    <span class="text-xs sm:text-sm font-medium text-[#fbfaf8] block">{{ $caracteristicas_financeiras['fiduciary_regime_label'] ?: '—' }}</span>
                </div>
                <div>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-white/45 block">Forma dos Títulos</span>
                    <span class="text-xs sm:text-sm font-medium text-[#fbfaf8] block">{{ $caracteristicas_financeiras['form_type'] ?: '—' }}</span>
                </div>
                <div>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-white/45 block">Atualização Monetária</span>
                    <span class="text-xs sm:text-sm font-medium text-[#fbfaf8] block">{{ $caracteristicas_financeiras['monetary_update_period'] ?: '—' }}</span>
                </div>
                <div>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-white/45 block">Pagamento de Juros</span>
                    <span class="text-xs sm:text-sm font-medium text-[#fbfaf8] block">{{ $caracteristicas_financeiras['interest_payment_frequency'] ?: '—' }}</span>
                </div>
                <div>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-white/45 block">Amortização</span>
                    <span class="text-xs sm:text-sm font-medium text-[#fbfaf8] block">{{ $caracteristicas_financeiras['amortization_frequency'] ?: '—' }}</span>
                </div>
                <div>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-white/45 block">Concentração</span>
                    <span class="text-xs sm:text-sm font-medium text-[#fbfaf8] block">{{ $caracteristicas_financeiras['concentration'] ?: '—' }}</span>
                </div>
                <div>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-white/45 block">Resgate Antecipado</span>
                    <span class="text-xs sm:text-sm font-medium text-[#fbfaf8] block">{{ $caracteristicas_financeiras['prepayment_possibility_label'] ?: '—' }}</span>
                </div>
                <div>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-white/45 block">Segmento</span>
                    <span class="text-xs sm:text-sm font-medium text-[#fbfaf8] block">{{ $caracteristicas_financeiras['segment'] ?: '—' }}</span>
                </div>
                <div>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-white/45 block">Público Alvo</span>
                    <span class="text-xs sm:text-sm font-medium text-[#fbfaf8] block">{{ $caracteristicas_financeiras['target_audience'] ?: '—' }}</span>
                </div>
            </div>
        </div>

        <!-- 05. VALORES E REMUNERAÇÃO -->
        <div class="rounded-xl bg-[#06151c] border border-white/10 shadow-sm overflow-hidden">
            <div class="flex items-center justify-between px-5 py-3.5 bg-white/[0.03] border-b border-white/5">
                <div class="flex items-center gap-2.5">
                    <span class="text-xs font-bold text-amber-400 font-mono">05</span>
                    <h4 class="text-sm font-bold text-[#fbfaf8]">Valores e Remuneração</h4>
                    @if($steps_validity['valores_remuneracao'])
                        <span class="inline-flex items-center gap-1 text-[11px] font-medium text-emerald-400">
                            <svg class="size-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd"/></svg>
                            Preenchido
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1 text-[11px] font-medium text-amber-400">
                            Atenção
                        </span>
                    @endif
                </div>

                <button
                    type="button"
                    @click="document.querySelectorAll('.fi-sc-wizard-header-step')[{{ $steps_indexes['valores_remuneracao'] }}]?.click(); window.scrollTo({ top: 0, behavior: 'smooth' });"
                    class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold text-amber-400 hover:text-amber-300 bg-amber-500/10 hover:bg-amber-500/20 border border-amber-500/25 rounded-lg transition-colors cursor-pointer"
                >
                    <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" />
                    </svg>
                    Editar
                </button>
            </div>

            <div class="p-5 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-white/45 block">Tipo de Oferta</span>
                    <span class="text-xs sm:text-sm font-medium text-[#fbfaf8] block">{{ $valores_remuneracao['offer_type'] ?: '—' }}</span>
                </div>
                <div>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-white/45 block">Indexador</span>
                    <span class="text-xs sm:text-sm font-medium text-amber-300 block">{{ $valores_remuneracao['remuneration_indexer'] ?: '—' }}</span>
                </div>
                <div>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-white/45 block">Taxa de Remuneração</span>
                    <span class="text-xs sm:text-sm font-medium text-amber-300 block tabular-nums">{{ $valores_remuneracao['remuneration_rate'] ? $valores_remuneracao['remuneration_rate'] . '%' : '—' }}</span>
                </div>
                <div>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-white/45 block">Preço Unitário (PU)</span>
                    <span class="text-xs sm:text-sm font-medium text-[#fbfaf8] block tabular-nums">{{ $valores_remuneracao['issued_price'] ? 'R$ ' . $valores_remuneracao['issued_price'] : '—' }}</span>
                </div>
                <div>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-white/45 block">Quantidade Emitida</span>
                    <span class="text-xs sm:text-sm font-medium text-[#fbfaf8] block tabular-nums">{{ $valores_remuneracao['issued_quantity'] ?: '0' }}</span>
                </div>
                <div>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-white/45 block">Quantidade Integralizada</span>
                    <span class="text-xs sm:text-sm font-medium text-[#fbfaf8] block tabular-nums">{{ $valores_remuneracao['integralized_quantity'] ?: '0' }}</span>
                </div>
                <div>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-white/45 block">Quantidade Restante</span>
                    <span class="text-xs sm:text-sm font-medium text-[#fbfaf8] block tabular-nums">{{ $valores_remuneracao['remaining_quantity'] ?: '0' }}</span>
                </div>
                <div>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-white/45 block">Volume Total Emitido</span>
                    <span class="text-xs sm:text-sm font-bold text-emerald-400 block tabular-nums">{{ $valores_remuneracao['issued_volume'] ? 'R$ ' . $valores_remuneracao['issued_volume'] : '—' }}</span>
                </div>
            </div>
        </div>

        <!-- 06. LASTRO, GARANTIAS E OPERAÇÃO -->
        <div class="rounded-xl bg-[#06151c] border border-white/10 shadow-sm overflow-hidden">
            <div class="flex items-center justify-between px-5 py-3.5 bg-white/[0.03] border-b border-white/5">
                <div class="flex items-center gap-2.5">
                    <span class="text-xs font-bold text-amber-400 font-mono">06</span>
                    <h4 class="text-sm font-bold text-[#fbfaf8]">Lastro, garantias e operação</h4>
                    <span class="inline-flex items-center gap-1 text-[11px] font-medium text-emerald-400">
                        <svg class="size-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd"/></svg>
                        Preenchido
                    </span>
                </div>

                <button
                    type="button"
                    @click="document.querySelectorAll('.fi-sc-wizard-header-step')[{{ $steps_indexes['lastro_garantias'] }}]?.click(); window.scrollTo({ top: 0, behavior: 'smooth' });"
                    class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold text-amber-400 hover:text-amber-300 bg-amber-500/10 hover:bg-amber-500/20 border border-amber-500/25 rounded-lg transition-colors cursor-pointer"
                >
                    <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" />
                    </svg>
                    Editar
                </button>
            </div>

            <div class="p-5 space-y-4">
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                    <div>
                        <span class="text-[10px] font-bold uppercase tracking-wider text-white/45 block">Fundo de Fiança</span>
                        <span class="text-xs sm:text-sm font-medium text-[#fbfaf8] block">{{ $lastro_garantias['guarantee_fund'] ?: 'Não' }}</span>
                    </div>
                    <div>
                        <span class="text-[10px] font-bold uppercase tracking-wider text-white/45 block">Fundo de Despesas</span>
                        <span class="text-xs sm:text-sm font-medium text-[#fbfaf8] block">{{ $lastro_garantias['expense_fund'] ?: 'Não' }}</span>
                    </div>
                    <div>
                        <span class="text-[10px] font-bold uppercase tracking-wider text-white/45 block">Fundo de Liquidez</span>
                        <span class="text-xs sm:text-sm font-medium text-[#fbfaf8] block">{{ $lastro_garantias['liquidity_fund'] ?: 'Não' }}</span>
                    </div>
                    <div>
                        <span class="text-[10px] font-bold uppercase tracking-wider text-white/45 block">Fundo de Reserva</span>
                        <span class="text-xs sm:text-sm font-medium text-[#fbfaf8] block">{{ $lastro_garantias['reserve_fund'] ?: 'Não' }}</span>
                    </div>
                </div>

                @if(!empty($lastro_garantias['clauses']))
                    <div class="pt-3 border-t border-white/5 space-y-3">
                        @foreach($lastro_garantias['clauses'] as $clause)
                            <div>
                                <span class="text-[10px] font-bold uppercase tracking-wider text-white/45 block">{{ $clause['label'] }}</span>
                                <p class="text-xs text-white/80 line-clamp-2 mt-0.5">{{ $clause['value'] }}</p>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        <!-- 07. DOCUMENTOS E INFORMAÇÕES PÚBLICAS -->
        <div class="rounded-xl bg-[#06151c] border border-white/10 shadow-sm overflow-hidden">
            <div class="flex items-center justify-between px-5 py-3.5 bg-white/[0.03] border-b border-white/5">
                <div class="flex items-center gap-2.5">
                    <span class="text-xs font-bold text-amber-400 font-mono">07</span>
                    <h4 class="text-sm font-bold text-[#fbfaf8]">Documentos e informações públicas</h4>
                    <span class="inline-flex items-center gap-1 text-[11px] font-medium text-emerald-400">
                        <svg class="size-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd"/></svg>
                        Preenchido
                    </span>
                </div>

                <button
                    type="button"
                    @click="document.querySelectorAll('.fi-sc-wizard-header-step')[{{ $steps_indexes['documentos'] }}]?.click(); window.scrollTo({ top: 0, behavior: 'smooth' });"
                    class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold text-amber-400 hover:text-amber-300 bg-amber-500/10 hover:bg-amber-500/20 border border-amber-500/25 rounded-lg transition-colors cursor-pointer"
                >
                    <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" />
                    </svg>
                    Editar
                </button>
            </div>

            <div class="p-5 grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-white/45 block">Classificação de Risco</span>
                    <span class="text-xs sm:text-sm font-medium text-[#fbfaf8] block">{{ $documentos['risk_rating'] ?: 'Não informada' }}</span>
                </div>
                <div>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-white/45 block">Código B3</span>
                    <span class="text-xs sm:text-sm font-mono font-medium text-[#fbfaf8] block">{{ $documentos['public_trading_code'] ?: 'Não informado' }}</span>
                </div>
                <div>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-white/45 block">Ambiente de Negociação</span>
                    <span class="text-xs sm:text-sm font-medium text-[#fbfaf8] block">{{ $documentos['trading_environment'] ?: 'Não informado' }}</span>
                </div>
                @if($documentos['description'])
                    <div class="col-span-full pt-2 border-t border-white/5">
                        <span class="text-[10px] font-bold uppercase tracking-wider text-white/45 block">Notas Institucionais / Sumário</span>
                        <p class="text-xs text-white/80 mt-1 leading-relaxed">{{ $documentos['description'] }}</p>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <!-- Rodapé de Confirmação -->
    <div class="flex items-center gap-3 p-4 rounded-xl bg-amber-500/10 border border-amber-500/20 text-xs text-amber-200/90">
        <svg class="size-5 text-amber-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
        </svg>
        <span>
            Ao clicar no botão de confirmação abaixo, a operação será salva e registrada no sistema com as informações conferidas nesta ficha.
        </span>
    </div>
</div>

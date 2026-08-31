@php
    $stateLabels = [
        'missing' => 'Sem calendário',
        'partial' => 'Parcial',
        'provisional' => 'Provisório',
        'confirmed' => 'Confirmado',
        'stale' => 'Desatualizado',
    ];
@endphp

<div class="business-calendar-overview-container flex w-full flex-col gap-6">

    {{-- 1. FAIXA COMPACTA DE STATUS (KPIS OPERACIONAIS - 100% LARGURA ÚTIL) --}}
    <div class="bsi-calendar-kpi-bar w-full overflow-hidden rounded-xl border border-slate-700/40 bg-[#0d252e] shadow-sm">
        <div class="grid grid-cols-1 divide-y divide-slate-700/30 sm:grid-cols-2 sm:divide-y-0 sm:divide-x lg:grid-cols-5">
            {{-- KPI 1: Calendários Ativos --}}
            <div class="flex flex-col p-4 sm:p-5">
                <span class="text-[0.6875rem] font-semibold uppercase tracking-wider text-slate-400">Calendários Ativos</span>
                <span class="mt-2 font-mono text-3xl font-bold tracking-tight text-white">{{ $kpis['active_calendars'] }}</span>
                <span class="mt-1 text-xs text-slate-400">Homologados no sistema</span>
            </div>

            {{-- KPI 2: Feriados Cadastrados --}}
            <div class="flex flex-col p-4 sm:p-5">
                <span class="text-[0.6875rem] font-semibold uppercase tracking-wider text-slate-400">Feriados Cadastrados</span>
                <span class="mt-2 font-mono text-3xl font-bold tracking-tight text-white">{{ number_format($kpis['total_holidays'], 0, ',', '.') }}</span>
                <span class="mt-1 text-xs text-slate-400">Base consolidada</span>
            </div>

            {{-- KPI 3: Fontes Oficiais --}}
            <div class="flex flex-col p-4 sm:p-5">
                <span class="text-[0.6875rem] font-semibold uppercase tracking-wider text-slate-400">Fontes Oficiais</span>
                <span class="mt-2 font-mono text-3xl font-bold tracking-tight text-white">{{ $kpis['configured_sources_count'] }}</span>
                <span class="mt-1 text-xs text-slate-400">ANBIMA, B3, Legislação</span>
            </div>

            {{-- KPI 4: Divergências / Conflitos --}}
            <div class="flex flex-col p-4 sm:p-5">
                <span class="text-[0.6875rem] font-semibold uppercase tracking-wider text-slate-400">Divergências</span>
                <div class="mt-2 flex items-baseline gap-2">
                    <span @class([
                        'font-mono text-3xl font-bold tracking-tight',
                        'text-emerald-400' => $kpis['total_conflicts'] === 0,
                        'text-amber-400' => $kpis['total_conflicts'] > 0,
                    ])>{{ $kpis['total_conflicts'] }}</span>
                    @if ($kpis['total_conflicts'] === 0)
                        <span class="inline-flex items-center rounded-full bg-emerald-500/15 px-2 py-0.5 text-[0.6875rem] font-medium text-emerald-300">0 pendências</span>
                    @else
                        <span class="inline-flex items-center rounded-full bg-amber-500/20 px-2 py-0.5 text-[0.6875rem] font-medium text-amber-300">Atenção</span>
                    @endif
                </div>
                <span class="mt-1 text-xs text-slate-400">
                    {{ $kpis['total_overrides'] > 0 ? $kpis['total_overrides'].' override(s) ativo(s)' : 'Sem conflitos ativos' }}
                </span>
            </div>

            {{-- KPI 5: Última Sincronização --}}
            <div class="flex flex-col p-4 sm:p-5">
                <span class="text-[0.6875rem] font-semibold uppercase tracking-wider text-slate-400">Última Sincronização</span>
                <div class="mt-2">
                    @if ($kpis['last_sync_at'])
                        <span class="font-mono text-base font-bold tracking-tight text-white sm:text-lg whitespace-nowrap">
                            {{ \Carbon\Carbon::parse($kpis['last_sync_at'])->format('d/m/Y · H:i') }}
                        </span>
                    @else
                        <span class="text-base font-bold text-slate-300">Sem histórico</span>
                    @endif
                </div>
                <div class="mt-1 flex items-center gap-1.5">
                    <span class="inline-flex items-center rounded-md bg-emerald-500/15 px-2 py-0.5 text-[0.6875rem] font-medium text-emerald-300 border border-emerald-500/25">
                        {{ $kpis['last_sync_status_label'] }}
                    </span>
                </div>
            </div>
        </div>
    </div>

    {{-- 2. BLOCO DE ATENÇÃO / ESTADO POSITIVO (100% LARGURA ÚTIL) --}}
    @if (count($attentions) > 0)
        <div class="w-full rounded-xl border border-amber-500/25 bg-amber-500/10 p-4 text-amber-200">
            <div class="flex items-start gap-3">
                <x-heroicon-o-exclamation-triangle class="mt-0.5 h-4 w-4 shrink-0 text-amber-400" />
                <div class="flex-1 space-y-1">
                    <div class="text-xs font-semibold text-amber-300">Atenções operacionais detectadas</div>
                    <ul class="list-disc pl-4 space-y-0.5 text-xs text-amber-200/90">
                        @foreach ($attentions as $attention)
                            <li>{{ $attention['message'] }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    @else
        <div class="flex w-full items-center gap-3 rounded-xl border border-emerald-500/25 bg-emerald-500/10 px-4 py-3 text-emerald-300">
            <x-heroicon-o-check-circle class="h-4 w-4 shrink-0 text-emerald-400" />
            <span class="text-xs font-medium">Nenhuma pendência crítica — Todos os calendários de negócio estão cobertos e alinhados com suas fontes oficiais.</span>
        </div>
    @endif

    {{-- 3. CARDS DE CALENDÁRIOS DE NEGÓCIO (3 POR LINHA NO DESKTOP, LARGURA AMPLA) --}}
    <div class="w-full space-y-3">
        <div class="flex items-center justify-between">
            <div>
                <h3 class="text-sm font-semibold text-white">Calendários de Negócio</h3>
                <p class="text-xs text-slate-400">Definições de dias úteis, convenções de mercado e fontes oficiais homologadas.</p>
            </div>
        </div>

        <div class="grid w-full grid-cols-1 gap-5 md:grid-cols-2 lg:grid-cols-3">
            @foreach ($definitions as $code => $definition)
                @php
                    $isLegacy = $definition['legacy'];
                    $isTrading = $code === \App\Domain\PuCalculator\Support\BusinessCalendarRegistry::B3_LISTED_TRADING;
                    $friendlyName = match ($code) {
                        \App\Domain\PuCalculator\Support\BusinessCalendarRegistry::BR_BANKING_ANBIMA => 'Calendário Bancário ANBIMA',
                        \App\Domain\PuCalculator\Support\BusinessCalendarRegistry::B3_LISTED_TRADING => 'Sessões de Negociação B3',
                        \App\Domain\PuCalculator\Support\BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS => 'Feriados Nacionais — Brasil',
                        \App\Domain\PuCalculator\Support\BusinessCalendarRegistry::LEGACY_B3 => 'B3 (Legado — Alias ANBIMA)',
                        default => $definition['label'],
                    };
                @endphp

                <div
                    wire:key="calendar-card-{{ $code }}"
                    class="group relative flex w-full flex-col justify-between rounded-xl border border-slate-700/40 bg-[#0d252e] p-5 transition-all duration-150 hover:border-slate-600/60 hover:bg-[#0f2c37]"
                >
                    <div>
                        {{-- Header do Card: Nome e Badge no topo --}}
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0 flex-1">
                                <h4 class="text-sm font-bold text-white leading-snug" title="{{ $definition['label'] }}">
                                    {{ $friendlyName }}
                                </h4>
                                <div class="mt-1 font-mono text-xs font-semibold text-[#d8a94f]">
                                    {{ $code }}
                                </div>
                            </div>

                            {{-- Badge de Status Semântico no Topo Direito --}}
                            @if ($isLegacy)
                                <span class="inline-flex shrink-0 items-center rounded-md bg-amber-500/20 px-2 py-0.5 text-xs font-semibold text-amber-200 border border-amber-500/30">
                                    Alias Legado
                                </span>
                            @elseif ($isTrading)
                                <span class="inline-flex shrink-0 items-center rounded-md bg-[#b7832f]/25 px-2 py-0.5 text-xs font-semibold text-[#fbfaf8] border border-[#b7832f]/40">
                                    Negociação B3
                                </span>
                            @else
                                <span class="inline-flex shrink-0 items-center rounded-md bg-emerald-500/20 px-2 py-0.5 text-xs font-semibold text-emerald-200 border border-emerald-500/30">
                                    Bancário Oficial
                                </span>
                            @endif
                        </div>

                        {{-- Propósito --}}
                        <p class="mt-3 text-xs leading-relaxed text-slate-300" title="{{ $definition['meaning'] }}">
                            {{ $definition['meaning'] }}
                        </p>

                        {{-- Grid de Informações Chave --}}
                        <div class="mt-4 grid grid-cols-2 gap-3 rounded-lg bg-[#06151c]/60 p-3 text-xs border border-slate-800/80">
                            <div>
                                <span class="text-[0.6875rem] font-medium text-slate-400 block">Tipo</span>
                                <span class="mt-0.5 font-semibold text-slate-200 block">{{ $definition['type'] }}</span>
                            </div>
                            <div>
                                <span class="text-[0.6875rem] font-medium text-slate-400 block">Fonte Oficial</span>
                                <span class="mt-0.5 font-semibold text-slate-200 block">{{ $definition['source'] ?? 'Oficial' }}</span>
                            </div>
                            <div>
                                <span class="text-[0.6875rem] font-medium text-slate-400 block">Anos Confirmados</span>
                                <span class="mt-0.5 font-semibold text-slate-200 block">{{ $definition['confirmed_years_count'] }} ano(s)</span>
                            </div>
                            <div>
                                <span class="text-[0.6875rem] font-medium text-slate-400 block">Uso Financeiro</span>
                                <span @class([
                                    'mt-0.5 font-semibold block',
                                    'text-emerald-400' => $definition['financial_use_allowed'],
                                    'text-slate-400' => ! $definition['financial_use_allowed'],
                                ])>{{ $definition['financial_use_allowed'] ? 'Permitido' : 'Restrito' }}</span>
                            </div>
                        </div>
                    </div>

                    {{-- Footer do Card --}}
                    <div class="mt-4 flex items-center justify-between border-t border-slate-700/30 pt-3 text-xs text-slate-400">
                        @if ($latestAttempts->get($code))
                            <span class="truncate font-mono text-[0.6875rem]">Sync: {{ $latestAttempts->get($code)->started_at?->format('d/m/Y · H:i') }}</span>
                            <span class="shrink-0 text-xs font-medium text-slate-300">{{ $latestAttempts->get($code)->result }}</span>
                        @else
                            <span class="text-[0.6875rem]">Sem sincronização recente</span>
                            <span class="text-slate-500">—</span>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    {{-- 4. SEÇÃO: MAPEAMENTOS, FONTES E DETALHES TÉCNICOS (ACCORDIONS 100% LARGURA ÚTIL) --}}
    <div class="w-full space-y-3 pt-2">
        <div>
            <h3 class="text-sm font-semibold text-white">Mapeamentos e Governança Anual</h3>
            <p class="text-xs text-slate-400">Histórico de revisões, cobertura de dias úteis, hashes SHA-256 e documentos comprobatórios por calendário.</p>
        </div>

        <div class="w-full space-y-3">
            @foreach ($definitions as $code => $definition)
                @php
                    $calendarRows = $summaries->get($code, collect());
                    $availableYears = $calendarRows->pluck('year')->sort()->values();
                @endphp

                <x-filament::section
                    wire:key="calendar-years-{{ $code }}"
                    :heading="$definition['label']"
                    :description="$availableYears->isEmpty()
                        ? 'Nenhum ano disponível.'
                        : sprintf('%d ano(s) cadastrado(s) (%d a %d) • Fonte: %s • Política: %s', $availableYears->count(), $availableYears->first(), $availableYears->last(), $definition['source'] ?? 'Oficial', $definition['type'])"
                    collapsible
                    collapsed
                    class="bsi-calendar-accordion w-full"
                >
                    @if ($calendarRows->isEmpty())
                        <div class="rounded-lg border border-dashed border-slate-700/50 p-4 text-center text-xs text-slate-400">
                            Sem dados, versão anual ou tentativa de importação registrada para este calendário.
                        </div>
                    @else
                        <div class="overflow-x-auto rounded-lg border border-slate-700/40">
                            <table class="min-w-full divide-y divide-slate-700/40 text-xs">
                                <thead class="bg-[#0a2028] text-left text-[0.6875rem] font-semibold uppercase tracking-wider text-slate-400">
                                    <tr>
                                        <th class="px-3.5 py-2.5">Ano / Estado</th>
                                        <th class="px-3.5 py-2.5">Cobertura</th>
                                        <th class="px-3.5 py-2.5">Fonte / Documento</th>
                                        <th class="px-3.5 py-2.5">Sincronização</th>
                                        <th class="px-3.5 py-2.5 text-right">Não Úteis</th>
                                        <th class="px-3.5 py-2.5 text-right">Conflitos</th>
                                        <th class="px-3.5 py-2.5 text-right">Overrides</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-700/20 bg-[#0d252e]">
                                    @foreach ($calendarRows as $row)
                                        <tr wire:key="calendar-year-{{ $code }}-{{ $row['year'] }}" class="hover:bg-[#12313b]/60 transition-colors">
                                            <td class="px-3.5 py-2.5 whitespace-nowrap">
                                                <div class="font-bold text-white">{{ $row['year'] }}</div>
                                                <span @class([
                                                    'mt-0.5 inline-flex rounded px-1.5 py-0.5 text-[0.625rem] font-semibold',
                                                    'bg-emerald-500/20 text-emerald-300 border border-emerald-500/30' => $row['state'] === 'confirmed',
                                                    'bg-cyan-500/20 text-cyan-300 border border-cyan-500/30' => $row['state'] === 'provisional',
                                                    'bg-amber-500/20 text-amber-300 border border-amber-500/30' => $row['state'] === 'partial',
                                                    'bg-rose-500/20 text-rose-300 border border-rose-500/30' => in_array($row['state'], ['missing', 'stale'], true),
                                                ])>{{ $stateLabels[$row['state']] ?? $row['state'] }}</span>
                                                <span class="block text-[0.625rem] text-slate-400 mt-0.5">rev. {{ $row['revision'] }}</span>
                                            </td>
                                            <td class="px-3.5 py-2.5 text-slate-300 whitespace-nowrap">
                                                <div class="font-mono">{{ $row['covered_days'] }} / {{ $row['expected_days'] }} dias</div>
                                                @if ($row['missing_days'] > 0)
                                                    <div class="text-[0.625rem] font-semibold text-rose-400">{{ $row['missing_days'] }} ausente(s)</div>
                                                @endif
                                                @if ($row['confirmed_at'])
                                                    <div class="text-[0.625rem] text-slate-400 mt-0.5">
                                                        Confirmado: {{ $row['confirmed_at']->format('d/m/Y') }}{{ $row['confirmed_by'] ? ' ('.$row['confirmed_by'].')' : '' }}
                                                    </div>
                                                @endif
                                            </td>
                                            <td class="px-3.5 py-2.5 text-slate-300 max-w-xs">
                                                <div class="font-medium text-white">{{ $row['source'] ?? 'Não informada' }}{{ $row['source_is_official'] ? ' • Oficial' : '' }}</div>
                                                <div class="truncate text-[0.625rem] text-slate-400 mt-0.5" title="{{ $row['source_document'] ?? '' }}">{{ $row['source_document'] ?? 'Sem documento' }}</div>
                                                @if ($row['checksum'])
                                                    <div class="font-mono text-[0.625rem] text-slate-400 mt-0.5">sha256 {{ \Illuminate\Support\Str::limit($row['checksum'], 12, '…') }}</div>
                                                @endif
                                            </td>
                                            <td class="px-3.5 py-2.5 text-slate-400 whitespace-nowrap text-[0.6875rem]">
                                                <div>Aplicação: {{ $row['last_import_at']?->format('d/m/Y · H:i') ?? '—' }}</div>
                                                <div>Tentativa: {{ $row['last_attempt_at']?->format('d/m/Y · H:i') ?? '—' }}</div>
                                                @if ($row['last_attempt_result'])
                                                    <div class="text-slate-300 font-medium mt-0.5">{{ $row['last_attempt_result'] }}</div>
                                                @endif
                                            </td>
                                            <td class="px-3.5 py-2.5 text-right font-mono font-semibold text-slate-200">{{ $row['non_business_days'] }}</td>
                                            <td @class([
                                                'px-3.5 py-2.5 text-right font-mono font-semibold',
                                                'text-rose-400' => $row['conflicts'] > 0,
                                                'text-slate-400' => $row['conflicts'] === 0,
                                            ])>{{ $row['conflicts'] }}</td>
                                            <td @class([
                                                'px-3.5 py-2.5 text-right font-mono font-semibold',
                                                'text-amber-300' => $row['overrides'] > 0,
                                                'text-slate-400' => $row['overrides'] === 0,
                                            ])>{{ $row['overrides'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </x-filament::section>
            @endforeach
        </div>
    </div>
</div>



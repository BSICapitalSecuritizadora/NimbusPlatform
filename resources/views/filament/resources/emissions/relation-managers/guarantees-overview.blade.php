@php
    use App\Enums\GuaranteeValueStatus;

    /** @var \App\DTOs\Guarantees\EmissionGuaranteePositionData $position */
    /** @var \Illuminate\Support\Collection $alerts */
    /** @var \Illuminate\Support\Collection $history */

    $alerts ??= collect();
    $history ??= collect();
    $pendingDetections ??= 0;
    $canUpdateValues ??= false;
    $canCloseCompetence ??= false;
    $canCreate ??= false;
    $isCompetenceClosed ??= false;

    // Ausência é indicada com traço elegante (§25 do escopo)
    $money = static fn (?float $value): string => $value === null
        ? '—'
        : 'R$ ' . \App\Concerns\MoneyFormatter::formatCurrencyForDisplay($value);

    $ratio = static fn (?float $value): string => $value === null
        ? '—'
        : number_format($value * 100, 2, ',', '.') . '%';

    $statusBadgeClasses = match ($position->coverageStatus->color()) {
        'success' => 'border-emerald-500/30 bg-emerald-950/60 text-emerald-300',
        'warning' => 'border-amber-500/30 bg-amber-950/60 text-amber-300',
        'danger' => 'border-rose-500/30 bg-rose-950/60 text-rose-300',
        default => 'border-[#1d4554]/60 bg-[#0c232e] text-slate-300',
    };

    $coverageCardClasses = match ($position->coverageStatus->color()) {
        'success' => 'border-emerald-500/40 bg-gradient-to-b from-[#0e2f3d] to-[#0c2733]',
        'warning' => 'border-amber-500/40 bg-gradient-to-b from-[#2e2615] to-[#1a1b18]',
        'danger' => 'border-rose-500/40 bg-gradient-to-b from-[#2e151b] to-[#1a1518]',
        default => 'border-[#1d4554]/60 bg-[#0c232e]',
    };

    $isSurplusDeficit = $position->surplusDeficit !== null && $position->surplusDeficit < 0;
@endphp

<div class="mb-6 space-y-4">
    {{-- 1. Resumo Executivo da Competência --}}
    <section class="overflow-hidden rounded-2xl border border-[#1d4554]/60 bg-[#0c232e] shadow-xl shadow-black/20">
        <div class="flex flex-col gap-3 border-b border-[#1d4554]/40 px-6 py-4 sm:px-8 xl:flex-row xl:items-center xl:justify-between">
            <div class="space-y-1">
                <div class="flex items-center gap-2">
                    <span class="text-[10px] font-bold uppercase tracking-[0.18em] text-slate-400">Acompanhamento de Garantias</span>
                    @if ($isCompetenceClosed)
                        <span class="inline-flex items-center gap-1 rounded-md bg-slate-800 px-2 py-0.5 text-[10px] font-medium text-slate-300 border border-slate-700">
                            <x-heroicon-m-lock-closed class="h-3 w-3" />
                            Fechada
                        </span>
                    @endif
                </div>
                <h3 class="text-xl font-bold text-white tracking-tight">
                    Competência {{ $position->referenceMonthLabel() }}
                </h3>
            </div>

            <div class="flex items-center gap-3">
                <span class="rounded-full border px-3.5 py-1 text-xs font-semibold uppercase tracking-wider {{ $statusBadgeClasses }}">
                    {{ $position->coverageStatus->label() }}
                </span>
            </div>
        </div>

        {{-- 4 KPIs Principais --}}
        <div class="grid gap-3 p-5 sm:p-6 md:grid-cols-2 xl:grid-cols-4">
            {{-- KPI 1: Valor das Garantias --}}
            <div class="flex flex-col justify-between rounded-xl border border-[#1d4554]/50 bg-[#081a22] p-4 shadow-sm">
                <div>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Valor das Garantias</span>
                    <div class="mt-2 text-2xl font-bold tracking-tight text-white font-mono">
                        {{ $money($position->totalEligibleValue) }}
                    </div>
                </div>
                <p class="mt-3 text-xs text-slate-400 border-t border-[#1d4554]/30 pt-2">
                    @if ($position->totalGrossValue !== null)
                        Bruto: <span class="text-slate-200 font-medium font-mono">{{ $money($position->totalGrossValue) }}</span>
                    @else
                        Dados ainda não informados
                    @endif
                </p>
            </div>

            {{-- KPI 2: Saldo Devedor --}}
            <div class="flex flex-col justify-between rounded-xl border border-[#1d4554]/50 bg-[#081a22] p-4 shadow-sm">
                <div>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Saldo Devedor</span>
                    <div class="mt-2 text-2xl font-bold tracking-tight text-white font-mono">
                        {{ $money($position->outstandingBalance) }}
                    </div>
                </div>
                <p class="mt-3 text-xs text-slate-400 border-t border-[#1d4554]/30 pt-2">
                    @if ($position->outstandingBalance !== null)
                        Curva de PU da competência
                    @else
                        Aguardando curva de PU
                    @endif
                </p>
            </div>

            {{-- KPI 3: Cobertura --}}
            <div class="flex flex-col justify-between rounded-xl border p-4 shadow-sm relative overflow-hidden {{ $coverageCardClasses }}">
                <div class="absolute -right-6 -bottom-6 w-24 h-24 bg-white/5 rounded-full pointer-events-none blur-xl"></div>
                <div>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-slate-300">Índice de Cobertura</span>
                    <div class="mt-2 text-2xl font-bold tracking-tight text-white font-mono">
                        {{ $ratio($position->coverageRatio) }}
                    </div>
                </div>
                <p class="mt-3 text-xs text-slate-300/90 border-t border-white/10 pt-2 flex items-center justify-between">
                    <span>Mínimo contratual:</span>
                    <span class="font-semibold text-white font-mono">{{ $ratio($position->requiredRatio) }}</span>
                </p>
            </div>

            {{-- KPI 4: Excedente / Déficit --}}
            <div class="flex flex-col justify-between rounded-xl border border-[#1d4554]/50 bg-[#081a22] p-4 shadow-sm">
                <div>
                    <span class="text-[10px] font-bold uppercase tracking-wider {{ $isSurplusDeficit ? 'text-rose-400' : 'text-slate-400' }}">
                        {{ $isSurplusDeficit ? 'Déficit de Cobertura' : 'Excedente de Cobertura' }}
                    </span>
                    <div class="mt-2 text-2xl font-bold tracking-tight font-mono {{ $isSurplusDeficit ? 'text-rose-400' : 'text-white' }}">
                        {{ $position->surplusDeficit === null ? '—' : $money(abs($position->surplusDeficit)) }}
                    </div>
                </div>
                <p class="mt-3 text-xs text-slate-400 border-t border-[#1d4554]/30 pt-2 flex items-center justify-between">
                    <span>{{ $position->activeGuaranteesCount }} ativa(s)</span>
                    <span>Exigido: <strong class="text-slate-200 font-mono">{{ $money($position->totalRequiredValue) }}</strong></span>
                </p>
            </div>
        </div>
    </section>

    {{-- 2. Pendências e Alertas (Colapsável) --}}
    @if ($alerts->isNotEmpty())
        <section x-data="{ open: true }" class="overflow-hidden rounded-2xl border border-amber-500/30 bg-[#0c232e] shadow-lg shadow-black/15">
            <div class="flex items-center justify-between border-b border-[#1d4554]/40 px-6 py-3.5 sm:px-8 cursor-pointer select-none" @click="open = !open">
                <div class="flex items-center gap-2.5">
                    <x-heroicon-m-exclamation-triangle class="h-4 w-4 text-amber-400" />
                    <span class="text-xs font-semibold uppercase tracking-wider text-amber-300">Pendências e Alertas</span>
                    <span class="rounded-full bg-amber-500/20 px-2 py-0.5 text-xs font-bold text-amber-300 border border-amber-500/30">
                        {{ $alerts->count() }}
                    </span>
                </div>
                <button type="button" class="text-xs text-slate-400 hover:text-white flex items-center gap-1 font-medium transition-colors">
                    <span x-text="open ? 'Recolher' : 'Expandir'"></span>
                    <x-heroicon-m-chevron-down class="h-4 w-4 transition-transform duration-200" :class="{ 'rotate-180': open }" />
                </button>
            </div>

            <ul x-show="open" x-transition class="divide-y divide-[#1d4554]/40">
                @foreach ($alerts as $alert)
                    @php
                        $alertBadgeClasses = match ($alert['severity']) {
                            'danger' => 'bg-rose-950/60 text-rose-300 border border-rose-500/30',
                            'warning' => 'bg-amber-950/60 text-amber-300 border border-amber-500/30',
                            default => 'bg-[#081a22] text-slate-300 border border-[#1d4554]/40',
                        };
                    @endphp
                    <li class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 px-6 py-3.5 sm:px-8 hover:bg-[#081a22]/50 transition-colors">
                        <div class="space-y-0.5">
                            <div class="flex items-center gap-2.5">
                                <span class="rounded-md px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider {{ $alertBadgeClasses }}">
                                    {{ $alert['severity'] === 'danger' ? 'Crítico' : 'Atenção' }}
                                </span>
                                <span class="font-semibold text-sm text-white">{{ $alert['title'] }}</span>
                            </div>
                            <p class="text-xs text-slate-400 pl-0.5">{{ $alert['description'] }}</p>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @else
        <div class="flex items-center gap-2 rounded-xl border border-emerald-500/20 bg-[#0c232e] px-4 py-2.5 text-xs text-emerald-300">
            <x-heroicon-m-check-circle class="h-4 w-4 text-emerald-400" />
            <span>Nenhuma pendência ou inconformidade detectada para esta competência.</span>
        </div>
    @endif

    {{-- 3. Garantias Detectadas (Aviso) --}}
    @if ($pendingDetections > 0)
        <section class="rounded-2xl border border-amber-500/30 bg-[#0d2632] px-6 py-4 sm:px-8 shadow-sm">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-start gap-3">
                    <x-heroicon-m-document-magnifying-glass class="h-5 w-5 text-amber-400 shrink-0 mt-0.5" />
                    <div>
                        <h4 class="text-sm font-semibold text-white">
                            {{ $pendingDetections }} garantia(s) detectada(s) nos documentos
                        </h4>
                        <p class="mt-0.5 text-xs text-amber-200/80">
                            Aguardam revisão na aba <strong class="text-white font-medium">Garantias Detectadas</strong>. Nenhuma integra a emissão até ser homologada.
                        </p>
                    </div>
                </div>
            </div>
        </section>
    @endif

    {{-- 4. Posição da Competência (Componentes de Garantia) --}}
    <section class="overflow-hidden rounded-2xl border border-[#1d4554]/60 bg-[#0c232e] shadow-xl shadow-black/15">
        <div class="flex flex-col gap-1 border-b border-[#1d4554]/40 px-6 py-4 sm:px-8">
            <span class="text-[10px] font-bold uppercase tracking-[0.18em] text-slate-400">Posição da Competência</span>
            <div class="flex flex-col gap-1 xl:flex-row xl:items-center xl:justify-between">
                <h3 class="text-base font-semibold text-white">Composição de {{ $position->referenceMonthLabel() }}</h3>
                <p class="text-xs text-slate-400">
                    Valores consolidados automaticamente a partir das fontes operacionais e laudos vigentes.
                </p>
            </div>
        </div>

        @if ($position->positions->isEmpty())
            <div class="px-6 py-8 sm:px-8 text-center">
                <div class="rounded-xl border border-dashed border-[#1d4554]/50 bg-[#081a22] px-4 py-6 text-xs text-slate-400">
                    Nenhuma garantia cadastrada nesta emissão até o momento.
                </div>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-[#1d4554]/40 text-xs">
                    <thead class="bg-[#081a22]">
                        <tr class="text-left text-[10px] font-bold uppercase tracking-[0.16em] text-slate-400">
                            <th class="px-4 py-3">Componente</th>
                            <th class="px-4 py-3">Origem</th>
                            <th class="px-4 py-3 text-right">Valor Considerado</th>
                            <th class="px-4 py-3">Atualização</th>
                            <th class="px-4 py-3">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#1d4554]/30">
                        @foreach ($position->positions as $row)
                            <tr class="align-top text-slate-200 hover:bg-[#081a22]/60 transition-colors">
                                <td class="px-4 py-3.5">
                                    <div class="font-semibold text-white">{{ $row->guarantee->display_name }}</div>
                                    <div class="mt-0.5 text-[11px] text-slate-400">
                                        {{ \App\Enums\GuaranteeType::labelFor($row->guarantee->type) }}
                                    </div>
                                </td>
                                <td class="px-4 py-3.5 text-slate-300">
                                    <span class="inline-flex rounded-md bg-[#081a22] px-2 py-0.5 text-[11px] font-medium border border-[#1d4554]/40">
                                        {{ $row->value->source->label() }}
                                    </span>
                                </td>
                                <td class="px-4 py-3.5 text-right font-mono">
                                    <div class="font-semibold text-white">{{ $money($row->currentValue()) }}</div>
                                    @if ($row->eligibleValue !== null && $row->eligibilityFactor < 1.0)
                                        <div class="mt-0.5 text-[11px] text-slate-400">
                                            Elegível: {{ $money($row->eligibleValue) }}
                                        </div>
                                    @endif
                                </td>
                                <td class="px-4 py-3.5 text-slate-300">
                                    {{ $row->value->status->label() }}
                                </td>
                                <td class="px-4 py-3.5">
                                    @php
                                        $rowStatusClasses = match ($row->coverageStatus->color()) {
                                            'success' => 'bg-emerald-950/60 text-emerald-300 border border-emerald-500/30',
                                            'warning' => 'bg-amber-950/60 text-amber-300 border border-amber-500/30',
                                            'danger' => 'bg-rose-950/60 text-rose-300 border border-rose-500/30',
                                            default => 'bg-[#081a22] text-slate-300 border border-[#1d4554]/40',
                                        };
                                    @endphp
                                    <span class="rounded-md px-2.5 py-0.5 text-[11px] font-semibold {{ $rowStatusClasses }}">
                                        {{ $row->coverageStatus->label() }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{-- 5. Evolução Histórica --}}
    @include('filament.resources.emissions.relation-managers.guarantees-history', ['history' => $history])
</div>

<div class="space-y-4">
    @php
        $analysis = $analysis ?? null;
        $readiness = $analysis['readiness'] ?? null;
        $contractCoverage = $analysis['contract_coverage'] ?? [];
        $unitCoverage = $analysis['unit_coverage'] ?? [];
        $legacy = $analysis['legacy'] ?? [];
        $reconciliation = $analysis['monthly_reconciliation'] ?? collect();
        $reconciliationSummary = $analysis['reconciliation_summary'] ?? null;
        $fullReconciliation = $analysis['monthly_reconciliation_full'] ?? $reconciliation;
        $isContracts = $analysis['is_contracts'] ?? false;
    @endphp

    @if (! $analysis)
        <p class="text-sm text-white/60">Diagnóstico indisponível para esta operação.</p>
    @else
        {{-- Status banner --}}
        <div class="rounded-lg border px-4 py-3 flex items-start gap-3
            @if(($readiness['status'] ?? '') === 'incompleto') bg-red-500/10 border-red-500/30
            @elseif(($readiness['status'] ?? '') === 'atencao') bg-amber-500/10 border-amber-500/30
            @else bg-emerald-500/10 border-emerald-500/30
            @endif
        ">
            <div class="mt-0.5">
                @if(($readiness['status'] ?? '') === 'incompleto')
                    <span class="inline-flex size-7 items-center justify-center rounded-full bg-red-500/20 text-red-400 text-sm">!</span>
                @elseif(($readiness['status'] ?? '') === 'atencao')
                    <span class="inline-flex size-7 items-center justify-center rounded-full bg-amber-500/20 text-amber-400 text-sm">⚠</span>
                @else
                    <span class="inline-flex size-7 items-center justify-center rounded-full bg-emerald-500/20 text-emerald-400 text-sm">✓</span>
                @endif
            </div>
            <div class="flex-1 min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-sm font-semibold
                        @if(($readiness['status'] ?? '') === 'incompleto') text-red-300
                        @elseif(($readiness['status'] ?? '') === 'atencao') text-amber-300
                        @else text-emerald-300 @endif
                    ">{{ $readiness['label'] ?? '—' }}</span>
                    <span class="text-xs px-2 py-0.5 rounded-full border
                        @if(($readiness['status'] ?? '') === 'incompleto') bg-red-500/10 border-red-500/20 text-red-300
                        @elseif(($readiness['status'] ?? '') === 'atencao') bg-amber-500/10 border-amber-500/20 text-amber-300
                        @else bg-emerald-500/10 border-emerald-500/20 text-emerald-300 @endif
                    ">
                        {{ $isContracts ? 'Fonte atual: Contratos (automático)' : 'Fonte atual: Manual (legado)' }}
                    </span>
                </div>
                <p class="text-xs leading-relaxed mt-1
                    @if(($readiness['status'] ?? '') === 'incompleto') text-red-200/80
                    @elseif(($readiness['status'] ?? '') === 'atencao') text-amber-200/80
                    @else text-emerald-200/80 @endif
                ">{{ $readiness['description'] ?? '' }}</p>
                @if(! empty($readiness['issues']))
                    <ul class="mt-2 space-y-1">
                        @foreach($readiness['issues'] as $issue)
                            <li class="text-xs text-red-200/90 flex gap-1.5"><span class="text-red-400">•</span><span>{{ $issue }}</span></li>
                        @endforeach
                    </ul>
                @endif
                @if(! empty($readiness['warnings']))
                    <ul class="mt-2 space-y-1">
                        @foreach($readiness['warnings'] as $warning)
                            <li class="text-xs text-amber-200/90 flex gap-1.5"><span class="text-amber-400">•</span><span>{{ $warning }}</span></li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>

        {{-- Contract coverage --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
            <div class="rounded-lg border border-white/10 bg-white/[0.02] p-3">
                <h4 class="text-xs font-semibold text-white/80 uppercase tracking-wide mb-2">Cobertura de contratos</h4>
                <dl class="space-y-1.5 text-xs">
                    <div class="flex justify-between"><dt class="text-white/60">Total de contratos</dt><dd class="font-medium text-white">{{ $contractCoverage['total'] ?? 0 }}</dd></div>
                    <div class="flex justify-between"><dt class="text-white/60">Com data de venda</dt><dd class="font-medium text-white">{{ $contractCoverage['with_sale_date'] ?? 0 }}</dd></div>
                    <div class="flex justify-between"><dt class="{{ ($contractCoverage['without_sale_date'] ?? 0) > 0 ? 'text-red-300' : 'text-white/60' }}">Sem data de venda</dt><dd class="{{ ($contractCoverage['without_sale_date'] ?? 0) > 0 ? 'font-semibold text-red-300' : 'font-medium text-white' }}">{{ $contractCoverage['without_sale_date'] ?? 0 }}</dd></div>
                    <div class="flex justify-between"><dt class="text-white/60">Com data de distrato</dt><dd class="font-medium text-white">{{ $contractCoverage['with_cancellation_date'] ?? 0 }}</dd></div>
                    <div class="flex justify-between"><dt class="{{ ($contractCoverage['cancelled_without_cancellation_date'] ?? 0) > 0 ? 'text-red-300' : 'text-white/60' }}">Distratados sem data de distrato</dt><dd class="{{ ($contractCoverage['cancelled_without_cancellation_date'] ?? 0) > 0 ? 'font-semibold text-red-300' : 'font-medium text-white' }}">{{ $contractCoverage['cancelled_without_cancellation_date'] ?? 0 }}</dd></div>
                    <div class="flex justify-between"><dt class="{{ ($contractCoverage['without_unit_id'] ?? 0) > 0 ? 'text-red-300' : 'text-white/60' }}">Sem unidade vinculada</dt><dd class="{{ ($contractCoverage['without_unit_id'] ?? 0) > 0 ? 'font-semibold text-red-300' : 'font-medium text-white' }}">{{ $contractCoverage['without_unit_id'] ?? 0 }}</dd></div>
                    <div class="flex justify-between"><dt class="{{ ($contractCoverage['unresolved_unit'] ?? 0) > 0 ? 'text-red-300' : 'text-white/60' }}">Unidade não encontrada</dt><dd class="{{ ($contractCoverage['unresolved_unit'] ?? 0) > 0 ? 'font-semibold text-red-300' : 'font-medium text-white' }}">{{ $contractCoverage['unresolved_unit'] ?? 0 }}</dd></div>
                    <div class="flex justify-between"><dt class="{{ ($contractCoverage['inconsistent'] ?? 0) > 0 ? 'text-red-300' : 'text-white/60' }}">Vínculo inconsistente</dt><dd class="{{ ($contractCoverage['inconsistent'] ?? 0) > 0 ? 'font-semibold text-red-300' : 'font-medium text-white' }}">{{ $contractCoverage['inconsistent'] ?? 0 }}</dd></div>
                </dl>
            </div>
            <div class="rounded-lg border border-white/10 bg-white/[0.02] p-3">
                <h4 class="text-xs font-semibold text-white/80 uppercase tracking-wide mb-2">Unidades / empreendimentos</h4>
                <dl class="space-y-1.5 text-xs">
                    <div class="flex justify-between"><dt class="text-white/60">Empreendimentos vinculados</dt><dd class="font-medium text-white">{{ $unitCoverage['constructions_count'] ?? 0 }}</dd></div>
                    <div class="flex justify-between"><dt class="text-white/60">Unidades cadastradas</dt><dd class="font-medium text-white">{{ $unitCoverage['units_count'] ?? 0 }}</dd></div>
                    <div class="flex justify-between"><dt class="text-white/60">Unidades com contrato</dt><dd class="font-medium text-white">{{ $unitCoverage['units_with_contracts'] ?? 0 }}</dd></div>
                    <div class="flex justify-between"><dt class="text-white/60">Unidades sem contrato</dt><dd class="font-medium text-white">{{ $unitCoverage['units_without_contracts'] ?? 0 }}</dd></div>
                </dl>
                <p class="text-[11px] leading-relaxed text-white/45 mt-3 border-t border-white/10 pt-2">
                    Unidades sem contrato não são falhas: estoque, permuta e unidades não comercializadas são estados legítimos. A contagem é informativa.
                </p>
                <div class="rounded-lg border border-white/10 bg-white/[0.02] p-3 mt-3">
                    <h5 class="text-xs font-semibold text-white/80 uppercase tracking-wide mb-2">Histórico legado (Negociações manuais)</h5>
                    <dl class="space-y-1.5 text-xs">
                        <div class="flex justify-between"><dt class="text-white/60">Competências lançadas</dt><dd class="font-medium text-white">{{ $legacy['rows'] ?? 0 }}</dd></div>
                        <div class="flex justify-between"><dt class="text-white/60">Primeira competência</dt><dd class="font-medium text-white">{{ $legacy['earliest_label'] ?? '—' }}</dd></div>
                        <div class="flex justify-between"><dt class="text-white/60">Última competência</dt><dd class="font-medium text-white">{{ $legacy['latest_label'] ?? '—' }}</dd></div>
                        <div class="flex justify-between"><dt class="text-white/60">Total Vendas (legado)</dt><dd class="font-medium text-white">{{ $legacy['total_sales'] ?? 0 }}</dd></div>
                        <div class="flex justify-between"><dt class="text-white/60">Total Distratos (legado)</dt><dd class="font-medium text-white">{{ $legacy['total_cancellations'] ?? 0 }}</dd></div>
                    </dl>
                </div>
            </div>
        </div>

        {{-- Monthly reconciliation summary (full historical period) --}}
        @if($reconciliationSummary)
            <div class="rounded-lg border border-white/10 bg-white/[0.02] px-3 py-2.5 grid grid-cols-2 lg:grid-cols-4 gap-3">
                <div>
                    <div class="text-[11px] font-medium text-white/50 uppercase tracking-wide">Período analisado</div>
                    <div class="text-xs font-semibold text-white mt-0.5">{{ $reconciliationSummary['period_label'] ?? '—' }}</div>
                </div>
                <div>
                    <div class="text-[11px] font-medium text-white/50 uppercase tracking-wide">Competências comparadas</div>
                    <div class="text-xs font-semibold text-white mt-0.5">{{ $reconciliationSummary['compared_months'] ?? 0 }}</div>
                </div>
                <div>
                    <div class="text-[11px] font-medium text-white/50 uppercase tracking-wide">Competências compatíveis</div>
                    <div class="text-xs font-semibold text-emerald-300 mt-0.5">{{ $reconciliationSummary['compatible_months'] ?? 0 }}</div>
                </div>
                <div>
                    <div class="text-[11px] font-medium text-white/50 uppercase tracking-wide">Competências divergentes</div>
                    <div class="text-xs font-semibold {{ ($reconciliationSummary['divergent_months'] ?? 0) > 0 ? 'text-amber-300' : 'text-white' }} mt-0.5">{{ $reconciliationSummary['divergent_months'] ?? 0 }}</div>
                </div>
                @if(($reconciliationSummary['total_months'] ?? 0) > 12)
                    <div class="col-span-2 lg:col-span-4 text-[11px] leading-relaxed text-white/45 border-t border-white/10 pt-2 mt-1">
                        Exibindo as 12 competências mais recentes de {{ $reconciliationSummary['total_months'] }} no total. Divergências fora da janela permanecem refletidas no status acima.
                    </div>
                @endif
            </div>
        @endif

        {{-- Monthly reconciliation --}}
        <div class="rounded-lg border border-white/10 overflow-hidden">
            <div class="px-3 py-2 border-b border-white/10 bg-white/[0.02] flex items-center justify-between">
                <h4 class="text-xs font-semibold text-white/80 uppercase tracking-wide">Conciliação mensal — Legado vs Contratos</h4>
                <span class="text-[11px] text-white/50">Diagnóstico apenas — não mescla valores</span>
            </div>
            @if($reconciliation->isEmpty())
                <p class="text-xs text-white/60 px-3 py-4">Nenhum dado histórico encontrado em nenhuma das fontes para conciliação.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead>
                            <tr class="bg-white/[0.03] text-white/60">
                                <th class="text-left font-medium px-3 py-2 whitespace-nowrap">Competência</th>
                                <th class="text-right font-medium px-3 py-2 whitespace-nowrap">Legado Vendas</th>
                                <th class="text-right font-medium px-3 py-2 whitespace-nowrap">Contratos Vendas</th>
                                <th class="text-right font-medium px-3 py-2 whitespace-nowrap">Legado Distratos</th>
                                <th class="text-right font-medium px-3 py-2 whitespace-nowrap">Contratos Distratos</th>
                                <th class="text-left font-medium px-3 py-2 whitespace-nowrap">Situação</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            @foreach($reconciliation as $row)
                                <tr class="@if($row['is_divergent']) bg-amber-500/5 @endif">
                                    <td class="px-3 py-2 font-medium text-white whitespace-nowrap">{{ $row['competencia'] }}</td>
                                    <td class="px-3 py-2 text-right text-white/90">{{ $row['legacy_sales'] }}</td>
                                    <td class="px-3 py-2 text-right {{ $row['legacy_sales'] !== $row['contract_sales'] && $row['has_legacy'] ? 'text-amber-300 font-semibold' : 'text-white/90' }}">{{ $row['contract_sales'] }}</td>
                                    <td class="px-3 py-2 text-right text-white/90">{{ $row['legacy_cancellations'] }}</td>
                                    <td class="px-3 py-2 text-right {{ $row['legacy_cancellations'] !== $row['contract_cancellations'] && $row['has_legacy'] ? 'text-amber-300 font-semibold' : 'text-white/90' }}">{{ $row['contract_cancellations'] }}</td>
                                    <td class="px-3 py-2 whitespace-nowrap">
                                        @if($row['is_divergent'])
                                            <span class="inline-flex items-center rounded-full bg-amber-500/15 border border-amber-500/25 px-2 py-0.5 text-[11px] font-medium text-amber-300">Divergência</span>
                                        @elseif($row['has_legacy'])
                                            <span class="inline-flex items-center rounded-full bg-emerald-500/15 border border-emerald-500/25 px-2 py-0.5 text-[11px] font-medium text-emerald-300">Compatível</span>
                                        @else
                                            <span class="inline-flex items-center rounded-full bg-white/10 border border-white/10 px-2 py-0.5 text-[11px] font-medium text-white/60">Sem histórico legado</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="text-[11px] leading-relaxed text-white/45 px-3 py-2 border-t border-white/10">
                    Compatível indica que, para a competência onde existe histórico legado, os totais de vendas e distratos derivados de <code class="text-white/70">sale_date</code> e <code class="text-white/70">cancellation_date</code> coincidem. Divergência não altera dados: investigue antes de migrar.
                </p>
            @endif
        </div>

        @if($isContracts)
            <div class="rounded-lg border border-sky-500/20 bg-sky-500/10 px-3 py-2">
                <p class="text-xs leading-relaxed text-sky-200/90">
                    Esta emissão já utiliza <strong>Contratos (automático)</strong> como fonte das Negociações. O painel acima segue útil como diagnóstico de qualidade dos contratos.
                </p>
            </div>
        @endif
    @endif
</div>

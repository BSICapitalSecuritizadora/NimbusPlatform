@php
    /** @var array{previous: \App\Models\LegalInstrumentField|null, proposed: \App\Models\LegalInstrumentField, changed: bool} $change */
    $previous = $change['previous'];
    $proposed = $change['proposed'];
    $isSameValue = $previous !== null && $proposed->hasSameValueAs($previous);
@endphp

<div class="space-y-5 text-sm text-[#e6e4e4]">
    {{-- Alerta de Conflito Documental --}}
    @if ($proposed->has_conflict)
        <div class="rounded-xl border border-rose-500/35 bg-rose-950/50 p-4 shadow-sm">
            <div class="flex items-center gap-2 font-semibold text-rose-300">
                <x-heroicon-m-exclamation-triangle class="h-5 w-5 shrink-0 text-rose-400" />
                <span>Conflito documental — revisão necessária</span>
            </div>
            <p class="mt-1.5 text-xs leading-relaxed text-rose-200/90">{{ $proposed->conflict_reason }}</p>
        </div>
    @endif

    {{-- Diagnóstico Contextual para Valores Iguais ou Constituição --}}
    @if ($isSameValue)
        <div class="rounded-xl border border-amber-500/30 bg-[#0d2632] p-3.5 flex items-start gap-2.5 shadow-sm">
            <x-heroicon-m-information-circle class="h-5 w-5 shrink-0 text-amber-400 mt-0.5" />
            <div class="text-xs leading-relaxed text-slate-300">
                <strong class="text-amber-300 font-medium">Valor inalterado:</strong> O valor extraído (<code class="text-[#e6e4e4] bg-[#091b23] border border-[#1d4554]/50 px-1 py-0.5 rounded">{{ $proposed->formatted_value }}</code>) coincide com a posição vigente. Esta alteração foi registrada para atualização de <strong class="text-white">vigência documental</strong> ou <strong class="text-white">ratificação contratual</strong>.
            </div>
        </div>
    @elseif ($previous === null)
        <div class="rounded-xl border border-cyan-500/30 bg-[#0d2632] p-3.5 flex items-start gap-2.5 shadow-sm">
            <x-heroicon-m-sparkles class="h-5 w-5 shrink-0 text-cyan-400 mt-0.5" />
            <div class="text-xs leading-relaxed text-slate-300">
                <strong class="text-cyan-300 font-medium">Constituição inicial:</strong> Não havia valor anterior registrado para este campo. Esta entrada define a primeira posição vigente.
            </div>
        </div>
    @endif

    {{-- Bloco Principal: Comparação Anterior → Novo --}}
    <section>
        <h4 class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-400 mb-2 flex items-center gap-1.5">
            <x-heroicon-m-arrows-right-left class="h-3.5 w-3.5 text-slate-400" />
            Comparação Documental
        </h4>

        <div class="grid gap-3 sm:grid-cols-2 items-stretch">
            {{-- Card Anterior --}}
            <div class="flex flex-col justify-between rounded-xl border border-[#1d4554]/60 bg-[#0c232e] p-4 transition-all">
                <div>
                    <div class="flex items-center justify-between">
                        <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Posição Anterior</span>
                        @if ($previous)
                            <span class="text-[10px] text-slate-400 font-mono">ID #{{ $previous->id }}</span>
                        @endif
                    </div>
                    <div class="mt-2 text-base font-medium text-slate-200 break-words">
                        {{ $previous?->formatted_value ?? 'Sem valor anterior' }}
                    </div>
                </div>

                @if ($previous?->source_label || $previous?->document_label)
                    <div class="mt-3 pt-2.5 border-t border-[#1d4554]/40 text-[11px] text-slate-400 leading-snug">
                        {{ $previous->document_label }}
                        @if ($previous->source_label)
                            · {{ $previous->source_label }}
                        @endif
                    </div>
                @endif
            </div>

            {{-- Card Novo --}}
            <div class="flex flex-col justify-between rounded-xl border border-emerald-500/40 bg-gradient-to-b from-[#0e2f3d] to-[#0c2733] p-4 shadow-sm relative overflow-hidden">
                <div class="absolute -right-6 -bottom-6 w-24 h-24 bg-emerald-500/10 rounded-full pointer-events-none blur-xl"></div>
                <div>
                    <div class="flex items-center justify-between">
                        <span class="text-[10px] font-bold uppercase tracking-wider text-emerald-400 flex items-center gap-1">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                            Nova Proposta
                        </span>
                        @if ($proposed->effective_date)
                            <span class="text-[11px] font-medium text-emerald-300 bg-emerald-950/60 px-2 py-0.5 rounded-md border border-emerald-500/30">
                                Vigente a partir de {{ $proposed->effective_date->format('d/m/Y') }}
                            </span>
                        @endif
                    </div>
                    <div class="mt-2 text-base font-bold text-white break-words">
                        {{ $proposed->formatted_value }}
                    </div>
                </div>

                <div class="mt-3 pt-2.5 border-t border-emerald-500/20 text-[11px] text-emerald-200/90 leading-snug">
                    {{ $proposed->instrumentDocument?->role_label ?? $proposed->document_label }}
                    @if ($proposed->source_label)
                        · {{ $proposed->source_label }}
                    @endif
                </div>
            </div>
        </div>
    </section>

    {{-- Bloco: Fonte Documental & Trecho --}}
    <section class="rounded-xl border border-[#1d4554]/60 bg-[#0c232e] p-4 space-y-4 shadow-sm">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-[#1d4554]/40 pb-3">
            <div>
                <h4 class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-400">Origem da Informação</h4>
                <div class="mt-1 text-xs text-slate-300">
                    <span class="font-semibold text-white">{{ $proposed->instrument?->display_name ?? 'Instrumento' }}</span>
                    @if ($proposed->instrumentDocument?->role_label)
                        · <span class="text-slate-400">{{ $proposed->instrumentDocument->role_label }}</span>
                    @endif
                </div>
            </div>

            @if ($proposed->source_url)
                <a
                    href="{{ $proposed->source_url }}"
                    target="_blank"
                    rel="noopener"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-[#e5c76b] hover:text-white bg-[#a06e28]/20 hover:bg-[#a06e28]/35 border border-[#a06e28]/50 rounded-lg transition-all shadow-sm shrink-0"
                >
                    <x-heroicon-m-document-magnifying-glass class="w-4 h-4 text-[#e5c76b]" />
                    <span>Ver no documento</span>
                    @if ($proposed->page)
                        <span class="text-[11px] opacity-80">(pág. {{ $proposed->page }})</span>
                    @endif
                    <x-heroicon-m-arrow-top-right-on-square class="w-3.5 h-3.5 opacity-70 ml-0.5" />
                </a>
            @endif
        </div>

        <dl class="grid gap-3 grid-cols-2 sm:grid-cols-3 text-xs">
            <div class="rounded-lg bg-[#081a22] p-2.5 border border-[#1d4554]/45">
                <dt class="text-[10px] uppercase tracking-wider text-slate-400 font-semibold">Documento</dt>
                <dd class="mt-1 font-medium text-slate-200 truncate" title="{{ $proposed->instrumentDocument?->role_label ?? $proposed->document_label }}">
                    {{ $proposed->instrumentDocument?->role_label ?? $proposed->document_label }}
                </dd>
            </div>
            <div class="rounded-lg bg-[#081a22] p-2.5 border border-[#1d4554]/45">
                <dt class="text-[10px] uppercase tracking-wider text-slate-400 font-semibold">Cláusula</dt>
                <dd class="mt-1 font-medium text-slate-200">
                    {{ $proposed->clause ?? 'Não informada' }}
                </dd>
            </div>
            <div class="rounded-lg bg-[#081a22] p-2.5 border border-[#1d4554]/45 col-span-2 sm:col-span-1">
                <dt class="text-[10px] uppercase tracking-wider text-slate-400 font-semibold">Página</dt>
                <dd class="mt-1 font-medium text-slate-200">
                    {{ $proposed->page ? 'Página '.$proposed->page : 'Não informada' }}
                </dd>
            </div>
        </dl>

        {{-- Trecho Transcrito da Evidência --}}
        @if (filled($proposed->excerpt))
            <div class="space-y-1.5">
                <div class="text-[10px] uppercase tracking-wider text-slate-400 font-semibold flex items-center gap-1.5">
                    <x-heroicon-m-chat-bubble-bottom-center-text class="w-3.5 h-3.5 text-[#a06e28]" />
                    Trecho Identificado no Documento
                </div>
                <blockquote class="relative rounded-r-lg border-l-2 border-[#a06e28] border-y border-r border-[#1d4554]/40 bg-[#081a22] p-3.5 text-xs italic leading-relaxed text-slate-200 shadow-inner">
                    “{{ $proposed->excerpt }}”
                </blockquote>
            </div>
        @endif
    </section>

    {{-- Bloco: Avaliação da Detecção --}}
    <section class="flex flex-wrap items-center gap-2.5 pt-1 text-xs">
        <span class="inline-flex items-center gap-1.5 rounded-lg bg-cyan-950/50 px-3 py-1 text-xs font-medium text-cyan-300 border border-cyan-500/30">
            <x-heroicon-m-finger-print class="h-3.5 w-3.5" />
            Evidência: {{ $proposed->evidence_level?->label() ?? 'Explícita' }}
        </span>

        @if ($proposed->confidence_score !== null)
            <span class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-950/50 px-3 py-1 text-xs font-medium text-emerald-300 border border-emerald-500/30">
                <x-heroicon-m-check-badge class="h-3.5 w-3.5" />
                Confiança: {{ round($proposed->confidence_score * 100) }}%
            </span>
        @endif

        @if ($proposed->field_key?->isMaterial())
            <span class="inline-flex items-center gap-1.5 rounded-lg bg-amber-950/50 px-3 py-1 text-xs font-semibold text-amber-300 border border-amber-500/35">
                <x-heroicon-m-shield-exclamation class="h-3.5 w-3.5 text-amber-400" />
                Alteração Material
            </span>
        @endif
    </section>
</div>

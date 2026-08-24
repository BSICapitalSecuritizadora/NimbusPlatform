@php
    use App\Enums\GuaranteeEvidenceLevel;
    use App\Enums\GuaranteeReconciliationOutcome;

    /** @var \App\Models\ExtractedGuarantee $candidate */
    /** @var \App\DTOs\Guarantees\GuaranteeConsolidationPlan|null $plan */

    $plan ??= null;
    $outcome = $plan?->outcome ?? $candidate->outcome();

    $money = static fn (mixed $value): string => blank($value)
        ? '—'
        : 'R$ ' . \App\Concerns\MoneyFormatter::formatCurrencyForDisplay($value);

    $identificationLabels = $candidate->type?->category()->identificationFields() ?? [];

    $evidenceBadge = static function (GuaranteeEvidenceLevel $level): string {
        return match ($level) {
            GuaranteeEvidenceLevel::Explicit => 'bg-emerald-950/60 text-emerald-300 border border-emerald-500/30',
            GuaranteeEvidenceLevel::Inferred => 'bg-amber-950/60 text-amber-300 border border-amber-500/30',
            GuaranteeEvidenceLevel::Conflicting => 'bg-rose-950/60 text-rose-300 border border-rose-500/30',
            GuaranteeEvidenceLevel::NotFound => 'bg-[#081a22] text-slate-400 border border-[#1d4554]/40',
        };
    };

    $banner = match ($outcome) {
        GuaranteeReconciliationOutcome::Conflict => ['border-rose-500/40 bg-rose-950/50', 'text-rose-300', 'text-rose-200/90'],
        GuaranteeReconciliationOutcome::Change => ['border-amber-500/40 bg-amber-950/50', 'text-amber-300', 'text-amber-200/90'],
        GuaranteeReconciliationOutcome::Complement => ['border-emerald-500/40 bg-emerald-950/50', 'text-emerald-300', 'text-emerald-200/90'],
        GuaranteeReconciliationOutcome::Confirmation => ['border-[#1d4554]/60 bg-[#0c232e]', 'text-slate-200', 'text-slate-400'],
        GuaranteeReconciliationOutcome::NewGuarantee => ['border-sky-500/40 bg-sky-950/50', 'text-sky-300', 'text-sky-200/90'],
    };
@endphp

<div class="space-y-5 text-xs text-slate-300">
    {{-- 1. Faixa do Veredito da Reconciliação --}}
    <div class="rounded-xl border p-4 shadow-sm {{ $banner[0] }}">
        <div class="font-semibold text-sm {{ $banner[1] }} flex items-center gap-2">
            <x-heroicon-m-document-magnifying-glass class="h-4 w-4" />
            {{ $outcome->label() }}
        </div>
        <p class="mt-1 leading-relaxed {{ $banner[2] }}">
            {{ $candidate->conflict_reason ?? $outcome->description() }}
        </p>
    </div>

    {{-- 2. Possível Correspondência Cadastrada --}}
    @if ($plan?->hasGuarantee())
        <section class="rounded-xl border border-[#1d4554]/60 bg-[#081a22] p-4 shadow-sm">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <div>
                    <div class="text-[10px] font-bold uppercase tracking-[0.18em] text-slate-400">Possível correspondência cadastrada</div>
                    <div class="mt-1 font-bold text-sm text-white">{{ $plan->guarantee->display_name }}</div>
                </div>
                @if ($plan->match)
                    <div class="text-xs text-slate-400 flex items-center gap-1.5">
                        <span>Correspondência:</span>
                        <span class="font-semibold text-emerald-300 bg-emerald-950/60 px-2 py-0.5 rounded border border-emerald-500/30">
                            {{ $plan->match->level->label() }}
                            @if ($candidate->matchPercent())
                                ({{ $candidate->matchPercent() }})
                            @endif
                        </span>
                    </div>
                @endif
            </div>

            @if ($plan->match?->evidence)
                <ul class="mt-3 space-y-1 text-slate-300 border-t border-[#1d4554]/30 pt-2.5">
                    @foreach ($plan->match->evidence as $evidence)
                        <li class="flex items-start gap-2">
                            <span class="text-emerald-400 mt-0.5">•</span>
                            <span>{{ $evidence }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($plan->match?->contradictions)
                <ul class="mt-2 space-y-1 text-amber-300/90 border-t border-amber-500/20 pt-2">
                    @foreach ($plan->match->contradictions as $contradiction)
                        <li class="flex items-start gap-2">
                            <span class="text-amber-400">⚠</span>
                            <span>{{ $contradiction }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif

            <p class="mt-3 text-[11px] text-slate-400 border-t border-[#1d4554]/20 pt-2">
                @if ($candidate->related_guarantee_id === null)
                    Esta correspondência foi identificada agora. Complementar aplica as informações à garantia acima mantendo a rastreabilidade.
                @else
                    Complementar aplica estas informações à garantia acima e preserva a posição anterior no histórico.
                @endif
            </p>
        </section>
    @endif

    {{-- 3. O que este documento acrescenta / altera --}}
    @if ($plan?->changesAnyValue() || $plan?->confirmations)
        <section class="rounded-xl border border-[#1d4554]/60 bg-[#0c232e] p-4 shadow-sm">
            <h4 class="text-[10px] font-bold uppercase tracking-[0.18em] text-slate-400">
                O que este documento acrescenta ou altera
            </h4>

            <div class="mt-3 overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="border-b border-[#1d4554]/40 text-[10px] font-bold uppercase tracking-wider text-slate-400">
                            <th class="py-2 pr-3">Campo</th>
                            <th class="py-2 pr-3">Cadastrado</th>
                            <th class="py-2 pr-3">Documento identificou</th>
                            <th class="py-2">Situação</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#1d4554]/30">
                        @foreach ($plan->complements as $delta)
                            <tr>
                                <td class="py-2.5 pr-3 text-slate-300 font-medium">{{ $delta->label }}</td>
                                <td class="py-2.5 pr-3 text-slate-500 font-mono">{{ $delta->currentDisplay }}</td>
                                <td class="py-2.5 pr-3 font-semibold text-emerald-300 font-mono">{{ $delta->newDisplay }}</td>
                                <td class="py-2.5 text-emerald-400 font-medium">Complementa</td>
                            </tr>
                        @endforeach
                        @foreach ($plan->divergences as $delta)
                            <tr>
                                <td class="py-2.5 pr-3 text-slate-300 font-medium">{{ $delta->label }}</td>
                                <td class="py-2.5 pr-3 font-medium text-slate-200 font-mono">{{ $delta->currentDisplay }}</td>
                                <td class="py-2.5 pr-3 font-semibold text-amber-300 font-mono">{{ $delta->newDisplay }}</td>
                                <td class="py-2.5 text-amber-400 font-medium">Diverge — exige decisão</td>
                            </tr>
                        @endforeach
                        @foreach ($plan->confirmations as $delta)
                            <tr>
                                <td class="py-2.5 pr-3 text-slate-300 font-medium">{{ $delta->label }}</td>
                                <td class="py-2.5 pr-3 text-slate-400 font-mono">{{ $delta->currentDisplay }}</td>
                                <td class="py-2.5 pr-3 text-slate-400 font-mono">{{ $delta->newDisplay }}</td>
                                <td class="py-2.5 text-slate-500">Confirma — nova fonte</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($plan->hasDivergences())
                <p class="mt-3 rounded-lg border border-amber-500/30 bg-amber-950/40 p-2.5 text-[11px] text-amber-200">
                    Nenhum valor divergente é sobrescrito automaticamente. Ao complementar, escolha campo a campo entre manter o cadastrado e adotar o do documento.
                </p>
            @endif
        </section>
    @endif

    {{-- 4. O que foi Identificado no Documento --}}
    <section class="rounded-xl border border-[#1d4554]/60 bg-[#081a22] p-4 shadow-sm">
        <h4 class="text-[10px] font-bold uppercase tracking-[0.18em] text-slate-400">Classificação e Valores Extraídos</h4>
        <dl class="mt-3 grid gap-3 sm:grid-cols-2">
            <div class="rounded-lg bg-[#0c232e] p-2.5 border border-[#1d4554]/40">
                <dt class="text-[10px] uppercase font-bold text-slate-400">Tipo de Garantia</dt>
                <dd class="mt-1 font-semibold text-white">{{ \App\Enums\GuaranteeType::labelFor($candidate->type) }}</dd>
            </div>
            <div class="rounded-lg bg-[#0c232e] p-2.5 border border-[#1d4554]/40">
                <dt class="text-[10px] uppercase font-bold text-slate-400">Evento</dt>
                <dd class="mt-1 font-semibold text-white">{{ $candidate->event_type?->label() ?? '—' }}</dd>
            </div>
            <div class="rounded-lg bg-[#0c232e] p-2.5 border border-[#1d4554]/40">
                <dt class="text-[10px] uppercase font-bold text-slate-400">Valor Identificado</dt>
                <dd class="mt-1 font-bold text-white font-mono">{{ $money($candidate->contracted_value) }}</dd>
            </div>
            <div class="rounded-lg bg-[#0c232e] p-2.5 border border-[#1d4554]/40">
                <dt class="text-[10px] uppercase font-bold text-slate-400">Cobertura Mínima Prevista</dt>
                <dd class="mt-1 font-bold text-white font-mono">
                    @if ($candidate->requirement_percentage !== null)
                        {{ number_format((float) $candidate->requirement_percentage * 100, 2, ',', '.') }}%
                        @if ($candidate->requirement_base)
                            <span class="text-xs font-normal text-slate-300">do {{ mb_strtolower($candidate->requirement_base->label()) }}</span>
                        @endif
                    @elseif ($candidate->requirement_value !== null)
                        {{ $money($candidate->requirement_value) }}
                    @else
                        —
                    @endif
                </dd>
            </div>
            @foreach (($candidate->identification ?? []) as $key => $value)
                @continue(blank($value) || ! is_scalar($value))
                <div class="rounded-lg bg-[#0c232e] p-2.5 border border-[#1d4554]/40">
                    <dt class="text-[10px] uppercase font-bold text-slate-400">{{ $identificationLabels[$key] ?? \Illuminate\Support\Str::of($key)->replace('_', ' ')->title() }}</dt>
                    <dd class="mt-1 font-medium text-slate-200">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>
    </section>

    {{-- 5. Origem no Documento & Trecho Identificado --}}
    <section class="rounded-xl border border-[#1d4554]/60 bg-[#081a22] p-4 shadow-sm">
        <h4 class="text-[10px] font-bold uppercase tracking-[0.18em] text-slate-400">Origem Documental & Evidência</h4>
        <dl class="mt-3 grid gap-2 sm:grid-cols-3">
            <div class="rounded-lg bg-[#0c232e] p-2.5 border border-[#1d4554]/40">
                <dt class="text-[10px] uppercase font-bold text-slate-400">Documento</dt>
                <dd class="mt-0.5 font-medium text-white truncate">{{ $candidate->document?->title ?? $candidate->document_type?->label() ?? '—' }}</dd>
            </div>
            <div class="rounded-lg bg-[#0c232e] p-2.5 border border-[#1d4554]/40">
                <dt class="text-[10px] uppercase font-bold text-slate-400">Cláusula</dt>
                <dd class="mt-0.5 font-semibold text-white">{{ $candidate->source_clause ?? '—' }}</dd>
            </div>
            <div class="rounded-lg bg-[#0c232e] p-2.5 border border-[#1d4554]/40">
                <dt class="text-[10px] uppercase font-bold text-slate-400">Página</dt>
                <dd class="mt-0.5 font-semibold text-white">{{ $candidate->source_page ?? '—' }}</dd>
            </div>
        </dl>

        @if (filled($candidate->source_excerpt))
            <blockquote class="mt-3 rounded-r-lg border-l-2 border-[#a06e28] border-y border-r border-[#1d4554]/40 bg-[#0c232e] p-3.5 italic leading-relaxed text-slate-200 shadow-inner">
                “{{ $candidate->source_excerpt }}”
            </blockquote>
        @endif
    </section>

    {{-- 6. Confiança da Extração --}}
    <section class="rounded-xl border border-[#1d4554]/60 bg-[#081a22] p-4 shadow-sm">
        <div class="flex items-center justify-between">
            <h4 class="text-[10px] font-bold uppercase tracking-[0.18em] text-slate-400">Nível de Confiança da Extração</h4>
            <span class="font-bold text-xs text-white">
                {{ $candidate->confidenceLevel()?->label() ?? '—' }}
                @if ($candidate->confidencePercent())
                    <span class="text-slate-400 font-mono font-normal">({{ $candidate->confidencePercent() }})</span>
                @endif
            </span>
        </div>

        @if (filled($candidate->field_evidence))
            <ul class="mt-3 flex flex-wrap gap-1.5">
                @foreach ($candidate->field_evidence as $field => $level)
                    @php $evidenceLevel = GuaranteeEvidenceLevel::tryFrom($level) ?? GuaranteeEvidenceLevel::NotFound; @endphp
                    <li class="rounded-md px-2 py-0.5 text-[11px] {{ $evidenceBadge($evidenceLevel) }}">
                        {{ \Illuminate\Support\Str::of($field)->replace('_', ' ')->title() }}: {{ $evidenceLevel->label() }}
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    @if (filled($candidate->review_notes))
        <section class="rounded-xl border border-[#1d4554]/60 bg-[#081a22] p-3.5 shadow-sm">
            <h4 class="text-[10px] font-bold uppercase tracking-[0.18em] text-slate-400">Observações da Revisão</h4>
            <p class="mt-1 text-slate-300 leading-relaxed">{{ $candidate->review_notes }}</p>
        </section>
    @endif
</div>

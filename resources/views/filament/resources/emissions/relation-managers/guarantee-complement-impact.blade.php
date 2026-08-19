@php
    /** @var \App\DTOs\Guarantees\GuaranteeConsolidationPlan $plan */
    /** @var \App\Models\ExtractedGuarantee $candidate */

    $sourceParts = array_filter([
        $candidate->document?->title ?? $candidate->document_type?->label(),
        filled($candidate->source_clause) ? "Cláusula {$candidate->source_clause}" : null,
        $candidate->source_page !== null ? "Página {$candidate->source_page}" : null,
    ]);
@endphp

<div class="space-y-4 text-sm">
    @if ($plan->summaryLines() === [] && ! $plan->hasDivergences())
        <div class="rounded-xl border border-white/10 bg-white/[0.03] p-4 text-xs text-gray-300">
            O documento não traz informação nova. A garantia ganha apenas mais uma fonte documental,
            sem alteração de valores — o que fortalece a rastreabilidade sem poluir o histórico.
        </div>
    @else
        <div>
            <h4 class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-400">Alterações que serão realizadas</h4>
            <ul class="mt-2 space-y-1 font-mono text-xs">
                @foreach ($plan->complements as $delta)
                    <li class="text-emerald-200">+ {{ $delta->label }}: {{ $delta->newDisplay }}</li>
                @endforeach
                @foreach ($plan->divergences as $delta)
                    <li class="text-amber-200">? {{ $delta->label }}: {{ $delta->currentDisplay }} → {{ $delta->newDisplay }} (decida abaixo)</li>
                @endforeach
                @if ($sourceParts !== [])
                    <li class="text-emerald-200">+ Referência documental: {{ implode(' — ', $sourceParts) }}</li>
                @endif
            </ul>
        </div>
    @endif

    @if ($plan->confirmations !== [])
        <div>
            <h4 class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-400">Confirmado por este documento</h4>
            <p class="mt-2 text-xs text-gray-400">
                {{ collect($plan->confirmations)->map(fn ($delta) => $delta->label)->join(', ', ' e ') }}
                — sem alteração de valor, apenas nova evidência.
            </p>
        </div>
    @endif

    @if ($plan->providesFirstDocumentarySource)
        <div class="rounded-lg bg-sky-500/10 p-3 text-xs text-sky-100">
            Esta garantia ainda não tinha fonte documental. Com a confirmação, ela passa de cadastro manual
            a garantia comprovada documentalmente.
        </div>
    @endif

    @if ($plan->linkedFund)
        <div class="rounded-lg bg-sky-500/10 p-3 text-xs text-sky-100">
            A conta identificada já existe como fundo cadastrado. Banco, agência e conta ficam registrados
            no fundo — a garantia passa a apontar para ele, sem guardar uma segunda cópia.
            A origem documental desses valores é preservada na vigência por campo.
        </div>
    @endif
</div>

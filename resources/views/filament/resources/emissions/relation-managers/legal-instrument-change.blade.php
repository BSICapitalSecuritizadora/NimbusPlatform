@php
    /** @var array{previous: \App\Models\LegalInstrumentField|null, proposed: \App\Models\LegalInstrumentField, changed: bool} $change */
    $previous = $change['previous'];
    $proposed = $change['proposed'];
@endphp

<div class="space-y-5 text-sm">
    @if ($proposed->has_conflict)
        <div class="rounded-xl border border-rose-400/20 bg-rose-500/10 p-4">
            <div class="font-semibold text-rose-200">Conflito documental — revisão necessária</div>
            <p class="mt-1 text-xs text-rose-100/80">{{ $proposed->conflict_reason }}</p>
        </div>
    @endif

    <section class="grid gap-3 sm:grid-cols-2">
        <div class="rounded-xl border border-white/10 bg-white/[0.02] p-3">
            <div class="text-xs uppercase tracking-wide text-gray-400">Anterior</div>
            <div class="mt-1 font-medium text-gray-300">
                {{ $previous?->formatted_value ?? 'Sem valor anterior' }}
            </div>
            @if ($previous?->source_label)
                <div class="mt-1 text-xs text-gray-500">
                    {{ $previous->document_label }} · {{ $previous->source_label }}
                </div>
            @endif
        </div>

        <div class="rounded-xl border border-emerald-400/20 bg-emerald-500/10 p-3">
            <div class="text-xs uppercase tracking-wide text-emerald-200">Novo</div>
            <div class="mt-1 font-semibold">{{ $proposed->formatted_value }}</div>
            @if ($proposed->effective_date)
                <div class="mt-1 text-xs text-emerald-100/80">
                    Vigente a partir de {{ $proposed->effective_date->format('d/m/Y') }}
                </div>
            @endif
        </div>
    </section>

    <section>
        <h4 class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-400">Fonte</h4>
        <dl class="mt-2 grid gap-2 sm:grid-cols-3">
            <div>
                <dt class="text-xs text-gray-400">Documento</dt>
                <dd class="font-medium">{{ $proposed->instrumentDocument?->role_label ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs text-gray-400">Cláusula</dt>
                <dd class="font-medium">{{ $proposed->clause ?? 'Não informada' }}</dd>
            </div>
            <div>
                <dt class="text-xs text-gray-400">Página</dt>
                <dd class="font-medium">{{ $proposed->page ?? 'Não informada' }}</dd>
            </div>
        </dl>

        @if ($proposed->source_url)
            <a
                href="{{ $proposed->source_url }}"
                target="_blank"
                rel="noopener"
                class="mt-3 inline-flex items-center gap-1 text-xs font-medium text-primary-400 hover:underline"
            >
                Ver no documento
                @if ($proposed->page)
                    (página {{ $proposed->page }})
                @endif
            </a>
        @endif

        @if (filled($proposed->excerpt))
            <blockquote class="mt-3 border-l-2 border-white/20 pl-3 text-xs italic text-gray-300">
                “{{ $proposed->excerpt }}”
            </blockquote>
        @endif
    </section>

    <section class="flex flex-wrap gap-3 text-xs">
        <span class="rounded-full bg-white/[0.05] px-2.5 py-0.5">
            Evidência: {{ $proposed->evidence_level?->label() }}
        </span>
        @if ($proposed->confidence_score !== null)
            <span class="rounded-full bg-white/[0.05] px-2.5 py-0.5">
                Confiança: {{ round($proposed->confidence_score * 100) }}%
            </span>
        @endif
        @if ($proposed->field_key?->isMaterial())
            <span class="rounded-full bg-amber-500/10 px-2.5 py-0.5 text-amber-200">
                Alteração material
            </span>
        @endif
    </section>
</div>

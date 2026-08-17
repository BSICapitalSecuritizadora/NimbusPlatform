@php
    use App\Enums\GuaranteeEvidenceLevel;

    /** @var \App\Models\ExtractedGuarantee $candidate */

    $money = static fn (mixed $value): string => blank($value)
        ? 'Não localizado'
        : 'R$ ' . \App\Concerns\MoneyFormatter::formatCurrencyForDisplay($value);

    $identificationLabels = $candidate->type?->category()->identificationFields() ?? [];

    $evidenceBadge = static function (GuaranteeEvidenceLevel $level): string {
        return match ($level) {
            GuaranteeEvidenceLevel::Explicit => 'bg-emerald-500/10 text-emerald-200',
            GuaranteeEvidenceLevel::Inferred => 'bg-amber-500/10 text-amber-200',
            GuaranteeEvidenceLevel::NotFound => 'bg-white/[0.05] text-gray-400',
        };
    };
@endphp

<div class="space-y-6 text-sm">
    @if ($candidate->has_conflict)
        <div class="rounded-xl border border-rose-400/20 bg-rose-500/10 p-4">
            <div class="font-semibold text-rose-200">Conflito documental — revisão necessária</div>
            <p class="mt-1 text-xs text-rose-100/80">{{ $candidate->conflict_reason }}</p>
        </div>
    @endif

    <section>
        <h4 class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-400">O que foi identificado</h4>
        <dl class="mt-3 grid gap-3 sm:grid-cols-2">
            <div>
                <dt class="text-gray-400">Tipo</dt>
                <dd class="font-medium">{{ \App\Enums\GuaranteeType::labelFor($candidate->type) }}</dd>
            </div>
            <div>
                <dt class="text-gray-400">Evento</dt>
                <dd class="font-medium">{{ $candidate->event_type?->label() ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-gray-400">Valor identificado</dt>
                <dd class="font-medium">{{ $money($candidate->contracted_value) }}</dd>
            </div>
            <div>
                <dt class="text-gray-400">Cobertura mínima identificada</dt>
                <dd class="font-medium">
                    @if ($candidate->requirement_percentage !== null)
                        {{ number_format((float) $candidate->requirement_percentage * 100, 2, ',', '.') }}%
                        @if ($candidate->requirement_base)
                            do {{ mb_strtolower($candidate->requirement_base->label()) }}
                        @endif
                    @elseif ($candidate->requirement_value !== null)
                        {{ $money($candidate->requirement_value) }}
                    @else
                        Não localizada
                    @endif
                </dd>
            </div>
            @foreach (($candidate->identification ?? []) as $key => $value)
                @continue(blank($value))
                <div>
                    <dt class="text-gray-400">{{ $identificationLabels[$key] ?? \Illuminate\Support\Str::of($key)->replace('_', ' ')->title() }}</dt>
                    <dd class="font-medium">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>
    </section>

    @if ($candidate->relatedGuarantee)
        <section class="rounded-xl border border-white/10 bg-white/[0.03] p-4">
            <div class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-400">Garantia afetada</div>
            <div class="mt-1 font-medium">{{ $candidate->relatedGuarantee->display_name }}</div>
            <p class="mt-1 text-xs text-gray-400">
                A confirmação aplica o evento a esta garantia e preserva a posição anterior no histórico.
            </p>
        </section>
    @endif

    <section>
        <h4 class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-400">Origem no documento</h4>
        <dl class="mt-3 grid gap-3 sm:grid-cols-3">
            <div>
                <dt class="text-gray-400">Documento</dt>
                <dd class="font-medium">{{ $candidate->document?->title ?? $candidate->document_type?->label() ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-gray-400">Cláusula</dt>
                <dd class="font-medium">{{ $candidate->source_clause ?? 'Não informada' }}</dd>
            </div>
            <div>
                <dt class="text-gray-400">Página</dt>
                <dd class="font-medium">{{ $candidate->source_page ?? 'Não informada' }}</dd>
            </div>
        </dl>

        @if (filled($candidate->source_excerpt))
            <blockquote class="mt-3 border-l-2 border-white/20 pl-3 text-xs italic text-gray-300">
                “{{ $candidate->source_excerpt }}”
            </blockquote>
        @endif
    </section>

    <section>
        <h4 class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-400">Confiança da extração</h4>
        <p class="mt-2 text-xs text-gray-400">
            Geral: <span class="font-medium text-gray-200">{{ $candidate->confidenceLevel()?->label() ?? '—' }}</span>
            @if ($candidate->confidencePercent())
                ({{ $candidate->confidencePercent() }})
            @endif
        </p>

        @if (filled($candidate->field_evidence))
            <ul class="mt-3 flex flex-wrap gap-2">
                @foreach ($candidate->field_evidence as $field => $level)
                    @php $evidenceLevel = GuaranteeEvidenceLevel::tryFrom($level) ?? GuaranteeEvidenceLevel::NotFound; @endphp
                    <li class="rounded-full px-2.5 py-0.5 text-xs {{ $evidenceBadge($evidenceLevel) }}">
                        {{ \Illuminate\Support\Str::of($field)->replace('_', ' ')->title() }}: {{ $evidenceLevel->label() }}
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($candidate->inferredFields() !== [])
            <p class="mt-3 rounded-lg bg-amber-500/10 p-3 text-xs text-amber-100">
                Campos inferidos exigem conferência contra o documento antes de confirmar.
            </p>
        @endif
    </section>

    @if (filled($candidate->review_notes))
        <section>
            <h4 class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-400">Observações</h4>
            <p class="mt-2 text-xs text-gray-300">{{ $candidate->review_notes }}</p>
        </section>
    @endif
</div>

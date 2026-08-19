@php
    use App\Enums\GuaranteeEvidenceLevel;
    use App\Enums\GuaranteeReconciliationOutcome;

    /** @var \App\Models\ExtractedGuarantee $candidate */
    /** @var \App\DTOs\Guarantees\GuaranteeConsolidationPlan|null $plan */

    $plan ??= null;
    $outcome = $plan?->outcome ?? $candidate->outcome();

    $money = static fn (mixed $value): string => blank($value)
        ? 'Não localizado'
        : 'R$ ' . \App\Concerns\MoneyFormatter::formatCurrencyForDisplay($value);

    $identificationLabels = $candidate->type?->category()->identificationFields() ?? [];

    $evidenceBadge = static function (GuaranteeEvidenceLevel $level): string {
        return match ($level) {
            GuaranteeEvidenceLevel::Explicit => 'bg-emerald-500/10 text-emerald-200',
            GuaranteeEvidenceLevel::Inferred => 'bg-amber-500/10 text-amber-200',
            GuaranteeEvidenceLevel::Conflicting => 'bg-rose-500/10 text-rose-200',
            GuaranteeEvidenceLevel::NotFound => 'bg-white/[0.05] text-gray-400',
        };
    };

    $banner = match ($outcome) {
        GuaranteeReconciliationOutcome::Conflict => ['border-rose-400/20 bg-rose-500/10', 'text-rose-200', 'text-rose-100/80'],
        GuaranteeReconciliationOutcome::Change => ['border-amber-400/20 bg-amber-500/10', 'text-amber-200', 'text-amber-100/80'],
        GuaranteeReconciliationOutcome::Complement => ['border-emerald-400/20 bg-emerald-500/10', 'text-emerald-200', 'text-emerald-100/80'],
        GuaranteeReconciliationOutcome::Confirmation => ['border-white/10 bg-white/[0.04]', 'text-gray-200', 'text-gray-400'],
        GuaranteeReconciliationOutcome::NewGuarantee => ['border-sky-400/20 bg-sky-500/10', 'text-sky-200', 'text-sky-100/80'],
    };
@endphp

<div class="space-y-6 text-sm">
    <div class="rounded-xl border p-4 {{ $banner[0] }}">
        <div class="font-semibold {{ $banner[1] }}">{{ $outcome->label() }}</div>
        <p class="mt-1 text-xs {{ $banner[2] }}">
            {{ $candidate->conflict_reason ?? $outcome->description() }}
        </p>
    </div>

    @if ($plan?->hasGuarantee())
        <section class="rounded-xl border border-white/10 bg-white/[0.03] p-4">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <div>
                    <div class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-400">Possível correspondência</div>
                    <div class="mt-1 font-medium">{{ $plan->guarantee->display_name }}</div>
                </div>
                @if ($plan->match)
                    <div class="text-xs text-gray-400">
                        Correspondência:
                        <span class="font-medium text-gray-200">{{ $plan->match->level->label() }}</span>
                        @if ($candidate->matchPercent())
                            ({{ $candidate->matchPercent() }})
                        @endif
                    </div>
                @endif
            </div>

            @if ($plan->match?->evidence)
                <ul class="mt-3 space-y-1 text-xs text-gray-300">
                    @foreach ($plan->match->evidence as $evidence)
                        <li class="flex gap-2"><span class="text-emerald-300">•</span><span>{{ $evidence }}</span></li>
                    @endforeach
                </ul>
            @endif

            @if ($plan->match?->contradictions)
                <ul class="mt-2 space-y-1 text-xs text-amber-200/90">
                    @foreach ($plan->match->contradictions as $contradiction)
                        <li class="flex gap-2"><span>⚠</span><span>{{ $contradiction }}</span></li>
                    @endforeach
                </ul>
            @endif

            <p class="mt-3 text-xs text-gray-400">
                @if ($candidate->related_guarantee_id === null)
                    Esta correspondência foi encontrada agora, depois da detecção — provavelmente a garantia
                    foi cadastrada nesse intervalo. Complementar aplica as informações a ela mesmo assim.
                @else
                    Complementar aplica estas informações à garantia acima e preserva a posição anterior no histórico.
                @endif
            </p>
        </section>
    @endif

    @if ($plan?->changesAnyValue() || $plan?->confirmations)
        <section>
            <h4 class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-400">
                O que este documento acrescenta
            </h4>

            <div class="mt-3 overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="text-gray-400">
                        <tr class="border-b border-white/10">
                            <th class="py-2 pr-3 font-medium">Campo</th>
                            <th class="py-2 pr-3 font-medium">Atualmente</th>
                            <th class="py-2 pr-3 font-medium">Documento identificou</th>
                            <th class="py-2 font-medium">Situação</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        @foreach ($plan->complements as $delta)
                            <tr>
                                <td class="py-2 pr-3 text-gray-300">{{ $delta->label }}</td>
                                <td class="py-2 pr-3 text-gray-500">{{ $delta->currentDisplay }}</td>
                                <td class="py-2 pr-3 font-medium text-emerald-200">{{ $delta->newDisplay }}</td>
                                <td class="py-2 text-emerald-300">Complementa</td>
                            </tr>
                        @endforeach
                        @foreach ($plan->divergences as $delta)
                            <tr>
                                <td class="py-2 pr-3 text-gray-300">{{ $delta->label }}</td>
                                <td class="py-2 pr-3 font-medium text-gray-200">{{ $delta->currentDisplay }}</td>
                                <td class="py-2 pr-3 font-medium text-amber-200">{{ $delta->newDisplay }}</td>
                                <td class="py-2 text-amber-300">Diverge — exige decisão</td>
                            </tr>
                        @endforeach
                        @foreach ($plan->confirmations as $delta)
                            <tr>
                                <td class="py-2 pr-3 text-gray-300">{{ $delta->label }}</td>
                                <td class="py-2 pr-3 text-gray-400">{{ $delta->currentDisplay }}</td>
                                <td class="py-2 pr-3 text-gray-400">{{ $delta->newDisplay }}</td>
                                <td class="py-2 text-gray-500">Confirma — só nova fonte</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($plan->hasDivergences())
                <p class="mt-3 rounded-lg bg-amber-500/10 p-3 text-xs text-amber-100">
                    Nenhum valor divergente é sobrescrito automaticamente. Ao complementar, escolha campo a campo
                    entre manter o cadastrado e adotar o do documento — a decisão fica registrada no histórico.
                </p>
            @endif
        </section>
    @endif

    @if ($plan?->linkedFund)
        <section class="rounded-xl border border-white/10 bg-white/[0.03] p-4">
            <div class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-400">Conta já cadastrada</div>
            <p class="mt-1 text-xs text-gray-300">
                A conta do documento é a do fundo
                <span class="font-medium text-gray-100">{{ $plan->linkedFund->trade_name ?? $plan->linkedFund->fundName?->name ?? 'cadastrado' }}</span>.
                A garantia será vinculada a ele em vez de guardar uma segunda cópia dos dados bancários.
            </p>
        </section>
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
                @continue(blank($value) || ! is_scalar($value))
                <div>
                    <dt class="text-gray-400">{{ $identificationLabels[$key] ?? \Illuminate\Support\Str::of($key)->replace('_', ' ')->title() }}</dt>
                    <dd class="font-medium">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>
    </section>

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

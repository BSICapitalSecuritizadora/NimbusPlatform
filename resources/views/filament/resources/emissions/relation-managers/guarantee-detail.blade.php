@php
    /** @var \App\Models\Guarantee $guarantee */
    /** @var \App\DTOs\Guarantees\GuaranteePositionData|null $position */
    $position ??= null;

    $money = static fn (mixed $value): string => blank($value)
        ? 'Não informado'
        : 'R$ ' . \App\Concerns\MoneyFormatter::formatCurrencyForDisplay($value);
    $ratio = static fn (?float $value): string => $value === null
        ? '—'
        : number_format($value * 100, 2, ',', '.') . '%';
    $date = static fn (mixed $value): string => $value === null ? '—' : $value->format('d/m/Y');

    $identificationLabels = $guarantee->type?->category()->identificationFields() ?? [];

    $fieldTimeline = app(\App\Services\Guarantees\GuaranteeFieldVersionWriter::class)->timeline($guarantee);
@endphp

<div class="space-y-6 text-sm">
    {{-- Identificação --}}
    <section>
        <h4 class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-400">Identificação</h4>
        <dl class="mt-3 grid gap-3 sm:grid-cols-2">
            <div>
                <dt class="text-gray-400">Tipo</dt>
                <dd class="font-medium">{{ \App\Enums\GuaranteeType::labelFor($guarantee->type) }}</dd>
            </div>
            <div>
                <dt class="text-gray-400">Situação jurídica</dt>
                <dd class="font-medium">{{ $guarantee->legal_status?->label() ?? '—' }}</dd>
            </div>
            @if ($guarantee->construction)
                <div>
                    <dt class="text-gray-400">Empreendimento</dt>
                    <dd class="font-medium">{{ $guarantee->construction->development_name }}</dd>
                </div>
            @endif
            @if ($guarantee->fund)
                {{-- A conta vem do fundo, não de uma segunda cópia dentro da
                     garantia: é o fundo que tem o saldo e é ele que é atualizado. --}}
                <div>
                    <dt class="text-gray-400">Conta vinculada</dt>
                    <dd class="font-medium">
                        {{ $guarantee->fund->trade_name ?? $guarantee->fund->fundName?->name ?? 'Fundo cadastrado' }}
                    </dd>
                    <dd class="text-xs text-gray-400">
                        {{ collect([
                            $guarantee->fund->bank?->name,
                            filled($guarantee->fund->agency) ? 'Ag. ' . $guarantee->fund->agency : null,
                            filled($guarantee->fund->account) ? 'C/C ' . $guarantee->fund->account : null,
                        ])->filter()->join(' · ') ?: 'Dados bancários não informados no fundo' }}
                    </dd>
                </div>
            @endif
            @foreach (($guarantee->identification ?? []) as $key => $value)
                @continue(blank($value))
                <div>
                    <dt class="text-gray-400">{{ $identificationLabels[$key] ?? \Illuminate\Support\Str::of($key)->replace('_', ' ')->title() }}</dt>
                    <dd class="font-medium">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>
    </section>

    {{-- Regra contratual --}}
    <section>
        <h4 class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-400">Regra contratual</h4>
        <dl class="mt-3 grid gap-3 sm:grid-cols-2">
            <div>
                <dt class="text-gray-400">Forma do mínimo</dt>
                <dd class="font-medium">{{ $guarantee->resolvedRequirementBasis()->label() }}</dd>
            </div>
            <div>
                <dt class="text-gray-400">Regra aplicada</dt>
                <dd class="font-medium">{{ $position?->requirement->description ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-gray-400">Periodicidade de avaliação</dt>
                <dd class="font-medium">{{ $guarantee->evaluation_frequency ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-gray-400">Fator de elegibilidade</dt>
                <dd class="font-medium">{{ number_format($guarantee->resolvedEligibilityFactor(), 4, ',', '.') }}</dd>
            </div>
            @if (filled($guarantee->requirement_formula))
                <div class="sm:col-span-2">
                    <dt class="text-gray-400">Texto literal da regra</dt>
                    <dd class="mt-1 rounded-lg bg-white/[0.03] p-3 italic">{{ $guarantee->requirement_formula }}</dd>
                </div>
            @endif
            @if (filled($guarantee->requirement_conditions))
                <div class="sm:col-span-2">
                    <dt class="text-gray-400">Condições especiais</dt>
                    <dd class="mt-1">{{ $guarantee->requirement_conditions }}</dd>
                </div>
            @endif
        </dl>
    </section>

    {{-- Valores --}}
    <section>
        <h4 class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-400">Valores</h4>
        <dl class="mt-3 grid gap-3 sm:grid-cols-3">
            <div>
                <dt class="text-gray-400">Na contratação</dt>
                <dd class="font-medium">{{ $money($guarantee->contracted_value) }}</dd>
            </div>
            <div>
                <dt class="text-gray-400">Documental</dt>
                <dd class="font-medium">{{ $money($guarantee->documentary_value) }}</dd>
            </div>
            <div>
                <dt class="text-gray-400">Atual ({{ $position?->referenceMonth ? \Illuminate\Support\Carbon::parse($position->referenceMonth)->format('m/Y') : '—' }})</dt>
                <dd class="font-medium">{{ $money($position?->currentValue()) }}</dd>
            </div>
            <div>
                <dt class="text-gray-400">Elegível</dt>
                <dd class="font-medium">{{ $money($position?->eligibleValue) }}</dd>
            </div>
            <div>
                <dt class="text-gray-400">Exigido</dt>
                <dd class="font-medium">{{ $money($position?->requiredValue()) }}</dd>
            </div>
            <div>
                <dt class="text-gray-400">Excedente / déficit</dt>
                <dd class="font-medium {{ ($position?->surplusDeficit !== null && $position->surplusDeficit < 0) ? 'text-rose-300' : '' }}">
                    {{ $position?->surplusDeficit === null ? '—' : $money(abs($position->surplusDeficit)) }}
                </dd>
            </div>
        </dl>
        @if ($position)
            <p class="mt-3 text-xs text-gray-400">
                Origem do valor: {{ $position->value->source->label() }} · {{ $position->value->status->label() }}
                @if (filled($position->value->metadata['reason'] ?? null))
                    — {{ $position->value->metadata['reason'] }}
                @endif
            </p>
        @endif
    </section>

    {{-- Vigência --}}
    <section>
        <h4 class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-400">Vigência</h4>
        <dl class="mt-3 grid gap-3 sm:grid-cols-4">
            <div><dt class="text-gray-400">Constituição</dt><dd class="font-medium">{{ $date($guarantee->constituted_at) }}</dd></div>
            <div><dt class="text-gray-400">Registro</dt><dd class="font-medium">{{ $date($guarantee->registered_at) }}</dd></div>
            <div><dt class="text-gray-400">Início</dt><dd class="font-medium">{{ $date($guarantee->validity_start_date) }}</dd></div>
            <div><dt class="text-gray-400">Término</dt><dd class="font-medium">{{ $date($guarantee->validity_end_date) }}</dd></div>
        </dl>
    </section>

    {{-- Origem documental --}}
    <section>
        <h4 class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-400">Origem documental</h4>
        @if ($guarantee->documentReferences->isEmpty())
            <p class="mt-3 text-gray-400">Nenhuma origem documental registrada.</p>
        @else
            <ul class="mt-3 space-y-3">
                @foreach ($guarantee->documentReferences->sortBy('document_date') as $reference)
                    <li class="rounded-xl border border-white/10 bg-white/[0.03] p-3">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-xs font-semibold uppercase tracking-wide text-gray-400">
                                {{ $reference->reference_type?->label() }}
                            </span>
                            @if ($reference->confidence_level)
                                <span class="rounded-full bg-white/[0.05] px-2 py-0.5 text-xs">
                                    Confiança: {{ $reference->confidence_level->label() }}
                                </span>
                            @endif
                        </div>
                        <div class="mt-1 font-medium">{{ $reference->document_label }}</div>
                        <div class="text-xs text-gray-400">
                            {{ $reference->location_label ?? 'Localização não informada' }}
                            @if ($reference->document_date)
                                · {{ $reference->document_date->format('d/m/Y') }}
                            @endif
                        </div>
                        @if (filled($reference->excerpt))
                            <p class="mt-2 border-l-2 border-white/20 pl-3 text-xs italic text-gray-300">
                                “{{ $reference->excerpt }}”
                            </p>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- Vigência por campo --}}
    @if ($fieldTimeline->isNotEmpty())
        <section>
            <h4 class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-400">Vigência por campo</h4>
            <p class="mt-1 text-xs text-gray-500">
                Cada valor guarda desde quando vale e qual documento o estabeleceu. O valor anterior não é
                apagado: ele deixa de vigorar na data em que o seguinte passa a valer.
            </p>

            <div class="mt-3 space-y-3">
                @foreach ($fieldTimeline as $entry)
                    <div class="rounded-xl border border-white/10 bg-white/[0.02] p-3">
                        <div class="text-xs font-semibold uppercase tracking-[0.14em] text-gray-400">
                            {{ $entry['key']?->label() ?? 'Campo' }}
                        </div>

                        <ul class="mt-2 space-y-2">
                            @foreach ($entry['versions'] as $version)
                                <li class="flex flex-wrap items-baseline gap-x-2 gap-y-1 text-xs">
                                    <span class="font-medium {{ $version['is_current'] ? 'text-gray-100' : 'text-gray-400 line-through decoration-white/30' }}">
                                        {{ $version['field']->formatted_value }}
                                    </span>

                                    @if ($version['is_current'])
                                        <span class="rounded-full bg-emerald-500/10 px-2 py-0.5 text-emerald-200">
                                            Vigente desde {{ $date($version['valid_from']) }}
                                        </span>
                                    @else
                                        <span class="rounded-full bg-white/[0.05] px-2 py-0.5 text-gray-400">
                                            {{ $date($version['valid_from']) }} até {{ $date($version['valid_until']) }}
                                        </span>
                                    @endif

                                    <span class="text-gray-500">
                                        {{ $version['field']->document_label }}
                                        @if ($version['field']->source_label)
                                            · {{ $version['field']->source_label }}
                                        @endif
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Histórico jurídico --}}
    <section>
        <h4 class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-400">Histórico jurídico</h4>
        @if ($guarantee->events->isEmpty())
            <p class="mt-3 text-gray-400">Nenhum evento registrado.</p>
        @else
            <ol class="mt-3 space-y-3 border-l border-white/10 pl-4">
                @foreach ($guarantee->events->sortBy(fn ($event) => $event->effective_date?->toDateString() ?? '') as $event)
                    <li>
                        <div class="flex flex-wrap items-baseline gap-2">
                            <span class="font-medium">{{ $date($event->effective_date) }}</span>
                            <span class="rounded-full bg-white/[0.05] px-2 py-0.5 text-xs">{{ $event->event_type?->label() }}</span>
                        </div>
                        @if (filled($event->description))
                            <p class="mt-1 text-xs text-gray-400">{{ \Illuminate\Support\Str::limit($event->description, 220) }}</p>
                        @endif
                        @foreach ($event->change_summary as $change)
                            <p class="mt-1 text-xs text-gray-300">
                                {{ $change['label'] }}:
                                <span class="text-gray-400">{{ $change['from_display'] ?? 'Não informado' }}</span>
                                →
                                <span class="font-medium">{{ $change['to_display'] ?? '—' }}</span>
                            </p>
                        @endforeach
                        @if ($event->documentReference)
                            <p class="mt-1 text-xs text-gray-500">
                                Documento: {{ $event->documentReference->document_label }}
                                @if ($event->documentReference->location_label)
                                    · {{ $event->documentReference->location_label }}
                                @endif
                            </p>
                        @endif
                    </li>
                @endforeach
            </ol>
        @endif
    </section>

    {{-- Avaliações --}}
    @if ($guarantee->valuations->isNotEmpty())
        <section>
            <h4 class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-400">Avaliações</h4>
            <table class="mt-3 min-w-full divide-y divide-white/10 text-xs">
                <thead>
                    <tr class="text-left text-gray-400">
                        <th class="py-2">Data-base</th>
                        <th class="py-2">Critério</th>
                        <th class="py-2">Avaliador</th>
                        <th class="py-2 text-right">Valor</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/10">
                    @foreach ($guarantee->valuations->sortByDesc('valuation_date') as $valuation)
                        <tr>
                            <td class="py-2">{{ $date($valuation->valuation_date) }}</td>
                            <td class="py-2">{{ $valuation->basis?->label() }}</td>
                            <td class="py-2">{{ $valuation->appraiser ?? '—' }}</td>
                            <td class="py-2 text-right font-medium">{{ $money($valuation->value) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif

    {{-- Histórico financeiro --}}
    @if ($guarantee->monthlyPositions->isNotEmpty())
        <section>
            <h4 class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-400">Histórico financeiro</h4>
            <table class="mt-3 min-w-full divide-y divide-white/10 text-xs">
                <thead>
                    <tr class="text-left text-gray-400">
                        <th class="py-2">Competência</th>
                        <th class="py-2 text-right">Valor atual</th>
                        <th class="py-2 text-right">Elegível</th>
                        <th class="py-2 text-right">Cobertura</th>
                        <th class="py-2">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/10">
                    @foreach ($guarantee->monthlyPositions->sortByDesc('reference_month')->take(12) as $monthly)
                        <tr>
                            <td class="py-2">{{ $monthly->formatted_reference_month }}</td>
                            <td class="py-2 text-right">{{ $money($monthly->current_value) }}</td>
                            <td class="py-2 text-right">{{ $money($monthly->eligible_value) }}</td>
                            <td class="py-2 text-right">{{ $ratio($monthly->coverage_ratio === null ? null : (float) $monthly->coverage_ratio) }}</td>
                            <td class="py-2">{{ $monthly->value_status?->label() ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif
</div>

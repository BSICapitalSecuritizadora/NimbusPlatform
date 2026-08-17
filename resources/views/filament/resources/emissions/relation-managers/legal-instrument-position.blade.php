@php
    use App\Enums\LegalInstrumentFieldKey;

    /** @var \App\Models\LegalInstrument $instrument */
    /** @var \App\DTOs\LegalInstruments\InstrumentPositionData $position */
    /** @var \App\Services\LegalInstruments\InstrumentPositionResolver $resolver */

    $latestAmendment = $instrument->latestAmendment();
@endphp

<div class="space-y-6 text-sm">
    <div class="rounded-xl border border-white/10 bg-white/[0.03] p-4">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <span class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-400">Posição vigente</span>
            <span class="text-xs text-gray-400">Reconstruída em {{ $position->asOf->format('d/m/Y') }}</span>
        </div>
        <div class="mt-2 text-lg font-semibold">{{ $instrument->display_name }}</div>
        <div class="text-xs text-gray-400">{{ $instrument->type->label() }} · {{ $instrument->status_label }}</div>
    </div>

    {{-- Última alteração (§42) --}}
    @if ($latestAmendment)
        <section class="rounded-xl border border-amber-400/20 bg-amber-500/10 p-4">
            <div class="text-xs font-semibold uppercase tracking-[0.18em] text-amber-200">Última alteração</div>
            <div class="mt-1 font-medium">
                {{ $latestAmendment->role_label }}
                @if ($latestAmendment->document_date)
                    — {{ $latestAmendment->document_date->format('d/m/Y') }}
                @endif
            </div>
            @if (filled($latestAmendment->effect_summary))
                <p class="mt-1 text-xs text-amber-100/80">{{ $latestAmendment->effect_summary }}</p>
            @endif

            @php $changed = $position->changedFields(); @endphp
            @if ($changed->isNotEmpty())
                <ul class="mt-2 space-y-1 text-xs">
                    @foreach ($changed as $field)
                        <li>
                            <span class="text-gray-300">{{ $field->label() }}:</span>
                            <span class="text-gray-400">{{ $field->previousFormattedValue() }}</span>
                            →
                            <span class="font-medium">{{ $field->formattedValue() }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endif

    {{-- Campos consolidados, agrupados, com proveniência (§8) --}}
    @forelse ($position->fieldsByGroup() as $group => $fields)
        <section>
            <h4 class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-400">{{ $group }}</h4>
            <dl class="mt-3 grid gap-3 sm:grid-cols-2">
                @foreach ($fields as $field)
                    <div class="rounded-lg border border-white/10 bg-white/[0.02] p-3">
                        <dt class="text-xs text-gray-400">{{ $field->label() }}</dt>
                        <dd class="mt-0.5 font-medium">{{ $field->formattedValue() }}</dd>
                        <div class="mt-2 text-xs text-gray-500">
                            <div>{{ $field->sourceDocumentLabel() }}</div>
                            @if ($field->sourceLocation())
                                <div>{{ $field->sourceLocation() }}</div>
                            @endif
                            @if ($field->effectiveSince())
                                <div>Vigente desde {{ $field->effectiveSince() }}</div>
                            @endif
                            @if ($field->confirmedBy())
                                <div>Confirmado por {{ $field->confirmedBy() }}</div>
                            @endif
                            @if ($field->current->source_url)
                                <a
                                    href="{{ $field->current->source_url }}"
                                    target="_blank"
                                    rel="noopener"
                                    class="mt-1 inline-block font-medium text-primary-400 hover:underline"
                                >Ver no documento</a>
                            @endif
                            @if ($field->hasChanged())
                                <div class="mt-1 text-amber-300/80">
                                    Anterior: {{ $field->previousFormattedValue() }}
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </dl>
        </section>
    @empty
        <p class="text-gray-400">
            Nenhuma informação consolidada ainda. Anexe o documento original ao dossiê e confirme as informações extraídas.
        </p>
    @endforelse

    {{-- Garantias vinculadas (§14) --}}
    <section>
        <h4 class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-400">Garantias vigentes</h4>
        @if ($position->guarantees->isEmpty())
            <p class="mt-3 text-gray-400">Nenhuma garantia vinculada a este instrumento.</p>
        @else
            <ul class="mt-3 space-y-3">
                @foreach ($position->guarantees as $guarantee)
                    @php $guaranteeFields = $resolver->resolveGuaranteeFields($guarantee, $position->asOf); @endphp
                    <li class="rounded-lg border border-white/10 bg-white/[0.02] p-3">
                        <div class="font-medium">{{ $guarantee->display_name }}</div>
                        <div class="text-xs text-gray-400">
                            {{ \App\Enums\GuaranteeType::labelFor($guarantee->type) }} ·
                            {{ $guarantee->legal_status?->label() }}
                        </div>
                        @if ($guaranteeFields->isNotEmpty())
                            <dl class="mt-2 grid gap-2 sm:grid-cols-2">
                                @foreach ($guaranteeFields as $field)
                                    <div>
                                        <dt class="text-xs text-gray-400">{{ $field->label() }}</dt>
                                        <dd class="text-sm font-medium">{{ $field->formattedValue() }}</dd>
                                        @if ($field->hasChanged())
                                            <div class="text-xs text-gray-500">
                                                Anterior: {{ $field->previousFormattedValue() }}
                                                @if ($field->sourceLocation())
                                                    · {{ $field->sourceLocation() }}
                                                @endif
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </dl>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>

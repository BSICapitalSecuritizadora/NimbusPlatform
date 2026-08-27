@php
    $totals = $this->outcomeTotals();
    $statusOrder = [
        \App\Enums\DocumentBatchItemStatus::Created,
        \App\Enums\DocumentBatchItemStatus::Rejected,
        \App\Enums\DocumentBatchItemStatus::Duplicated,
        \App\Enums\DocumentBatchItemStatus::Failed,
        \App\Enums\DocumentBatchItemStatus::NotProcessed,
    ];
@endphp

<x-filament::section
    :heading="'Resumo do cadastro em lote'"
    :description="'Resultado individual de cada arquivo enviado.'"
    icon="heroicon-o-clipboard-document-list"
    class="mb-6 border-[rgba(148,163,184,0.18)] bg-[#0b1a20] dark:border-[rgba(148,163,184,0.18)] dark:bg-[#0b1a20]"
>
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
        @foreach ($statusOrder as $status)
            <div class="rounded-xl border border-[rgba(148,163,184,0.16)] bg-[#08171e] p-3.5 shadow-sm">
                <div class="text-2xl font-bold tracking-tight text-[#fbfaf8]">
                    {{ $totals[$status->value] ?? 0 }}
                </div>
                <div class="mt-1 text-xs font-medium text-[rgba(251,250,248,0.65)]">
                    {{ $status->label() }}
                </div>
            </div>
        @endforeach
    </div>

    <div class="mt-6 overflow-x-auto rounded-xl border border-[rgba(148,163,184,0.14)] bg-[#08171e]">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b border-[rgba(148,163,184,0.14)] bg-[#06151c] text-xs font-semibold uppercase tracking-wider text-[rgba(251,250,248,0.65)]">
                    <th class="px-4 py-3">Arquivo</th>
                    <th class="px-4 py-3">Título</th>
                    <th class="px-4 py-3">Situação</th>
                    <th class="px-4 py-3">Motivo</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[rgba(148,163,184,0.08)]">
                @foreach ($this->outcomes as $outcome)
                    <tr class="align-top transition-colors hover:bg-[rgba(251,250,248,0.02)]">
                        <td class="px-4 py-3.5 font-medium text-[#fbfaf8]">
                            {{ $outcome['original_name'] }}
                        </td>
                        <td class="px-4 py-3.5 text-[rgba(251,250,248,0.8)]">
                            {{ $outcome['title'] }}
                        </td>
                        <td class="px-4 py-3.5">
                            <x-filament::badge :color="$outcome['status_color']">
                                {{ $outcome['status_label'] }}
                            </x-filament::badge>
                        </td>
                        <td class="px-4 py-3.5 text-[rgba(251,250,248,0.75)]">
                            {{ $outcome['reason'] ?? '—' }}

                            @if (filled($outcome['duplicate_warning'] ?? null))
                                <div class="mt-1 text-xs text-amber-400">
                                    {{ $outcome['duplicate_warning'] }}
                                </div>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-6 flex flex-wrap items-center gap-3 border-t border-[rgba(148,163,184,0.12)] pt-4">
        <x-filament::button
            tag="a"
            :href="$this->listDocumentsUrl()"
            icon="heroicon-o-arrow-top-right-on-square"
            color="gray"
            outlined
        >
            Ir para a listagem de documentos
        </x-filament::button>

        <x-filament::button
            wire:click="startNewBatch"
            wire:loading.attr="disabled"
            icon="heroicon-o-plus-circle"
            color="gray"
            outlined
        >
            Iniciar novo lote
        </x-filament::button>

        @if ($this->hasRetryableOutcomes())
            <span class="text-sm text-[rgba(251,250,248,0.6)]">
                Use o botão de confirmação do formulário abaixo para reprocessar apenas os arquivos pendentes.
            </span>
        @endif
    </div>
</x-filament::section>

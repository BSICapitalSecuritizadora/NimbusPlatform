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
>
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
        @foreach ($statusOrder as $status)
            <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                <div class="text-2xl font-semibold text-gray-950 dark:text-white">
                    {{ $totals[$status->value] ?? 0 }}
                </div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ $status->label() }}
                </div>
            </div>
        @endforeach
    </div>

    <div class="mt-6 overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                    <th class="py-2 pr-4 font-medium">Arquivo</th>
                    <th class="py-2 pr-4 font-medium">Título</th>
                    <th class="py-2 pr-4 font-medium">Situação</th>
                    <th class="py-2 font-medium">Motivo</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($this->outcomes as $outcome)
                    <tr class="border-b border-gray-100 align-top dark:border-white/5">
                        <td class="py-3 pr-4 text-gray-950 dark:text-white">
                            {{ $outcome['original_name'] }}
                        </td>
                        <td class="py-3 pr-4 text-gray-600 dark:text-gray-300">
                            {{ $outcome['title'] }}
                        </td>
                        <td class="py-3 pr-4">
                            <x-filament::badge :color="$outcome['status_color']">
                                {{ $outcome['status_label'] }}
                            </x-filament::badge>
                        </td>
                        <td class="py-3 text-gray-600 dark:text-gray-300">
                            {{ $outcome['reason'] ?? '—' }}

                            @if (filled($outcome['duplicate_warning'] ?? null))
                                <div class="mt-1 text-xs text-amber-600 dark:text-amber-400">
                                    {{ $outcome['duplicate_warning'] }}
                                </div>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-6 flex flex-wrap items-center gap-3">
        <x-filament::button
            tag="a"
            :href="$this->listDocumentsUrl()"
            icon="heroicon-o-arrow-top-right-on-square"
            color="gray"
        >
            Ir para a listagem de documentos
        </x-filament::button>

        <x-filament::button
            wire:click="startNewBatch"
            wire:loading.attr="disabled"
            icon="heroicon-o-plus-circle"
            color="gray"
        >
            Iniciar novo lote
        </x-filament::button>

        @if ($this->hasRetryableOutcomes())
            <span class="text-sm text-gray-500 dark:text-gray-400">
                Use o botão de confirmação do formulário abaixo para reprocessar apenas os arquivos pendentes.
            </span>
        @endif
    </div>
</x-filament::section>

<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Relatório mensal por emissão</x-slot>
        <x-slot name="description">
            Gere o relatório mensal de uma emissão ou informe uma competência final para consolidar
            vários meses em um único PDF.
        </x-slot>

        <div class="grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-12 xl:items-end">
            <div class="md:col-span-2 xl:col-span-4">
                <label for="emissionId" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">
                    Emissão <span class="text-danger-600 dark:text-danger-400">*</span>
                </label>
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="emissionId" id="emissionId">
                        <option value="">Selecione uma emissão...</option>
                        @foreach ($this->emissionOptions() as $id => $label)
                            <option value="{{ $id }}">{{ $label }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>

            <div class="md:col-span-2 xl:col-span-5">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-[1fr_auto_1fr] sm:items-end sm:gap-3">
                    <div>
                        <label for="referenceMonth" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">
                            Competência inicial <span class="text-danger-600 dark:text-danger-400">*</span>
                        </label>
                        <x-filament::input.wrapper>
                            <x-filament::input type="month" wire:model.live="referenceMonth" id="referenceMonth" />
                        </x-filament::input.wrapper>
                    </div>

                    <div class="hidden pb-2.5 text-gray-400 dark:text-gray-500 sm:block" aria-hidden="true">
                        <x-filament::icon icon="heroicon-o-arrow-right" class="h-4 w-4" />
                    </div>

                    <div>
                        <label for="referenceMonthEnd" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">
                            Competência final
                            <span class="font-normal text-gray-500 dark:text-gray-400">(opcional)</span>
                        </label>
                        <x-filament::input.wrapper>
                            <x-filament::input type="month" wire:model.live="referenceMonthEnd" id="referenceMonthEnd" />
                        </x-filament::input.wrapper>
                    </div>
                </div>
            </div>

            <div class="md:col-span-2 xl:col-span-3">
                @php($url = $this->reportUrl())
                @if ($url)
                    <x-filament::button
                        tag="a"
                        :href="$url"
                        target="_blank"
                        icon="heroicon-o-document-arrow-down"
                        aria-describedby="report-generation-help"
                    >
                        Gerar PDF
                    </x-filament::button>
                @else
                    <x-filament::button
                        type="button"
                        icon="heroicon-o-document-arrow-down"
                        disabled
                        aria-describedby="report-generation-help"
                    >
                        Gerar PDF
                    </x-filament::button>
                @endif
            </div>
        </div>

        @if ($this->hasInvalidRange())
            <p id="report-generation-help" class="mt-4 flex items-center gap-1.5 text-sm text-danger-600 dark:text-danger-400">
                <x-filament::icon icon="heroicon-o-exclamation-circle" class="h-4 w-4" />
                A competência final deve ser igual ou posterior à competência inicial.
            </p>
        @elseif (($summary = $this->reportSummary()) !== null)
            <div
                id="report-generation-help"
                class="mt-4 flex w-fit items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm dark:border-white/10 dark:bg-white/5"
            >
                <x-filament::icon icon="heroicon-o-document-text" class="h-4 w-4 text-gray-400 dark:text-gray-500" />
                <span class="text-gray-500 dark:text-gray-400">
                    {{ $this->isConsolidated() ? 'Relatório consolidado' : 'Relatório mensal' }}
                </span>
                <span class="font-medium text-gray-900 dark:text-white">{{ $summary }}</span>
            </div>
        @else
            <p id="report-generation-help" class="mt-4 text-sm text-gray-500 dark:text-gray-400">
                Selecione uma emissão e a competência inicial para habilitar a geração do relatório.
            </p>
        @endif
    </x-filament::section>
</x-filament-panels::page>

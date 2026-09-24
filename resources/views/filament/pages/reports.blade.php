<x-filament-panels::page>
    <x-filament::section class="bsi-reports-section">
        <x-slot name="heading">Relatório mensal por emissão</x-slot>
        <x-slot name="description">
            Gere o relatório mensal de uma emissão ou informe uma competência final para consolidar
            vários meses em um único PDF.
        </x-slot>

        <div class="grid grid-cols-1 gap-y-5 sm:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] sm:items-end sm:gap-x-3 xl:grid-cols-[minmax(14rem,1.4fr)_minmax(11rem,1fr)_auto_minmax(11rem,1fr)_auto] xl:gap-x-4">
            <div class="sm:col-span-3 xl:col-span-1">
                <label for="emissionId" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">
                    Emissão <span class="text-danger-600 dark:text-danger-400">*</span>
                </label>
                <x-filament::input.wrapper class="bsi-emission-select-wrp">
                    <x-filament::input.select wire:model.live="emissionId" id="emissionId" class="bsi-emission-select">
                        <option value="">Selecione uma emissão...</option>
                        @foreach ($this->emissionOptions() as $id => $label)
                            <option value="{{ $id }}">{{ $label }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>

            <div class="min-w-0">
                <label for="referenceMonth" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">
                    Competência inicial <span class="text-danger-600 dark:text-danger-400">*</span>
                </label>
                <x-month-picker
                    wire:model.live="referenceMonth"
                    id="referenceMonth"
                    placeholder="mm/aaaa"
                    required
                />
            </div>

            <div class="hidden pb-2.5 text-gray-400 dark:text-gray-500 sm:block" aria-hidden="true">
                <x-filament::icon icon="heroicon-o-arrow-right" class="h-4 w-4" />
            </div>

            <div class="min-w-0">
                <label for="referenceMonthEnd" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">
                    Competência final
                    <span class="font-normal text-gray-500 dark:text-gray-400">(opcional)</span>
                </label>
                <x-month-picker
                    wire:model.live="referenceMonthEnd"
                    id="referenceMonthEnd"
                    placeholder="mm/aaaa"
                />
            </div>

            <div class="shrink-0 sm:col-span-3 xl:col-span-1">
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

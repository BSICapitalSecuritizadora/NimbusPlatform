<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Relatório Mensal por Emissão</x-slot>
        <x-slot name="description">
            Gera o PDF do relatório por emissão. Informe apenas o mês de referência para o relatório
            mensal, ou também um mês final para gerar um PDF consolidado multi-mês da mesma emissão.
        </x-slot>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-4 md:items-end">
            <div class="md:col-span-1">
                <label for="emissionId" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">
                    Emissão
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

            <div>
                <label for="referenceMonth" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">
                    Mês de referência
                </label>
                <x-filament::input.wrapper>
                    <x-filament::input type="month" wire:model.live="referenceMonth" id="referenceMonth" />
                </x-filament::input.wrapper>
            </div>

            <div>
                <label for="referenceMonthEnd" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">
                    Mês final (opcional)
                </label>
                <x-filament::input.wrapper>
                    <x-filament::input type="month" wire:model.live="referenceMonthEnd" id="referenceMonthEnd" />
                </x-filament::input.wrapper>
            </div>

            <div>
                @php($url = $this->reportUrl())
                <x-filament::button
                    tag="a"
                    :href="$url ?? '#'"
                    target="_blank"
                    icon="heroicon-o-document-arrow-down"
                    :disabled="$url === null"
                >
                    Gerar PDF
                </x-filament::button>
            </div>
        </div>

        @if ($this->reportUrl() === null)
            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                Selecione uma emissão e o mês de referência para habilitar a geração do relatório.
            </p>
        @else
            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                Deixe o "Mês final" em branco para o relatório mensal. Preenchendo-o, será gerado um PDF
                consolidado de {{ $referenceMonth }} até {{ $referenceMonthEnd ?: $referenceMonth }}.
            </p>
        @endif
    </x-filament::section>
</x-filament-panels::page>

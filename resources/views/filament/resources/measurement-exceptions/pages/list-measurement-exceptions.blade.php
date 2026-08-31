<x-filament-panels::page>
    @php
        $result = $this->result();
        $exceptions = $this->exceptions();
    @endphp

    <div class="space-y-6">
        <x-filament::section>
            <x-slot name="heading">Resumo da população filtrada</x-slot>
            <x-slot name="description">
                Cada contagem usa os mesmos filtros e a mesma classificação da lista abaixo.
            </x-slot>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Total</p>
                    <p class="mt-2 text-2xl font-semibold text-gray-950 dark:text-white">{{ $result->total }}</p>
                </div>

                @foreach (\App\Enums\MeasurementOperationalExceptionType::cases() as $type)
                    @php
                        $canDrillDown = $this->canDrillDown($type);
                        $count = $result->counts[$type->value] ?? 0;
                    @endphp

                    @if ($canDrillDown)
                        <button
                            type="button"
                            wire:key="exception-summary-{{ $type->value }}"
                            wire:click="filterByException('{{ $type->value }}')"
                            class="rounded-xl border border-gray-200 bg-white p-4 text-left transition hover:border-primary-400 hover:shadow-sm focus:outline-none focus:ring-2 focus:ring-primary-500/30 dark:border-gray-700 dark:bg-gray-900 dark:hover:border-primary-500"
                        >
                            <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $type->label() }}</p>
                            <p class="mt-2 text-2xl font-semibold text-gray-950 dark:text-white">{{ $count }}</p>
                        </button>
                    @else
                        <div
                            wire:key="exception-summary-{{ $type->value }}"
                            class="rounded-xl border border-gray-200 bg-gray-50 p-4 opacity-60 dark:border-gray-700 dark:bg-gray-950"
                        >
                            <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $type->label() }}</p>
                            <p class="mt-2 text-2xl font-semibold text-gray-950 dark:text-white">0</p>
                        </div>
                    @endif
                @endforeach
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Filtros</x-slot>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                <div class="xl:col-span-2">
                    <label for="exceptionSearch" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Busca</label>
                    <input
                        id="exceptionSearch"
                        type="search"
                        wire:model.live.debounce.400ms="search"
                        class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                        placeholder="Medição, operação ou emissão"
                    >
                </div>

                <div>
                    <label for="exceptionOperation" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Operação</label>
                    <select id="exceptionOperation" wire:model.live="operationId" class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                        <option value="">Todas</option>
                        @foreach ($this->operationOptions() as $id => $label)
                            <option value="{{ $id }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="exceptionEmission" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Emissão</label>
                    <select id="exceptionEmission" wire:model.live="emissionId" class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                        <option value="">Todas</option>
                        @foreach ($this->emissionOptions() as $id => $label)
                            <option value="{{ $id }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="exceptionCompetenceFrom" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Competência inicial</label>
                    <input id="exceptionCompetenceFrom" type="date" wire:model.live="competenceFrom" class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                </div>

                <div>
                    <label for="exceptionCompetenceTo" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Competência final</label>
                    <input id="exceptionCompetenceTo" type="date" wire:model.live="competenceTo" class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                </div>

                <div>
                    <label for="exceptionStage" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Etapa</label>
                    <select id="exceptionStage" wire:model.live="stage" class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                        <option value="">Todas</option>
                        @foreach ($this->stageOptions() as $id => $label)
                            <option value="{{ $id }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="exceptionType" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Tipo de exceção</label>
                    <select id="exceptionType" wire:model.live="exceptionType" class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                        <option value="">Todos</option>
                        @foreach ($this->exceptionTypeOptions() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="mt-4 flex justify-end">
                <x-filament::button color="gray" wire:click="clearFilters">
                    Limpar filtros
                </x-filament::button>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Exceções identificadas</x-slot>
            <x-slot name="description">
                Uma medição aparece uma vez para cada tipo de exceção estrutural atual.
            </x-slot>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    <thead>
                        <tr class="text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            <th class="px-3 py-3">Exceção</th>
                            <th class="px-3 py-3">Operação / Emissão</th>
                            <th class="px-3 py-3">Medição</th>
                            <th class="px-3 py-3">Etapa / Status</th>
                            <th class="px-3 py-3">Responsabilidade</th>
                            <th class="px-3 py-3">Atualização</th>
                            <th class="px-3 py-3 text-right">Navegação</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                        @forelse ($exceptions as $exception)
                            @php
                                $measurementUrl = $this->measurementUrl($exception);
                                $operationUrl = $this->operationUrl($exception);
                                $manageResponsibilitiesUrl = $this->manageResponsibilitiesUrl($exception);
                            @endphp

                            <tr wire:key="measurement-exception-{{ $exception->key() }}" class="align-top">
                                <td class="px-3 py-4">
                                    <x-filament::badge :color="$exception->type->color()">
                                        {{ $exception->type->label() }}
                                    </x-filament::badge>
                                    <p class="mt-2 max-w-sm text-gray-600 dark:text-gray-300">{{ $exception->description() }}</p>
                                    @if ($exception->slaStatusLabel())
                                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Estado SLA: {{ $exception->slaStatusLabel() }}</p>
                                    @endif
                                </td>
                                <td class="px-3 py-4">
                                    <p class="font-medium text-gray-950 dark:text-white">{{ $exception->operationLabel() }}</p>
                                    <p class="mt-1 text-gray-500 dark:text-gray-400">{{ $exception->emissionLabel() }}</p>
                                </td>
                                <td class="px-3 py-4">
                                    <p class="font-medium text-gray-950 dark:text-white">{{ $exception->measurementLabel() }}</p>
                                    <p class="mt-1 text-gray-500 dark:text-gray-400">Competência {{ $exception->competenceLabel() }}</p>
                                </td>
                                <td class="px-3 py-4">
                                    <p class="font-medium text-gray-950 dark:text-white">{{ $exception->stageLabel() }}</p>
                                    <p class="mt-1 text-gray-500 dark:text-gray-400">{{ $exception->statusLabel() }}</p>
                                </td>
                                <td class="px-3 py-4">
                                    <p class="text-gray-950 dark:text-white">{{ $exception->expectedResponsibility?->label() ?? 'Não se aplica' }}</p>
                                    <p class="mt-1 text-gray-500 dark:text-gray-400">
                                        {{ $exception->configuredResponsibleName ?? ($exception->expectedResponsibility ? 'Não configurado ou inativo' : '—') }}
                                    </p>
                                </td>
                                <td class="whitespace-nowrap px-3 py-4 text-gray-600 dark:text-gray-300">
                                    {{ $exception->measurement->updated_at?->format('d/m/Y H:i') ?? '—' }}
                                </td>
                                <td class="px-3 py-4">
                                    <div class="flex flex-col items-end gap-2">
                                        @if ($measurementUrl)
                                            <x-filament::button size="xs" color="gray" tag="a" :href="$measurementUrl">
                                                Ver medição
                                            </x-filament::button>
                                        @endif

                                        @if ($operationUrl)
                                            <x-filament::button size="xs" color="gray" tag="a" :href="$operationUrl">
                                                Ver operação
                                            </x-filament::button>
                                        @endif

                                        @if ($manageResponsibilitiesUrl)
                                            <x-filament::button size="xs" tag="a" :href="$manageResponsibilitiesUrl">
                                                Gerir responsáveis
                                            </x-filament::button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-3 py-10 text-center text-sm text-gray-500 dark:text-gray-400">
                                    Nenhuma exceção operacional corresponde aos filtros atuais.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($exceptions->hasPages())
                <div class="mt-4">
                    {{ $exceptions->links() }}
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>

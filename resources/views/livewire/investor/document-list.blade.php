<div>
    <flux:card class="mb-6 space-y-4">
        <div class="grid grid-cols-1 gap-4 items-end md:grid-cols-3 lg:grid-cols-6">
            <div class="md:col-span-3 lg:col-span-2">
                <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Buscar documento por titulo..." />
            </div>

            <div class="lg:col-span-1">
                <flux:select wire:model.live="category" placeholder="Todas as Categorias" class="w-full">
                    <flux:select.option value="">Todas</flux:select.option>
                    @foreach($categoryOptions as $value => $label)
                        <flux:select.option wire:key="category-{{ $value }}" value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <div class="lg:col-span-1">
                <flux:select wire:model.live="emissionId" placeholder="Todas Emissoes" class="w-full">
                    <flux:select.option value="">Todas</flux:select.option>
                    @foreach($emissions as $em)
                        <flux:select.option wire:key="emission-{{ $em->id }}" value="{{ $em->id }}">{{ $em->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <div class="lg:col-span-1">
                <flux:input type="date" wire:model.live="dateFrom" label="Periodo De" />
            </div>

            <div class="lg:col-span-1">
                <flux:input type="date" wire:model.live="dateTo" label="Periodo Ate" />
            </div>
        </div>

        <div class="flex items-center gap-3 pt-2">
            <flux:checkbox wire:model.live="onlyNew" label="Somente novos desde o ultimo acesso" />
        </div>
    </flux:card>

    @if($documents->isEmpty())
        <flux:card>
            <div class="py-12 text-center text-zinc-500">
                <flux:icon.document-text class="mx-auto mb-3 h-12 w-12 text-zinc-300" />
                <h3 class="text-lg font-medium text-zinc-900 dark:text-zinc-100">Nenhum documento encontrado.</h3>
                <p>Tente ajustar os filtros ou os termos de busca.</p>
            </div>
        </flux:card>
    @else
        <div class="space-y-3">
            @foreach($documents as $doc)
                @php
                    $docDate = $doc->published_at ?? $doc->created_at;
                    $isNew = $docDate > ($investor->last_portal_seen_at ?? '1970-01-01');
                @endphp
                <flux:card wire:key="document-{{ $doc->id }}" class="flex flex-col justify-between gap-4 p-4 transition-all hover:bg-zinc-50 sm:flex-row sm:items-center dark:hover:bg-zinc-800/50">
                    <div class="flex items-start gap-4">
                        <div class="mt-1 flex-shrink-0">
                            <flux:icon.document class="h-8 w-8 text-zinc-400" />
                        </div>
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="text-base font-medium text-zinc-900 dark:text-white">{{ $doc->title }}</h3>
                                @if($isNew)
                                    <flux:badge color="blue" size="sm">Novo</flux:badge>
                                @endif
                            </div>

                            <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-2 text-sm text-zinc-500">
                                <flux:badge size="sm" color="zinc">{{ $doc->category_label ?: 'Documento' }}</flux:badge>

                                <span class="hidden text-zinc-300 sm:inline">&bull;</span>

                                @if($doc->emissions->isNotEmpty())
                                    <span class="flex items-center gap-1">
                                        <flux:icon.building-office-2 class="h-4 w-4 text-zinc-400" />
                                        @if($doc->emissions->count() === 1)
                                            {{ $doc->emissions->first()->name }}
                                        @else
                                            {{ $doc->emissions->count() }} Emissoes
                                        @endif
                                    </span>
                                    <span class="hidden text-zinc-300 sm:inline">&bull;</span>
                                @endif

                                <span class="flex items-center gap-1">
                                    <flux:icon.calendar-days class="h-4 w-4 text-zinc-400" />
                                    {{ $docDate->format('d/m/Y') }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="w-full flex-shrink-0 sm:ml-auto sm:w-auto">
                        <flux:button
                            icon="arrow-down-tray"
                            variant="primary"
                            class="w-full sm:w-auto"
                            as="a"
                            href="{{ route('investor.documents.download', $doc) }}"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            Baixar
                        </flux:button>
                    </div>
                </flux:card>
            @endforeach
        </div>

        <div class="mt-6">
            {{ $documents->links() }}
        </div>
    @endif
</div>

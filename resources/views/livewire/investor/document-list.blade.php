<div>
    <!-- Filtros UX -->
    <flux:card class="mb-6 space-y-4">
        <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-6 gap-4 items-end">
            <!-- Busca -->
            <div class="md:col-span-3 lg:col-span-2">
                <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Buscar documento por título..." />
            </div>

            <!-- Categoria -->
            <div class="lg:col-span-1">
                <flux:select wire:model.live="category" placeholder="Todas as Categorias" class="w-full">
                    <flux:select.option value="">Todas</flux:select.option>
                    @foreach($categories as $cat)
                        <flux:select.option value="{{ $cat }}">{{ $cat }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <!-- Emissão -->
            <div class="lg:col-span-1">
                <flux:select wire:model.live="emissionId" placeholder="Todas Emissões" class="w-full">
                    <flux:select.option value="">Todas</flux:select.option>
                    @foreach($emissions as $em)
                        <flux:select.option value="{{ $em->id }}">{{ $em->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <!-- Período De -->
            <div class="lg:col-span-1">
                <flux:input type="date" wire:model.live="dateFrom" label="Período De" />
            </div>

            <!-- Período Até -->
            <div class="lg:col-span-1">
                <flux:input type="date" wire:model.live="dateTo" label="Período Até" />
            </div>
        </div>

        <div class="flex items-center gap-3 pt-2">
            <flux:checkbox wire:model.live="onlyNew" label="Somente novos desde o último acesso" />
        </div>
    </flux:card>

    <!-- Lista de Documentos -->
    @if($documents->isEmpty())
        <flux:card>
            <div class="py-12 text-center text-zinc-500">
                <flux:icon.document-text class="mx-auto h-12 w-12 text-zinc-300 mb-3" />
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
                <flux:card class="flex flex-col sm:flex-row sm:items-center justify-between p-4 transition-all hover:bg-zinc-50 dark:hover:bg-zinc-800/50 gap-4">
                    <div class="flex items-start gap-4">
                        <div class="flex-shrink-0 mt-1">
                            <flux:icon.document class="h-8 w-8 text-zinc-400" />
                        </div>
                        <div>
                            <div class="flex items-center flex-wrap gap-2">
                                <h3 class="font-medium text-base text-zinc-900 dark:text-white">{{ $doc->title }}</h3>
                                @if($isNew)
                                    <flux:badge color="blue" size="sm">Novo</flux:badge>
                                @endif
                            </div>
                            
                            <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-2 text-sm text-zinc-500">
                                <!-- Categoria -->
                                <flux:badge size="sm" color="zinc">{{ $doc->category ?? 'Documento' }}</flux:badge>
                                
                                <span class="hidden sm:inline text-zinc-300">&bull;</span>
                                
                                <!-- Emissão Vinculada (pega a primeira ou exibe se tiver múltiplas) -->
                                @if($doc->emissions->isNotEmpty())
                                    <span class="flex items-center gap-1">
                                        <flux:icon.building-office-2 class="w-4 h-4 text-zinc-400"/>
                                        @if($doc->emissions->count() === 1)
                                            {{ $doc->emissions->first()->name }}
                                        @else
                                            {{ $doc->emissions->count() }} Emissões
                                        @endif
                                    </span>
                                    <span class="hidden sm:inline text-zinc-300">&bull;</span>
                                @endif

                                <!-- Data de Publicação -->
                                <span class="flex items-center gap-1">
                                    <flux:icon.calendar-days class="w-4 h-4 text-zinc-400"/>
                                    {{ $docDate->format('d/m/Y') }}
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="flex-shrink-0 sm:ml-auto w-full sm:w-auto">
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

        <!-- Paginação -->
        <div class="mt-6">
            {{ $documents->links() }}
        </div>
    @endif
</div>

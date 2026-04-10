@php
    $loadingTargets = 'search,category,emissionId,dateFrom,dateTo,onlyNew,gotoPage,previousPage,nextPage,setPage';
    $firstItem = $documents->firstItem() ?? 0;
    $lastItem = $documents->lastItem() ?? 0;
@endphp

<div class="space-y-6">
    <section class="bsi-shell-card p-6 lg:p-8">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div class="max-w-3xl">
                <div class="bsi-kicker mb-2">Consulta documental</div>
                <h1 class="text-3xl font-semibold tracking-[-0.04em] text-brand-800">Meus documentos</h1>
                <p class="mt-3 text-sm leading-7 text-zinc-600">
                    Acompanhe e baixe os documentos e relatórios relacionados aos seus investimentos com filtros por emissão, categoria e período.
                </p>
            </div>

            <div class="flex flex-wrap gap-3">
                <span class="bsi-portal-meta">
                    <flux:icon.folder class="size-4" />
                    <span>{{ $documents->total() }} documento(s)</span>
                </span>
                @if($hasActiveFilters)
                    <flux:button variant="subtle" type="button" wire:click="resetFilters" wire:loading.attr="disabled" wire:target="{{ $loadingTargets }}" class="!rounded-full !px-5">
                        Limpar filtros
                    </flux:button>
                @endif
            </div>
        </div>
    </section>

    <section class="bsi-shell-card p-6">
        <div class="grid items-end gap-4 md:grid-cols-2 xl:grid-cols-6">
            <div class="xl:col-span-2">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    wire:loading.attr="disabled"
                    wire:target="{{ $loadingTargets }}"
                    icon="magnifying-glass"
                    label="Buscar"
                    placeholder="Buscar documento por título"
                />
            </div>

            <div>
                <flux:select wire:model.live="category" wire:loading.attr="disabled" wire:target="{{ $loadingTargets }}" label="Categoria" placeholder="Todas">
                    <flux:select.option value="">Todas</flux:select.option>
                    @foreach($categoryOptions as $value => $label)
                        <flux:select.option wire:key="category-{{ $value }}" value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <div>
                <flux:select wire:model.live="emissionId" wire:loading.attr="disabled" wire:target="{{ $loadingTargets }}" label="Emissão" placeholder="Todas">
                    <flux:select.option value="">Todas</flux:select.option>
                    @foreach($emissions as $em)
                        <flux:select.option wire:key="emission-{{ $em->id }}" value="{{ $em->id }}">{{ $em->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <div>
                <flux:input type="date" wire:model.live="dateFrom" wire:loading.attr="disabled" wire:target="{{ $loadingTargets }}" label="Período de" />
            </div>

            <div>
                <flux:input type="date" wire:model.live="dateTo" wire:loading.attr="disabled" wire:target="{{ $loadingTargets }}" label="Período até" />
            </div>
        </div>

        <div class="mt-5 flex flex-col gap-4 border-t border-zinc-200/80 pt-5">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <flux:checkbox wire:model.live="onlyNew" wire:loading.attr="disabled" wire:target="{{ $loadingTargets }}" label="Somente novos desde o último acesso" />

                <div class="flex items-center gap-2 rounded-full border border-brand-100 bg-brand-50/80 px-3 py-2 text-sm font-medium text-brand-700">
                    <flux:icon.arrow-path class="size-4 animate-spin" wire:loading.inline-flex wire:target="{{ $loadingTargets }}" />
                    <span wire:loading.remove wire:target="{{ $loadingTargets }}">Resultados atualizados em tempo real</span>
                    <span wire:loading.inline wire:target="{{ $loadingTargets }}">Atualizando resultados</span>
                </div>
            </div>

            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex flex-wrap gap-2">
                    @foreach($activeFilters as $label => $value)
                        <span class="inline-flex items-center gap-2 rounded-full border border-brand-100 bg-white px-3 py-2 text-xs font-semibold text-brand-700">
                            <span class="uppercase tracking-[0.16em] text-zinc-400">{{ $label }}</span>
                            <span class="normal-case tracking-normal text-zinc-700">{{ $value }}</span>
                        </span>
                    @endforeach

                    @if(!$hasActiveFilters)
                        <span class="inline-flex items-center gap-2 rounded-full border border-dashed border-zinc-200 bg-zinc-50/80 px-3 py-2 text-xs font-semibold uppercase tracking-[0.16em] text-zinc-400">
                            Sem filtros ativos
                        </span>
                    @endif
                </div>

                <div class="text-sm text-zinc-500">
                    @if($documents->total() > 0)
                        Exibindo <span class="font-semibold text-zinc-800">{{ $firstItem }}</span> a <span class="font-semibold text-zinc-800">{{ $lastItem }}</span> de <span class="font-semibold text-zinc-800">{{ $documents->total() }}</span> documentos.
                    @else
                        Nenhum documento disponível para o escopo atual.
                    @endif
                </div>
            </div>
        </div>
    </section>

    <div wire:loading.delay.flex wire:target="{{ $loadingTargets }}" class="flex-col gap-4">
        @for ($index = 0; $index < 3; $index++)
            <article class="bsi-shell-card p-5" wire:key="document-skeleton-{{ $index }}">
                <div class="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
                    <div class="flex items-start gap-4">
                        <flux:skeleton class="size-14 rounded-[24px]" />

                        <div class="min-w-0 flex-1 space-y-3">
                            <flux:skeleton class="h-6 w-3/4 max-w-xs" />
                            <div class="flex flex-wrap gap-2">
                                <flux:skeleton class="h-6 w-28 rounded-full" />
                                <flux:skeleton class="h-6 w-36 rounded-full" />
                            </div>
                            <div class="flex flex-wrap gap-3">
                                <flux:skeleton class="h-4 w-28" />
                                <flux:skeleton class="h-4 w-32" />
                            </div>
                        </div>
                    </div>

                    <flux:skeleton class="h-11 w-full rounded-full lg:w-28" />
                </div>
            </article>
        @endfor
    </div>

    <div wire:loading.remove wire:target="{{ $loadingTargets }}" class="space-y-4">
        @if($documents->isEmpty())
            <section class="bsi-shell-card p-10 text-center">
                <span class="mx-auto flex size-16 items-center justify-center rounded-[24px] bg-brand-50 text-brand-700">
                    <flux:icon.document-text class="size-8" />
                </span>
                <h2 class="mt-5 text-2xl font-semibold tracking-[-0.04em] text-brand-800">
                    {{ $hasActiveFilters ? 'Nenhum documento corresponde aos filtros aplicados' : 'Nenhum documento encontrado' }}
                </h2>
                <p class="mx-auto mt-3 max-w-xl text-sm leading-7 text-zinc-600">
                    {{ $hasActiveFilters ? 'Ajuste os filtros ou limpe a busca para ampliar o escopo de consulta.' : 'Quando houver novos arquivos publicados no seu escopo, eles aparecerão aqui com acesso direto para download.' }}
                </p>

                @if($hasActiveFilters)
                    <flux:button variant="primary" type="button" wire:click="resetFilters" class="mt-5 !rounded-full !px-5">
                        Limpar filtros
                    </flux:button>
                @endif
            </section>
        @else
            <section class="space-y-4">
                @foreach($documents as $doc)
                    @php
                        $docDate = $doc->published_at ?? $doc->created_at;
                        $isNew = $docDate > ($previousPortalSeenAt ?? '1970-01-01 00:00:00');
                    @endphp

                    <article class="bsi-shell-card p-5 transition hover:-translate-y-0.5 hover:shadow-[0_24px_48px_rgba(0,32,91,0.12)]" wire:key="document-{{ $doc->id }}">
                        <div class="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
                            <div class="flex items-start gap-4">
                                <span class="flex size-14 flex-shrink-0 items-center justify-center rounded-[24px] bg-brand-50 text-brand-700">
                                    <flux:icon.document class="size-7" />
                                </span>

                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h2 class="text-xl font-semibold tracking-[-0.03em] text-brand-800">{{ $doc->title }}</h2>
                                        @if($isNew)
                                            <span class="inline-flex items-center rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700">
                                                Novo
                                            </span>
                                        @endif
                                    </div>

                                    <div class="mt-3 flex flex-wrap items-center gap-2">
                                        <span class="inline-flex items-center rounded-full bg-gold-400/15 px-3 py-1 text-xs font-semibold text-gold-600">
                                            {{ $doc->category_label ?: 'Documento' }}
                                        </span>

                                        @if($doc->emissions->isNotEmpty())
                                            <span class="inline-flex items-center rounded-full border border-brand-100 bg-brand-50/80 px-3 py-1 text-xs font-semibold text-brand-700">
                                                {{ $doc->emissions->count() === 1 ? $doc->emissions->first()->name : $doc->emissions->count().' emissões' }}
                                            </span>
                                        @endif
                                    </div>

                                    <div class="mt-4 flex flex-wrap gap-x-4 gap-y-2 text-sm text-zinc-500">
                                        <span class="inline-flex items-center gap-1.5">
                                            <flux:icon.calendar-days class="size-4 text-zinc-400" />
                                            {{ $docDate->format('d/m/Y') }}
                                        </span>
                                        <span class="inline-flex items-center gap-1.5">
                                            <flux:icon.shield-check class="size-4 text-zinc-400" />
                                            Documento controlado
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <div class="w-full lg:w-auto">
                                <flux:button
                                    icon="arrow-down-tray"
                                    variant="primary"
                                    class="w-full !rounded-full !px-5 lg:w-auto"
                                    as="a"
                                    href="{{ route('investor.documents.download', $doc) }}"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    Baixar
                                </flux:button>
                            </div>
                        </div>
                    </article>
                @endforeach
            </section>

            @if($documents->hasPages())
                <div class="flex flex-col gap-3 pt-2 sm:flex-row sm:items-center sm:justify-between">
                    <div class="text-sm text-zinc-500">
                        Página <span class="font-semibold text-zinc-800">{{ $documents->currentPage() }}</span> de <span class="font-semibold text-zinc-800">{{ $documents->lastPage() }}</span>.
                    </div>

                    <div>
                        {{ $documents->links() }}
                    </div>
                </div>
            @endif
        @endif
    </div>
</div>

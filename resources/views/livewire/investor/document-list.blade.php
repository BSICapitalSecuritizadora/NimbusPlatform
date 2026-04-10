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
                @if($onlyNew || $search !== '' || $category !== '' || $emissionId !== '' || $dateFrom !== '' || $dateTo !== '')
                    <a href="{{ route('investor.documents') }}" class="inline-flex items-center gap-2 rounded-full border border-brand-100 bg-white px-4 py-2 text-sm font-semibold text-brand-700 transition hover:border-gold-500/50 hover:bg-brand-50">
                        Limpar filtros
                    </a>
                @endif
            </div>
        </div>
    </section>

    <section class="bsi-shell-card p-6">
        <div class="grid items-end gap-4 md:grid-cols-2 xl:grid-cols-6">
            <div class="xl:col-span-2">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    icon="magnifying-glass"
                    label="Buscar"
                    placeholder="Buscar documento por título"
                />
            </div>

            <div>
                <flux:select wire:model.live="category" label="Categoria" placeholder="Todas">
                    <flux:select.option value="">Todas</flux:select.option>
                    @foreach($categoryOptions as $value => $label)
                        <flux:select.option wire:key="category-{{ $value }}" value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <div>
                <flux:select wire:model.live="emissionId" label="Emissão" placeholder="Todas">
                    <flux:select.option value="">Todas</flux:select.option>
                    @foreach($emissions as $em)
                        <flux:select.option wire:key="emission-{{ $em->id }}" value="{{ $em->id }}">{{ $em->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <div>
                <flux:input type="date" wire:model.live="dateFrom" label="Período de" />
            </div>

            <div>
                <flux:input type="date" wire:model.live="dateTo" label="Período até" />
            </div>
        </div>

        <div class="mt-5 flex flex-wrap items-center justify-between gap-3 border-t border-zinc-200/80 pt-5">
            <flux:checkbox wire:model.live="onlyNew" label="Somente novos desde o último acesso" />

            <div class="text-sm text-zinc-500">
                Exibindo <span class="font-semibold text-zinc-800">{{ $documents->count() }}</span> item(ns) nesta página.
            </div>
        </div>
    </section>

    @if($documents->isEmpty())
        <section class="bsi-shell-card p-10 text-center">
            <span class="mx-auto flex size-16 items-center justify-center rounded-[24px] bg-brand-50 text-brand-700">
                <flux:icon.document-text class="size-8" />
            </span>
            <h2 class="mt-5 text-2xl font-semibold tracking-[-0.04em] text-brand-800">Nenhum documento encontrado</h2>
            <p class="mx-auto mt-3 max-w-xl text-sm leading-7 text-zinc-600">
                Tente ajustar os filtros ou alterar os termos de busca para localizar os documentos desejados.
            </p>
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

        <div class="pt-2">
            {{ $documents->links() }}
        </div>
    @endif
</div>

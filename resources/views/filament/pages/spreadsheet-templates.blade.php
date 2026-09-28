<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Faixa Horizontal "Como funciona" --}}
        <section class="overflow-hidden rounded-2xl border border-slate-700/40 bg-gradient-to-br from-[#091b23] to-[#0d252e] p-5 shadow-lg shadow-black/10">
            <div class="mb-3.5 flex items-center gap-2">
                <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedInformationCircle" class="h-5 w-5 text-primary-400" />
                <span class="text-xs font-semibold uppercase tracking-[0.16em] text-primary-300">Como funciona o gerenciamento de templates</span>
            </div>

            <div class="grid gap-3.5 sm:grid-cols-2 lg:grid-cols-4">
                <div class="flex items-start gap-3 rounded-xl border border-slate-800/80 bg-[#07181f]/70 p-3.5">
                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-primary-500/15 text-xs font-bold text-primary-300 ring-1 ring-primary-400/30">1</span>
                    <div class="space-y-0.5">
                        <p class="text-xs font-semibold text-white">Baixar template atual</p>
                        <p class="text-xs leading-relaxed text-slate-400">Baixe o modelo oficial de cada fluxo operacional do sistema.</p>
                    </div>
                </div>

                <div class="flex items-start gap-3 rounded-xl border border-slate-800/80 bg-[#07181f]/70 p-3.5">
                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-primary-500/15 text-xs font-bold text-primary-300 ring-1 ring-primary-400/30">2</span>
                    <div class="space-y-0.5">
                        <p class="text-xs font-semibold text-white">Ajustar planilha</p>
                        <p class="text-xs leading-relaxed text-slate-400">Faça as edições mantendo a estrutura esperada das colunas.</p>
                    </div>
                </div>

                <div class="flex items-start gap-3 rounded-xl border border-slate-800/80 bg-[#07181f]/70 p-3.5">
                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-primary-500/15 text-xs font-bold text-primary-300 ring-1 ring-primary-400/30">3</span>
                    <div class="space-y-0.5">
                        <p class="text-xs font-semibold text-white">Substituir e salvar</p>
                        <p class="text-xs leading-relaxed text-slate-400">Nos modelos personalizáveis, envie o arquivo .xlsx e salve. O novo modelo entra em vigor na hora.</p>
                    </div>
                </div>

                <div class="flex items-start gap-3 rounded-xl border border-slate-800/80 bg-[#07181f]/70 p-3.5">
                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-primary-500/15 text-xs font-bold text-primary-300 ring-1 ring-primary-400/30">4</span>
                    <div class="space-y-0.5">
                        <p class="text-xs font-semibold text-white">Restaurar padrão</p>
                        <p class="text-xs leading-relaxed text-slate-400">Restaure o modelo original do sistema a qualquer momento.</p>
                    </div>
                </div>
            </div>
        </section>

        {{-- Cabeçalho da Seção --}}
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h2 class="text-xl font-bold tracking-tight text-white sm:text-2xl">Templates de planilhas</h2>
                <p class="text-xs text-slate-400 sm:text-sm">
                    Modelos (.xlsx) disponibilizados para download nos fluxos de importação do sistema.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span class="inline-flex items-center rounded-full border border-slate-600/50 bg-[#07181f]/80 px-3 py-1 text-xs font-semibold text-slate-200">
                    {{ $stats['total'] }} {{ $stats['total'] === 1 ? 'template disponível' : 'templates disponíveis' }}
                </span>
                <span class="inline-flex items-center rounded-full border border-amber-400/30 bg-amber-500/15 px-3 py-1 text-xs font-semibold text-amber-100">
                    {{ $stats['customized'] }} {{ $stats['customized'] === 1 ? 'personalizado' : 'personalizados' }}
                </span>
            </div>
        </div>

        {{-- Busca e filtro por categoria --}}
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <div class="relative flex-1">
                <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedMagnifyingGlass" class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500" />
                <input
                    type="search"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Buscar template..."
                    aria-label="Buscar template"
                    class="w-full rounded-xl border border-slate-700/60 bg-[#07181f]/80 py-2.5 pl-10 pr-3.5 text-sm text-slate-200 placeholder:text-slate-500 focus:border-primary-400/60 focus:outline-none focus:ring-2 focus:ring-primary-400/40"
                >
            </div>
            <div class="sm:w-64">
                <select
                    wire:model.live="categoryFilter"
                    aria-label="Filtrar por categoria"
                    class="w-full rounded-xl border border-slate-700/60 bg-[#07181f]/80 px-3.5 py-2.5 text-sm text-slate-200 focus:border-primary-400/60 focus:outline-none focus:ring-2 focus:ring-primary-400/40"
                >
                    @foreach ($categoryOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        {{-- Templates agrupados por categoria, empilhados full-width --}}
        @forelse ($groups as $group)
            <div class="space-y-4">
                <div class="flex items-center gap-3">
                    <h3 class="text-xs font-bold uppercase tracking-[0.2em] text-primary-300">{{ $group['category'] }}</h3>
                    <div class="h-px flex-1 bg-slate-700/40"></div>
                </div>

                <div class="space-y-6">
                    @foreach ($group['cards'] as $card)
                        <x-spreadsheet-template-card :card="$card" wire:key="template-card-{{ $card['key'] }}" />
                    @endforeach
                </div>
            </div>
        @empty
            <div class="rounded-2xl border border-dashed border-slate-700/60 bg-[#07181f]/50 px-6 py-12 text-center">
                <div class="mx-auto flex h-11 w-11 items-center justify-center rounded-xl bg-primary-500/10 text-primary-400 ring-1 ring-primary-400/20">
                    <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedDocumentMagnifyingGlass" class="h-6 w-6" />
                </div>
                <p class="mt-4 text-sm font-semibold text-white">Nenhum template encontrado para os filtros selecionados.</p>
                <p class="mt-1 text-xs text-slate-400">Ajuste a busca ou a categoria para ver outros modelos.</p>
            </div>
        @endforelse
    </div>
</x-filament-panels::page>

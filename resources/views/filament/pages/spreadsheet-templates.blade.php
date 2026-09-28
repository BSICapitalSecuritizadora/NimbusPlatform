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
                        <p class="text-xs leading-relaxed text-slate-400">Baixe o modelo oficial de pagamentos, PU ou integralizações.</p>
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
                        <p class="text-xs leading-relaxed text-slate-400">Envie o arquivo .xlsx e salve. O novo modelo entra em vigor na hora.</p>
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
        <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-xl font-bold tracking-tight text-white sm:text-2xl">Templates de planilhas</h2>
                <p class="text-xs text-slate-400 sm:text-sm">
                    Personalize as planilhas-modelo (.xlsx) disponibilizadas para download nos fluxos operacionais das emissões.
                </p>
            </div>
        </div>

        {{-- Seção Principal / Lista de Templates Empilhados Full-Width (um abaixo do outro) --}}
        <div class="space-y-6">
            @foreach ($this->templateSections() as $template)
                @php
                    $inputId = $template['input_id'];
                    $hintId = $inputId . '-hint';
                    $hasFile = !empty($this->{$template['property']});
                @endphp

                <section
                    wire:key="template-card-{{ $template['key'] }}"
                    class="overflow-hidden rounded-2xl border border-slate-700/40 bg-gradient-to-b from-[#091b23] to-[#0d252e] shadow-xl shadow-black/10 transition hover:border-slate-600/50"
                >
                    {{-- Cabeçalho do Bloco: Identificação, Situação e Download --}}
                    <div class="border-b border-slate-700/40 bg-[#091b23]/80 px-6 py-5 sm:px-8">
                        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                            <div class="space-y-1.5">
                                <div class="flex flex-wrap items-center gap-3">
                                    <h3 id="{{ $inputId }}-title" class="text-lg font-bold tracking-tight text-white sm:text-xl">
                                        {{ $template['title'] }}
                                    </h3>
                                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $template['status_classes'] }}">
                                        {{ $template['status_label'] }}
                                    </span>
                                </div>
                                <p class="text-xs font-medium uppercase tracking-[0.14em] text-primary-300/80">
                                    {{ $template['context'] }}
                                </p>
                                <p class="max-w-3xl text-xs leading-relaxed text-slate-300/90 sm:text-sm">
                                    {{ $template['description'] }}
                                </p>
                            </div>

                            @if ($template['download_url'])
                                <a
                                    href="{{ $template['download_url'] }}"
                                    class="inline-flex shrink-0 items-center justify-center gap-2 rounded-xl border border-slate-600/50 bg-[#07181f]/80 px-4 py-2.5 text-xs font-semibold text-slate-200 shadow-sm transition hover:border-primary-400/50 hover:bg-primary-500/10 hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-400 sm:text-sm"
                                >
                                    <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedArrowDownTray" class="h-4 w-4 text-primary-400" />
                                    <span>Baixar template atual</span>
                                </a>
                            @endif
                        </div>
                    </div>

                    {{-- Corpo: Substituição de Arquivo e Ações --}}
                    <div class="p-6 sm:p-8">
                        <form wire:submit="{{ $template['save_method'] }}" class="space-y-6">
                            <div class="space-y-3">
                                <div class="flex items-center justify-between">
                                    <label for="{{ $inputId }}" class="text-sm font-semibold text-white">
                                        Substituir template
                                    </label>
                                    @if ($hasFile)
                                        <span class="inline-flex items-center gap-1.5 text-xs font-medium text-amber-300">
                                            <span class="h-2 w-2 rounded-full bg-amber-400 animate-pulse"></span>
                                            Alteração pronta para salvar
                                        </span>
                                    @endif
                                </div>

                                {{-- Área de Upload / Dropzone Compacta e Fluida --}}
                                <div class="relative flex flex-col items-center justify-center rounded-xl border-2 border-dashed {{ $hasFile ? 'border-amber-400/50 bg-[#07181f]' : 'border-slate-700/60 bg-[#07181f]/70 hover:border-slate-600 hover:bg-[#07181f]' }} p-6 text-center transition">
                                    @if ($hasFile)
                                        <div class="flex flex-wrap items-center justify-center gap-3">
                                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-amber-500/15 text-amber-400 ring-1 ring-amber-400/30">
                                                <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedDocumentArrowUp" class="h-6 w-6" />
                                            </div>
                                            <div class="text-left">
                                                <p class="text-sm font-semibold text-white">
                                                    {{ is_object($this->{$template['property']}) ? $this->{$template['property']}->getClientOriginalName() : 'Arquivo selecionado' }}
                                                </p>
                                                <p class="text-xs text-slate-400">
                                                    Planilha Excel pronta para envio
                                                </p>
                                            </div>
                                            <button
                                                type="button"
                                                wire:click="$set('{{ $template['property'] }}', null)"
                                                class="ml-2 inline-flex items-center gap-1 rounded-lg border border-slate-700 bg-slate-800/80 px-2.5 py-1.5 text-xs font-medium text-slate-300 transition hover:bg-rose-500/20 hover:text-rose-200 hover:border-rose-500/40"
                                            >
                                                <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedXMark" class="h-3.5 w-3.5" />
                                                <span>Cancelar</span>
                                            </button>
                                        </div>
                                    @else
                                        <div class="space-y-2">
                                            <div class="mx-auto flex h-10 w-10 items-center justify-center rounded-lg bg-primary-500/10 text-primary-400 ring-1 ring-primary-400/20">
                                                <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedArrowUpTray" class="h-5 w-5" />
                                            </div>
                                            <div class="text-xs text-slate-300 sm:text-sm">
                                                <label for="{{ $inputId }}" class="cursor-pointer font-semibold text-primary-400 hover:text-primary-300 hover:underline">
                                                    Clique para escolher o arquivo .xlsx
                                                </label>
                                                <span class="text-slate-400"> ou arraste aqui</span>
                                            </div>
                                            <p id="{{ $hintId }}" class="text-xs text-slate-500">
                                                Formato aceito: .xlsx (Excel). O novo arquivo passa a valer imediatamente nos downloads deste fluxo.
                                            </p>
                                        </div>
                                    @endif

                                    <input
                                        id="{{ $inputId }}"
                                        type="file"
                                        wire:model.live="{{ $template['property'] }}"
                                        accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                                        aria-describedby="{{ $hintId }}"
                                        class="{{ $hasFile ? 'hidden' : 'absolute inset-0 h-full w-full cursor-pointer opacity-0' }}"
                                    >
                                </div>

                                @error($template['property'])
                                    <p class="text-xs font-medium text-rose-400">{{ $message }}</p>
                                @enderror
                            </div>

                            {{-- Barra de Ações: Restaurar Padrão e Salvar Template --}}
                            <div class="flex flex-col-reverse gap-3 border-t border-slate-700/40 pt-5 sm:flex-row sm:items-center sm:justify-end">
                                <button
                                    type="button"
                                    wire:click="{{ $template['restore_method'] }}"
                                    wire:confirm="{{ $template['restore_confirmation'] }}"
                                    wire:loading.attr="disabled"
                                    wire:target="{{ $template['restore_method'] }}"
                                    class="inline-flex items-center justify-center gap-2 rounded-xl border border-slate-700/60 bg-[#07181f]/60 px-4 py-2.5 text-xs font-semibold text-slate-300 transition hover:border-slate-600 hover:bg-slate-800/80 hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-400 disabled:cursor-not-allowed disabled:opacity-50 sm:text-sm"
                                >
                                    <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedArrowPath" class="h-4 w-4 text-slate-400" />
                                    <span>Restaurar padrão</span>
                                </button>

                                <button
                                    type="submit"
                                    wire:loading.attr="disabled"
                                    wire:target="{{ $template['save_method'] }},{{ $template['property'] }}"
                                    @disabled(!$hasFile)
                                    class="inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-b from-[#b7832f] to-[#a06e28] px-5 py-2.5 text-xs font-semibold text-white shadow-md shadow-black/25 transition hover:from-[#c48f38] hover:to-[#ad762c] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 disabled:cursor-not-allowed disabled:opacity-50 sm:text-sm"
                                >
                                    <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedCheck" class="h-4 w-4 text-white" />
                                    <span wire:loading.remove wire:target="{{ $template['save_method'] }}">Salvar template</span>
                                    <span wire:loading wire:target="{{ $template['save_method'] }}">Salvando template...</span>
                                </button>
                            </div>
                        </form>
                    </div>
                </section>
            @endforeach
        </div>
    </div>
</x-filament-panels::page>

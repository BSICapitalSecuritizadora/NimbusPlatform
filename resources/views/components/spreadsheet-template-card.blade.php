@props(['card'])

@php
    $inputId = $card['input_id'];
    $hintId = $inputId . '-hint';
@endphp

<section
    {{ $attributes->merge(['class' => 'block overflow-hidden rounded-2xl border border-slate-700/40 bg-gradient-to-b from-[#091b23] to-[#0d252e] shadow-xl shadow-black/10 transition hover:border-slate-600/50']) }}
>
    {{-- Cabeçalho do Bloco: Identificação, Situação e Download --}}
    <div class="border-b border-slate-700/40 bg-[#091b23]/80 px-6 py-5 sm:px-8">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="space-y-1.5">
                <div class="flex flex-wrap items-center gap-2.5">
                    <h4 id="{{ $inputId }}-title" class="text-lg font-bold tracking-tight text-white sm:text-xl">
                        {{ $card['title'] }}
                    </h4>
                    <span class="inline-flex items-center rounded-full border border-slate-600/50 bg-slate-800/60 px-2.5 py-0.5 text-xs font-semibold text-slate-300">
                        {{ $card['category'] }}
                    </span>
                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $card['status_classes'] }}">
                        {{ $card['status_label'] }}
                    </span>
                </div>
                <p class="text-xs font-medium uppercase tracking-[0.14em] text-primary-300/80">
                    {{ $card['context'] }}
                </p>
                <p class="max-w-3xl text-xs leading-relaxed text-slate-300/90 sm:text-sm">
                    {{ $card['description'] }}
                </p>
                @if ($card['file_name'])
                    <p class="text-xs text-slate-400">
                        Arquivo atual:
                        <span class="font-medium text-slate-200">{{ $card['file_name'] }}</span>
                    </p>
                @endif
                @if ($card['is_custom'] && ($card['customized_at'] || $card['customized_by']))
                    <p class="text-xs text-slate-500">
                        @if ($card['customized_at'])
                            Alterado em {{ $card['customized_at'] }}
                        @endif
                        @if ($card['customized_by'])
                            por {{ $card['customized_by'] }}
                        @endif
                    </p>
                @endif
            </div>

            @if ($card['download_url'])
                <a
                    href="{{ $card['download_url'] }}"
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
        @if ($card['can_replace'])
            <form wire:submit="{{ $card['save_action'] }}" class="space-y-6">
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <label for="{{ $inputId }}" class="text-sm font-semibold text-white">
                            Substituir template
                        </label>
                        @if ($card['has_file'])
                            <span class="inline-flex items-center gap-1.5 text-xs font-medium text-amber-300">
                                <span class="h-2 w-2 rounded-full bg-amber-400 animate-pulse"></span>
                                Alteração pronta para salvar
                            </span>
                        @endif
                    </div>

                    {{-- Área de Upload / Dropzone Compacta e Fluida --}}
                    <div class="relative flex flex-col items-center justify-center rounded-xl border-2 border-dashed {{ $card['has_file'] ? 'border-amber-400/50 bg-[#07181f]' : 'border-slate-700/60 bg-[#07181f]/70 hover:border-slate-600 hover:bg-[#07181f]' }} p-6 text-center transition">
                        @if ($card['has_file'])
                            <div class="flex flex-wrap items-center justify-center gap-3">
                                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-amber-500/15 text-amber-400 ring-1 ring-amber-400/30">
                                    <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedDocumentArrowUp" class="h-6 w-6" />
                                </div>
                                <div class="text-left">
                                    <p class="text-sm font-semibold text-white">
                                        {{ $card['file_display_name'] }}
                                    </p>
                                    <p class="text-xs text-slate-400">
                                        Planilha Excel pronta para envio
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    wire:click="$set('{{ $card['property'] }}', null)"
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
                            wire:model.live="{{ $card['property'] }}"
                            accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                            aria-describedby="{{ $hintId }}"
                            class="{{ $card['has_file'] ? 'hidden' : 'absolute inset-0 h-full w-full cursor-pointer opacity-0' }}"
                        >
                    </div>

                    @error($card['property'])
                        <p class="text-xs font-medium text-rose-400">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Barra de Ações: Restaurar Padrão e Salvar Template --}}
                <div class="flex flex-col-reverse gap-3 border-t border-slate-700/40 pt-5 sm:flex-row sm:items-center sm:justify-end">
                    @if ($card['can_restore'])
                        <button
                            type="button"
                            wire:click="{{ $card['restore_action'] }}"
                            @if ($card['restore_confirmation']) wire:confirm="{{ $card['restore_confirmation'] }}" @endif
                            wire:loading.attr="disabled"
                            wire:target="{{ $card['restore_action'] }}"
                            class="inline-flex items-center justify-center gap-2 rounded-xl border border-slate-700/60 bg-[#07181f]/60 px-4 py-2.5 text-xs font-semibold text-slate-300 transition hover:border-slate-600 hover:bg-slate-800/80 hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-400 disabled:cursor-not-allowed disabled:opacity-50 sm:text-sm"
                        >
                            <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedArrowPath" class="h-4 w-4 text-slate-400" />
                            <span>Restaurar padrão</span>
                        </button>
                    @endif

                    <button
                        type="submit"
                        wire:loading.attr="disabled"
                        wire:target="{{ $card['save_action'] }},{{ $card['property'] }}"
                        @disabled(! $card['has_file'])
                        class="inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-b from-[#b7832f] to-[#a06e28] px-5 py-2.5 text-xs font-semibold text-white shadow-md shadow-black/25 transition hover:from-[#c48f38] hover:to-[#ad762c] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 disabled:cursor-not-allowed disabled:opacity-50 sm:text-sm"
                    >
                        <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedCheck" class="h-4 w-4 text-white" />
                        <span wire:loading.remove wire:target="{{ $card['save_action'] }}">Salvar template</span>
                        <span wire:loading wire:target="{{ $card['save_action'] }}">Salvando template...</span>
                    </button>
                </div>
            </form>
        @elseif ($card['is_dynamic'])
            <div class="flex items-start gap-3 rounded-xl border border-slate-700/60 bg-[#07181f]/70 p-4">
                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary-500/10 text-primary-400 ring-1 ring-primary-400/20">
                    <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedSparkles" class="h-5 w-5" />
                </div>
                <div class="space-y-1">
                    <p class="text-sm font-semibold text-white">Template gerado pelo sistema</p>
                    <p class="text-xs leading-relaxed text-slate-400">Este modelo é construído dinamicamente a partir das colunas atuais do importador e não pode ser substituído manualmente.</p>
                </div>
            </div>
        @endif
    </div>
</section>

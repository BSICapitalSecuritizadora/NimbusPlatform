@props(['card'])

@php
    $inputId = $card['input_id'];
    $hintId = $inputId . '-hint';
@endphp

<section
    {{ $attributes->merge(['class' => 'block overflow-hidden rounded-2xl border border-[var(--border-subtle)] bg-[var(--surface-card)] shadow-sm transition hover:border-[var(--border-strong)]']) }}
>
    {{-- Cabeçalho do Bloco: Identificação, Situação e Download --}}
    <div class="border-b border-[var(--border-subtle)] bg-[var(--surface-ground)]/60 px-6 py-5 sm:px-8">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="space-y-1.5">
                <div class="flex flex-wrap items-center gap-2.5">
                    <h4 id="{{ $inputId }}-title" class="text-lg font-bold tracking-tight text-[var(--text-primary)] sm:text-xl">
                        {{ $card['title'] }}
                    </h4>
                    <span class="inline-flex items-center rounded-full border border-[var(--border-subtle)] bg-[var(--surface-ground)] px-2.5 py-0.5 text-xs font-semibold text-[var(--text-secondary)]">
                        {{ $card['category'] }}
                    </span>
                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $card['status_classes'] }}">
                        {{ $card['status_label'] }}
                    </span>
                </div>
                <p class="text-xs font-medium uppercase tracking-[0.14em] text-[var(--accent)]">
                    {{ $card['context'] }}
                </p>
                <p class="max-w-3xl text-xs leading-relaxed text-[var(--text-secondary)] sm:text-sm">
                    {{ $card['description'] }}
                </p>
                @if ($card['file_name'])
                    <p class="text-xs text-[var(--text-muted)]">
                        Arquivo atual:
                        <span class="font-medium text-[var(--text-primary)]">{{ $card['file_name'] }}</span>
                    </p>
                @endif
                @if ($card['is_custom'] && ($card['customized_at'] || $card['customized_by']))
                    <p class="text-xs text-[var(--text-muted)]">
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
                    class="inline-flex shrink-0 items-center justify-center gap-2 rounded-xl border border-[var(--border-subtle)] bg-[var(--surface-card)] px-4 py-2.5 text-xs font-semibold text-[var(--text-primary)] shadow-sm transition hover:border-[var(--accent)] hover:text-[var(--accent)] hover:bg-[var(--surface-highlight)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accent)] sm:text-sm"
                >
                    <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedArrowDownTray" class="h-4 w-4 text-[var(--accent)]" />
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
                        <label for="{{ $inputId }}" class="text-sm font-semibold text-[var(--text-primary)]">
                            Substituir template
                        </label>
                        @if ($card['has_file'])
                            <span class="inline-flex items-center gap-1.5 text-xs font-medium text-amber-600 dark:text-amber-400">
                                <span class="h-2 w-2 rounded-full bg-amber-500 animate-pulse"></span>
                                Alteração pronta para salvar
                            </span>
                        @endif
                    </div>

                    {{-- Área de Upload / Dropzone Compacta e Fluida --}}
                    <div class="relative flex flex-col items-center justify-center rounded-xl border-2 border-dashed {{ $card['has_file'] ? 'border-amber-500/50 bg-amber-500/5' : 'border-[var(--border-subtle)] bg-[var(--surface-ground)] hover:border-[var(--accent)] hover:bg-[var(--surface-highlight)]/40' }} p-6 text-center transition">
                        @if ($card['has_file'])
                            <div class="flex flex-wrap items-center justify-center gap-3">
                                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-amber-500/15 text-amber-600 dark:text-amber-400 ring-1 ring-amber-500/30">
                                    <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedDocumentArrowUp" class="h-6 w-6" />
                                </div>
                                <div class="text-left">
                                    <p class="text-sm font-semibold text-[var(--text-primary)]">
                                        {{ $card['file_display_name'] }}
                                    </p>
                                    <p class="text-xs text-[var(--text-muted)]">
                                        Planilha Excel pronta para envio
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    wire:click="$set('{{ $card['property'] }}', null)"
                                    class="ml-2 inline-flex items-center gap-1 rounded-lg border border-[var(--border-subtle)] bg-[var(--surface-card)] px-2.5 py-1.5 text-xs font-medium text-[var(--text-secondary)] transition hover:bg-rose-500/15 hover:text-rose-600 dark:hover:text-rose-300 hover:border-rose-500/40"
                                >
                                    <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedXMark" class="h-3.5 w-3.5" />
                                    <span>Cancelar</span>
                                </button>
                            </div>
                        @else
                            <div class="space-y-2">
                                <div class="mx-auto flex h-10 w-10 items-center justify-center rounded-lg bg-[var(--accent-subtle)] text-[var(--accent)] ring-1 ring-[var(--accent)]/20">
                                    <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedArrowUpTray" class="h-5 w-5" />
                                </div>
                                <div class="text-xs text-[var(--text-secondary)] sm:text-sm">
                                    <label for="{{ $inputId }}" class="cursor-pointer font-semibold text-[var(--accent)] hover:underline">
                                        Clique para escolher o arquivo .xlsx
                                    </label>
                                    <span class="text-[var(--text-muted)]"> ou arraste aqui</span>
                                </div>
                                <p id="{{ $hintId }}" class="text-xs text-[var(--text-muted)]">
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
                        <p class="text-xs font-medium text-rose-500">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Barra de Ações: Restaurar Padrão e Salvar Template --}}
                <div class="flex flex-col-reverse gap-3 border-t border-[var(--border-subtle)] pt-5 sm:flex-row sm:items-center sm:justify-end">
                    @if ($card['can_restore'])
                        <button
                            type="button"
                            wire:click="{{ $card['restore_action'] }}"
                            @if ($card['restore_confirmation']) wire:confirm="{{ $card['restore_confirmation'] }}" @endif
                            wire:loading.attr="disabled"
                            wire:target="{{ $card['restore_action'] }}"
                            class="inline-flex items-center justify-center gap-2 rounded-xl border border-[var(--border-subtle)] bg-[var(--surface-card)] px-4 py-2.5 text-xs font-semibold text-[var(--text-secondary)] transition hover:border-[var(--border-strong)] hover:bg-[var(--surface-highlight)] hover:text-[var(--text-primary)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accent)] disabled:cursor-not-allowed disabled:opacity-50 sm:text-sm"
                        >
                            <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedArrowPath" class="h-4 w-4 text-[var(--text-muted)]" />
                            <span>Restaurar padrão</span>
                        </button>
                    @endif

                    <button
                        type="submit"
                        wire:loading.attr="disabled"
                        wire:target="{{ $card['save_action'] }},{{ $card['property'] }}"
                        @disabled(! $card['has_file'])
                        class="inline-flex items-center justify-center gap-2 rounded-xl bg-[var(--accent)] px-5 py-2.5 text-xs font-semibold text-[var(--accent-foreground)] shadow-sm transition hover:bg-[var(--accent-hover)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accent)] disabled:cursor-not-allowed disabled:opacity-50 sm:text-sm"
                    >
                        <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedCheck" class="h-4 w-4 text-[var(--accent-foreground)]" />
                        <span wire:loading.remove wire:target="{{ $card['save_action'] }}">Salvar template</span>
                        <span wire:loading wire:target="{{ $card['save_action'] }}">Salvando template...</span>
                    </button>
                </div>
            </form>
        @elseif ($card['is_dynamic'])
            <div class="flex items-start gap-3 rounded-xl border border-[var(--border-subtle)] bg-[var(--surface-ground)] p-4">
                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-[var(--accent-subtle)] text-[var(--accent)] ring-1 ring-[var(--accent)]/20">
                    <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedSparkles" class="h-5 w-5" />
                </div>
                <div class="space-y-1">
                    <p class="text-sm font-semibold text-[var(--text-primary)]">Template gerado pelo sistema</p>
                    <p class="text-xs leading-relaxed text-[var(--text-secondary)]">Este modelo é construído dinamicamente a partir das colunas atuais do importador e não pode ser substituído manualmente.</p>
                </div>
            </div>
        @endif
    </div>
</section>

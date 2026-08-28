<x-filament-panels::page>
    @php
        $microsoftConnection = $this->getMicrosoftConnectionSummary();
    @endphp

    <form wire:submit="save" class="space-y-6">
        <div class="grid gap-6 lg:grid-cols-12 xl:gap-8">
            {{-- Painel Principal: Preferências de Notificações (70-75% no desktop) --}}
            <div class="lg:col-span-8 xl:col-span-8 space-y-6">
                <section class="rounded-2xl border border-slate-700/40 bg-[#0d252e]/90 shadow-xl backdrop-blur-sm overflow-hidden">
                    {{-- Header da Seção --}}
                    <div class="border-b border-slate-700/40 px-5 py-4 sm:px-6 sm:py-5 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary-500/10 text-primary-400 ring-1 ring-primary-500/20">
                                <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedBell" class="h-5 w-5" />
                            </div>
                            <div>
                                <h2 class="text-base sm:text-lg font-semibold text-[#fbfaf8]">Notificações do portal</h2>
                                <p class="text-xs sm:text-sm text-slate-400">Gerencie quando e como os usuários recebem comunicados automáticos por e-mail.</p>
                            </div>
                        </div>

                        {{-- Indicador de status de autosave/salvamento --}}
                        <div class="hidden sm:flex items-center gap-2 text-xs text-slate-400">
                            <span wire:loading.remove wire:target="save" class="inline-flex items-center gap-1.5 text-slate-400">
                                <span class="h-2 w-2 rounded-full bg-emerald-400/80"></span>
                                Preferências ativas
                            </span>
                            <span wire:loading wire:target="save" class="inline-flex items-center gap-1.5 text-amber-300">
                                <span class="h-2 w-2 rounded-full bg-amber-400 animate-pulse"></span>
                                Salvando alterações...
                            </span>
                        </div>
                    </div>

                    {{-- Lista de Notificações em Linhas com Divisores Sutis (sem cards aninhados) --}}
                    <div class="divide-y divide-slate-700/30">
                        @foreach ($this->notificationOptions() as $option)
                            @php
                                $toggleId = 'notification-' . $option['state_path'];
                                $toggleLabelId = $toggleId . '-label';
                                $toggleDescriptionId = $toggleId . '-description';
                                $isMandatory = ! empty($option['disabled']);
                            @endphp
                            <div
                                wire:key="notification-option-{{ $option['state_path'] }}"
                                class="group flex flex-col sm:flex-row sm:items-center justify-between gap-4 px-5 py-4 sm:px-6 sm:py-4.5 transition-colors hover:bg-white/[0.02]"
                            >
                                <div class="flex items-start gap-3.5 min-w-0 pr-2">
                                    {{-- Ícone Compacto Padronizado com Accent Semântico Moderado --}}
                                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-slate-800/80 ring-1 ring-slate-700/50 mt-0.5 {{ $option['icon_color'] }}">
                                        <x-filament::icon :icon="$option['icon']" class="h-5 w-5" />
                                    </div>

                                    <div class="space-y-0.5 min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <h3 id="{{ $toggleLabelId }}" class="text-sm font-medium text-[#fbfaf8] leading-tight">
                                                {{ $option['title'] }}
                                            </h3>

                                            @if (! empty($option['badge']))
                                                <span class="inline-flex items-center gap-1 rounded-md bg-amber-500/15 px-2 py-0.5 text-xs font-medium text-amber-300 ring-1 ring-amber-400/30">
                                                    <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedLockClosed" class="h-3 w-3" />
                                                    {{ $option['badge'] }}
                                                </span>
                                            @endif
                                        </div>

                                        <p id="{{ $toggleDescriptionId }}" class="text-xs sm:text-sm text-slate-400 leading-normal">
                                            {{ $option['description'] }}
                                        </p>

                                        @if ($isMandatory)
                                            <p class="text-[11px] text-amber-300/80 font-mono mt-0.5 flex items-center gap-1">
                                                <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedInformationCircle" class="h-3.5 w-3.5" />
                                                <span>Esta notificação é obrigatória por segurança e não pode ser desativada.</span>
                                            </p>
                                        @endif
                                    </div>
                                </div>

                                {{-- Switch Alinhado à Direita --}}
                                <div class="flex items-center self-end sm:self-center shrink-0 pl-2">
                                    <label
                                        for="{{ $toggleId }}"
                                        class="relative inline-flex items-center cursor-pointer select-none {{ $isMandatory ? 'cursor-not-allowed opacity-90' : '' }}"
                                        @if ($isMandatory) title="Notificação obrigatória por segurança" @endif
                                    >
                                        <input
                                            id="{{ $toggleId }}"
                                            type="checkbox"
                                            class="peer sr-only"
                                            wire:model.live="data.{{ $option['state_path'] }}"
                                            aria-labelledby="{{ $toggleLabelId }}"
                                            aria-describedby="{{ $toggleDescriptionId }}"
                                            @if ($isMandatory) disabled @endif
                                        >

                                        <span class="h-6 w-11 rounded-full bg-slate-700/70 transition-colors duration-200 ease-in-out peer-checked:bg-[#b7832f] peer-focus-visible:ring-2 peer-focus-visible:ring-[#d49e47]/60 {{ $isMandatory ? 'peer-checked:bg-[#b7832f]/80' : '' }}"></span>
                                        <span class="pointer-events-none absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow-md transition-transform duration-200 ease-in-out peer-checked:translate-x-5 flex items-center justify-center">
                                            @if ($isMandatory)
                                                <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedLockClosed" class="h-2.5 w-2.5 text-slate-700" />
                                            @endif
                                        </span>
                                    </label>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    {{-- Rodapé de Ações do Formulário --}}
                    <div class="border-t border-slate-700/40 px-5 py-4 sm:px-6 sm:py-4.5 bg-[#091b23]/50 flex flex-col sm:flex-row items-center justify-between gap-3">
                        <p class="text-xs text-slate-400 text-center sm:text-left">
                            As notificações desmarcadas não gerarão envios transacionais aos clientes.
                        </p>

                        <div class="flex items-center gap-3 w-full sm:w-auto">
                            <button
                                type="submit"
                                class="inline-flex w-full sm:w-auto items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-[#b7832f] to-[#d49e47] px-5 py-2.5 text-sm font-semibold text-[#06151c] shadow-md shadow-[#b7832f]/20 transition-all hover:brightness-110 active:scale-[0.98] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#d49e47]/60 disabled:opacity-60"
                                wire:loading.attr="disabled"
                                wire:target="save"
                            >
                                <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedCheck" class="h-4 w-4" />
                                <span>Salvar configurações</span>
                            </button>

                            <p class="sr-only" role="status" aria-live="polite" aria-atomic="true">
                                <span wire:loading wire:target="save">Salvando configurações.</span>
                            </p>
                        </div>
                    </div>
                </section>
            </div>

            {{-- Sidebar: Infraestrutura e Diagnóstico (25-30% no desktop) --}}
            <div class="lg:col-span-4 xl:col-span-4 space-y-5">
                {{-- Card de Canal Microsoft 365 / Outlook --}}
                <section class="rounded-2xl border border-slate-700/40 bg-[#0d252e]/90 shadow-xl backdrop-blur-sm overflow-hidden">
                    <div class="border-b border-slate-700/40 px-5 py-4 flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedServerStack" class="h-4 w-4 text-slate-400" />
                            <span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-400">Infraestrutura</span>
                        </div>

                        <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium {{ $microsoftConnection['status_classes'] }}">
                            @if ($microsoftConnection['is_connected'])
                                <span class="h-1.5 w-1.5 rounded-full bg-emerald-400"></span>
                            @elseif ($microsoftConnection['is_partial'])
                                <span class="h-1.5 w-1.5 rounded-full bg-amber-400"></span>
                            @else
                                <span class="h-1.5 w-1.5 rounded-full bg-slate-400"></span>
                            @endif
                            {{ $microsoftConnection['status_label'] }}
                        </span>
                    </div>

                    <div class="p-5 space-y-4">
                        {{-- Identificação do Canal --}}
                        <div class="flex items-start gap-3">
                            <div class="grid h-8 w-8 shrink-0 grid-cols-2 gap-0.5 rounded-lg bg-slate-900 p-1.5 ring-1 ring-slate-700/60 mt-0.5">
                                <span class="rounded-sm bg-[#f25022]"></span>
                                <span class="rounded-sm bg-[#7fba00]"></span>
                                <span class="rounded-sm bg-[#00a4ef]"></span>
                                <span class="rounded-sm bg-[#ffb900]"></span>
                            </div>

                            <div class="space-y-0.5">
                                <h3 class="text-sm font-semibold text-[#fbfaf8] leading-tight">Microsoft 365 / Outlook</h3>
                                <p class="text-xs text-slate-400">Canal corporativo para envios transacionais</p>
                            </div>
                        </div>

                        <p class="text-xs text-slate-400 leading-relaxed">
                            {{ $microsoftConnection['description'] }}
                        </p>

                        {{-- Lista Escaneável de Pendências --}}
                        @if ($microsoftConnection['missing_labels'] !== [])
                            <div class="rounded-xl border border-amber-400/20 bg-amber-500/10 p-3 text-xs space-y-2">
                                <div class="flex items-center justify-between text-amber-200 font-medium">
                                    <span class="flex items-center gap-1.5">
                                        <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedExclamationTriangle" class="h-4 w-4 text-amber-400" />
                                        Configuração pendente
                                    </span>
                                    <span class="text-[11px] font-mono text-amber-300/80">{{ count($microsoftConnection['missing_labels']) }} pendente(s)</span>
                                </div>
                                <div class="grid grid-cols-2 gap-1.5 pt-0.5">
                                    @foreach ($microsoftConnection['missing_labels'] as $label)
                                        <div class="flex items-center gap-1.5 text-slate-300 text-[11px] bg-slate-900/50 rounded-md px-2 py-1">
                                            <span class="h-1.5 w-1.5 rounded-full bg-amber-400 shrink-0"></span>
                                            <span class="truncate">{{ $label }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        {{-- CTA de Conexão --}}
                        <button
                            type="button"
                            class="inline-flex w-full items-center justify-center gap-2 rounded-xl px-4 py-2.5 text-xs font-semibold shadow-md transition-all focus-visible:outline-none focus-visible:ring-2 disabled:cursor-not-allowed disabled:opacity-60 {{ $microsoftConnection['action_classes'] }}"
                            wire:click="connectMicrosoftCorporateAccount"
                            wire:loading.attr="disabled"
                            wire:target="connectMicrosoftCorporateAccount"
                        >
                            <x-filament::icon :icon="$microsoftConnection['action_icon']" class="h-4 w-4" />
                            <span>{{ $microsoftConnection['action_label'] }}</span>
                        </button>
                    </div>
                </section>

                {{-- Card Secundário: Fila e Logs / Auditoria --}}
                <section class="rounded-2xl border border-slate-700/40 bg-[#0d252e]/90 shadow-xl backdrop-blur-sm overflow-hidden">
                    <div class="border-b border-slate-700/40 px-5 py-3.5 flex items-center gap-2">
                        <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedInboxStack" class="h-4 w-4 text-primary-400" />
                        <span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-400">Fila e logs</span>
                    </div>

                    <div class="p-5 space-y-3.5">
                        <p class="text-xs text-slate-400 leading-relaxed">
                            Monitore em tempo real as tentativas e o status de entrega dos e-mails transacionais enviados pelo sistema.
                        </p>

                        <a
                            href="{{ $this->getNotificationOutboxUrl() }}"
                            class="inline-flex w-full items-center justify-center gap-2 rounded-xl border border-slate-600/50 bg-slate-800/60 px-4 py-2 text-xs font-medium text-[#fbfaf8] transition hover:border-primary-400/50 hover:bg-primary-500/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-300/60"
                        >
                            <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedArrowTopRightOnSquare" class="h-3.5 w-3.5 text-primary-400" />
                            <span>Ver auditoria de envios</span>
                        </a>
                    </div>
                </section>
            </div>
        </div>
    </form>
</x-filament-panels::page>

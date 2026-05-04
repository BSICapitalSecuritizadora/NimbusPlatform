<x-filament-panels::page>
    @php
        $microsoftConnection = $this->getMicrosoftConnectionSummary();
    @endphp

    <form wire:submit="save" class="space-y-6">
        <div class="grid gap-6 xl:grid-cols-12">
            <section class="overflow-hidden rounded-3xl border border-white/10 bg-gray-950/70 shadow-2xl shadow-black/20 xl:col-span-8">
                <div class="border-b border-white/10 px-6 py-6 sm:px-8">
                    <div class="flex items-start gap-4">
                        <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-primary-500/15 text-primary-300 ring-1 ring-primary-400/20">
                            <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedBellAlert" class="h-7 w-7" />
                        </div>

                        <div class="space-y-1">
                            <h2 class="text-xl font-semibold text-white sm:text-2xl">Notificações do portal</h2>
                            <p class="max-w-2xl text-sm leading-6 text-gray-400">
                                Gerencie quando e como os usuários recebem as notificações do Gestão Documental Externa.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="space-y-4 px-6 py-6 sm:px-8">
                    @foreach ($this->notificationOptions() as $option)
                        <div
                            wire:key="notification-option-{{ $option['state_path'] }}"
                            class="flex flex-col gap-5 rounded-2xl border border-white/10 bg-white/[0.03] p-5 shadow-lg shadow-black/5 sm:flex-row sm:items-center sm:justify-between"
                        >
                            <div class="flex items-start gap-4">
                                <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl {{ $option['icon_background'] }} {{ $option['icon_color'] }}">
                                    <x-filament::icon :icon="$option['icon']" class="h-5 w-5" />
                                </div>

                                <div class="space-y-1">
                                    <h3 class="text-sm font-semibold text-white sm:text-base">{{ $option['title'] }}</h3>
                                    <p class="max-w-xl text-sm leading-6 text-gray-400">{{ $option['description'] }}</p>
                                </div>
                            </div>

                            <label class="relative inline-flex cursor-pointer items-center self-end sm:self-center">
                                <input
                                    type="checkbox"
                                    class="peer sr-only"
                                    wire:model.live="data.{{ $option['state_path'] }}"
                                >

                                <span class="h-7 w-12 rounded-full bg-white/10 transition peer-checked:bg-primary-500 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-primary-300/60"></span>
                                <span class="pointer-events-none absolute left-1 top-1 h-5 w-5 rounded-full bg-white shadow-lg shadow-black/20 transition peer-checked:translate-x-5"></span>
                            </label>
                        </div>
                    @endforeach

                    <div class="flex justify-end border-t border-white/10 pt-6">
                        <button
                            type="submit"
                            class="inline-flex min-w-60 items-center justify-center gap-2 rounded-2xl bg-primary-500 px-5 py-3 text-sm font-semibold text-gray-950 shadow-lg shadow-primary-500/20 transition hover:bg-primary-400 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-300/60 disabled:cursor-not-allowed disabled:opacity-60"
                            wire:loading.attr="disabled"
                            wire:target="save"
                        >
                            <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedCheck" class="h-5 w-5" />
                            <span>Salvar configurações</span>
                        </button>
                    </div>
                </div>
            </section>

            <div class="space-y-6 xl:col-span-4">
                <section class="overflow-hidden rounded-3xl border border-white/10 bg-gray-950/70 shadow-2xl shadow-black/20">
                    <div class="border-b border-white/10 px-6 py-5">
                        <div class="flex items-center gap-2">
                            <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedInformationCircle" class="h-5 w-5 text-gray-400" />
                            <span class="text-xs font-semibold uppercase tracking-[0.22em] text-gray-400">Infraestrutura</span>
                        </div>
                    </div>

                    <div class="space-y-5 px-6 py-6">
                        <div class="flex items-start gap-4">
                            <div class="grid h-14 w-14 shrink-0 grid-cols-2 gap-1 rounded-2xl bg-slate-950/60 p-2 ring-1 ring-white/10">
                                <span class="rounded-sm bg-[#f25022]"></span>
                                <span class="rounded-sm bg-[#7fba00]"></span>
                                <span class="rounded-sm bg-[#00a4ef]"></span>
                                <span class="rounded-sm bg-[#ffb900]"></span>
                            </div>

                            <div class="space-y-2">
                                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-500">Conta corporativa</p>
                                <h3 class="text-lg font-semibold text-white">Microsoft 365 / Outlook</h3>

                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold {{ $microsoftConnection['status_classes'] }}">
                                        {{ $microsoftConnection['status_label'] }}
                                    </span>
                                    <span class="text-xs text-gray-500">Canal corporativo para envios transacionais</span>
                                </div>
                            </div>
                        </div>

                        <p class="text-sm leading-6 text-gray-400">
                            {{ $microsoftConnection['description'] }}
                        </p>

                        @if ($microsoftConnection['missing_labels'] !== [])
                            <div class="rounded-2xl border border-amber-400/20 bg-amber-500/10 px-4 py-3 text-xs leading-5 text-amber-100/90">
                                Pendências: {{ implode(', ', $microsoftConnection['missing_labels']) }}.
                            </div>
                        @endif

                        <button
                            type="button"
                            class="inline-flex w-full items-center justify-center gap-2 rounded-2xl px-5 py-3 text-sm font-semibold shadow-lg transition focus-visible:outline-none focus-visible:ring-2 disabled:cursor-not-allowed disabled:opacity-60 {{ $microsoftConnection['action_classes'] }}"
                            wire:click="connectMicrosoftCorporateAccount"
                            wire:loading.attr="disabled"
                            wire:target="connectMicrosoftCorporateAccount"
                        >
                            <x-filament::icon :icon="$microsoftConnection['action_icon']" class="h-5 w-5" />
                            <span>{{ $microsoftConnection['action_label'] }}</span>
                        </button>
                    </div>
                </section>

                <section class="overflow-hidden rounded-3xl border border-white/10 bg-gray-950/70 shadow-2xl shadow-black/20">
                    <div class="border-b border-white/10 px-6 py-5">
                        <div class="flex items-center gap-2">
                            <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedInboxStack" class="h-5 w-5 text-rose-300" />
                            <span class="text-lg font-semibold text-white">Fila e logs</span>
                        </div>
                    </div>

                    <div class="space-y-5 px-6 py-6">
                        <p class="text-sm leading-6 text-gray-400">
                            Monitore em tempo real o envio das mensagens. Caso algum e-mail falhe, você poderá reprocessá-lo na auditoria.
                        </p>

                        <a
                            href="{{ $this->getNotificationOutboxUrl() }}"
                            class="inline-flex w-full items-center justify-center gap-2 rounded-2xl border border-white/15 bg-white/[0.03] px-5 py-3 text-sm font-semibold text-white transition hover:border-primary-400/40 hover:bg-primary-500/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-300/60"
                        >
                            <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedArrowUpOnSquare" class="h-5 w-5 text-primary-300" />
                            <span>Ver auditoria de envios</span>
                        </a>
                    </div>
                </section>
            </div>
        </div>
    </form>
</x-filament-panels::page>

<x-filament-panels::page>
    <div class="grid gap-6 xl:grid-cols-12">
        <section class="overflow-hidden rounded-3xl border border-white/10 bg-gray-950/70 shadow-2xl shadow-black/20 xl:col-span-8">
            <div class="border-b border-white/10 px-6 py-6 sm:px-8">
                <div class="flex items-start gap-4">
                    <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-primary-500/15 text-primary-300 ring-1 ring-primary-400/20">
                        <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedArrowDownTray" class="h-7 w-7" />
                    </div>

                    <div class="space-y-1">
                        <h2 class="text-xl font-semibold text-white sm:text-2xl">Template do fluxo de pagamentos</h2>
                        <p class="max-w-2xl text-sm leading-6 text-gray-400">
                            Baixe o modelo oficial da planilha e, quando precisar, substitua o arquivo usado pelo sistema sem editar código.
                        </p>
                    </div>
                </div>
            </div>

            <div class="space-y-6 px-6 py-6 sm:px-8">
                <div class="rounded-2xl border border-white/10 bg-white/[0.03] p-5">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                        <div class="space-y-3">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold {{ $this->getPaymentTemplateStatusClasses() }}">
                                    {{ $this->getPaymentTemplateStatusLabel() }}
                                </span>
                                <span class="text-xs text-gray-500">Arquivo disponibilizado no fluxo de pagamentos</span>
                            </div>

                            <p class="text-sm leading-6 text-gray-400">
                                {{ $this->getPaymentTemplateDescription() }}
                            </p>
                        </div>

                        @if ($this->hasPaymentTemplate())
                            <a
                                href="{{ $this->getPaymentTemplateDownloadUrl() }}"
                                class="inline-flex items-center justify-center gap-2 rounded-2xl bg-primary-500 px-5 py-3 text-sm font-semibold text-gray-950 shadow-lg shadow-primary-500/20 transition hover:bg-primary-400 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-300/60"
                            >
                                <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedArrowDownTray" class="h-5 w-5" />
                                <span>Baixar template atual</span>
                            </a>
                        @endif
                    </div>
                </div>

                <form wire:submit="savePaymentTemplate" class="space-y-5">
                    <div class="space-y-2">
                        <label for="payment-template-file" class="text-sm font-semibold text-white">Substituir template</label>
                        <input
                            id="payment-template-file"
                            type="file"
                            wire:model="paymentTemplateFile"
                            accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                            class="block w-full rounded-2xl border border-white/10 bg-white/[0.03] px-4 py-3 text-sm text-gray-200 file:mr-4 file:rounded-xl file:border-0 file:bg-primary-500 file:px-4 file:py-2 file:font-semibold file:text-gray-950 hover:file:bg-primary-400 focus:border-primary-400/40 focus:outline-none focus:ring-2 focus:ring-primary-300/40"
                        >
                        <p class="text-sm text-gray-500">
                            Envie uma nova planilha `.xlsx` para substituir o arquivo padrão.
                        </p>
                        @error('paymentTemplateFile')
                            <p class="text-sm font-medium text-rose-300">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="flex flex-col gap-3 sm:flex-row sm:justify-end">
                        <button
                            type="button"
                            wire:click="restoreDefaultPaymentTemplate"
                            wire:loading.attr="disabled"
                            wire:target="restoreDefaultPaymentTemplate"
                            class="inline-flex items-center justify-center gap-2 rounded-2xl border border-white/15 bg-white/[0.03] px-5 py-3 text-sm font-semibold text-white transition hover:border-primary-400/40 hover:bg-primary-500/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-300/60 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedArrowPath" class="h-5 w-5 text-primary-300" />
                            <span>Restaurar padrão</span>
                        </button>

                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            wire:target="savePaymentTemplate,paymentTemplateFile"
                            class="inline-flex items-center justify-center gap-2 rounded-2xl bg-primary-500 px-5 py-3 text-sm font-semibold text-gray-950 shadow-lg shadow-primary-500/20 transition hover:bg-primary-400 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-300/60 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedCheck" class="h-5 w-5" />
                            <span>Salvar template</span>
                        </button>
                    </div>
                </form>
            </div>
        </section>

        <section class="overflow-hidden rounded-3xl border border-white/10 bg-gray-950/70 shadow-2xl shadow-black/20 xl:col-span-4">
            <div class="border-b border-white/10 px-6 py-5">
                <div class="flex items-center gap-2">
                    <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedInformationCircle" class="h-5 w-5 text-gray-400" />
                    <span class="text-lg font-semibold text-white">Como funciona</span>
                </div>
            </div>

            <div class="space-y-4 px-6 py-6 text-sm leading-6 text-gray-400">
                <p>
                    O botão <span class="font-semibold text-white">Baixar Template</span> foi adicionado diretamente no fluxo de pagamentos da emissão.
                </p>
                <p>
                    Se você enviar um novo arquivo aqui, ele passa a ser o template oficial usado pelo botão de download.
                </p>
                <p>
                    Se quiser voltar para o arquivo original do sistema, use <span class="font-semibold text-white">Restaurar padrão</span>.
                </p>
            </div>
        </section>
    </div>
</x-filament-panels::page>

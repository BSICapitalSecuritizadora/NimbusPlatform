<x-filament-panels::page>
    <div class="grid gap-6 xl:grid-cols-12">
        <section class="overflow-hidden rounded-3xl border border-white/10 bg-gray-950/70 shadow-2xl shadow-black/20 xl:col-span-8">
            <div class="border-b border-white/10 px-6 py-6 sm:px-8">
                <div class="flex items-start gap-4">
                    <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-primary-500/15 text-primary-300 ring-1 ring-primary-400/20">
                        <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedTableCells" class="h-7 w-7" />
                    </div>

                    <div class="space-y-1">
                        <h2 class="text-xl font-semibold text-white sm:text-2xl">Templates de planilhas</h2>
                        <p class="max-w-2xl text-sm leading-6 text-gray-400">
                            Planilhas-modelo (.xlsx) disponibilizadas para download nos fluxos operacionais da emissão. Substitua um template apenas quando o padrão do sistema precisar de ajustes.
                        </p>
                    </div>
                </div>
            </div>

            <div class="space-y-4 px-6 py-6 sm:px-8">
                @foreach ($this->templateSections() as $template)
                    @php
                        $inputId = $template['input_id'];
                        $hintId = $inputId . '-hint';
                    @endphp

                    <div
                        wire:key="template-section-{{ $template['key'] }}"
                        class="space-y-5 rounded-2xl border border-white/10 bg-white/[0.03] p-5 shadow-lg shadow-black/5"
                    >
                        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                            <div class="space-y-2">
                                <div class="flex flex-wrap items-center gap-3">
                                    <h3 id="{{ $inputId }}-title" class="text-base font-semibold text-white">{{ $template['title'] }}</h3>
                                    <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold {{ $template['status_classes'] }}">
                                        {{ $template['status_label'] }}
                                    </span>
                                </div>

                                <p class="text-xs font-medium uppercase tracking-[0.14em] text-gray-500">{{ $template['context'] }}</p>

                                <p class="max-w-xl text-sm leading-6 text-gray-400">{{ $template['description'] }}</p>
                            </div>

                            @if ($template['download_url'])
                                <a
                                    href="{{ $template['download_url'] }}"
                                    class="inline-flex shrink-0 items-center justify-center gap-2 rounded-2xl border border-white/15 bg-white/[0.03] px-5 py-3 text-sm font-semibold text-white transition hover:border-primary-400/40 hover:bg-primary-500/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-300/60"
                                >
                                    <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedArrowDownTray" class="h-5 w-5 text-primary-300" />
                                    <span>Baixar template atual</span>
                                </a>
                            @endif
                        </div>

                        <form wire:submit="{{ $template['save_method'] }}" class="space-y-4 border-t border-white/10 pt-5">
                            <div class="space-y-2">
                                <label for="{{ $inputId }}" class="text-sm font-semibold text-white">Substituir template</label>
                                <input
                                    id="{{ $inputId }}"
                                    type="file"
                                    wire:model="{{ $template['property'] }}"
                                    accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                                    aria-describedby="{{ $hintId }}"
                                    class="block w-full max-w-xl rounded-2xl border border-white/10 bg-white/[0.03] px-4 py-3 text-sm text-gray-200 file:mr-4 file:rounded-xl file:border-0 file:bg-primary-500 file:px-4 file:py-2 file:font-semibold file:text-[#091b23] hover:file:bg-primary-400 focus:border-primary-400/40 focus:outline-none focus:ring-2 focus:ring-primary-300/40"
                                >
                                <p id="{{ $hintId }}" class="text-sm text-gray-500">
                                    Envie uma planilha .xlsx. O novo arquivo passa a valer imediatamente nos downloads deste fluxo.
                                </p>
                                @error($template['property'])
                                    <p class="text-sm font-medium text-rose-300">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="flex flex-col gap-3 sm:flex-row sm:justify-end">
                                <button
                                    type="button"
                                    wire:click="{{ $template['restore_method'] }}"
                                    wire:confirm="{{ $template['restore_confirmation'] }}"
                                    wire:loading.attr="disabled"
                                    wire:target="{{ $template['restore_method'] }}"
                                    class="inline-flex items-center justify-center gap-2 rounded-2xl border border-white/15 bg-white/[0.03] px-5 py-3 text-sm font-semibold text-white transition hover:border-primary-400/40 hover:bg-primary-500/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-300/60 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedArrowPath" class="h-5 w-5 text-primary-300" />
                                    <span>Restaurar padrão</span>
                                </button>

                                <button
                                    type="submit"
                                    wire:loading.attr="disabled"
                                    wire:target="{{ $template['save_method'] }},{{ $template['property'] }}"
                                    class="inline-flex items-center justify-center gap-2 rounded-2xl bg-primary-500 px-5 py-3 text-sm font-semibold text-[#091b23] shadow-lg shadow-primary-500/20 transition hover:bg-primary-400 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-300/60 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedCheck" class="h-5 w-5" />
                                    <span>Salvar template</span>
                                </button>
                            </div>
                        </form>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="h-fit overflow-hidden rounded-3xl border border-white/10 bg-gray-950/70 shadow-2xl shadow-black/20 xl:col-span-4">
            <div class="border-b border-white/10 px-6 py-5">
                <div class="flex items-center gap-2">
                    <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedInformationCircle" class="h-5 w-5 text-gray-400" />
                    <span class="text-xs font-semibold uppercase tracking-[0.22em] text-gray-400">Como funciona</span>
                </div>
            </div>

            <div class="px-6 py-6">
                <ol class="space-y-4 text-sm leading-6 text-gray-400">
                    <li class="flex gap-3">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary-500/15 text-xs font-semibold text-primary-300 ring-1 ring-primary-400/20">1</span>
                        <span>Baixe o template atual do fluxo desejado (pagamentos, histórico de PU ou integralizações) na emissão correspondente.</span>
                    </li>
                    <li class="flex gap-3">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary-500/15 text-xs font-semibold text-primary-300 ring-1 ring-primary-400/20">2</span>
                        <span>Se necessário, envie aqui um arquivo .xlsx personalizado. Ele passa a ser o template oficial do respectivo botão de download.</span>
                    </li>
                    <li class="flex gap-3">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary-500/15 text-xs font-semibold text-primary-300 ring-1 ring-primary-400/20">3</span>
                        <span>Para voltar ao arquivo original do sistema, use <span class="font-semibold text-white">Restaurar padrão</span> no template correspondente.</span>
                    </li>
                </ol>
            </div>
        </section>
    </div>
</x-filament-panels::page>

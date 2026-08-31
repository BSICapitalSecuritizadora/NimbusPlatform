<x-filament-widgets::widget class="bsi-cockpit-widget bsi-measurement-cockpit">
    <x-filament::section
        heading="Medições e pagamentos"
        description="Visão da carteira autorizada; os indicadores abrem o mesmo recorte nas listagens operacionais."
        icon="heroicon-o-presentation-chart-line"
        icon-color="primary"
    >
        @if($summary['total'] === 0)
            <div class="flex items-start gap-3 rounded-xl border border-gray-200 bg-gray-50/70 p-4 dark:border-white/10 dark:bg-white/5">
                <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-300">
                    <x-heroicon-o-check-circle class="size-5" aria-hidden="true" />
                </span>
                <div>
                    <p class="text-sm font-semibold text-gray-950 dark:text-white">Nenhuma medição corresponde ao recorte operacional no seu escopo.</p>
                    <p class="mt-1 text-xs leading-5 text-gray-500 dark:text-gray-400">Ajuste os filtros do cockpit para consultar outro segmento autorizado.</p>
                </div>
            </div>
        @else
            <div class="grid gap-5 xl:grid-cols-[minmax(0,1.35fr)_minmax(18rem,0.65fr)]">
                <section aria-labelledby="measurement-stage-heading">
                    <div class="flex flex-wrap items-end justify-between gap-2">
                        <div>
                            <h3 id="measurement-stage-heading" class="text-sm font-semibold text-gray-950 dark:text-white">Distribuição por etapa</h3>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ number_format($summary['total'], 0, ',', '.') }} medições no recorte atual</p>
                        </div>
                        <x-filament::link :href="$paymentWorkspaceUrl" icon="heroicon-m-arrow-right" icon-position="after" size="sm">
                            Abrir workspace
                        </x-filament::link>
                    </div>

                    <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-5">
                        @foreach($stages as $stage)
                            @if($stage['url'] !== null)
                                <a
                                    wire:key="measurement-stage-{{ $loop->iteration }}"
                                    href="{{ $stage['url'] }}"
                                    class="group rounded-lg border border-gray-200 bg-white px-3 py-2.5 transition-colors hover:border-primary-300 hover:bg-primary-50/50 focus-visible:outline-2 focus-visible:outline-primary-600 dark:border-white/10 dark:bg-white/5 dark:hover:border-primary-500/40 dark:hover:bg-primary-500/10"
                                >
                                    <span class="block text-2xl font-semibold tabular-nums tracking-tight text-gray-950 dark:text-white">{{ number_format($stage['count'], 0, ',', '.') }}</span>
                                    <span class="mt-1 block text-xs font-medium text-gray-600 group-hover:text-primary-700 dark:text-gray-300 dark:group-hover:text-primary-300">{{ $stage['label'] }}</span>
                                </a>
                            @else
                                <div
                                    wire:key="measurement-stage-{{ $loop->iteration }}"
                                    aria-disabled="true"
                                    class="cursor-default rounded-lg border border-gray-200 bg-white px-3 py-2.5 dark:border-white/10 dark:bg-white/5"
                                >
                                    <span class="block text-2xl font-semibold tabular-nums tracking-tight text-gray-950 dark:text-white">{{ number_format($stage['count'], 0, ',', '.') }}</span>
                                    <span class="mt-1 block text-xs font-medium text-gray-600 dark:text-gray-300">{{ $stage['label'] }}</span>
                                </div>
                            @endif
                        @endforeach
                    </div>
                </section>

                <section class="rounded-xl border border-gray-200 bg-gray-50/60 p-4 dark:border-white/10 dark:bg-white/5" aria-labelledby="payment-summary-heading">
                    <h3 id="payment-summary-heading" class="text-sm font-semibold text-gray-950 dark:text-white">Consolidação operacional</h3>
                    <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-3">
                        <div>
                            <dt class="text-xs text-gray-500 dark:text-gray-400">Pagamentos registrados</dt>
                            <dd class="mt-1 text-lg font-semibold tabular-nums text-gray-950 dark:text-white">{{ number_format($summary['payment_count'], 0, ',', '.') }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-gray-500 dark:text-gray-400">Sem comprovante</dt>
                            <dd class="mt-1 text-lg font-semibold tabular-nums {{ $summary['pending_receipt_count'] > 0 ? 'text-danger-700 dark:text-danger-300' : 'text-gray-950 dark:text-white' }}">{{ number_format($summary['pending_receipt_count'], 0, ',', '.') }}</dd>
                        </div>
                        <div class="col-span-2 border-t border-gray-200 pt-3 dark:border-white/10">
                            <dt class="text-xs text-gray-500 dark:text-gray-400">Valor registrado</dt>
                            <dd class="mt-1 text-xl font-semibold tabular-nums tracking-tight text-gray-950 dark:text-white">R$ {{ number_format($summary['recorded_amount'], 2, ',', '.') }}</dd>
                            <p class="mt-1 text-xs leading-5 text-gray-500 dark:text-gray-400">Soma dos registros operacionais; não representa saldo contábil ou valor liquidado.</p>
                        </div>
                    </dl>
                </section>
            </div>

            <section class="mt-5 border-t border-gray-200/80 pt-4 dark:border-white/10" aria-labelledby="measurement-signals-heading">
                <h3 id="measurement-signals-heading" class="text-sm font-semibold text-gray-950 dark:text-white">Sinais operacionais</h3>
                <div class="mt-3 grid gap-px overflow-hidden rounded-xl border border-gray-200 bg-gray-200 sm:grid-cols-2 lg:grid-cols-4 dark:border-white/10 dark:bg-white/10">
                    @foreach($signals as $signal)
                        @if($signal['url'] !== null)
                            <a
                                wire:key="measurement-signal-{{ $loop->iteration }}"
                                href="{{ $signal['url'] }}"
                                class="group flex min-h-20 items-center justify-between gap-3 bg-white px-3 py-3 transition-colors hover:bg-gray-50 focus-visible:z-10 focus-visible:outline-2 focus-visible:outline-primary-600 dark:bg-gray-900 dark:hover:bg-white/5"
                            >
                                <span class="min-w-0">
                                    <span class="block text-sm font-medium text-gray-950 dark:text-white">{{ $signal['label'] }}</span>
                                    <span class="mt-1 block text-xs text-gray-500 dark:text-gray-400">{{ $signal['description'] }}</span>
                                </span>
                                <x-filament::badge :color="$signal['tone']" size="sm">{{ number_format($signal['count'], 0, ',', '.') }}</x-filament::badge>
                            </a>
                        @else
                            <div
                                wire:key="measurement-signal-{{ $loop->iteration }}"
                                aria-disabled="true"
                                class="flex min-h-20 cursor-default items-center justify-between gap-3 bg-white px-3 py-3 dark:bg-gray-900"
                            >
                                <span class="min-w-0">
                                    <span class="block text-sm font-medium text-gray-950 dark:text-white">{{ $signal['label'] }}</span>
                                    <span class="mt-1 block text-xs text-gray-500 dark:text-gray-400">{{ $signal['description'] }}</span>
                                </span>
                                <x-filament::badge :color="$signal['tone']" size="sm">{{ number_format($signal['count'], 0, ',', '.') }}</x-filament::badge>
                            </div>
                        @endif
                    @endforeach
                </div>
            </section>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>

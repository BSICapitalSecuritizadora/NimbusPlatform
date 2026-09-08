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
            <div>
                <section aria-labelledby="measurement-stage-heading">
                    <div class="flex flex-wrap items-end justify-between gap-x-3 gap-y-2">
                        <div>
                            <h3 id="measurement-stage-heading" class="text-sm font-semibold text-gray-950 dark:text-white">Distribuição por etapa</h3>
                            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ number_format($summary['total'], 0, ',', '.') }} medições no recorte atual</p>
                        </div>
                        <a
                            href="{{ $paymentWorkspaceUrl }}"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 px-3 py-2 text-xs font-semibold text-gray-600 transition-colors hover:border-bsi-gold-500/60 hover:text-bsi-gold-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600 dark:border-white/10 dark:text-gray-300 dark:hover:border-bsi-gold-500/50 dark:hover:text-bsi-gold-500"
                        >
                            Abrir workspace
                            <x-heroicon-m-arrow-up-right class="size-3.5 shrink-0" aria-hidden="true" />
                        </a>
                    </div>

                    <ol class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-[1fr_auto_1fr_auto_1fr_auto_1fr_auto_1fr_auto_1fr]">
                        @foreach($stages as $stage)
                            <li class="min-w-0">
                                @if($stage['url'] !== null)
                                    <a
                                        wire:key="measurement-stage-{{ $loop->iteration }}"
                                        href="{{ $stage['url'] }}"
                                        @class([
                                            'group flex h-full flex-col justify-center rounded-xl border px-3 py-2.5 transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600',
                                            'border-bsi-gold-500/50 bg-bsi-paper hover:border-bsi-gold-500 dark:border-bsi-gold-500/40 dark:bg-bsi-gold-500/[0.07] dark:hover:border-bsi-gold-500/70' => $stage['count'] > 0,
                                            'border-gray-200 bg-white hover:border-primary-300 hover:bg-primary-50/50 dark:border-white/10 dark:bg-white/5 dark:hover:border-primary-500/40 dark:hover:bg-primary-500/10' => $stage['count'] === 0,
                                        ])
                                    >
                                        <span class="block text-2xl font-semibold tabular-nums tracking-tight text-gray-950 dark:text-white">{{ number_format($stage['count'], 0, ',', '.') }}</span>
                                        <span @class([
                                            'mt-1 block text-xs font-medium',
                                            'text-gray-600 group-hover:text-bsi-gold-600 dark:text-gray-300 dark:group-hover:text-bsi-gold-500' => $stage['count'] > 0,
                                            'text-gray-600 group-hover:text-primary-700 dark:text-gray-300 dark:group-hover:text-primary-300' => $stage['count'] === 0,
                                        ])>{{ $stage['label'] }}</span>
                                    </a>
                                @else
                                    <div
                                        wire:key="measurement-stage-{{ $loop->iteration }}"
                                        aria-disabled="true"
                                        @class([
                                            'flex h-full cursor-default flex-col justify-center rounded-xl border px-3 py-2.5',
                                            'border-bsi-gold-500/50 bg-bsi-paper dark:border-bsi-gold-500/40 dark:bg-bsi-gold-500/[0.07]' => $stage['count'] > 0,
                                            'border-gray-200 bg-white dark:border-white/10 dark:bg-white/5' => $stage['count'] === 0,
                                        ])
                                    >
                                        <span class="block text-2xl font-semibold tabular-nums tracking-tight text-gray-950 dark:text-white">{{ number_format($stage['count'], 0, ',', '.') }}</span>
                                        <span class="mt-1 block text-xs font-medium text-gray-600 dark:text-gray-300">{{ $stage['label'] }}</span>
                                    </div>
                                @endif
                            </li>
                            @if(! $loop->last)
                                <li aria-hidden="true" class="hidden self-center justify-self-center xl:block">
                                    <x-heroicon-m-chevron-right class="size-4 shrink-0 text-gray-300 dark:text-white/20" />
                                </li>
                            @endif
                        @endforeach
                    </ol>
                </section>

                <section class="mt-6 rounded-xl border border-gray-200 bg-bsi-paper p-4 sm:p-5 dark:border-bsi-gold-500/15 dark:bg-[#071820]/70" aria-labelledby="payment-summary-heading">
                    <h3 id="payment-summary-heading" class="text-sm font-semibold text-gray-950 dark:text-white">Consolidação operacional</h3>
                    <dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-4 sm:max-w-xl">
                        <div class="min-w-0">
                            <dt class="text-xs text-gray-500 dark:text-gray-400">Pagamentos registrados</dt>
                            <dd class="mt-1 text-lg font-semibold tabular-nums text-gray-950 dark:text-white">{{ number_format($summary['payment_count'], 0, ',', '.') }}</dd>
                        </div>
                        <div class="min-w-0">
                            <dt class="text-xs text-gray-500 dark:text-gray-400">Sem comprovante</dt>
                            <dd class="mt-1 text-lg font-semibold tabular-nums {{ $summary['pending_receipt_count'] > 0 ? 'text-danger-700 dark:text-danger-300' : 'text-gray-950 dark:text-white' }}">{{ number_format($summary['pending_receipt_count'], 0, ',', '.') }}</dd>
                        </div>
                    </dl>
                    <div class="mt-4 border-t border-gray-200 pt-4 dark:border-white/10">
                        <dl>
                            <dt class="text-xs text-gray-500 dark:text-gray-400">Valor registrado</dt>
                            <dd class="mt-1 text-2xl font-semibold tabular-nums tracking-tight text-gray-950 dark:text-white">R$ {{ number_format($summary['recorded_amount'], 2, ',', '.') }}</dd>
                        </dl>
                        <p class="mt-1.5 max-w-2xl text-xs leading-5 text-gray-500 dark:text-gray-400">Soma dos registros operacionais; não representa saldo contábil ou valor liquidado.</p>
                    </div>
                </section>
            </div>

            <section class="mt-6" aria-labelledby="measurement-signals-heading">
                <h3 id="measurement-signals-heading" class="text-sm font-semibold text-gray-950 dark:text-white">Sinais operacionais</h3>
                <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach($signals as $signal)
                        @php
                            $isSignalled = $signal['count'] > 0;
                            $badgeColor = $isSignalled ? $signal['tone'] : 'gray';
                            $dotClasses = $isSignalled
                                ? match ($signal['tone']) {
                                    'danger' => 'bg-danger-500',
                                    'warning' => 'bg-amber-500',
                                    'success' => 'bg-success-500',
                                    'info' => 'bg-info-500',
                                    default => 'bg-gray-400 dark:bg-gray-500',
                                }
                                : 'bg-gray-300 dark:bg-white/20';
                        @endphp
                        @if($signal['url'] !== null)
                            <a
                                wire:key="measurement-signal-{{ $loop->iteration }}"
                                href="{{ $signal['url'] }}"
                                class="group flex h-full flex-col rounded-xl border border-gray-200 bg-white p-3.5 transition-colors hover:border-primary-300 hover:bg-primary-50/50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600 dark:border-bsi-gold-500/15 dark:bg-[#091f28]/75 dark:hover:border-bsi-gold-500/40 dark:hover:bg-[#0e2c38]"
                            >
                                <span class="flex items-start justify-between gap-3">
                                    <span class="inline-flex min-w-0 items-center gap-1.5 text-[0.8125rem] font-semibold leading-snug text-gray-950 dark:text-white">
                                        <span aria-hidden="true" class="mt-1 size-1.5 shrink-0 rounded-full {{ $dotClasses }}"></span>
                                        <span class="min-w-0">{{ $signal['label'] }}</span>
                                    </span>
                                    <x-filament::badge :color="$badgeColor" size="sm" class="shrink-0">{{ number_format($signal['count'], 0, ',', '.') }}</x-filament::badge>
                                </span>
                                <span class="mt-1 block ps-3 text-xs leading-5 text-gray-500 dark:text-gray-400">{{ $signal['description'] }}</span>
                            </a>
                        @else
                            <div
                                wire:key="measurement-signal-{{ $loop->iteration }}"
                                aria-disabled="true"
                                class="flex h-full cursor-default flex-col rounded-xl border border-gray-200 bg-white p-3.5 dark:border-bsi-gold-500/15 dark:bg-[#091f28]/75"
                            >
                                <span class="flex items-start justify-between gap-3">
                                    <span class="inline-flex min-w-0 items-center gap-1.5 text-[0.8125rem] font-semibold leading-snug text-gray-950 dark:text-white">
                                        <span aria-hidden="true" class="mt-1 size-1.5 shrink-0 rounded-full {{ $dotClasses }}"></span>
                                        <span class="min-w-0">{{ $signal['label'] }}</span>
                                    </span>
                                    <x-filament::badge :color="$badgeColor" size="sm" class="shrink-0">{{ number_format($signal['count'], 0, ',', '.') }}</x-filament::badge>
                                </span>
                                <span class="mt-1 block ps-3 text-xs leading-5 text-gray-500 dark:text-gray-400">{{ $signal['description'] }}</span>
                            </div>
                        @endif
                    @endforeach
                </div>
            </section>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>

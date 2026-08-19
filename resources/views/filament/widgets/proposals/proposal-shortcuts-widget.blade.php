<x-filament-widgets::widget class="bsi-cockpit-widget bsi-cockpit-shortcuts">
    <x-filament::section
        heading="Ações e Navegação Rápida"
        description="Atalhos operacionais e direcionamento prioritário na esteira de prospecção."
        icon="heroicon-o-bolt"
        icon-color="primary"
    >
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @if($canViewProposals)
                <a
                    href="{{ $proposalsUrl }}"
                    class="bsi-cockpit-action group flex min-h-20 items-center gap-3 rounded-xl bg-bsi-navy-900 px-4 py-3 text-bsi-paper shadow-[0_10px_24px_rgba(9,27,35,0.16)] transition-[background-color,box-shadow] duration-200 ease-out hover:bg-bsi-navy-800 hover:shadow-[0_14px_28px_rgba(9,27,35,0.22)] focus-visible:outline-2 focus-visible:outline-offset-3 focus-visible:outline-bsi-gold-500 motion-reduce:transition-none"
                    aria-label="Ver todas as propostas da carteira comercial"
                >
                    <div class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-bsi-gold-500/20 text-bsi-gold-500">
                        <x-heroicon-o-document-text class="size-5 shrink-0 text-bsi-gold-500" aria-hidden="true" />
                    </div>
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-semibold text-white">Carteira de Propostas</span>
                        <span class="mt-0.5 block text-xs leading-snug text-bsi-paper/70">
                            {{ $summary['total'] }} total &bull; {{ $summary['active_pipeline'] }} em andamento
                        </span>
                    </span>
                    <x-heroicon-o-chevron-right class="bsi-cockpit-action-arrow size-4 shrink-0 text-bsi-gold-500 transition-transform group-hover:translate-x-0.5" aria-hidden="true" />
                </a>
            @endif

            @if($canViewProposals)
                <a
                    href="{{ $proposalsUrl }}"
                    class="bsi-cockpit-action bsi-cockpit-shortcut group flex min-h-20 items-center gap-3 rounded-xl border border-gray-200 bg-bsi-paper px-4 py-3 shadow-sm transition-[border-color,background-color] duration-200 ease-out hover:border-amber-400 hover:bg-amber-50/40 dark:border-gray-700 dark:bg-gray-900 dark:hover:border-amber-500 dark:hover:bg-amber-500/5"
                    aria-label="Ver propostas com pendências ou SLA crítico"
                >
                    <div class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300">
                        <x-heroicon-o-exclamation-triangle class="size-5 shrink-0" aria-hidden="true" />
                    </div>
                    <span class="min-w-0 flex-1">
                        <span class="flex items-center gap-1.5 text-sm font-semibold text-gray-950 dark:text-white">
                            Pendências e SLA
                            @if($summary['attention'] > 0)
                                <span class="inline-flex items-center rounded-full bg-amber-100 px-1.5 py-0.5 text-[0.65rem] font-bold text-amber-800 dark:bg-amber-500/20 dark:text-amber-300">
                                    {{ $summary['attention'] }}
                                </span>
                            @endif
                        </span>
                        <span class="mt-0.5 block text-xs leading-snug text-gray-600 dark:text-gray-300">
                            @if($summary['stale_review'] > 0)
                                {{ $summary['stale_review'] }} estagnadas há +3 dias
                            @else
                                {{ $summary['attention'] }} requerem acompanhamento
                            @endif
                        </span>
                    </span>
                    <x-heroicon-o-chevron-right class="bsi-cockpit-action-arrow size-4 shrink-0 text-gray-400 transition-colors group-hover:text-amber-600 dark:group-hover:text-amber-300" aria-hidden="true" />
                </a>
            @endif

            @if($canManageRepresentatives)
                <a
                    href="{{ $representativesUrl }}"
                    class="bsi-cockpit-action bsi-cockpit-shortcut group flex min-h-20 items-center gap-3 rounded-xl border border-gray-200 bg-bsi-paper px-4 py-3 shadow-sm transition-[border-color,background-color] duration-200 ease-out hover:border-bsi-navy-800 hover:bg-bsi-stone-50 dark:border-gray-700 dark:bg-gray-900 dark:hover:border-bsi-gold-500 dark:hover:bg-gray-800/50"
                    aria-label="Gerenciar fila de representantes comerciais"
                >
                    <div class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-info-100 text-info-800 dark:bg-info-500/20 dark:text-info-300">
                        <x-heroicon-o-user-group class="size-5 shrink-0" aria-hidden="true" />
                    </div>
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-semibold text-gray-950 dark:text-white">Fila Comercial</span>
                        <span class="mt-0.5 block text-xs leading-snug text-gray-600 dark:text-gray-300">
                            Distribuição & capacidade
                        </span>
                    </span>
                    <x-heroicon-o-chevron-right class="bsi-cockpit-action-arrow size-4 shrink-0 text-gray-400 transition-colors group-hover:text-primary-600 dark:group-hover:text-primary-300" aria-hidden="true" />
                </a>
            @else
                <a
                    href="{{ $proposalsUrl }}"
                    class="bsi-cockpit-action bsi-cockpit-shortcut group flex min-h-20 items-center gap-3 rounded-xl border border-gray-200 bg-bsi-paper px-4 py-3 shadow-sm transition-[border-color,background-color] duration-200 ease-out hover:border-primary-400 hover:bg-primary-50/50 dark:border-gray-700 dark:bg-gray-900"
                    aria-label="Ver histórico e movimentações da sua carteira"
                >
                    <div class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary-100 text-primary-800 dark:bg-primary-500/20 dark:text-primary-300">
                        <x-heroicon-o-clock class="size-5 shrink-0" aria-hidden="true" />
                    </div>
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-semibold text-gray-950 dark:text-white">Minha Carteira</span>
                        <span class="mt-0.5 block text-xs leading-snug text-gray-600 dark:text-gray-300">
                            {{ $summary['received_last_30_days'] }} novas nos últimos 30 dias
                        </span>
                    </span>
                    <x-heroicon-o-chevron-right class="bsi-cockpit-action-arrow size-4 shrink-0 text-gray-400 transition-colors group-hover:text-primary-600" aria-hidden="true" />
                </a>
            @endif

            <div
                class="bsi-cockpit-action bsi-cockpit-shortcut flex min-h-20 items-center gap-3 rounded-xl border border-gray-200 bg-bsi-paper px-4 py-3 shadow-sm dark:border-gray-700 dark:bg-gray-900"
            >
                <div class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300">
                    <x-heroicon-o-check-badge class="size-5 shrink-0" aria-hidden="true" />
                </div>
                <span class="min-w-0 flex-1">
                    <span class="flex items-center justify-between">
                        <span class="block text-sm font-semibold text-gray-950 dark:text-white">Taxa de Deferimento</span>
                        <span class="font-mono text-sm font-bold text-emerald-600 dark:text-emerald-400">{{ $summary['conversion_rate'] }}%</span>
                    </span>
                    <span class="mt-0.5 block text-xs leading-snug text-gray-600 dark:text-gray-300">
                        {{ $summary['approved'] }} aprovadas &bull; {{ $summary['completed'] }} formalizadas
                    </span>
                </span>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>

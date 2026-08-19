<x-filament-widgets::widget class="bsi-cockpit-widget bsi-cockpit-pendings">
    <x-filament::section
        heading="Minhas pendências"
        :description="$sectionDescription"
        icon="heroicon-o-clipboard-document-check"
        icon-color="primary"
        collapsible
        persist-collapsed
        collapse-id="cockpit-my-pendings"
    >
        @if($totalPendingCount === 0)
            <div
                data-pending-state="empty"
                class="grid gap-5 py-1 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center"
            >
                <div class="flex min-w-0 items-start gap-3">
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-300">
                        <x-heroicon-o-check-circle class="size-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <h3 class="text-base font-semibold text-gray-950 dark:text-white">Tudo em dia</h3>
                        <p class="mt-1 max-w-2xl text-sm leading-6 text-gray-600 dark:text-gray-300">
                            Você não possui obrigações ou propostas pendentes no momento.
                        </p>
                    </div>
                </div>

                <div class="grid gap-2 border-t border-gray-200/80 pt-4 text-xs font-medium text-gray-600 sm:grid-cols-2 lg:grid-cols-1 lg:border-t-0 lg:border-l lg:py-1 lg:pl-5 dark:border-white/10 dark:text-gray-300">
                    <span class="flex items-center gap-2">
                        <x-heroicon-m-check class="size-4 shrink-0 text-success-600 dark:text-success-300" aria-hidden="true" />
                        Nenhuma obrigação pendente
                    </span>
                    <span class="flex items-center gap-2">
                        <x-heroicon-m-check class="size-4 shrink-0 text-success-600 dark:text-success-300" aria-hidden="true" />
                        Nenhuma proposta aguardando sua ação
                    </span>
                </div>
            </div>
        @else
            <div
                data-pending-state="{{ $obligationCount > 0 && $proposalCount > 0 ? 'active' : 'mixed' }}"
                class="grid grid-cols-1 divide-y divide-gray-200/80 md:grid-cols-2 md:divide-x md:divide-y-0 dark:divide-white/10"
            >
                <section class="pb-5 md:pr-6 md:pb-0" aria-labelledby="cockpit-obligations-heading">
                    <div class="flex items-center gap-3">
                        <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-300">
                            <x-heroicon-o-clipboard-document-check class="size-4" aria-hidden="true" />
                        </span>
                        <h3 id="cockpit-obligations-heading" class="text-sm font-semibold text-gray-950 dark:text-white">
                            Obrigações sob minha responsabilidade
                        </h3>
                    </div>

                    @if($obligationCount === 0)
                        <div class="mt-5 flex items-center gap-2 text-sm font-medium text-success-700 dark:text-success-300">
                            <x-heroicon-o-check-circle class="size-5 shrink-0" aria-hidden="true" />
                            <span>Nenhuma pendência</span>
                        </div>
                        <p class="mt-1.5 text-xs leading-5 text-gray-500 dark:text-gray-400">
                            Não há obrigações que exijam sua ação.
                        </p>
                    @else
                        <div class="mt-4 flex flex-wrap items-end justify-between gap-3">
                            <p class="flex items-baseline gap-2">
                                <span class="text-3xl font-semibold tracking-tight tabular-nums text-warning-700 dark:text-warning-300">{{ $obligationCount }}</span>
                                <span class="text-sm font-medium text-gray-600 dark:text-gray-300">{{ $obligationCount === 1 ? 'pendente' : 'pendentes' }}</span>
                            </p>

                            @if($obligationsUrl)
                                <x-filament::link
                                    :href="$obligationsUrl"
                                    icon="heroicon-m-arrow-right"
                                    icon-position="after"
                                    size="sm"
                                    aria-label="Ver todas as {{ $obligationCount }} obrigações pendentes"
                                >
                                    Ver obrigações
                                </x-filament::link>
                            @endif
                        </div>

                        @if($overdueObligationCount > 0 || $dueTodayObligationCount > 0)
                            <div class="mt-3 flex flex-wrap gap-2" aria-label="Prioridades das obrigações">
                                @if($overdueObligationCount > 0)
                                    <span class="inline-flex items-center gap-1.5 rounded-md bg-danger-50 px-2 py-1 text-xs font-medium text-danger-700 dark:bg-danger-500/10 dark:text-danger-300">
                                        <x-heroicon-m-exclamation-circle class="size-3.5" aria-hidden="true" />
                                        {{ $overdueObligationCount }} {{ $overdueObligationCount === 1 ? 'vencida' : 'vencidas' }}
                                    </span>
                                @endif
                                @if($dueTodayObligationCount > 0)
                                    <span class="inline-flex items-center gap-1.5 rounded-md bg-warning-50 px-2 py-1 text-xs font-medium text-warning-700 dark:bg-warning-500/10 dark:text-warning-300">
                                        <x-heroicon-m-clock class="size-3.5" aria-hidden="true" />
                                        {{ $dueTodayObligationCount }} {{ $dueTodayObligationCount === 1 ? 'vence hoje' : 'vencem hoje' }}
                                    </span>
                                @endif
                            </div>
                        @endif

                        <ul class="mt-4 divide-y divide-gray-200/70 border-t border-gray-200/70 dark:divide-white/8 dark:border-white/8" aria-label="Prévia das obrigações pendentes">
                            @foreach($obligations as $obligation)
                                <li wire:key="cockpit-obligation-{{ $obligation->id }}">
                                    <a
                                        href="{{ \App\Filament\Resources\Emissions\EmissionResource::getUrl('edit', ['record' => $obligation->emission_id, 'relation' => \App\Filament\Resources\Emissions\EmissionResource\RelationManagers\ObligationsRelationManager::class]) }}"
                                        class="group -mx-2 flex min-h-14 items-center gap-3 rounded-lg px-2 py-2.5 transition-colors duration-200 ease-out hover:bg-primary-50/60 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600 motion-reduce:transition-none dark:hover:bg-primary-500/10"
                                        aria-label="Abrir obrigação: {{ $obligation->title }}"
                                    >
                                        <span class="min-w-0 flex-1">
                                            <span class="line-clamp-1 text-sm font-medium text-gray-950 dark:text-white">{{ $obligation->title }}</span>
                                            <span class="mt-1 flex items-center gap-1.5 text-xs {{ $obligation->status === 'vencida' ? 'text-danger-700 dark:text-danger-300' : ($obligation->due_date?->isToday() ? 'text-warning-700 dark:text-warning-300' : 'text-gray-500 dark:text-gray-400') }}">
                                                <x-heroicon-m-calendar-days class="size-3.5 shrink-0" aria-hidden="true" />
                                                @if($obligation->status === 'vencida')
                                                    Vencida em {{ $obligation->due_date?->format('d/m/Y') ?? 'data não informada' }}
                                                @elseif($obligation->due_date?->isToday())
                                                    Vence hoje
                                                @elseif($obligation->due_date)
                                                    Vence em {{ $obligation->due_date->format('d/m/Y') }}
                                                @else
                                                    Sem prazo definido
                                                @endif
                                            </span>
                                        </span>
                                        <x-heroicon-o-chevron-right class="size-4 shrink-0 text-gray-400 transition-colors group-hover:text-primary-600 dark:group-hover:text-primary-300" aria-hidden="true" />
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                        @if($obligationHiddenCount > 0)
                            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                + {{ $obligationHiddenCount }} {{ $obligationHiddenCount === 1 ? 'obrigação adicional' : 'obrigações adicionais' }} na visão completa
                            </p>
                        @endif
                    @endif
                </section>

                <section class="pt-5 md:pt-0 md:pl-6" aria-labelledby="cockpit-proposals-heading">
                    <div class="flex items-center gap-3">
                        <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-300">
                            <x-heroicon-o-document-text class="size-4" aria-hidden="true" />
                        </span>
                        <h3 id="cockpit-proposals-heading" class="text-sm font-semibold text-gray-950 dark:text-white">
                            Propostas em andamento
                        </h3>
                    </div>

                    @if($proposalCount === 0)
                        <div class="mt-5 flex items-center gap-2 text-sm font-medium text-success-700 dark:text-success-300">
                            <x-heroicon-o-check-circle class="size-5 shrink-0" aria-hidden="true" />
                            <span>Nenhuma pendência</span>
                        </div>
                        <p class="mt-1.5 text-xs leading-5 text-gray-500 dark:text-gray-400">
                            Nenhuma proposta aguarda sua ação.
                        </p>
                    @else
                        <div class="mt-4 flex flex-wrap items-end justify-between gap-3">
                            <p class="flex items-baseline gap-2">
                                <span class="text-3xl font-semibold tracking-tight tabular-nums text-warning-700 dark:text-warning-300">{{ $proposalCount }}</span>
                                <span class="text-sm font-medium text-gray-600 dark:text-gray-300">em andamento</span>
                            </p>

                            @if($proposalsUrl)
                                <x-filament::link
                                    :href="$proposalsUrl"
                                    icon="heroicon-m-arrow-right"
                                    icon-position="after"
                                    size="sm"
                                    aria-label="Ver todas as {{ $proposalCount }} propostas em andamento"
                                >
                                    Ver propostas
                                </x-filament::link>
                            @endif
                        </div>

                        <ul class="mt-4 divide-y divide-gray-200/70 border-t border-gray-200/70 dark:divide-white/8 dark:border-white/8" aria-label="Prévia das propostas em andamento">
                            @foreach($proposals as $proposal)
                                <li wire:key="cockpit-proposal-{{ $proposal->id }}">
                                    <a
                                        href="{{ \App\Filament\Resources\Proposals\ProposalResource::getUrl('view', ['record' => $proposal->id]) }}"
                                        class="group -mx-2 flex min-h-14 items-center gap-3 rounded-lg px-2 py-2.5 transition-colors duration-200 ease-out hover:bg-primary-50/60 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600 motion-reduce:transition-none dark:hover:bg-primary-500/10"
                                        aria-label="Abrir proposta de {{ $proposal->company->name ?? 'empresa não informada' }}"
                                    >
                                        <span class="min-w-0 flex-1">
                                            <span class="line-clamp-1 text-sm font-medium text-gray-950 dark:text-white">
                                                {{ $proposal->company->name ?? 'Empresa não informada' }}
                                            </span>
                                            <x-filament::badge
                                                class="mt-1"
                                                :color="\App\Enums\ProposalStatus::colorFor($proposal->status)"
                                                size="sm"
                                            >
                                                {{ \App\Enums\ProposalStatus::labelFor($proposal->status) }}
                                            </x-filament::badge>
                                        </span>
                                        <x-heroicon-o-chevron-right class="size-4 shrink-0 text-gray-400 transition-colors group-hover:text-primary-600 dark:group-hover:text-primary-300" aria-hidden="true" />
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                        @if($proposalHiddenCount > 0)
                            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                + {{ $proposalHiddenCount }} {{ $proposalHiddenCount === 1 ? 'proposta adicional' : 'propostas adicionais' }} na visão completa
                            </p>
                        @endif
                    @endif
                </section>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>

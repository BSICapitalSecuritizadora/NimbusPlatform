<x-filament-widgets::widget class="bsi-cockpit-widget bsi-cockpit-activities">
    <x-filament::section
        class="h-full"
        heading="Atividades recentes"
        description="Últimas movimentações registradas no ambiente."
        icon="heroicon-o-bars-3-bottom-left"
        icon-color="gray"
        collapsible
        persist-collapsed
        collapse-id="cockpit-recent-activities"
    >
        @if($viewAllUrl)
            <x-slot name="afterHeader">
                <span class="hidden sm:block">
                    <x-filament::link
                        :href="$viewAllUrl"
                        icon="heroicon-m-arrow-top-right-on-square"
                        size="sm"
                        aria-label="Ver todas as atividades no log de auditoria"
                    >
                        Ver todas
                    </x-filament::link>
                </span>
            </x-slot>
        @endif

        @if(! $canViewActivities)
            <div class="flex items-start gap-3 text-sm text-gray-600 dark:text-gray-300">
                <x-heroicon-o-lock-closed class="mt-0.5 size-5 shrink-0 text-gray-500" aria-hidden="true" />
                <p>As atividades não estão disponíveis para o seu perfil.</p>
            </div>
        @elseif($activityGroups->isEmpty())
            <div class="flex items-start gap-3 text-sm text-gray-600 dark:text-gray-300">
                <x-heroicon-o-clock class="mt-0.5 size-5 shrink-0 text-gray-500" aria-hidden="true" />
                <p>Nenhuma atividade recente encontrada.</p>
            </div>
        @else
            @if($viewAllUrl)
                <div class="mb-3 flex justify-end sm:hidden">
                    <x-filament::link
                        :href="$viewAllUrl"
                        icon="heroicon-m-arrow-top-right-on-square"
                        size="sm"
                        aria-label="Ver todas as atividades no log de auditoria"
                    >
                        Ver todas
                    </x-filament::link>
                </div>
            @endif

            <div class="space-y-4" aria-label="Atividades recentes agrupadas por período">
                @foreach($activityGroups as $period => $activities)
                    <section aria-labelledby="cockpit-activity-period-{{ $loop->index }}">
                        <div class="flex items-center gap-3">
                            <h3 id="cockpit-activity-period-{{ $loop->index }}" class="text-xs font-semibold text-gray-600 dark:text-gray-300">
                                {{ $period }}
                            </h3>
                            <span class="h-px flex-1 bg-gray-200/80 dark:bg-white/10" aria-hidden="true"></span>
                        </div>

                        <ol class="mt-1 divide-y divide-gray-200/70 dark:divide-white/8">
                            @foreach($activities as $activity)
                                <li
                                    wire:key="cockpit-activity-{{ $activity['id'] }}"
                                    class="group/activity -mx-2 grid grid-cols-[auto_minmax(0,1fr)] gap-3 px-2 py-3 transition-colors duration-200 ease-out first:pt-2 hover:bg-gray-50/80 motion-reduce:transition-none dark:hover:bg-white/3"
                                >
                                    <span
                                        class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg {{ $activity['markerClasses'] }}"
                                        role="img"
                                        aria-label="Tipo de atividade: {{ $activity['typeLabel'] }}"
                                    >
                                        <x-dynamic-component :component="$activity['markerIcon']" class="size-4" aria-hidden="true" />
                                    </span>

                                    <article class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="text-xs font-semibold text-gray-600 dark:text-gray-300">
                                                {{ $activity['typeLabel'] }}
                                            </span>

                                            @if($activity['subjectLabel'] && $activity['subjectId'])
                                                <span
                                                    class="inline-flex min-w-0 items-baseline gap-1 rounded-md border border-primary-200/80 bg-primary-50 px-1.5 py-0.5 text-xs text-primary-800 dark:border-primary-400/20 dark:bg-primary-500/10 dark:text-primary-200"
                                                    aria-label="Registro relacionado: {{ $activity['subjectLabel'] }} #{{ $activity['subjectId'] }}"
                                                >
                                                    <span class="max-w-32 truncate">{{ $activity['subjectLabel'] }}</span>
                                                    <strong class="font-semibold tabular-nums">#{{ $activity['subjectId'] }}</strong>
                                                </span>
                                            @endif
                                        </div>

                                        @if($activity['isExpandable'])
                                            <details class="bsi-activity-details mt-1.5">
                                                <summary class="cursor-pointer rounded-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600">
                                                    <span class="bsi-activity-description block text-sm font-medium leading-5 text-gray-950 dark:text-white">
                                                        {{ $activity['action'] }}
                                                    </span>
                                                    <span class="mt-1 inline-flex items-center gap-1 text-xs font-medium text-primary-700 dark:text-primary-300">
                                                        <span class="bsi-activity-expand-label">Mostrar mais</span>
                                                        <span class="bsi-activity-collapse-label">Mostrar menos</span>
                                                        <x-heroicon-m-chevron-down class="bsi-activity-expand-icon size-3.5" aria-hidden="true" />
                                                    </span>
                                                </summary>
                                            </details>
                                        @else
                                            <p class="mt-1.5 text-sm font-medium leading-5 text-gray-950 dark:text-white">
                                                {{ $activity['action'] }}
                                            </p>
                                        @endif

                                        <div class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-gray-600 dark:text-gray-300">
                                            <span class="inline-flex min-w-0 items-center gap-1.5 font-medium text-gray-700 dark:text-gray-200">
                                                @if($activity['isSystem'])
                                                    <x-heroicon-m-cog-6-tooth class="size-3.5 shrink-0 text-gray-500" aria-hidden="true" />
                                                @else
                                                    <x-heroicon-m-user class="size-3.5 shrink-0 text-gray-500" aria-hidden="true" />
                                                @endif
                                                <span class="truncate">{{ $activity['actorName'] }}</span>
                                            </span>
                                            <span class="text-gray-300 dark:text-gray-600" aria-hidden="true">•</span>
                                            <time
                                                class="inline-flex items-center gap-1.5 tabular-nums"
                                                datetime="{{ $activity['occurredAt'] }}"
                                                title="{{ $activity['occurredAtExact'] }}"
                                            >
                                                <x-heroicon-m-clock class="size-3.5 shrink-0 text-gray-500" aria-hidden="true" />
                                                {{ $activity['occurredAtRelative'] }}
                                            </time>
                                        </div>
                                    </article>
                                </li>
                            @endforeach
                        </ol>
                    </section>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>

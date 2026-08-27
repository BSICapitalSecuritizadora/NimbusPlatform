@php
    use Filament\Support\Icons\Heroicon;
@endphp

<x-filament-widgets::widget class="bsi-cockpit-widget bsi-nimbus-activities-widget">
    <div class="flex flex-col gap-5">
        {{-- Últimas atividades --}}
        <div class="rounded-xl border border-gray-200/80 bg-white p-4 shadow-sm dark:border-slate-700/50 dark:bg-[#0d252e]">
            <div class="flex items-center justify-between border-b border-gray-100 pb-3 mb-3 dark:border-slate-800">
                <div class="flex items-center gap-2">
                    <div class="flex size-7 items-center justify-center rounded-lg bg-sky-500/15 text-sky-400">
                        <x-filament::icon :icon="Heroicon::Clock" class="h-4 w-4" />
                    </div>
                    <div>
                        <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Últimas atividades</h3>
                        <p class="text-[0.6875rem] text-gray-500 dark:text-slate-400">Acessos recentes ao portal</p>
                    </div>
                </div>
                <span class="text-[0.625rem] font-semibold uppercase tracking-wider text-slate-400">5 mais recentes</span>
            </div>
            
            <ul class="divide-y divide-gray-100 dark:divide-slate-800/80">
                @forelse($recentActivities as $activity)
                <li class="py-2.5 flex items-center justify-between gap-3">
                    <div class="flex items-center gap-2.5 min-w-0">
                        <div class="flex size-6 shrink-0 items-center justify-center rounded-full bg-emerald-500/15 text-emerald-400">
                            <x-filament::icon :icon="Heroicon::ArrowRightEndOnRectangle" class="h-3.5 w-3.5" />
                        </div>
                        <div class="min-w-0">
                            <span class="block text-xs font-medium text-gray-800 dark:text-slate-200 truncate">
                                {{ $activity->full_name }}
                            </span>
                            <span class="block text-[0.6875rem] text-gray-500 dark:text-slate-400">Acesso ao portal</span>
                        </div>
                    </div>
                    <span class="shrink-0 text-[0.6875rem] text-gray-500 dark:text-slate-400 tabular-nums">
                        {{ $activity->last_login_at?->diffForHumans() }}
                    </span>
                </li>
                @empty
                <li class="py-4 flex flex-col items-center justify-center text-center">
                    <div class="flex size-9 items-center justify-center rounded-lg bg-slate-800/60 border border-slate-700/40 text-slate-400">
                        <x-filament::icon :icon="Heroicon::Clock" class="h-4.5 w-4.5" />
                    </div>
                    <span class="mt-2 text-xs font-semibold text-gray-950 dark:text-white">Nenhuma atividade recente</span>
                    <span class="text-[0.6875rem] text-gray-500 dark:text-slate-400">Os acessos e ações recentes aparecerão aqui.</span>
                </li>
                @endforelse
            </ul>
        </div>

        {{-- Atenções necessárias --}}
        <div class="rounded-xl border border-gray-200/80 bg-white p-4 shadow-sm dark:border-slate-700/50 dark:bg-[#0d252e]">
            <div class="flex items-center gap-2 border-b border-gray-100 pb-3 mb-3 dark:border-slate-800">
                <div class="flex size-7 items-center justify-center rounded-lg bg-amber-500/15 text-amber-400">
                    <x-filament::icon :icon="Heroicon::ExclamationTriangle" class="h-4 w-4" />
                </div>
                <div>
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Atenções necessárias</h3>
                    <p class="text-[0.6875rem] text-gray-500 dark:text-slate-400">Alertas operacionais e pendências críticas</p>
                </div>
            </div>
            
            <div class="flex flex-col gap-2.5">
                @if($oldPendingCount > 0)
                <div class="flex items-center justify-between rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 transition hover:bg-amber-500/15">
                    <div class="flex items-center gap-2.5">
                        <div class="flex size-7 shrink-0 items-center justify-center rounded-md bg-amber-500/20 text-amber-400">
                            <x-filament::icon :icon="Heroicon::Clock" class="h-4 w-4" />
                        </div>
                        <div class="flex flex-col">
                            <span class="text-xs font-bold text-amber-300">
                                {{ $oldPendingCount }} {{ $oldPendingCount === 1 ? 'envio' : 'envios' }}
                            </span>
                            <span class="text-[0.6875rem] text-amber-200/80">aguardando há mais de 7 dias</span>
                        </div>
                    </div>
                </div>
                @endif
                
                @if($expiredTokensCount > 0)
                    @if ($expiredTokensUrl)
                        <a href="{{ $expiredTokensUrl }}" class="flex items-center justify-between rounded-lg border border-rose-500/30 bg-rose-500/10 p-3 transition hover:bg-rose-500/15 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rose-500/50">
                            <div class="flex items-center gap-2.5">
                                <div class="flex size-7 shrink-0 items-center justify-center rounded-md bg-rose-500/20 text-rose-400">
                                    <x-filament::icon :icon="Heroicon::Key" class="h-4 w-4" />
                                </div>
                                <div class="flex flex-col">
                                    <span class="text-xs font-bold text-rose-300">
                                        {{ $expiredTokensCount }} {{ $expiredTokensCount === 1 ? 'token' : 'tokens' }}
                                    </span>
                                    <span class="text-[0.6875rem] text-rose-200/80">de acesso expirados</span>
                                </div>
                            </div>
                            <x-filament::icon :icon="Heroicon::ChevronRight" class="h-4 w-4 text-rose-400" />
                        </a>
                    @else
                        <div class="flex items-center justify-between rounded-lg border border-rose-500/30 bg-rose-500/10 p-3">
                            <div class="flex items-center gap-2.5">
                                <div class="flex size-7 shrink-0 items-center justify-center rounded-md bg-rose-500/20 text-rose-400">
                                    <x-filament::icon :icon="Heroicon::Key" class="h-4 w-4" />
                                </div>
                                <div class="flex flex-col">
                                    <span class="text-xs font-bold text-rose-300">
                                        {{ $expiredTokensCount }} {{ $expiredTokensCount === 1 ? 'token' : 'tokens' }}
                                    </span>
                                    <span class="text-[0.6875rem] text-rose-200/80">de acesso expirados</span>
                                </div>
                            </div>
                        </div>
                    @endif
                @endif
                
                @if($oldPendingCount == 0 && $expiredTokensCount == 0)
                <div class="flex items-center gap-3 rounded-lg border border-emerald-500/20 bg-emerald-950/25 p-3 text-emerald-300">
                    <div class="flex size-7 shrink-0 items-center justify-center rounded-md bg-emerald-500/15 text-emerald-400">
                        <x-filament::icon :icon="Heroicon::CheckCircle" class="h-4.5 w-4.5" />
                    </div>
                    <div class="flex flex-col">
                        <span class="text-xs font-bold text-emerald-300">Nenhuma pendência crítica</span>
                        <span class="text-[0.6875rem] text-emerald-400/70">Envios e chaves de acesso estão em dia.</span>
                    </div>
                </div>
                @endif
            </div>
        </div>
    </div>
</x-filament-widgets::widget>

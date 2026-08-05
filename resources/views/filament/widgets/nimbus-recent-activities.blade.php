@php
    use Filament\Support\Icons\Heroicon;
@endphp

<x-filament-widgets::widget>
    <div class="flex flex-col gap-6">
        
        {{-- Últimas atividades --}}
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center gap-2">
                    <x-filament::icon :icon="Heroicon::Bolt" class="h-5 w-5 text-gray-500" />
                    <h3 class="text-base font-medium text-gray-950 dark:text-white">Últimas atividades</h3>
                </div>
                <span class="text-xs font-medium text-gray-500 dark:text-gray-400">5 acessos mais recentes</span>
            </div>
            
            <ul class="divide-y divide-gray-200 dark:divide-white/10">
                @forelse($recentActivities as $activity)
                <li class="py-3 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="w-2 h-2 rounded-full bg-success-500"></div>
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-200">
                            Acesso realizado por {{ $activity->full_name }}
                        </span>
                    </div>
                    <span class="text-xs text-gray-500">{{ $activity->last_login_at?->diffForHumans() }}</span>
                </li>
                @empty
                <li class="py-3 text-sm text-gray-500">Nenhuma atividade recente encontrada.</li>
                @endforelse
            </ul>
        </div>

        {{-- Atenções necessárias --}}
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
            <div class="flex items-center gap-2 mb-4">
                <x-filament::icon :icon="Heroicon::ExclamationTriangle" class="h-5 w-5 text-amber-500" />
                <h3 class="text-base font-medium text-gray-950 dark:text-white">Atenções necessárias</h3>
            </div>
            
            <div class="flex flex-col gap-2">
                @if($oldPendingCount > 0)
                <div class="flex items-center justify-between rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-500/20 dark:bg-amber-500/10">
                    <div class="flex flex-col">
                        <span class="text-sm font-bold text-amber-800 dark:text-amber-200">{{ $oldPendingCount }} {{ $oldPendingCount === 1 ? 'envio' : 'envios' }}</span>
                        <span class="text-xs text-amber-700 dark:text-amber-300">aguardando há mais de 7 dias</span>
                    </div>
                    <x-filament::icon :icon="Heroicon::Clock" class="h-4 w-4 text-amber-600 dark:text-amber-300" />
                </div>
                @endif
                
                @if($expiredTokensCount > 0)
                    @if ($expiredTokensUrl)
                        <a href="{{ $expiredTokensUrl }}" class="flex items-center justify-between rounded-lg border border-danger-200 bg-danger-50 p-3 transition hover:bg-danger-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-danger-500/50 dark:border-danger-500/20 dark:bg-danger-500/10 dark:hover:bg-danger-500/15">
                            <div class="flex flex-col">
                                <span class="text-sm font-bold text-danger-800 dark:text-danger-200">{{ $expiredTokensCount }} {{ $expiredTokensCount === 1 ? 'token' : 'tokens' }}</span>
                                <span class="text-xs text-danger-700 dark:text-danger-300">de acesso expirados</span>
                            </div>
                            <x-filament::icon :icon="Heroicon::ChevronRight" class="h-4 w-4 text-danger-600 dark:text-danger-300" />
                        </a>
                    @else
                        <div class="flex items-center justify-between rounded-lg border border-danger-200 bg-danger-50 p-3 dark:border-danger-500/20 dark:bg-danger-500/10">
                            <div class="flex flex-col">
                                <span class="text-sm font-bold text-danger-800 dark:text-danger-200">{{ $expiredTokensCount }} {{ $expiredTokensCount === 1 ? 'token' : 'tokens' }}</span>
                                <span class="text-xs text-danger-700 dark:text-danger-300">de acesso expirados</span>
                            </div>
                            <x-filament::icon :icon="Heroicon::ExclamationCircle" class="h-4 w-4 text-danger-600 dark:text-danger-300" />
                        </div>
                    @endif
                @endif
                
                @if($oldPendingCount == 0 && $expiredTokensCount == 0)
                <div class="rounded-lg bg-gray-50 p-3">
                    <span class="text-sm text-gray-500">Nenhuma pendência crítica.</span>
                </div>
                @endif
            </div>
        </div>
        
    </div>
</x-filament-widgets::widget>

@php
    use Filament\Support\Icons\Heroicon;
@endphp

<x-filament-widgets::widget>
    <div class="flex flex-col gap-6">
        
        {{-- Últimas Atividades --}}
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center gap-2">
                    <x-filament::icon :icon="Heroicon::Bolt" class="h-5 w-5 text-gray-500" />
                    <h3 class="text-base font-medium text-gray-950 dark:text-white">Últimas atividades</h3>
                </div>
                <a href="#" class="text-xs text-primary-600 hover:text-primary-500 font-medium">Ver todas</a>
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

        {{-- Atenções Necessárias --}}
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
            <div class="flex items-center gap-2 mb-4">
                <x-filament::icon :icon="Heroicon::ExclamationTriangle" class="h-5 w-5 text-amber-500" />
                <h3 class="text-base font-medium text-gray-950 dark:text-white">Atenções necessárias</h3>
            </div>
            
            <div class="flex flex-col gap-2">
                @if($oldPendingCount > 0)
                <a href="#" class="flex items-center justify-between rounded-lg bg-orange-50 p-3 hover:bg-orange-100 transition">
                    <div class="flex flex-col">
                        <span class="text-sm font-bold text-orange-800">{{ $oldPendingCount }} envios</span>
                        <span class="text-xs text-orange-600">aguardando há mais de 7 dias</span>
                    </div>
                    <x-filament::icon :icon="Heroicon::ChevronRight" class="h-4 w-4 text-orange-400" />
                </a>
                @endif
                
                @if($expiredTokensCount > 0)
                <a href="#" class="flex items-center justify-between rounded-lg bg-rose-50 p-3 hover:bg-rose-100 transition">
                    <div class="flex flex-col">
                        <span class="text-sm font-bold text-rose-800">{{ $expiredTokensCount }} tokens</span>
                        <span class="text-xs text-rose-600">de acesso expirados</span>
                    </div>
                    <x-filament::icon :icon="Heroicon::ChevronRight" class="h-4 w-4 text-rose-400" />
                </a>
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

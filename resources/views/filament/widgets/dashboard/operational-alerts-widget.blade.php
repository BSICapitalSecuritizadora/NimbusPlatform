<x-filament-widgets::widget>
    <x-filament::section>
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold leading-6 text-gray-950 dark:text-white flex items-center gap-2">
                <x-heroicon-o-bell-alert class="w-6 h-6 text-warning-500" />
                Alertas Operacionais
            </h3>
        </div>

        @if($alerts->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">Nenhum alerta crítico no momento.</p>
        @else
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                @foreach($alerts as $alert)
                    <a href="{{ $alert['url'] }}" class="block p-4 rounded-xl border border-gray-200 bg-white shadow-sm hover:ring-2 hover:ring-primary-500 transition dark:bg-gray-900 dark:border-gray-800">
                        <div class="flex items-center gap-3">
                            <div class="p-2 bg-{{ $alert['color'] }}-100 text-{{ $alert['color'] }}-600 rounded-lg dark:bg-{{ $alert['color'] }}-900/50 dark:text-{{ $alert['color'] }}-400">
                                <x-dynamic-component :component="$alert['icon']" class="w-5 h-5" />
                            </div>
                            <div>
                                <h4 class="text-sm font-bold text-gray-900 dark:text-white">{{ $alert['title'] }}</h4>
                                <p class="text-xs text-gray-500 mt-1 dark:text-gray-400">{{ $alert['description'] }}</p>
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>

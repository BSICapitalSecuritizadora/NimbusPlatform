<x-filament-widgets::widget>
    <x-filament::section>
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold leading-6 text-gray-950 dark:text-white flex items-center gap-2">
                <x-heroicon-o-bell-alert class="w-6 h-6 text-warning-500" />
                Alertas operacionais
            </h3>
        </div>

        @if($alerts->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">Nenhum alerta crítico no momento.</p>
        @else
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                @foreach($alerts as $alert)
                    @php
                        $iconClasses = match ($alert['color']) {
                            'danger' => 'bg-danger-100 text-danger-700 dark:bg-danger-500/15 dark:text-danger-300',
                            'warning' => 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
                            'info' => 'bg-info-100 text-info-700 dark:bg-info-500/20 dark:text-info-200',
                            default => 'bg-gray-100 text-gray-700 dark:bg-gray-500/15 dark:text-gray-300',
                        };
                    @endphp

                    @if ($alert['url'])
                        <a href="{{ $alert['url'] }}" class="block rounded-xl border border-gray-200 bg-white p-4 shadow-sm transition hover:ring-2 hover:ring-info-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-info-500 dark:border-gray-800 dark:bg-gray-900">
                    @else
                        <div class="block rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                    @endif
                        <div class="flex items-center gap-3">
                            <div class="rounded-lg p-2 {{ $iconClasses }}">
                                <x-dynamic-component :component="$alert['icon']" class="w-5 h-5" />
                            </div>
                            <div>
                                <h4 class="text-sm font-bold text-gray-900 dark:text-white">{{ $alert['title'] }}</h4>
                                <p class="text-xs text-gray-500 mt-1 dark:text-gray-400">{{ $alert['description'] }}</p>
                            </div>
                        </div>
                    @if ($alert['url'])
                        </a>
                    @else
                        </div>
                    @endif
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>

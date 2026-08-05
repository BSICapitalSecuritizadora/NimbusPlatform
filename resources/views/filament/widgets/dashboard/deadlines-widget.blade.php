<x-filament-widgets::widget>
    <x-filament::section>
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold leading-6 text-gray-950 dark:text-white flex items-center gap-2">
                <x-heroicon-o-calendar-days class="w-6 h-6 text-primary-500" />
                Prazos e vencimentos (obrigações)
            </h3>
        </div>

        @if(empty($groups))
            <p class="text-sm text-gray-500">Você não tem permissão para visualizar obrigações.</p>
        @else
            <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
                @foreach($groups as $title => $data)
                    @php
                        $badgeClasses = match ($data['color']) {
                            'danger' => 'bg-danger-100 text-danger-700 dark:bg-danger-500/15 dark:text-danger-300',
                            'warning' => 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
                            default => 'bg-gray-100 text-gray-700 dark:bg-gray-500/15 dark:text-gray-300',
                        };
                        $focusClasses = match ($data['color']) {
                            'danger' => 'hover:ring-danger-500 focus-visible:ring-danger-500',
                            'warning' => 'hover:ring-amber-500 focus-visible:ring-amber-500',
                            default => 'hover:ring-gray-500 focus-visible:ring-gray-500',
                        };
                    @endphp
                    <div class="flex flex-col h-full bg-gray-50 dark:bg-gray-800 rounded-xl border border-gray-100 dark:border-gray-700">
                        <div class="px-3 py-2 border-b border-gray-100 dark:border-gray-700 flex justify-between items-center bg-white dark:bg-gray-900 rounded-t-xl">
                            <span class="text-xs font-bold text-gray-700 dark:text-gray-300 uppercase tracking-wider">{{ $title }}</span>
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $badgeClasses }}">
                                {{ $data['count'] }}
                            </span>
                        </div>
                        <div class="p-3 flex-1 overflow-y-auto max-h-60 space-y-2">
                            @if($data['items']->isEmpty())
                                <p class="text-xs text-gray-400 text-center mt-2">Nenhum item.</p>
                            @else
                                @foreach($data['items'] as $item)
                                    <a href="{{ \App\Filament\Resources\Emissions\EmissionResource::getUrl('edit', ['record' => $item->emission_id, 'relation' => \App\Filament\Resources\Emissions\EmissionResource\RelationManagers\ObligationsRelationManager::class]) }}" class="block rounded border border-gray-200 bg-white p-2 transition hover:ring-1 focus-visible:outline-none focus-visible:ring-1 dark:border-gray-700 dark:bg-gray-900 {{ $focusClasses }}">
                                        <div class="text-xs font-semibold text-gray-800 dark:text-gray-200 truncate" title="{{ $item->title }}">{{ $item->title }}</div>
                                        <div class="text-[10px] text-gray-500 truncate mt-0.5">{{ $item->emission->name ?? 'Sem emissão' }}</div>
                                    </a>
                                @endforeach
                                @if($data['count'] > 5)
                                    @if (\App\Filament\Pages\ObligationDashboard::canAccess())
                                        <a href="{{ \App\Filament\Pages\ObligationDashboard::getUrl() }}" class="mt-2 block text-center text-xs text-info-700 hover:underline dark:text-info-200">Ver todos ({{ $data['count'] }})</a>
                                    @else
                                        <span class="mt-2 block text-center text-xs text-gray-500">{{ $data['count'] - 5 }} itens adicionais</span>
                                    @endif
                                @endif
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>

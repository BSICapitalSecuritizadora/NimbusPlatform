<x-filament-widgets::widget>
    <x-filament::section>
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold leading-6 text-gray-950 dark:text-white flex items-center gap-2">
                <x-heroicon-o-bars-3-bottom-left class="w-6 h-6 text-gray-500" />
                Atividades recentes
            </h3>
        </div>

        @if($activities->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">Nenhuma atividade recente encontrada ou sem permissão.</p>
        @else
            <div class="space-y-4">
                @foreach($activities as $activity)
                    @php
                        $causerName = $activity->causer ? $activity->causer->name : 'Sistema';
                        
                        $subjectType = $activity->subject_type ? class_basename($activity->subject_type) : 'Registro';
                        $description = match($activity->description) {
                            'created' => 'criou',
                            'updated' => 'atualizou',
                            'deleted' => 'removeu',
                            'restored' => 'restaurou',
                            default => $activity->description,
                        };
                        
                        $markerClasses = match($activity->description) {
                            'created' => 'bg-success-500 ring-success-100 dark:ring-success-500/20',
                            'updated' => 'bg-info-500 ring-info-100 dark:ring-info-500/20',
                            'deleted' => 'bg-danger-500 ring-danger-100 dark:ring-danger-500/20',
                            default => 'bg-gray-500 ring-gray-100 dark:ring-gray-500/20',
                        };
                    @endphp
                    <div class="flex gap-4">
                        <div class="flex-shrink-0 mt-1">
                            <div class="h-2 w-2 rounded-full ring-4 {{ $markerClasses }}"></div>
                        </div>
                        <div class="flex flex-col">
                            <p class="text-sm text-gray-800 dark:text-gray-200">
                                <span class="font-semibold">{{ $causerName }}</span> 
                                {{ $description }} 
                                <span class="font-medium text-gray-600 dark:text-gray-400">{{ $subjectType }}</span>
                                @if($activity->subject_id)
                                    #{{ $activity->subject_id }}
                                @endif
                            </p>
                            <span class="text-xs text-gray-500 mt-0.5">{{ $activity->created_at->diffForHumans() }}</span>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>

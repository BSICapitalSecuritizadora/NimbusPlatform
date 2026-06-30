<x-filament-widgets::widget>
    <x-filament::section>
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold leading-6 text-gray-950 dark:text-white flex items-center gap-2">
                <x-heroicon-o-bars-3-bottom-left class="w-6 h-6 text-gray-500" />
                Atividades Recentes
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
                        
                        $color = match($activity->description) {
                            'created' => 'success',
                            'updated' => 'info',
                            'deleted' => 'danger',
                            default => 'gray',
                        };
                    @endphp
                    <div class="flex gap-4">
                        <div class="flex-shrink-0 mt-1">
                            <div class="w-2 h-2 rounded-full bg-{{ $color }}-500 ring-4 ring-{{ $color }}-100 dark:ring-{{ $color }}-900/30"></div>
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

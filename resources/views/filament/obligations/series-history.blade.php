<div class="space-y-4">
    @forelse ($activities as $activity)
        <article
            wire:key="series-activity-{{ $activity->id }}"
            class="rounded-xl border border-gray-200 p-4 dark:border-white/10"
        >
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <p class="font-medium text-gray-950 dark:text-white">
                        {{ $activity->description ?: $activity->event }}
                    </p>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        {{ $activity->causer?->name ?? 'Sistema' }}
                    </p>
                </div>

                <time class="text-sm text-gray-500 dark:text-gray-400">
                    {{ $activity->created_at?->format('d/m/Y H:i') }}
                </time>
            </div>

            @if ($activity->properties->isNotEmpty())
                <dl class="mt-3 grid gap-2 text-sm sm:grid-cols-2">
                    @foreach ($activity->properties->except(['old', 'attributes']) as $key => $value)
                        <div>
                            <dt class="font-medium text-gray-700 dark:text-gray-300">{{ str($key)->headline() }}</dt>
                            <dd class="text-gray-600 dark:text-gray-400">
                                {{ is_scalar($value) || $value === null ? ($value ?? '—') : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}
                            </dd>
                        </div>
                    @endforeach
                </dl>
            @endif
        </article>
    @empty
        <p class="text-sm text-gray-500 dark:text-gray-400">Nenhum evento foi registrado para esta recorrência.</p>
    @endforelse
</div>

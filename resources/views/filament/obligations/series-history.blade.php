<div class="space-y-3">
    @forelse ($activities as $activity)
        <article
            wire:key="series-activity-{{ $activity->id }}"
            class="rounded-xl border border-[#1d4554]/60 bg-[#081a22] p-3.5 shadow-sm"
        >
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <p class="font-bold text-xs text-white">
                        {{ $activity->description ?: $activity->event }}
                    </p>
                    <p class="mt-0.5 text-[11px] text-slate-400">
                        {{ $activity->causer?->name ?? 'Sistema' }}
                    </p>
                </div>

                <time class="text-[11px] font-mono text-slate-400">
                    {{ $activity->created_at?->format('d/m/Y H:i') }}
                </time>
            </div>

            @if ($activity->properties->isNotEmpty())
                <dl class="mt-2.5 grid gap-2 text-xs sm:grid-cols-2 border-t border-[#1d4554]/30 pt-2">
                    @foreach ($activity->properties->except(['old', 'attributes']) as $key => $value)
                        <div class="rounded bg-[#0c232e] p-2 border border-[#1d4554]/40">
                            <dt class="font-bold text-[10px] uppercase text-slate-400">{{ str($key)->headline() }}</dt>
                            <dd class="mt-0.5 text-slate-200 font-mono">
                                {{ is_scalar($value) || $value === null ? ($value ?? '—') : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}
                            </dd>
                        </div>
                    @endforeach
                </dl>
            @endif
        </article>
    @empty
        <p class="text-xs text-slate-400 text-center py-6">Nenhum evento foi registrado para esta recorrência.</p>
    @endforelse
</div>

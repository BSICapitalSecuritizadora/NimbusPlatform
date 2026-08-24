@php
    use App\Support\ActivityLog\ActivityPresenter;

    $items = $records->map(fn ($record) => ActivityPresenter::present($record));

    $dotClasses = [
        'success' => 'bg-emerald-50 text-emerald-600 ring-emerald-600/20 dark:bg-emerald-500/15 dark:text-emerald-400 dark:ring-emerald-400/25',
        'danger' => 'bg-rose-50 text-rose-600 ring-rose-600/20 dark:bg-rose-500/15 dark:text-rose-400 dark:ring-rose-400/25',
        'info' => 'bg-sky-50 text-sky-600 ring-sky-600/20 dark:bg-sky-500/15 dark:text-sky-400 dark:ring-sky-400/25',
        'warning' => 'bg-amber-50 text-amber-600 ring-amber-600/20 dark:bg-amber-500/15 dark:text-amber-400 dark:ring-amber-400/25',
        'gray' => 'bg-slate-100 text-slate-600 ring-slate-500/10 dark:bg-slate-800 dark:text-slate-300 dark:ring-white/10',
    ];
@endphp

<div class="fi-activity-timeline-wrapper px-2 py-3 sm:px-6 sm:py-4">
    @if ($items->isEmpty())
        <div class="flex flex-col items-center justify-center py-12 text-center">
            <div class="flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 text-gray-400 dark:bg-white/5 dark:text-gray-500">
                <x-filament::icon icon="heroicon-o-clock" class="h-6 w-6" />
            </div>
            <h3 class="mt-3 text-sm font-semibold text-gray-950 dark:text-white">Nenhuma movimentação registrada</h3>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400 max-w-sm">
                As movimentações e alterações desta operação aparecerão aqui em ordem cronológica.
            </p>
        </div>
    @else
        <ol aria-label="Linha do tempo da operação" class="relative ms-4 border-s border-gray-200 dark:border-white/10 space-y-6 sm:space-y-7">
            @foreach ($items as $item)
                @php
                    $statusChange = $item->statusChange();
                    $observationChanges = $item->observationChanges();
                    $regularChanges = $item->regularChanges();
                    $hasInternal = $item->hasInternalNote();
                @endphp

                <li class="relative ps-6 sm:ps-7">
                    {{-- Marcador do evento no trilho vertical --}}
                    <span @class([
                        'absolute -start-[17px] top-1.5 flex h-8 w-8 items-center justify-center rounded-full ring-2 shadow-xs transition-transform duration-150',
                        $dotClasses[$item->color] ?? $dotClasses['gray'],
                    ]) aria-hidden="true">
                        <x-filament::icon :icon="$item->icon" class="h-4 w-4" />
                    </span>

                    {{-- Card do Evento --}}
                    <div class="fi-activity-card rounded-xl border border-gray-200/80 bg-white p-4 shadow-xs transition-all duration-150 hover:border-gray-300 dark:border-white/10 dark:bg-[#0c222b] dark:hover:border-white/15 dark:hover:bg-[#0e2733]">
                        {{-- ── Cabeçalho do Evento ── --}}
                        <div class="flex flex-col gap-y-2 sm:flex-row sm:items-center sm:justify-between border-b border-gray-100 pb-3 dark:border-white/5">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">
                                    {{ $item->title }}
                                </h3>

                                @if ($item->eventLabel && ! in_array($item->title, [$item->eventLabel, 'Registro atualizado']))
                                    <x-filament::badge :color="$item->color ?? 'gray'" size="sm">
                                        {{ $item->eventLabel }}
                                    </x-filament::badge>
                                @endif

                                @if ($hasInternal)
                                    <span class="inline-flex items-center gap-1 rounded-md bg-amber-500/10 px-2 py-0.5 text-[11px] font-medium text-amber-700 dark:bg-amber-400/10 dark:text-amber-300 ring-1 ring-amber-500/20">
                                        <x-filament::icon icon="heroicon-m-lock-closed" class="h-3 w-3" />
                                        Uso interno
                                    </span>
                                @endif
                            </div>

                            {{-- Autor e Data/Hora --}}
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                                <div class="inline-flex items-center gap-1.5 font-medium text-gray-700 dark:text-gray-200">
                                    @if ($item->isSystem())
                                        <span class="flex h-5 w-5 items-center justify-center rounded-full bg-slate-100 text-slate-600 dark:bg-white/10 dark:text-slate-300" aria-hidden="true">
                                            <x-filament::icon icon="heroicon-m-cpu-chip" class="h-3.5 w-3.5" />
                                        </span>
                                        <span>Sistema</span>
                                    @elseif ($item->authorAvatarUrl)
                                        <x-filament::avatar :src="$item->authorAvatarUrl" :alt="$item->author" size="sm" class="!h-5 !w-5" />
                                        <span>{{ $item->author }}</span>
                                    @else
                                        <span class="flex h-5 w-5 items-center justify-center rounded-full bg-teal-500/10 text-[10px] font-semibold text-teal-700 ring-1 ring-teal-500/20 dark:bg-teal-400/15 dark:text-teal-300 dark:ring-teal-400/25" aria-hidden="true">
                                            {{ $item->authorInitials() }}
                                        </span>
                                        <span>{{ $item->author }}</span>
                                    @endif
                                </div>

                                <span aria-hidden="true" class="text-gray-300 dark:text-gray-600">·</span>

                                <time
                                    datetime="{{ $item->occurredAt->toIso8601String() }}"
                                    title="{{ $item->occurredAt->format('d/m/Y H:i:s') }}"
                                    class="font-medium text-gray-600 dark:text-gray-300"
                                >
                                    {{ $item->occurredAt->format('d/m/Y \à\s H:i') }}
                                </time>

                                <span aria-hidden="true" class="text-gray-300 dark:text-gray-600">·</span>

                                <span class="text-gray-400 dark:text-gray-500">
                                    {{ $item->occurredAt->diffForHumans() }}
                                </span>
                            </div>
                        </div>

                        {{-- ── Nível 1: Resumo Operacional ── --}}
                        <div class="mt-3 space-y-2.5">
                            {{-- 1. Transição de Status --}}
                            @if ($statusChange)
                                <div class="flex flex-wrap items-center gap-2 rounded-lg bg-gray-50/80 px-3 py-2 text-xs dark:bg-white/[0.03] ring-1 ring-gray-950/5 dark:ring-white/5">
                                    <span class="font-medium text-gray-500 dark:text-gray-400">{{ $statusChange->label }}:</span>

                                    @if ($statusChange->hasTransition())
                                        <x-filament::badge :color="$statusChange->oldColor ?? 'gray'" size="sm">
                                            {{ $statusChange->old ?? '—' }}
                                        </x-filament::badge>

                                        <x-filament::icon icon="heroicon-m-arrow-long-right" class="h-4 w-4 text-gray-400 dark:text-gray-500" aria-hidden="true" />
                                    @endif

                                    <x-filament::badge :color="$statusChange->newColor ?? 'gray'" size="sm">
                                        {{ $statusChange->new ?? '—' }}
                                    </x-filament::badge>
                                </div>
                            @endif

                            {{-- 2. Campos Alterados Regulares (Visualização Rápida) --}}
                            @if (count($regularChanges))
                                <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5 text-xs text-gray-700 dark:text-gray-300">
                                    @foreach ($regularChanges as $change)
                                        <div class="inline-flex items-center gap-1.5">
                                            <span class="font-medium text-gray-500 dark:text-gray-400">{{ $change->label }}:</span>

                                            @if ($change->hasTransition())
                                                <span class="text-gray-400 dark:text-gray-500 line-through text-[11px]">{{ $change->old }}</span>
                                                <x-filament::icon icon="heroicon-m-arrow-right" class="h-3 w-3 text-gray-400" aria-hidden="true" />
                                                <span class="font-semibold text-gray-900 dark:text-white">{{ $change->new }}</span>
                                            @else
                                                <span class="font-semibold text-gray-900 dark:text-white">{{ $change->new ?? '—' }}</span>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            @endif

                            {{-- 3. Observações / Notas / Comentários (Container de Citação com Clamp) --}}
                            @if (count($observationChanges))
                                @foreach ($observationChanges as $obs)
                                    <div
                                        x-data="{ expanded: false }"
                                        class="rounded-lg border-s-2 border-teal-500 bg-gray-50/70 p-3 dark:border-teal-400 dark:bg-white/[0.03] text-xs"
                                    >
                                        <div class="flex items-center justify-between font-semibold text-gray-700 dark:text-gray-300 mb-1">
                                            <span class="flex items-center gap-1.5">
                                                <x-filament::icon icon="heroicon-m-chat-bubble-bottom-center-text" class="h-3.5 w-3.5 text-teal-600 dark:text-teal-400" />
                                                {{ $obs->label }}
                                            </span>

                                            @if ($obs->isInternal)
                                                <span class="text-[10px] font-normal text-amber-600 dark:text-amber-400">Nota interna</span>
                                            @endif
                                        </div>

                                        <p
                                            class="text-gray-600 dark:text-gray-300 whitespace-pre-line leading-relaxed"
                                            :class="!expanded ? 'line-clamp-3' : ''"
                                        >
                                            {{ $obs->new ?? '—' }}
                                        </p>

                                        @if (strlen($obs->new ?? '') > 160)
                                            <button
                                                type="button"
                                                x-on:click="expanded = !expanded"
                                                class="mt-1.5 inline-flex items-center gap-1 text-[11px] font-medium text-teal-600 hover:text-teal-700 dark:text-teal-400 dark:hover:text-teal-300"
                                            >
                                                <span x-text="expanded ? 'Mostrar menos' : 'Ver mais da observação'">Ver mais</span>
                                                <x-filament::icon icon="heroicon-m-chevron-down" class="h-3 w-3 transition-transform duration-150" x-bind:class="expanded && 'rotate-180'" />
                                            </button>
                                        @endif
                                    </div>
                                @endforeach
                            @endif
                        </div>

                        {{-- ── Nível 2: Detalhes Técnicos & Auditoria (Expansível) ── --}}
                        <div x-data="{ open: false, copied: false, copiedJson: false }" class="mt-3 pt-2 border-t border-gray-100/80 dark:border-white/5">
                            <div class="flex items-center justify-between">
                                <button
                                    type="button"
                                    x-on:click="open = ! open"
                                    class="inline-flex items-center gap-1.5 text-xs font-medium text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200 transition-colors py-1 px-2 -ms-2 rounded-md hover:bg-gray-100 dark:hover:bg-white/5 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gray-400"
                                >
                                    <x-filament::icon
                                        icon="heroicon-m-chevron-right"
                                        class="h-3.5 w-3.5 transition-transform duration-150"
                                        x-bind:class="open && 'rotate-90'"
                                    />
                                    <span x-text="open ? 'Ocultar detalhes técnicos' : 'Ver detalhes técnicos'">Ver detalhes técnicos</span>
                                    <span class="text-[11px] text-gray-400 dark:text-gray-500">({{ count($item->technicalDetails) }} campos)</span>
                                </button>

                                <div x-show="open" x-cloak class="flex items-center gap-2">
                                    <button
                                        type="button"
                                        x-on:click="navigator.clipboard.writeText($refs.technical.innerText).then(() => { copied = true; setTimeout(() => copied = false, 2000) })"
                                        class="inline-flex items-center gap-1 text-[11px] font-medium text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200 transition-colors"
                                        title="Copiar lista de detalhes técnicos"
                                    >
                                        <x-filament::icon icon="heroicon-m-clipboard-document" class="h-3.5 w-3.5" />
                                        <span x-text="copied ? 'Copiado!' : 'Copiar detalhes'">Copiar detalhes</span>
                                    </button>
                                </div>
                            </div>

                            <div
                                x-show="open"
                                x-cloak
                                x-collapse
                                class="mt-2.5 space-y-3 rounded-lg bg-gray-50/90 p-3.5 text-xs ring-1 ring-gray-950/5 dark:bg-black/30 dark:ring-white/10"
                            >
                                {{-- A. Tabela Estruturada de Campos Alterados --}}
                                @if (count($item->changes))
                                    <div class="space-y-1.5">
                                        <h4 class="font-semibold text-gray-700 dark:text-gray-200 flex items-center gap-1.5 text-[11px] uppercase tracking-wider">
                                            <x-filament::icon icon="heroicon-m-adjustments-horizontal" class="h-3.5 w-3.5 text-gray-400 dark:text-gray-500" />
                                            Campos alterados
                                        </h4>

                                        <div class="overflow-x-auto rounded border border-gray-200/80 bg-white dark:border-white/10 dark:bg-[#07151c]">
                                            <table class="w-full text-left text-xs">
                                                <thead class="bg-gray-50 text-[10px] font-semibold uppercase tracking-wider text-gray-500 dark:bg-white/5 dark:text-gray-400 border-b border-gray-200/70 dark:border-white/5">
                                                    <tr>
                                                        <th class="px-2.5 py-1.5">Campo</th>
                                                        <th class="px-2.5 py-1.5">Valor anterior</th>
                                                        <th class="px-2.5 py-1.5">Novo valor</th>
                                                    </tr>
                                                </thead>
                                                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                                                    @foreach ($item->changes as $change)
                                                        <tr>
                                                            <td class="px-2.5 py-1.5 font-medium text-gray-700 dark:text-gray-300 whitespace-nowrap">{{ $change->label }}</td>
                                                            <td class="px-2.5 py-1.5 text-gray-500 dark:text-gray-400 font-mono text-[11px]">{{ $change->old ?? '—' }}</td>
                                                            <td class="px-2.5 py-1.5 text-gray-900 dark:text-white font-mono text-[11px] font-medium">{{ $change->new ?? '—' }}</td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                @endif

                                {{-- B. Metadados de Auditoria --}}
                                <div class="space-y-1.5">
                                    <h4 class="font-semibold text-gray-700 dark:text-gray-200 flex items-center gap-1.5 text-[11px] uppercase tracking-wider">
                                        <x-filament::icon icon="heroicon-m-shield-check" class="h-3.5 w-3.5 text-gray-400 dark:text-gray-500" />
                                        Metadados de auditoria
                                    </h4>

                                    <dl class="grid gap-x-4 gap-y-1.5 font-mono text-[11px] sm:grid-cols-[minmax(max-content,14rem)_1fr] rounded border border-gray-200/80 bg-white p-2.5 dark:border-white/10 dark:bg-[#07151c]">
                                        @foreach ($item->technicalDetails as $detail)
                                            <dt class="text-gray-500 dark:text-gray-400">{{ $detail['label'] }}</dt>
                                            <dd class="break-all text-gray-800 dark:text-gray-200 font-medium">{{ $detail['value'] }}</dd>
                                        @endforeach
                                    </dl>
                                </div>

                                {{-- C. Payload Bruto (JSON Prettified) --}}
                                @if ($item->rawJson)
                                    <details class="group rounded border border-gray-200/80 bg-white dark:border-white/10 dark:bg-[#07151c] p-2.5">
                                        <summary class="cursor-pointer font-medium text-gray-700 dark:text-gray-300 select-none flex items-center justify-between text-[11px]">
                                            <span class="flex items-center gap-1.5">
                                                <x-filament::icon icon="heroicon-m-code-bracket" class="h-3.5 w-3.5 text-gray-400" />
                                                Payload JSON completo
                                            </span>
                                            <span class="text-xs text-gray-400 group-open:rotate-180 transition-transform">▼</span>
                                        </summary>

                                        <div class="mt-2 relative">
                                            <button
                                                type="button"
                                                x-on:click="navigator.clipboard.writeText($refs.rawJsonPre.innerText).then(() => { copiedJson = true; setTimeout(() => copiedJson = false, 2000) })"
                                                class="absolute top-2 right-2 rounded bg-gray-200 px-2 py-1 text-[10px] font-medium text-gray-700 hover:bg-gray-300 dark:bg-white/10 dark:text-gray-300 dark:hover:bg-white/20"
                                            >
                                                <span x-text="copiedJson ? 'Copiado!' : 'Copiar JSON'">Copiar JSON</span>
                                            </button>

                                            <pre x-ref="rawJsonPre" class="max-h-56 overflow-y-auto rounded bg-gray-100 p-3 font-mono text-[10.5px] leading-relaxed text-gray-800 dark:bg-black/50 dark:text-gray-200 whitespace-pre overflow-x-auto"><code>{{ $item->rawJson }}</code></pre>
                                        </div>
                                    </details>
                                @endif

                                <pre x-ref="technical" class="hidden">{{ $item->technicalDetailsAsText }}</pre>
                            </div>
                        </div>
                    </div>
                </li>
            @endforeach
        </ol>
    @endif
</div>

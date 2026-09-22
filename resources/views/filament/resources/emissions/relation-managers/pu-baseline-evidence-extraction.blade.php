@php
    /**
     * @var string|null $message
     * @var array<string, mixed>|null $extraction
     * @var list<array{label: string, suggested: string}> $divergences
     * @var list<array{value: string, page: int|null, excerpt: string|null, url: string|null}> $occurrences
     * @var bool $documentOpenable
     */
    $suggestion = $extraction['suggestion'] ?? [];
    $referenceLabel = $suggestion['reference_label'] ?? null;
    $excerpt = $suggestion['excerpt'] ?? null;
    $analyzedAt = filled($extraction['analyzed_at'] ?? null)
        ? \App\Support\BusinessTime::at(\Carbon\CarbonImmutable::parse($extraction['analyzed_at']))->format('d/m/Y H:i')
        : null;
@endphp

<div class="space-y-3 text-sm">
    @if (filled($message))
        <p>{{ $message }}</p>
    @endif

    @if (filled($referenceLabel) || filled($excerpt))
        <div class="space-y-2 rounded-lg border border-gray-950/10 bg-white/70 p-3 dark:border-white/10 dark:bg-white/5">
            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Fonte identificada pela IA</div>

            @if (filled($referenceLabel))
                <div class="font-medium text-gray-950 dark:text-white">{{ $referenceLabel }}</div>
            @endif

            @if (filled($excerpt))
                <blockquote class="border-l-2 border-primary-500 pl-3 text-xs italic leading-relaxed text-gray-700 dark:text-gray-300">
                    “{{ $excerpt }}”
                </blockquote>
            @endif
        </div>
    @endif

    @if ($occurrences !== [])
        <div class="space-y-2 rounded-lg border border-gray-950/10 bg-white/70 p-3 dark:border-white/10 dark:bg-white/5">
            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Ocorrências com valores diferentes</div>

            <ul class="space-y-2">
                @foreach ($occurrences as $occurrence)
                    <li>
                        <div class="font-medium text-gray-950 dark:text-white">
                            {{ $occurrence['value'] }}
                            @if ($occurrence['page'] !== null)
                                @if ($occurrence['url'] !== null)
                                    · <a href="{{ $occurrence['url'] }}" target="_blank" rel="noopener" class="text-primary-600 underline hover:no-underline dark:text-primary-400">página {{ $occurrence['page'] }}</a>
                                @else
                                    · página {{ $occurrence['page'] }}
                                @endif
                            @endif
                        </div>
                        @if (filled($occurrence['excerpt']))
                            <blockquote class="mt-1 border-l-2 border-gray-300 pl-3 text-xs italic text-gray-700 dark:border-gray-600 dark:text-gray-300">
                                “{{ $occurrence['excerpt'] }}”
                            </blockquote>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ((filled($referenceLabel) || filled($excerpt) || $occurrences !== []) && ! $documentOpenable)
        <p class="text-xs text-gray-500 dark:text-gray-400">
            Abertura do arquivo indisponível: o documento ainda não concluiu a verificação de segurança ou seu perfil não acessa documentos.
        </p>
    @endif

    @if ($divergences !== [])
        <div class="text-xs">
            <p class="font-medium">Campos que você editou foram mantidos. A sugestão da IA para eles era:</p>
            <ul class="mt-1 list-disc space-y-0.5 pl-5">
                @foreach ($divergences as $divergence)
                    <li>{{ $divergence['label'] }}: <span class="font-mono">{{ \Illuminate\Support\Str::limit($divergence['suggested'], 120) }}</span></li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($extraction !== null)
        <p class="text-xs text-gray-500 dark:text-gray-400">
            {{ $extraction['model'] }}
            @if ($analyzedAt)
                · analisado em {{ $analyzedAt }}
            @endif
            @if (($extraction['source'] ?? null) === 'cache')
                · reaproveitado de análise anterior do mesmo arquivo
            @endif
            · a evidência seguirá pendente de revisão
        </p>
    @endif
</div>

@php
    /**
     * "Próxima ação" das telas do Quadro de Vendas.
     *
     * Apresentação apenas: quem monta o conteúdo é a página, a partir do estado
     * que ela já carregou. A cor acompanha, mas nunca carrega sozinha o sentido --
     * o texto diz o que fazer.
     *
     * @var array{headline: string, detail: string|null, color: string, icon: string, items?: list<string>} $nextAction
     */
    $border = match ($nextAction['color']) {
        'danger' => 'border-danger-300 dark:border-danger-700',
        'warning' => 'border-warning-300 dark:border-warning-700',
        default => 'border-gray-200 dark:border-gray-700',
    };

    $iconColor = match ($nextAction['color']) {
        'danger' => 'text-danger-600 dark:text-danger-400',
        'warning' => 'text-warning-600 dark:text-warning-400',
        'success' => 'text-success-600 dark:text-success-400',
        'info' => 'text-info-700 dark:text-info-400',
        default => 'text-gray-500 dark:text-gray-400',
    };
@endphp

<div class="rounded-lg border p-4 {{ $border }}" data-sales-board-next-action>
    <div class="flex items-start gap-3">
        <x-filament::icon :icon="$nextAction['icon']" class="mt-0.5 h-5 w-5 shrink-0 {{ $iconColor }}" />

        <div class="min-w-0">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Próxima ação</p>
            <p class="mt-1 text-sm font-semibold">{{ $nextAction['headline'] }}</p>

            @if (filled($nextAction['detail'] ?? null))
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $nextAction['detail'] }}</p>
            @endif

            @if (! empty($nextAction['items'] ?? []))
                <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-gray-600 dark:text-gray-300">
                    @foreach ($nextAction['items'] as $item)
                        <li>{{ $item }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</div>

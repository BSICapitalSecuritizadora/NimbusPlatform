@php
    /** @var \App\Models\ObligationGenerationRun|null $run */
    use App\Models\ObligationGenerationRun;

    $palette = match ($run?->status) {
        ObligationGenerationRun::STATUS_COMPLETED => ['bg' => 'bg-success-50 dark:bg-success-500/10', 'border' => 'border-success-200 dark:border-success-500/30', 'text' => 'text-success-700 dark:text-success-400', 'label' => 'Concluída'],
        ObligationGenerationRun::STATUS_FAILED => ['bg' => 'bg-danger-50 dark:bg-danger-500/10', 'border' => 'border-danger-200 dark:border-danger-500/30', 'text' => 'text-danger-700 dark:text-danger-400', 'label' => 'Falhou'],
        default => ['bg' => 'bg-info-50 dark:bg-info-500/10', 'border' => 'border-info-200 dark:border-info-500/30', 'text' => 'text-info-700 dark:text-info-400', 'label' => 'Em andamento'],
    };
@endphp

@if ($run)
    <span class="mb-4 flex items-start gap-3 rounded-xl border {{ $palette['border'] }} {{ $palette['bg'] }} p-4">
        <span class="mt-0.5 flex shrink-0">
            @if ($run->isActive())
                <x-filament::loading-indicator class="h-5 w-5 {{ $palette['text'] }}" />
            @elseif ($run->isCompleted())
                <x-filament::icon icon="heroicon-o-check-circle" class="h-5 w-5 {{ $palette['text'] }}" />
            @else
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5 {{ $palette['text'] }}" />
            @endif
        </span>

        <span class="flex flex-1 flex-col gap-1">
            <span class="flex items-center gap-2">
                <span class="text-sm font-semibold {{ $palette['text'] }}">Geração de obrigações do Termo</span>
                <span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $palette['text'] }} {{ $palette['bg'] }} {{ $palette['border'] }}">{{ $palette['label'] }}</span>
            </span>

            <span class="block text-sm text-gray-600 dark:text-gray-300">{{ $run->message ?? 'Processando...' }}</span>

            <span class="flex flex-wrap gap-x-4 gap-y-0.5 text-xs text-gray-500 dark:text-gray-400">
                @if ($run->started_at)
                    <span>Início: {{ $run->started_at->format('d/m/Y H:i') }}</span>
                @endif
                @if ($run->finished_at)
                    <span>Conclusão: {{ $run->finished_at->format('d/m/Y H:i') }}</span>
                @endif
                @if ($run->isCompleted())
                    <span>Obrigações geradas: {{ $run->generated_count }}</span>
                @endif
            </span>

            @if ($run->hasFailed())
                {{--
                    A causa técnica (503 de sobrecarga do modelo, arquivo ausente no disco)
                    já era gravada em `error_message`, mas só aparecia no log: quem operava
                    lia "tente novamente" sem saber se esperar um minuto resolvia ou se o
                    problema exigia suporte. O texto vem da exceção e é truncado em 500
                    caracteres na gravação; o escape do Blade cuida do conteúdo da API.
                --}}
                @if (filled($run->error_message))
                    <span class="mt-1 block rounded-lg border {{ $palette['border'] }} bg-white/60 px-2 py-1 font-mono text-xs break-words text-gray-600 dark:bg-gray-950/40 dark:text-gray-400">{{ $run->error_message }}</span>
                @endif

                <span class="block text-xs {{ $palette['text'] }}">Use novamente o botão "Gerar obrigações do Termo" para tentar de novo.</span>
            @endif
        </span>
    </span>
@endif

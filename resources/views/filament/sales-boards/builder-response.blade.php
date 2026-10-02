{{--
    A resposta da construtora que sustenta a validação, na Validação e na
    Análise: quem registrou e quando, quem respondeu pela construtora, por qual
    canal, quando chegou, e os arquivos -- com download autenticado. Rodada
    enviada antes da exigência de evidência aparece com aviso, sem bloquear.
--}}
@php
    /** @var \App\DTOs\SalesBoards\SalesBoardBuilderResponseView $builderResponse */
@endphp

<div class="bsi-builder-response rounded-lg border border-gray-200 p-4 text-sm dark:border-gray-700">
    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Resposta da construtora</p>

    <p class="mt-2 text-gray-600 dark:text-gray-300">
        {{ $builderResponse->reviewerTypeLabel }} por
        <span class="font-medium text-gray-950 dark:text-white">{{ $builderResponse->registeredByName ?? '—' }}</span>
        @if ($builderResponse->submittedAt)
            em {{ \App\Support\BusinessTime::at($builderResponse->submittedAt)->format('d/m/Y H:i') }}
        @endif
    </p>

    @if ($builderResponse->hasEvidence)
        <p class="mt-1">
            Resposta de <span class="font-medium">{{ $builderResponse->respondentName }}</span>
            ({{ $builderResponse->respondentEmail }}) ·
            {{ $builderResponse->channelLabel }} ·
            recebida em {{ $builderResponse->receivedOn?->format('d/m/Y') }}
        </p>

        <ul class="mt-3 space-y-1">
            @foreach ($builderResponse->attachments as $attachment)
                <li class="flex flex-wrap items-center gap-2" wire:key="builder-response-attachment-{{ $attachment['id'] }}">
                    <x-filament::icon icon="heroicon-o-paper-clip" class="h-4 w-4 text-gray-400 dark:text-gray-500" />
                    @if ($attachment['url'] !== null)
                        <a
                            href="{{ $attachment['url'] }}"
                            class="text-primary-600 hover:underline dark:text-primary-400"
                            target="_blank"
                            rel="noopener"
                        >{{ $attachment['name'] }}</a>
                    @else
                        <span>{{ $attachment['name'] }} (indisponível)</span>
                    @endif
                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ $attachment['size'] }}</span>
                </li>
            @endforeach
        </ul>
    @else
        <p class="mt-2 rounded-md bg-warning-50 p-3 text-warning-700 dark:bg-warning-400/10 dark:text-warning-400">
            Nenhuma resposta da construtora anexada (validação registrada antes da exigência de evidência).
        </p>
    @endif
</div>

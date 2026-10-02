@php
    /**
     * Avisos da apuração congelados com a versão (ou com a homologação).
     *
     * Apresentação apenas: os grupos chegam prontos de
     * `SalesBoardIssuePresenter::groupFrozen()`, e quem inclui decide o que entra
     * -- a Validação da construtora recebe só os pontos da construtora, sem
     * código nem dica interna. `null` é "não registrados" (versão congelada antes
     * de os avisos serem gravados), e não "nenhum aviso": a tela diz qual dos
     * dois.
     *
     * @var list<array{code: string, label: string, hint: string|null, count: int, items: list<array{unit: string|null, contract: string|null, message: string}>, hidden: int}>|null $warningGroups
     */
    $showCodes = $showCodes ?? true;
    $intro = $intro ?? null;
    $emptyText = $emptyText ?? 'Nenhum aviso da apuração nesta versão.';
    $notRecordedText = $notRecordedText ?? 'Avisos não registrados nesta versão: ela foi congelada antes de os avisos passarem a ser registrados. Use “Verificar alterações” para ver os avisos da fonte atual.';
@endphp

<div class="space-y-3" data-sales-board-derivation-warnings>
    @if (filled($intro))
        <p class="text-sm text-gray-600 dark:text-gray-300">{{ $intro }}</p>
    @endif

    @if ($warningGroups === null)
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $notRecordedText }}</p>
    @elseif ($warningGroups === [])
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $emptyText }}</p>
    @else
        @foreach ($warningGroups as $group)
            {{-- A chave pela posição, e não pelo código: a Validação não leva vocabulário técnico nem no DOM. --}}
            <div wire:key="derivation-warning-{{ $loop->index }}" class="rounded-lg border border-warning-300 p-3 dark:border-warning-700">
                <div class="flex flex-wrap items-center gap-2">
                    <x-filament::icon icon="heroicon-m-exclamation-triangle" class="h-4 w-4 shrink-0 text-warning-600 dark:text-warning-400" />
                    <p class="text-sm font-semibold text-gray-950 dark:text-white">{{ $group['label'] }}</p>
                    <x-filament::badge color="warning" size="sm">{{ $group['count'] }}</x-filament::badge>

                    @if ($showCodes)
                        <span class="font-mono text-xs text-gray-500 dark:text-gray-400">{{ $group['code'] }}</span>
                    @endif
                </div>

                @if (filled($group['hint'] ?? null))
                    <p class="mt-1 text-xs text-gray-600 dark:text-gray-300">{{ $group['hint'] }}</p>
                @endif

                <ul class="mt-2 space-y-1 text-sm text-gray-700 dark:text-gray-200">
                    @foreach ($group['items'] as $item)
                        <li wire:key="derivation-warning-{{ $loop->parent->index }}-{{ $loop->index }}">
                            @if (filled($item['unit']) || filled($item['contract']))
                                <span class="font-medium">{{ implode(' · ', array_filter([$item['unit'], $item['contract']], 'filled')) }}:</span>
                            @endif
                            {{ $item['message'] }}
                        </li>
                    @endforeach
                </ul>

                @if ($group['hidden'] > 0)
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">e mais {{ $group['hidden'] }}</p>
                @endif
            </div>
        @endforeach
    @endif
</div>

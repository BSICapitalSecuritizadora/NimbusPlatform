@php
    /**
     * A "Ponte com a competência anterior", na Validação, na Análise e na tela
     * da competência. Apresentação apenas: a ponte chega pronta do
     * SalesBoardCompetenceBridgeBuilder, que só lê o que foi congelado.
     *
     * @var \App\DTOs\SalesBoards\SalesBoardCompetenceBridge $bridge
     */
    $money = static fn (?int $cents): string => $cents === null ? '—' : 'R$ '.\App\Support\Money\IntegerMoney::format($cents);
    $signedMoney = static fn (?int $cents): string => match (true) {
        $cents === null => '—',
        $cents > 0 => '+ R$ '.\App\Support\Money\IntegerMoney::format($cents),
        $cents < 0 => '− R$ '.\App\Support\Money\IntegerMoney::format(abs($cents)),
        default => 'R$ 0,00',
    };
    $signed = static fn (?int $units, string $sign = ''): string => match (true) {
        $units === null => '—',
        $units === 0 => '0',
        $sign !== '' => $sign.$units,
        $units > 0 => '+'.$units,
        default => '−'.abs($units),
    };
@endphp

<div class="space-y-4" data-sales-board-competence-bridge>
    @if ($bridge->isManualAnchor())
        <p class="text-sm text-gray-600 dark:text-gray-300">
            Competência anterior ({{ $bridge->previousLabel }}) registrada manualmente: sem conciliação por unidade.
            A comparação abaixo é só entre os totais do quadro manual e os desta versão.
        </p>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">
                    <tr>
                        <th class="py-2 pr-4">Balde</th>
                        <th class="py-2 pr-4 text-right">Anterior</th>
                        <th class="py-2 pr-4 text-right">Atual</th>
                        <th class="py-2 pr-4 text-right">Diferença</th>
                        <th class="py-2 pr-4 text-right">Valor anterior</th>
                        <th class="py-2 pr-4 text-right">Valor atual</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($bridge->buckets as $bucket)
                        <tr wire:key="bridge-manual-{{ $bucket->classification->value }}" class="border-b border-gray-100 dark:border-gray-800">
                            <td class="py-2 pr-4">{{ $bucket->label() }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ $bucket->previousUnits }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ $bucket->currentUnits }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ $signed($bucket->unitsDifference()) }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ $money($bucket->previousValueCents) }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ $money($bucket->currentValueCents) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <p class="text-sm text-gray-600 dark:text-gray-300">
            Da posição de {{ $bridge->previousLabel }} ({{ $bridge->previousVersionLabel }}, {{ $bridge->previousPublished ? 'publicada' : 'ainda não publicada' }})
            a esta versão, unidade a unidade, pelos movimentos congelados: os de competências anteriores primeiro, depois os do mês.
        </p>

        @if ($bridge->anchorChangedSinceVersion)
            <p class="rounded-md bg-warning-50 p-3 text-sm text-warning-700 dark:bg-warning-400/10 dark:text-warning-400">
                A competência anterior foi retificada depois desta versão: a ponte usa a posição publicada vigente dela, e os movimentos
                desta versão foram apurados contra a anterior. Recalcule a competência para apurá-los contra a posição retificada.
            </p>
        @endif

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">
                    <tr>
                        <th class="py-2 pr-4">Balde</th>
                        <th class="py-2 pr-4 text-right">Anterior</th>
                        <th class="py-2 pr-4 text-right">Entradas</th>
                        <th class="py-2 pr-4 text-right">Saídas</th>
                        <th class="py-2 pr-4 text-right">Sem explicação</th>
                        <th class="py-2 pr-4 text-right">Atual</th>
                        <th class="py-2 pr-4 text-right">Valor anterior</th>
                        <th class="py-2 pr-4 text-right">Valor atual</th>
                        <th class="py-2 pr-4 text-right">Reavaliação</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($bridge->buckets as $bucket)
                        <tr wire:key="bridge-bucket-{{ $bucket->classification->value }}" class="border-b border-gray-100 dark:border-gray-800">
                            <td class="py-2 pr-4">{{ $bucket->label() }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ $bucket->previousUnits }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ $signed($bucket->entries, '+') }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ $signed($bucket->exits, '−') }}</td>
                            <td @class([
                                'py-2 pr-4 text-right tabular-nums',
                                'font-semibold text-warning-700 dark:text-warning-400' => ($bucket->unexplained ?? 0) !== 0,
                            ])>{{ $signed($bucket->unexplained) }}</td>
                            <td class="py-2 pr-4 text-right font-semibold tabular-nums">{{ $bucket->currentUnits }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ $money($bucket->previousValueCents) }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ $money($bucket->currentValueCents) }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ $signedMoney($bucket->revaluationCents) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($bridge->explanations !== [])
            <div class="flex flex-wrap items-center gap-2 text-xs text-gray-600 dark:text-gray-300">
                <span class="font-medium">Explicado por:</span>
                @foreach ($bridge->explanations as $explanation => $count)
                    <x-filament::badge color="gray" size="sm" wire:key="bridge-explanation-{{ $loop->index }}">{{ $explanation }}: {{ $count }}</x-filament::badge>
                @endforeach
            </div>
        @endif

        <div>
            <p class="text-sm font-semibold text-gray-950 dark:text-white">Sem movimento que explique</p>

            @if ($bridge->unexplainedUnits === [])
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Nenhuma unidade: a posição de {{ $bridge->previousLabel }} chega a esta versão pelos movimentos congelados.
                </p>
            @else
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    Unidades que mudaram de balde sem venda, distrato, quitação, permuta, inclusão ou baixa que leve de um ao outro --
                    estorno de quitação, data de venda movida, permuta encerrada depois da competência anterior. Confira cada uma.
                </p>

                <ul class="mt-2 space-y-1 text-sm text-gray-700 dark:text-gray-200">
                    @foreach ($bridge->unexplainedUnits as $row)
                        <li wire:key="bridge-unexplained-{{ $row->constructionUnitId }}">
                            <span class="font-medium">{{ $row->unitLabel }}:</span>
                            {{ $row->previousLabel() }}@if ($row->previousContractCode) ({{ $row->previousContractCode }})@endif
                            → {{ $row->currentLabel() }}@if ($row->currentContractCode) ({{ $row->currentContractCode }})@endif
                            @if ($row->note)
                                <span class="text-xs text-gray-500 dark:text-gray-400">· {{ $row->note }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif
</div>

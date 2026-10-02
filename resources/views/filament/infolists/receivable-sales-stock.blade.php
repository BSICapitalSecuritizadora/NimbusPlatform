@php
    use App\Concerns\MoneyFormatter;
    use App\DTOs\SalesBoards\ConstructionSalesPosition;
    use App\DTOs\SalesBoards\EmissionSalesPosition;
    use App\Enums\SalesBoardUnitClassification;

    /*
     * Posição consolidada da Emissão na competência do Recebível, lida pelo
     * SalesBoardPositionReader (ver ReceivableInfolist::salesPosition()). A soma
     * nunca aparece sem a cobertura: empreendimentos com posição, mês usado por
     * quem teve a posição transportada e quem ficou fora da soma.
     */

    /** @var EmissionSalesPosition|null $position */
    $position ??= null;

    /** @var list<int> $automatedSalesBoardIds */
    $automatedSalesBoardIds ??= [];

    /** @var \App\DTOs\SalesBoards\SalesBoardPublicationGaps $publicationGaps */
    $publicationGaps ??= \App\DTOs\SalesBoards\SalesBoardPublicationGaps::none();

    $formatUnits = fn (int $units): string => number_format($units, 0, ',', '.');
    $formatShare = fn (float $part, float $whole): string => $whole > 0 ? number_format(($part / $whole) * 100, 1, ',', '.').'%' : '—';
    $constructionName = fn (ConstructionSalesPosition $construction): string => filled($construction->constructionName)
        ? $construction->constructionName
        : 'Empreendimento #'.$construction->constructionId;
@endphp

@if ($position?->hasData())
    @php
        $competence = $position->positionDate->format('m/Y');
        $totalUnits = $position->totalUnits;
        $totalValue = $position->stockValue + $position->financedValue + $position->paidValue + $position->exchangedValue;

        $buckets = [
            ['label' => 'Vendidas não quitadas', 'classification' => SalesBoardUnitClassification::Financed, 'dot' => 'bg-emerald-500', 'units' => $position->financedUnits, 'value' => $position->financedValue],
            ['label' => 'Vendidas quitadas', 'classification' => SalesBoardUnitClassification::Settled, 'dot' => 'bg-blue-500', 'units' => $position->paidUnits, 'value' => $position->paidValue],
            ['label' => 'Estoque', 'classification' => SalesBoardUnitClassification::Stock, 'dot' => 'bg-amber-500', 'units' => $position->stockUnits, 'value' => $position->stockValue],
        ];

        if (($position->exchangedUnits > 0) || ($position->exchangedValue > 0)) {
            $buckets[] = ['label' => 'Permutadas', 'classification' => SalesBoardUnitClassification::Exchanged, 'dot' => 'bg-purple-500', 'units' => $position->exchangedUnits, 'value' => $position->exchangedValue];
        }

        $carriedForward = $position->carriedForwardPositions();
        $missing = $position->missingPositions();
        $isComplete = $position->isFullyCovered() && ($carriedForward === []);
    @endphp

    <div class="bsi-rcv-sales-stock space-y-4">
        <div
            @class([
                'bsi-rcv-sales-coverage rounded-lg border px-4 py-3 text-sm leading-relaxed',
                'border-slate-200 bg-slate-50 text-slate-700 dark:border-white/10 dark:bg-white/[0.03] dark:text-slate-300' => $isComplete,
                'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100' => ! $isComplete,
            ])
            data-coverage="{{ $isComplete ? 'complete' : 'partial' }}"
        >
            <p>
                <span class="font-semibold">Cobertura em {{ $competence }}:</span>
                {{ $position->constructionsCovered }} de {{ $position->constructionsExpected }}
                {{ $position->constructionsExpected === 1 ? 'empreendimento' : 'empreendimentos' }} com posição.
                @if ($isComplete)
                    Todos os empreendimentos esperados com o quadro da própria competência.
                @endif
            </p>

            @foreach ($carriedForward as $construction)
                <p>
                    Última posição conhecida: <span class="font-semibold">{{ $constructionName($construction) }}</span>
                    entra com o quadro de {{ $construction->referenceMonthUsedLabel() }}, sem quadro em {{ $competence }}.
                </p>
            @endforeach

            @if ($missing !== [])
                <p>
                    Sem quadro de vendas, fora da soma:
                    <span class="font-semibold">{{ collect($missing)->map(fn (ConstructionSalesPosition $construction): string => $constructionName($construction))->implode(', ') }}</span>.
                </p>
            @endif

            @if ($publicationGaps->awaitingPublication !== [])
                <p>
                    Competência produzida pelo ciclo mensal automatizado, ainda não publicada para:
                    <span class="font-semibold">{{ collect($publicationGaps->awaitingPublication)->map(fn (ConstructionSalesPosition $construction): string => $constructionName($construction))->implode(', ') }}</span>.
                </p>
            @endif

            @foreach ($publicationGaps->cancelled as $cancelledCompetence)
                <p>
                    Competência {{ $competence }} cancelada pela Gestão para
                    <span class="font-semibold">{{ $constructionName($cancelledCompetence['position']) }}</span>
                    em {{ $cancelledCompetence['cycle']->cancelled_at === null ? '—' : \App\Support\BusinessTime::at($cancelledCompetence['cycle']->cancelled_at)->format('d/m/Y') }}: {{ rtrim((string) $cancelledCompetence['cycle']->cancellation_reason, '. ') }}.
                    Os fatos do mês entram na competência seguinte; a Gestão pode reabri-la em “Ciclos do Quadro” enquanto nenhuma competência posterior tiver sido publicada.
                </p>
            @endforeach
        </div>

        <div class="bsi-financial-table-wrapper overflow-x-auto bg-white dark:bg-[#091b23]">
            <table class="bsi-financial-table min-w-full">
                <thead>
                    <tr>
                        <th class="text-left">Situação das unidades</th>
                        <th class="text-right">Unidades</th>
                        <th class="text-right">% Unidades</th>
                        <th class="text-right">Valor (R$)</th>
                        <th class="text-right">% VGV</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200/70 dark:divide-white/5">
                    @foreach ($buckets as $bucket)
                        <tr class="hover:bg-slate-50/50 dark:hover:bg-white/[0.02]">
                            <td class="font-medium text-slate-800 dark:text-slate-200">
                                <div class="flex items-center gap-1.5">
                                    <span class="inline-block h-2 w-2 shrink-0 rounded-full {{ $bucket['dot'] }}"></span>
                                    <span>{{ $bucket['label'] }}</span>
                                </div>
                                <div class="mt-0.5 pl-3.5 text-xs font-normal text-slate-500 dark:text-slate-400">
                                    {{ $bucket['classification']->valueCriterion() }}
                                </div>
                            </td>
                            <td class="bsi-num font-mono font-medium text-slate-900 dark:text-white">
                                {{ $formatUnits($bucket['units']) }}
                            </td>
                            <td class="bsi-num font-mono text-slate-600 dark:text-slate-400">
                                {{ $formatShare((float) $bucket['units'], (float) $totalUnits) }}
                            </td>
                            <td class="bsi-num font-mono font-medium text-slate-900 dark:text-white">
                                {{ MoneyFormatter::formatCurrencyForDisplay($bucket['value']) }}
                            </td>
                            <td class="bsi-num font-mono text-slate-600 dark:text-slate-400">
                                {{ $formatShare((float) $bucket['value'], (float) $totalValue) }}
                            </td>
                        </tr>
                    @endforeach

                    <tr class="bsi-total-row bg-[#091b23]/5 dark:bg-white/[0.04]">
                        <td class="font-bold text-slate-900 dark:text-[#fbfaf8]">
                            TOTAL DA EMISSÃO (VGV)
                        </td>
                        <td class="bsi-num font-mono font-bold text-slate-900 dark:text-[#fbfaf8]">
                            {{ $formatUnits($totalUnits) }}
                        </td>
                        <td class="bsi-num font-mono font-bold text-slate-900 dark:text-[#fbfaf8]">
                            100,0%
                        </td>
                        <td class="bsi-num font-mono font-bold text-slate-900 dark:text-[#fbfaf8]">
                            R$ {{ MoneyFormatter::formatCurrencyForDisplay($totalValue) }}
                        </td>
                        <td class="bsi-num font-mono font-bold text-slate-900 dark:text-[#fbfaf8]">
                            100,0%
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p class="text-xs text-slate-500 dark:text-slate-400">
            O VGV soma os grupos acima, cada um pelo seu critério de valor. Não é o saldo da carteira, que aparece em Posição Financeira.
        </p>

        <div class="bsi-financial-table-wrapper overflow-x-auto bg-white dark:bg-[#091b23]">
            <table class="bsi-financial-table min-w-full">
                <thead>
                    <tr>
                        <th class="text-left">Empreendimento</th>
                        <th class="text-left">Quadro usado</th>
                        <th class="text-left">Situação</th>
                        <th class="text-left">Origem</th>
                        <th class="text-right">Unidades</th>
                        <th class="text-right">VGV (R$)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200/70 dark:divide-white/5">
                    @foreach ($position->positions as $construction)
                        <tr class="hover:bg-slate-50/50 dark:hover:bg-white/[0.02]" data-construction-id="{{ $construction->constructionId }}">
                            <td class="font-medium text-slate-800 dark:text-slate-200">
                                {{ $constructionName($construction) }}
                            </td>
                            <td class="font-mono text-slate-700 dark:text-slate-300">
                                {{ $construction->referenceMonthUsedLabel() ?? '—' }}
                            </td>
                            @php
                                $needsAttention = $construction->wasCarriedForward()
                                    || ($construction->status->isExpected() && ! $construction->isResolved());
                            @endphp
                            <td
                                @class([
                                    'font-medium text-amber-700 dark:text-amber-300' => $needsAttention,
                                    'text-slate-700 dark:text-slate-300' => ! $needsAttention,
                                ])
                            >
                                {{ $construction->status->label() }}
                            </td>
                            <td class="text-slate-700 dark:text-slate-300">
                                @if ($construction->salesBoard === null)
                                    —
                                @elseif (in_array((int) $construction->salesBoard->getKey(), $automatedSalesBoardIds, true))
                                    Ciclo automatizado
                                @else
                                    Registro manual
                                @endif
                            </td>
                            <td class="bsi-num font-mono text-slate-900 dark:text-white">
                                {{ $construction->isResolved() ? $formatUnits($construction->totalUnits) : '—' }}
                            </td>
                            <td class="bsi-num font-mono text-slate-900 dark:text-white">
                                {{ $construction->isResolved()
                                    ? MoneyFormatter::formatCurrencyForDisplay($construction->stockValue + $construction->financedValue + $construction->paidValue + $construction->exchangedValue)
                                    : '—' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

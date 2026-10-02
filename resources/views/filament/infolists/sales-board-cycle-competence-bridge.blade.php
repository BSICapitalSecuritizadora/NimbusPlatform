@php
    /**
     * A ponte da versão vigente na tela da competência. A ponte só lê o que foi
     * congelado: abrir a tela não apura a fonte.
     */
    $baseline = $getRecord()->currentBaseline;
    $bridge = $baseline === null
        ? null
        : app(\App\Services\SalesBoards\SalesBoardCompetenceBridgeBuilder::class)->forBaseline($baseline);
@endphp

@if ($bridge === null || ! $bridge->hasAnchor())
    <p class="text-sm text-gray-500 dark:text-gray-400">
        Sem competência anterior no ciclo mensal: esta é a primeira competência apurada pela automação, ou a anterior não foi gerada.
    </p>
@else
    @include('filament.resources.sales-board-cycles.partials.competence-bridge', ['bridge' => $bridge])
@endif

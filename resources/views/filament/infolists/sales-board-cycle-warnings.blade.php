@php
    /**
     * Os avisos da versão vigente do ciclo, na tela da competência: com código
     * e dica, para quem opera e para a Gestão. Tudo vem da versão congelada --
     * nada é apurado ao abrir a tela.
     */
    $warningGroups = \App\Support\SalesBoards\SalesBoardIssuePresenter::groupFrozen(
        $getRecord()->currentBaseline?->frozenWarnings(),
    );
@endphp

@include('filament.sales-boards.derivation-warnings', [
    'warningGroups' => $warningGroups,
    'showCodes' => true,
])

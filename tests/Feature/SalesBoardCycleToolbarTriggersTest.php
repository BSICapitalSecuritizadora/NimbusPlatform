<?php

/**
 * Na visualização do ciclo, o tema amplia o .fi-icon-btn para 40px, mas o Filament
 * mantém a margem de -0.5rem. Cada botão vazava 8px do wrapper e os 8px de gap do
 * grupo viravam -8px: Filtros e Colunas ficavam sobrepostos na tabela "Unidades".
 */
it('keeps the Filtros and Colunas triggers apart on the sales board cycle view', function () {
    $toolbar = file_get_contents(resource_path('views/vendor/filament-tables/index.blade.php'));
    $cycleStyles = file_get_contents(resource_path('css/filament/admin/sales-board-cycle.css'));

    expect($toolbar)->toContain('class="fi-ta-filter-col-group flex items-center gap-2 shrink-0"')
        ->and($cycleStyles)->toMatch('/\.bsi-sales-board-cycle-view-page \.fi-ta-filter-col-group \.fi-icon-btn \{\s*margin: 0;\s*\}/');
});

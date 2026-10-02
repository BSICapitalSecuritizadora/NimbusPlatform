<?php

/**
 * Os benchmarks de escala rodam fora do portão de deploy.
 *
 * O portão continua sendo a suíte comum, onde a regressão que importa --
 * hidratar parcelas como model -- já é barrada por guardas determinísticos.
 * Orçamentos de tempo oscilam em runner compartilhado, e um vermelho por
 * oscilação travaria correções por quase uma hora. Os benchmarks ganham um
 * workflow próprio, não bloqueante, e um script do composer para rodar à mão.
 */
function salesBoardBenchmarkFiles(): array
{
    return [
        'tests/Feature/SalesBoardVolumePerformanceTest.php',
        'tests/Feature/SalesBoardAutomationPerformanceTest.php',
        'tests/Feature/SalesBoardRolloutPerformanceTest.php',
        'tests/Feature/ContractInstallmentImportScaleTest.php',
    ];
}

it('runs the sales board benchmarks outside the deploy gate', function () {
    $workflow = file_get_contents(base_path('.github/workflows/sales-board-benchmarks.yml'));
    $deploy = file_get_contents(base_path('.github/workflows/main_bsicapital.yml'));
    $composer = json_decode(file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($workflow)->toContain('NIMBUS_BENCH=1')
        ->and($workflow)->toContain('workflow_dispatch')
        ->and($deploy)->not->toContain('sales-board-benchmarks')
        ->and($deploy)->toContain('needs: [build, checks, lint]')
        ->and($composer['scripts']['test:bench'])->toContain('@putenv NIMBUS_BENCH=1');

    $benchCommand = collect($composer['scripts']['test:bench'])->first(fn (string $line): bool => str_contains($line, 'artisan test'));

    foreach (salesBoardBenchmarkFiles() as $file) {
        expect($workflow)->toContain($file)
            ->and($benchCommand)->toContain($file);
    }

    /**
     * O benchmark da importação de parcelas só dá sinal se o workflow também
     * disparar quando o código da importação muda, e não só o do Quadro.
     */
    expect($workflow)->toContain("'app/Actions/ContractInstallments/**'")
        ->and($workflow)->toContain("'app/Support/Imports/**'")
        ->and($workflow)->toContain("'app/Concerns/ImportsContractInstallments.php'");
});

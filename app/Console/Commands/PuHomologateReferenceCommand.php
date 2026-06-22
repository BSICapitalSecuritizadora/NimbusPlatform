<?php

namespace App\Console\Commands;

use App\Actions\Emissions\GeneratePuDailyCurve;
use App\Actions\Emissions\ValidatePuDailyCurve;
use App\Domain\PuCalculator\Enums\PuValidationMode;
use App\Domain\PuCalculator\Services\PuReferenceWorkbookScenarioService;
use App\Domain\PuCalculator\Services\PuValidationSpreadsheetLocatorService;
use App\Models\Emission;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;

class PuHomologateReferenceCommand extends Command
{
    protected $signature = 'pu:homologate-reference
        {keyword? : Palavra-chave da operação ou da planilha}
        {--emission-id= : ID da emissão}
        {--detailed=10 : Quantidade de linhas divergentes detalhadas}
        {--mode=raw-scale : Modo de validação (display-scale|raw-scale)}
        {--from= : Data inicial YYYY-MM-DD para filtrar a validação}
        {--to= : Data final YYYY-MM-DD para filtrar a validação}';

    protected $description = 'Sincroniza o cenário de referência, gera a curva PU e valida contra a planilha externa.';

    public function handle(
        PuValidationSpreadsheetLocatorService $spreadsheetLocator,
        PuReferenceWorkbookScenarioService $scenarioService,
        GeneratePuDailyCurve $generatePuDailyCurve,
        ValidatePuDailyCurve $validatePuDailyCurve,
    ): int {
        $keyword = $this->argument('keyword');
        $mode = PuValidationMode::from((string) $this->option('mode'));
        $rangeStart = $this->option('from') !== null ? CarbonImmutable::parse((string) $this->option('from')) : null;
        $rangeEnd = $this->option('to') !== null ? CarbonImmutable::parse((string) $this->option('to')) : null;

        if ($keyword === null) {
            foreach (['AMANI', 'TROUPE'] as $defaultKeyword) {
                $this->runScenario(
                    keyword: $defaultKeyword,
                    emissionId: null,
                    spreadsheetLocator: $spreadsheetLocator,
                    scenarioService: $scenarioService,
                    generatePuDailyCurve: $generatePuDailyCurve,
                    validatePuDailyCurve: $validatePuDailyCurve,
                    detailedLimit: (int) $this->option('detailed'),
                    mode: $mode,
                    rangeStart: $rangeStart,
                    rangeEnd: $rangeEnd,
                );
            }

            return self::SUCCESS;
        }

        $this->runScenario(
            keyword: (string) $keyword,
            emissionId: $this->option('emission-id') !== null ? (int) $this->option('emission-id') : null,
            spreadsheetLocator: $spreadsheetLocator,
            scenarioService: $scenarioService,
            generatePuDailyCurve: $generatePuDailyCurve,
            validatePuDailyCurve: $validatePuDailyCurve,
            detailedLimit: (int) $this->option('detailed'),
            mode: $mode,
            rangeStart: $rangeStart,
            rangeEnd: $rangeEnd,
        );

        return self::SUCCESS;
    }

    private function runScenario(
        string $keyword,
        ?int $emissionId,
        PuValidationSpreadsheetLocatorService $spreadsheetLocator,
        PuReferenceWorkbookScenarioService $scenarioService,
        GeneratePuDailyCurve $generatePuDailyCurve,
        ValidatePuDailyCurve $validatePuDailyCurve,
        int $detailedLimit,
        PuValidationMode $mode,
        ?CarbonImmutable $rangeStart,
        ?CarbonImmutable $rangeEnd,
    ): void {
        $spreadsheetPath = $spreadsheetLocator->findByKeyword($keyword);
        $emission = $this->resolveEmission($keyword, $emissionId);

        $this->newLine();
        $this->line(sprintf('<info>[%s]</info> %s', $keyword, $emission->name));

        $scenarioSummary = $scenarioService->sync($emission, $spreadsheetPath);
        $generationResult = $generatePuDailyCurve->handle($emission, syncLegacyProjections: false);
        $validationReport = $validatePuDailyCurve->handle(
            $emission,
            $spreadsheetPath,
            $generationResult->calculationVersion,
            $mode,
            $rangeStart,
            $rangeEnd,
        );

        $this->table(
            ['Métrica', 'Valor'],
            [
                ['Planilha', basename($spreadsheetPath)],
                ['Linhas da referência', $scenarioSummary['row_count']],
                ['Spread inferido', $scenarioSummary['spread_rate']],
                ['Modo de lookup do CDI', $scenarioSummary['index_lookup_mode']],
                ['Lag útil do CDI', $scenarioSummary['index_lag_business_days']],
                ['Modo de validação', $validationReport->mode->value],
                ['Faixa analisada', $this->rangeLabel($validationReport->rangeStart, $validationReport->rangeEnd)],
                ['Versão gerada', $generationResult->calculationVersion ?? 'v1'],
                ['Linhas comparadas', $validationReport->totalRowsCompared],
                ['Linhas divergentes', $validationReport->totalDivergences],
                ['Campos divergentes', $validationReport->totalFieldDivergences],
                ['Primeira divergência', $validationReport->firstDivergenceDate?->toDateString() ?? '-'],
                ['Maior diferença de PU', $validationReport->largestPuDifference],
                ['Maior diferença de valor total', $validationReport->largestTotalValueDifference],
                ['Maior diferença de pagamento', $validationReport->largestPaymentDifference],
                ['Status', $validationReport->status->value],
            ],
        );

        if ($validationReport->divergenceCountByField !== []) {
            $this->table(
                ['Campo', 'Qtd. divergências'],
                collect($validationReport->divergenceCountByField)
                    ->sortDesc()
                    ->map(fn (int $count, string $field): array => [$field, $count])
                    ->values()
                    ->all(),
            );
        }

        if ($validationReport->divergenceCountByCause !== []) {
            $this->table(
                ['Possível causa', 'Qtd. divergências'],
                collect($validationReport->divergenceCountByCause)
                    ->sortDesc()
                    ->map(fn (int $count, string $cause): array => [$cause, $count])
                    ->values()
                    ->all(),
            );
        }

        $detailedRows = $validationReport->divergentRows($detailedLimit);

        if ($detailedRows === []) {
            $this->line('<info>Sem divergências.</info>');

            return;
        }

        foreach ($detailedRows as $row) {
            $this->line(sprintf(
                '<comment>%s</comment> %s',
                $row->date->toDateString(),
                implode(' | ', array_map(
                    static fn ($difference) => $difference->summary(),
                    array_values($row->differences),
                )),
            ));
        }
    }

    private function resolveEmission(string $keyword, ?int $emissionId): Emission
    {
        if ($emissionId !== null) {
            return Emission::query()->findOrFail($emissionId);
        }

        $emission = Emission::query()
            ->where('name', 'like', '%'.$keyword.'%')
            ->first();

        if (! $emission instanceof Emission) {
            throw new InvalidArgumentException(sprintf('Emission matching [%s] was not found.', $keyword));
        }

        return $emission;
    }

    private function rangeLabel(?CarbonImmutable $rangeStart, ?CarbonImmutable $rangeEnd): string
    {
        if ($rangeStart === null && $rangeEnd === null) {
            return 'completa';
        }

        return sprintf(
            '%s..%s',
            $rangeStart?->toDateString() ?? '*',
            $rangeEnd?->toDateString() ?? '*',
        );
    }
}

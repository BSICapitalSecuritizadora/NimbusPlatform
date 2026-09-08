<?php

namespace App\Console\Commands;

use App\DTOs\SalesBoards\SalesBoardDerivedPosition;
use App\DTOs\SalesBoards\SalesBoardIssue;
use App\DTOs\SalesBoards\SalesBoardLegacyComparison;
use App\DTOs\SalesBoards\SalesBoardReadinessReport;
use App\Models\Construction;
use App\Models\Emission;
use App\Services\SalesBoards\SalesBoardDerivationService;
use App\Services\SalesBoards\SalesBoardReadinessService;
use App\Support\Money\IntegerMoney;
use App\Support\SalesBoards\ReferenceMonthInput;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Reconstrói e imprime a composição do Quadro de Vendas de uma competência.
 *
 * Somente leitura. Não cria quadro, não cria ciclo, não altera contrato,
 * unidade, política ou permuta, e não persiste snapshot -- é material de
 * homologação da derivação, não a automação dela.
 */
class DeriveSalesBoardCommand extends Command
{
    protected $signature = 'sales-boards:derive
                            {--construction= : Empreendimento a derivar (id)}
                            {--emission= : Deriva todos os empreendimentos da emissão (id)}
                            {--reference-month= : Competência no formato mm/aaaa ou aaaa-mm (padrão: mês anterior)}
                            {--details : Lista as unidades classificadas}
                            {--json : Devolve o resultado como JSON}';

    protected $description = 'Deriva (somente leitura) a composição do Quadro de Vendas de uma competência e apura a prontidão para automação';

    public function handle(
        SalesBoardDerivationService $derivationService,
        SalesBoardReadinessService $readinessService,
    ): int {
        $constructions = $this->resolveConstructions();

        if ($constructions === null) {
            return self::FAILURE;
        }

        if ($constructions->isEmpty()) {
            $this->warn('Nenhum empreendimento encontrado para os filtros informados.');

            return self::SUCCESS;
        }

        $referenceMonth = $this->resolveReferenceMonth();

        if ($referenceMonth === null) {
            $this->error('Competência inválida. Use mm/aaaa ou aaaa-mm.');

            return self::FAILURE;
        }

        $reports = [];

        foreach ($constructions as $construction) {
            $position = $derivationService->deriveForConstruction($construction, $referenceMonth);
            $report = $readinessService->fromPosition($construction, $position);

            $reports[] = ['position' => $position, 'report' => $report];
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(
                array_map(
                    fn (array $entry): array => [
                        'position' => $entry['position']->toArray(withLines: (bool) $this->option('details')),
                        'readiness' => $entry['report']->toArray(),
                    ],
                    $reports,
                ),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        }

        foreach ($reports as $entry) {
            $this->renderPosition($entry['position'], $entry['report']);
        }

        return self::SUCCESS;
    }

    private function renderPosition(SalesBoardDerivedPosition $position, SalesBoardReadinessReport $report): void
    {
        $this->newLine();
        $this->line(sprintf('<options=bold>Empreendimento:</> %s (#%d)', $position->constructionName ?? '—', $position->constructionId));
        $this->line(sprintf('<options=bold>Competência:</>    %s', $position->referenceMonth->format('m/Y')));
        $this->line(sprintf('<options=bold>Posição em:</>     %s', $position->positionDate->format('d/m/Y')));
        $this->newLine();

        $this->table(
            ['Balde', 'Unidades', 'Valor'],
            [
                ['Estoque', $position->stockUnits, $this->money($position->stockValueCents)],
                ['Financiado', $position->financedUnits, $this->money($position->financedValueCents)],
                ['Quitado', $position->settledUnits, $this->money($position->settledValueCents)],
                ['Permutado', $position->exchangedUnits, $this->money($position->exchangedValueCents)],
                ['Indeterminado', $position->undeterminedUnits, '—'],
                ['<options=bold>Total</>', '<options=bold>'.$position->unitsTotal.'</>', ''],
            ],
        );

        if (! $position->bucketsBalance()) {
            $this->error('Os baldes não fecham com o total de unidades. Isso é um defeito da derivação, não do cadastro.');
        }

        $movements = $position->movements;

        $this->line(sprintf(
            'Vendas no mês: %d (conformes: %d | não conformes: %d | indeterminadas: %d)',
            $movements->salesCount(),
            $movements->conformSalesCount(),
            $movements->nonConformSalesCount(),
            $movements->undeterminedSalesCount(),
        ));
        $this->line(sprintf('Quitações no mês: %d', $movements->settlementsCount()));
        $this->line(sprintf('Distratos no mês: %d', $movements->cancellationsCount()));
        $this->newLine();

        $this->renderIssues('Bloqueadores', $report->blockingIssueCounts());
        $this->renderIssues('Avisos', $report->warningCounts());

        $this->line($report->isReady()
            ? '<fg=green;options=bold>Readiness: READY</>'
            : '<fg=red;options=bold>Readiness: BLOCKED</>');

        $this->renderLegacyComparison($report->legacyComparison);

        if ($this->option('details')) {
            $this->renderLines($position);
        }
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function renderIssues(string $title, array $counts): void
    {
        if ($counts === []) {
            return;
        }

        $this->line(sprintf('<options=bold>%s:</>', $title));

        foreach ($counts as $code => $count) {
            $this->line(sprintf('  %-32s %d', $code, $count));
        }

        $this->newLine();
    }

    private function renderLegacyComparison(SalesBoardLegacyComparison $comparison): void
    {
        $this->newLine();

        if (! $comparison->hasLegacyPosition()) {
            $this->line('<options=bold>Comparativo com o quadro publicado:</> nenhuma posição publicada até a competência.');

            return;
        }

        if ($comparison->matches()) {
            $this->line(sprintf(
                '<options=bold>Comparativo com o quadro publicado (%s):</> <fg=green>idêntico</>',
                (string) $comparison->legacyReferenceMonth,
            ));

            return;
        }

        $this->line(sprintf(
            '<options=bold>Comparativo com o quadro publicado (%s):</> <fg=yellow>divergente</>',
            (string) $comparison->legacyReferenceMonth,
        ));

        $this->table(
            ['Campo', 'Derivado', 'Publicado'],
            collect($comparison->differences)
                ->map(fn (array $pair, string $field): array => [
                    $field,
                    $pair['derived'] ?? '—',
                    $pair['legacy'] ?? '—',
                ])
                ->values()
                ->all(),
        );

        $this->line('A divergência é material de investigação: o quadro digitado é referência de homologação, não veredito sobre a derivação.');
    }

    private function renderLines(SalesBoardDerivedPosition $position): void
    {
        $this->newLine();
        $this->line('<options=bold>Unidades:</>');

        $this->table(
            ['Bloco', 'Unidade', 'Classificação', 'Contrato', 'Valor do balde', 'Achados'],
            collect($position->lines)
                ->map(fn ($line): array => [
                    (string) $line->block,
                    (string) $line->unit,
                    $line->classification->label(),
                    (string) ($line->contractCode ?? '—'),
                    $this->money($line->bucketValueCents()),
                    collect($line->issues)->map(fn (SalesBoardIssue $issue): string => $issue->code->value)->implode(', ') ?: '—',
                ])
                ->all(),
        );
    }

    private function money(?int $cents): string
    {
        return $cents === null ? 'indisponível' : 'R$ '.IntegerMoney::format($cents);
    }

    /**
     * @return Collection<int, Construction>|null
     */
    private function resolveConstructions(): ?Collection
    {
        $constructionId = $this->option('construction');
        $emissionId = $this->option('emission');

        if (blank($constructionId) && blank($emissionId)) {
            $this->error('Informe --construction ou --emission.');

            return null;
        }

        if (filled($constructionId)) {
            $construction = Construction::query()->find($constructionId);

            if ($construction === null) {
                $this->error(sprintf('Empreendimento %s não encontrado.', (string) $constructionId));

                return null;
            }

            return collect([$construction]);
        }

        if (Emission::query()->whereKey($emissionId)->doesntExist()) {
            $this->error(sprintf('Emissão %s não encontrada.', (string) $emissionId));

            return null;
        }

        return Construction::query()
            ->where('emission_id', $emissionId)
            ->orderBy('development_name')
            ->orderBy('id')
            ->get();
    }

    private function resolveReferenceMonth(): ?CarbonImmutable
    {
        return ReferenceMonthInput::parseOrPreviousMonth($this->option('reference-month'));
    }
}

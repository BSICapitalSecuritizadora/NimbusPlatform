<?php

namespace App\Console\Commands;

use App\DTOs\SalesBoards\SalesBoardGenerationResult;
use App\Models\Construction;
use App\Models\Emission;
use App\Services\SalesBoards\SalesBoardGenerationService;
use App\Support\Money\IntegerMoney;
use App\Support\SalesBoards\ReferenceMonthInput;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Congela manualmente a competência de um empreendimento ou de uma emissão.
 *
 * Manual de propósito. Não há agendamento aqui e este comando não é registrado
 * em nenhum schedule: a automação do fechamento mensal depende de decisões --
 * até quando gerar uma emissão liquidada, o que notificar -- que ainda não foram
 * tomadas, e agendar antes delas produziria ciclos que ninguém pediu.
 *
 * Com `--emission`, cada empreendimento continua tendo o seu próprio ciclo e a
 * sua própria transação. Se A e C estão prontos e B não, A e C são gerados e B é
 * reportado, com o motivo. Não existe "ciclo da emissão" a ser segurado pelo pior
 * empreendimento da carteira.
 */
class GenerateSalesBoardCycleCommand extends Command
{
    protected $signature = 'sales-boards:generate-cycle
                            {--construction= : Empreendimento a congelar (id)}
                            {--emission= : Congela todos os empreendimentos da emissão (id)}
                            {--reference-month= : Competência no formato mm/aaaa ou aaaa-mm (padrão: mês anterior)}
                            {--dry-run : Apura e informa o que aconteceria, sem gravar nada}
                            {--json : Devolve o resultado como JSON}';

    protected $description = 'Congela a posição de uma competência num ciclo versionado do Quadro de Vendas';

    public function handle(SalesBoardGenerationService $generationService): int
    {
        $constructions = $this->resolveConstructions();

        if ($constructions === null) {
            return self::FAILURE;
        }

        if ($constructions->isEmpty()) {
            $this->warn('Nenhum empreendimento encontrado para os filtros informados.');

            return self::SUCCESS;
        }

        $referenceMonth = ReferenceMonthInput::parseOrPreviousMonth($this->option('reference-month'));

        if ($referenceMonth === null) {
            $this->error('Competência inválida. Use mm/aaaa ou aaaa-mm.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $results = $generationService->generateForConstructions(
            constructions: $constructions,
            referenceMonth: $referenceMonth,
            actor: null,
            dryRun: $dryRun,
        );

        if ($this->option('json')) {
            $this->line((string) json_encode(
                array_map(fn (SalesBoardGenerationResult $result): array => $result->toArray(), array_values($results)),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        }

        $this->renderHeader($referenceMonth, $dryRun);

        foreach ($results as $result) {
            $this->renderResult($result);
        }

        $this->renderSummary($results);

        return self::SUCCESS;
    }

    private function renderHeader(CarbonImmutable $referenceMonth, bool $dryRun): void
    {
        $this->newLine();
        $this->line(sprintf('<options=bold>Competência:</> %s', $referenceMonth->format('m/Y')));

        if ($dryRun) {
            $this->line('<fg=yellow;options=bold>Simulação:</> nada será gravado.');
        }
    }

    private function renderResult(SalesBoardGenerationResult $result): void
    {
        $this->newLine();
        $this->line(sprintf(
            '<options=bold>%s (#%d)</>',
            $result->constructionName ?? '—',
            $result->constructionId,
        ));

        if ($result->isBlocked()) {
            $this->line('<fg=red;options=bold>BLOQUEADO</> '.(string) $result->blockedReason);

            return;
        }

        if ($result->alreadyExisted()) {
            $this->line(sprintf(
                '<fg=yellow;options=bold>JÁ EXISTE</> ciclo #%s, versão %s.',
                (string) $result->cycle?->getKey(),
                (string) ($result->baseline?->versionLabel() ?? '—'),
            ));
            $this->line('  Refazer a posição é recálculo, que é ação própria e exige motivo.');

            return;
        }

        $position = $result->position;

        $this->line($result->dryRun
            ? '<fg=green;options=bold>SERIA GERADO</>'
            : sprintf(
                '<fg=green;options=bold>GERADO</> ciclo #%s versão %s.',
                (string) $result->cycle?->getKey(),
                (string) ($result->baseline?->versionLabel() ?? '—'),
            ));

        if ($position === null) {
            return;
        }

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

        $this->line(sprintf(
            'Movimentos congelados: %d venda(s), %d quitação(ões), %d distrato(s).',
            $position->movements->salesCount(),
            $position->movements->settlementsCount(),
            $position->movements->cancellationsCount(),
        ));

        $warnings = $result->readiness?->warningCounts() ?? [];

        foreach ($warnings as $code => $count) {
            $this->line(sprintf('  <fg=yellow>aviso</> %-32s %d', $code, $count));
        }
    }

    /**
     * @param  array<int, SalesBoardGenerationResult>  $results
     */
    private function renderSummary(array $results): void
    {
        $results = collect($results);

        $this->newLine();
        $this->line(sprintf(
            '<options=bold>Resumo:</> %d gerado(s) · %d já existente(s) · %d bloqueado(s).',
            $results->filter(fn (SalesBoardGenerationResult $result): bool => $result->wasGenerated())->count(),
            $results->filter(fn (SalesBoardGenerationResult $result): bool => $result->alreadyExisted())->count(),
            $results->filter(fn (SalesBoardGenerationResult $result): bool => $result->isBlocked())->count(),
        ));
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
            $construction = Construction::query()->with('emission')->find($constructionId);

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
            ->with('emission')
            ->orderBy('development_name')
            ->orderBy('id')
            ->get();
    }
}

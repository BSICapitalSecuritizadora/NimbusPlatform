<?php

namespace App\Console\Commands;

use App\Models\Emission;
use App\Models\SalesBoard;
use App\Services\SalesBoards\SalesBoardPositionReader;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Mede, sem tocar em nada, o impacto da correção do GF-01.
 *
 * Compara o número de unidades que o painel do relatório mensal mostrava — um
 * único quadro de vendas escolhido por `first()` dentro da competência — com a
 * posição consolidada da emissão lida pelo {@see SalesBoardPositionReader}.
 *
 * O comando só lê. Não cria quadro, não regenera PDF, não grava comparativo.
 */
class DiagnoseSalesBoardPositionDrift extends Command
{
    protected $signature = 'sales-boards:position-drift
                            {--emission=* : Limita a emissões específicas (id)}
                            {--all : Lista também as competências sem divergência}';

    protected $description = 'Compara (somente leitura) o painel antigo do relatório mensal com a posição consolidada do quadro de vendas';

    public function handle(SalesBoardPositionReader $positionReader): int
    {
        $emissionIds = array_map('intval', (array) $this->option('emission'));

        $emissions = Emission::query()
            ->when($emissionIds !== [], fn ($query) => $query->whereIn('id', $emissionIds))
            ->whereHas('salesBoards')
            ->orderBy('id')
            ->get();

        if ($emissions->isEmpty()) {
            $this->warn('Nenhuma emissão com quadro de vendas encontrada nesta base.');

            return self::SUCCESS;
        }

        $rows = [];
        $divergences = 0;
        $unitsDelta = 0;
        $competencesChecked = 0;

        foreach ($emissions as $emission) {
            $lastCompetence = $emission->salesBoards()->max('reference_month');

            if ($lastCompetence === null) {
                continue;
            }

            foreach ($positionReader->competencesUntil($emission, CarbonImmutable::parse((string) $lastCompetence)) as $competence) {
                $competencesChecked++;

                $previous = $this->previousPanelUnits($emission, $competence);
                $position = $positionReader->forEmission($emission, $competence);

                $differs = $previous !== $position->totalUnits;

                if ($differs) {
                    $divergences++;
                    $unitsDelta += $position->totalUnits - $previous;
                }

                if (! $differs && ! $this->option('all')) {
                    continue;
                }

                $rows[] = [
                    $emission->getKey(),
                    $competence->format('m/Y'),
                    $previous,
                    $position->totalUnits,
                    sprintf('%+d', $position->totalUnits - $previous),
                    sprintf('%d/%d', $position->constructionsCovered, $position->constructionsExpected),
                    $this->reason($position->carriedForwardPositions() !== [], $position->constructionsCovered),
                ];
            }
        }

        if ($rows !== []) {
            $this->table(
                ['Emissão', 'Competência', 'Antes', 'Depois', 'Δ', 'Cobertura', 'Motivo'],
                $rows,
            );
        }

        $this->newLine();
        $this->line(sprintf('Emissões analisadas: %d', $emissions->count()));
        $this->line(sprintf('Competências analisadas: %d', $competencesChecked));
        $this->line(sprintf('Competências com número diferente: %d', $divergences));
        $this->line(sprintf('Diferença total de unidades: %+d', $unitsDelta));

        if ($divergences === 0) {
            $this->info('Nenhuma divergência: nesta base a regra antiga e a consolidada devolvem o mesmo número.');
        }

        return self::SUCCESS;
    }

    /**
     * Reproduz a regra antiga do painel: o quadro mais recente dentro da
     * competência, sozinho, representando a emissão inteira.
     */
    private function previousPanelUnits(Emission $emission, CarbonImmutable $competence): int
    {
        $salesBoard = SalesBoard::query()
            ->where('emission_id', $emission->getKey())
            ->whereBetween('reference_month', [
                $competence->startOfMonth()->toDateString(),
                $competence->endOfMonth()->toDateString(),
            ])
            ->orderByDesc('reference_month')
            ->orderByDesc('id')
            ->first();

        return (int) ($salesBoard?->total_units ?? 0);
    }

    private function reason(bool $carriedForward, int $covered): string
    {
        return match (true) {
            $covered > 1 && $carriedForward => 'Empreendimentos omitidos + posição transportada',
            $covered > 1 => 'Empreendimentos omitidos pelo first()',
            $carriedForward => 'Posição transportada de competência anterior',
            default => '—',
        };
    }
}

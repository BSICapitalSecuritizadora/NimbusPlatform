<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\DTOs\SalesBoards\SalesBoardReadinessPreview;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\SalesBoard;
use App\Models\SalesBoardCycle;
use App\Support\BusinessTime;
use App\Support\Dates\InclusiveDateBound;
use App\Support\SalesBoards\CompetenceCalendar;
use App\Support\SalesBoards\SalesBoardIssuePresenter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * "Prévia de prontidão do Quadro": o que a automação encontraria numa
 * competência de uma Emissão, sem gravar nada.
 *
 * Era o diagnóstico que só existia na linha de comando (`sales-boards:derive`
 * e o `--dry-run` da automação), e no App Service exigia shell. Aqui ele vira
 * tela, para quem enxerga o rollout, com o mesmo motor: a derivação e a
 * prontidão de sempre, um empreendimento por vez -- o pico de memória é o do
 * maior empreendimento, não o da soma.
 *
 * Mostra também a integridade dos quadros registrados que o leitor de posição
 * pressupõe ({@see SalesBoardPositionIntegrityService}), filtrada pela Emissão.
 *
 * Somente leitura: nenhum ciclo, versão, alvo ou constatação de obsolescência
 * é gravado. Continuam só na linha de comando o `--dry-run` da automação, que
 * depende do interruptor e varre todas as Emissões, e a geração em lote.
 */
class SalesBoardReadinessPreviewService
{
    public function __construct(
        private readonly SalesBoardDerivationService $derivationService,
        private readonly SalesBoardReadinessService $readinessService,
        private readonly SalesBoardAutomationDueDateService $dueDates,
        private readonly SalesBoardPositionIntegrityService $integrity,
    ) {}

    public function preview(Emission $emission, CarbonImmutable $referenceMonth): SalesBoardReadinessPreview
    {
        $month = $referenceMonth->startOfMonth();

        $constructions = Construction::query()
            ->where('emission_id', $emission->getKey())
            ->orderBy('development_name')
            ->orderBy('id')
            ->get();

        $cycles = $this->cyclesOf($constructions, $month);

        $rows = [];

        foreach ($constructions as $construction) {
            $rows[] = $this->previewConstruction($construction, $month, $cycles->get((int) $construction->getKey()));
        }

        $emissionIds = [(int) $emission->getKey()];

        return new SalesBoardReadinessPreview(
            emissionId: (int) $emission->getKey(),
            emissionName: (string) $emission->name,
            referenceMonth: $month,
            positionDate: $month->endOfMonth()->startOfDay(),
            dueDate: $this->dueDates->dueDateFor($month),
            competenceClosed: CompetenceCalendar::isClosed($month),
            automationCovers: $emission->automationCovers($month),
            emissionLiquidated: $emission->isLiquidated(),
            constructions: $rows,
            misplacedBoards: $this->integrity->boardsOutsideConstructionEmission($emissionIds)
                ->map(fn (SalesBoard $board): array => [
                    'board_id' => (int) $board->getKey(),
                    'construction' => (string) ($board->construction_name ?? '—'),
                    'reference_month' => $board->reference_month->format('m/Y'),
                    'board_emission_id' => (int) $board->emission_id,
                    'construction_emission_id' => $board->construction_emission_id === null ? null : (int) $board->construction_emission_id,
                ])
                ->values()
                ->all(),
            duplicatedCompetences: $this->integrity->competencesWithSeveralBoards($emissionIds)
                ->map(function (Collection $boards): array {
                    /** @var SalesBoard $first */
                    $first = $boards->first();

                    return [
                        'construction' => (string) ($first->construction_name ?? '—'),
                        'reference_month' => $first->reference_month->format('m/Y'),
                        'construction_emission_id' => $first->construction_emission_id === null ? null : (int) $first->construction_emission_id,
                        'board_emission_ids' => $boards->pluck('emission_id')->map(fn (mixed $id): int => (int) $id)->unique()->implode(', '),
                        'board_ids' => $boards->map(fn (SalesBoard $board): int => (int) $board->getKey())->implode(', '),
                    ];
                })
                ->values()
                ->all(),
            calculatedAt: BusinessTime::at(CarbonImmutable::now()),
        );
    }

    /**
     * Uma consulta para os ciclos da competência de todos os empreendimentos.
     *
     * @param  Collection<int, Construction>  $constructions
     * @return Collection<int, SalesBoardCycle> indexado por `construction_id`
     */
    private function cyclesOf(Collection $constructions, CarbonImmutable $month): Collection
    {
        if ($constructions->isEmpty()) {
            return collect();
        }

        return SalesBoardCycle::query()
            ->whereIn('construction_id', $constructions->modelKeys())
            ->whereBetween('reference_month', [$month->toDateString(), InclusiveDateBound::upperBound($month)])
            ->get(['id', 'construction_id', 'status'])
            ->keyBy(fn (SalesBoardCycle $cycle): int => (int) $cycle->construction_id);
    }

    /**
     * @return array{id: int, name: string, ready: bool, cycle: array{id: int, status: string}|null, blockers: list<array{code: string, label: string, hint: string|null, count: int|null}>, warnings: list<array{code: string, label: string, hint: string|null, count: int|null}>, buckets: array{stock: int, financed: int, settled: int, exchanged: int, undetermined: int, total: int}}
     */
    private function previewConstruction(Construction $construction, CarbonImmutable $month, ?SalesBoardCycle $cycle): array
    {
        $position = $this->derivationService->deriveForConstruction($construction, $month);
        $readiness = $this->readinessService->fromPosition($construction, $position);

        return [
            'id' => (int) $construction->getKey(),
            'name' => (string) ($construction->development_name ?? '—'),
            'ready' => $readiness->isReady(),
            'cycle' => $cycle === null ? null : [
                'id' => (int) $cycle->getKey(),
                'status' => $cycle->status->label(),
            ],
            'blockers' => SalesBoardIssuePresenter::describe($readiness->blockingIssueCounts()),
            'warnings' => SalesBoardIssuePresenter::describe($readiness->warningCounts()),
            'buckets' => [
                'stock' => $position->stockUnits,
                'financed' => $position->financedUnits,
                'settled' => $position->settledUnits,
                'exchanged' => $position->exchangedUnits,
                'undetermined' => $position->undeterminedUnits,
                'total' => $position->unitsTotal,
            ],
        ];
    }
}

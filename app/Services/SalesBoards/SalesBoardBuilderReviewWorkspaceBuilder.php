<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\DTOs\SalesBoards\SalesBoardBuilderReviewWorkspace;
use App\DTOs\SalesBoards\SalesBoardBuilderWorkspaceBucket;
use App\DTOs\SalesBoards\SalesBoardBuilderWorkspaceMovementRow;
use App\DTOs\SalesBoards\SalesBoardBuilderWorkspaceSection;
use App\DTOs\SalesBoards\SalesBoardBuilderWorkspaceUnitRow;
use App\DTOs\SalesBoards\SalesBoardComparableSnapshot;
use App\DTOs\SalesBoards\SalesBoardPriorPosition;
use App\Enums\SalesBoardBuilderReviewSection as SectionEnum;
use App\Enums\SalesBoardRectificationStatus;
use App\Models\SalesBoardBuilderReview;
use App\Models\SalesBoardBuilderReviewSection;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleBaseline;
use App\Models\SalesBoardCycleLine;
use App\Models\SalesBoardCycleMovement;
use App\Models\SalesBoardCycleRectification;
use App\Support\Money\IntegerMoney;
use App\Support\SalesBoards\SalesBoardIssuePresenter;
use App\Support\SalesBoards\UnitDisplayOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Monta o que a construtora vê, a partir da versão que ela está validando.
 *
 * Lê o baseline da **revisão**, não o vigente do ciclo. Parece detalhe e não é:
 * se uma nova versão nascer enquanto a construtora conferia, a tela precisa
 * continuar mostrando o quadro sobre o qual ela foi perguntada. Trocar o
 * conteúdo debaixo dela transformaria a conferência em outra coisa no meio do
 * caminho -- e é justamente por isso que a submissão é recusada nesse cenário,
 * com uma mensagem, em vez de a tela mudar sozinha.
 *
 * Nenhuma consulta toca contratos, parcelas, tabelas de preço ou permutas
 * vivas. O que existe aqui é o que foi congelado.
 *
 * As linhas saem na ordem em que a construtora confere: unidades pela ordem
 * natural de {@see UnitDisplayOrder}, a mesma da aba Unidades do ciclo, e
 * movimentos com os de competências anteriores primeiro, depois pela data do
 * evento -- os sem data no fim -- e pela mesma ordem de unidade. A paginação
 * da tela vem depois desta ordem.
 *
 * A "Ponte com a competência anterior" e o contexto da retificação aberta --
 * motivo, posição publicada e o que muda contra ela, comparando versões
 * congeladas -- também saem daqui, sem leitura da fonte viva.
 */
class SalesBoardBuilderReviewWorkspaceBuilder
{
    public function __construct(
        private readonly SalesBoardPriorPositionResolver $priorPositionResolver,
        private readonly SalesBoardCompetenceBridgeBuilder $bridgeBuilder,
        private readonly SalesBoardBaselineDiffService $diffService,
    ) {}

    public function build(SalesBoardBuilderReview $review): SalesBoardBuilderReviewWorkspace
    {
        $review->loadMissing([
            'cycle.construction',
            'baseline.lines',
            'baseline.movements',
            'sections',
            'divergences',
        ]);

        $baseline = $review->baseline;
        $cycle = $review->cycle;
        $anchor = $this->priorPositionResolver->forCycle($cycle);

        $linesByClassification = $baseline->lines->groupBy(
            fn (SalesBoardCycleLine $line): string => $line->classification->value,
        );
        $movementsByType = $baseline->movements->groupBy(
            fn (SalesBoardCycleMovement $movement): string => $movement->movement_type->value,
        );

        $divergenceCounts = $review->divergences
            ->groupBy('sales_board_builder_review_section_id')
            ->map(fn ($group): int => $group->count());

        $sections = [];

        foreach ($review->sections->sortBy(
            fn (SalesBoardBuilderReviewSection $section): int => array_search($section->section, SectionEnum::ordered(), true),
        ) as $section) {
            $sections[$section->section->value] = $this->buildSection(
                $section,
                $linesByClassification,
                $movementsByType,
                (int) ($divergenceCounts[$section->getKey()] ?? 0),
                $anchor,
            );
        }

        $progress = $review->progress();

        return new SalesBoardBuilderReviewWorkspace(
            reviewId: (int) $review->getKey(),
            constructionName: (string) $cycle->construction?->development_name,
            referenceMonth: $cycle->reference_month->format('m/Y'),
            positionDate: CarbonImmutable::parse($cycle->position_date->toDateString()),
            attempt: (int) $review->attempt,
            status: $review->status,
            unitsTotal: (int) $baseline->units_total,
            buckets: [
                new SalesBoardBuilderWorkspaceBucket('Estoque', (int) $baseline->stock_units, IntegerMoney::cents($baseline->stock_value)),
                new SalesBoardBuilderWorkspaceBucket('Financiado', (int) $baseline->financed_units, IntegerMoney::cents($baseline->financed_value)),
                new SalesBoardBuilderWorkspaceBucket('Quitado', (int) $baseline->settled_units, IntegerMoney::cents($baseline->settled_value)),
                new SalesBoardBuilderWorkspaceBucket('Permutado', (int) $baseline->exchanged_units, IntegerMoney::cents($baseline->exchanged_value)),
            ],
            sections: $sections,
            divergences: $review->divergences->values()->all(),
            sectionsResolved: $progress['resolved'],
            sectionsTotal: $progress['total'],
            submittedAt: $review->submitted_at,
            reviewerName: $review->reviewer_name,
            overallComment: $review->overall_comment,
            warnings: SalesBoardIssuePresenter::groupFrozen($baseline->frozenWarnings(), forBuilder: true),
            bridge: $this->bridgeBuilder->forBaselineWithAnchor($baseline, $anchor),
            rectification: $this->rectificationContext($cycle, $baseline),
            lateMovementsCount: $baseline->movements
                ->filter(fn (SalesBoardCycleMovement $movement): bool => $movement->timing !== null)
                ->count(),
        );
    }

    /**
     * O contexto da retificação aberta da competência: o motivo, a posição
     * publicada que ela substitui e o que muda contra ela -- a comparação entre
     * as duas versões congeladas, sem tocar a fonte viva. `null` sem
     * retificação aberta.
     *
     * @return array{reason: string, requested_by: string|null, requested_at: CarbonImmutable|null, published_version: string|null, published_at: CarbonImmutable|null, diff: string|null}|null
     */
    private function rectificationContext(SalesBoardCycle $cycle, SalesBoardCycleBaseline $baseline): ?array
    {
        $rectification = SalesBoardCycleRectification::query()
            ->where('sales_board_cycle_id', $cycle->getKey())
            ->where('status', SalesBoardRectificationStatus::Open->value)
            ->with(['requestedBy', 'rectifiedPublication.baseline'])
            ->first();

        if (! $rectification instanceof SalesBoardCycleRectification) {
            return null;
        }

        $published = $rectification->rectifiedPublication?->baseline;

        return [
            'reason' => (string) $rectification->reason,
            'requested_by' => $rectification->requestedBy?->name,
            'requested_at' => $rectification->requested_at,
            'published_version' => $published?->versionLabel(),
            'published_at' => $rectification->rectifiedPublication?->published_at,
            'diff' => $published === null
                ? null
                : $this->diffService->compare(
                    SalesBoardComparableSnapshot::fromBaseline($published),
                    SalesBoardComparableSnapshot::fromBaseline($baseline),
                )->summary(),
        ];
    }

    /**
     * @param  Collection<string, Collection<int, SalesBoardCycleLine>>  $linesByClassification
     * @param  Collection<string, Collection<int, SalesBoardCycleMovement>>  $movementsByType
     */
    private function buildSection(
        SalesBoardBuilderReviewSection $section,
        $linesByClassification,
        $movementsByType,
        int $divergenceCount,
        ?SalesBoardPriorPosition $anchor = null,
    ): SalesBoardBuilderWorkspaceSection {
        $classification = $section->section->classification();

        if ($classification !== null) {
            $lines = $linesByClassification->get($classification->value, collect());

            $rows = $lines
                ->sort(fn (SalesBoardCycleLine $left, SalesBoardCycleLine $right): int => UnitDisplayOrder::compare($left->block, $left->unit, $right->block, $right->unit)
                    ?: [(int) $left->construction_unit_id, (int) $left->getKey()] <=> [(int) $right->construction_unit_id, (int) $right->getKey()])
                ->map(fn (SalesBoardCycleLine $line): SalesBoardBuilderWorkspaceUnitRow => SalesBoardBuilderWorkspaceUnitRow::fromLine($line))
                ->values()
                ->all();

            return new SalesBoardBuilderWorkspaceSection(
                sectionId: (int) $section->getKey(),
                section: $section->section,
                status: $section->status,
                comment: $section->comment,
                rows: $rows,
                divergenceCount: $divergenceCount,
                totalValueCents: $this->bucketTotal($lines),
            );
        }

        $movements = $movementsByType->get($section->section->movementType()->value, collect());

        return new SalesBoardBuilderWorkspaceSection(
            sectionId: (int) $section->getKey(),
            section: $section->section,
            status: $section->status,
            comment: $section->comment,
            rows: $movements
                ->sort(fn (SalesBoardCycleMovement $left, SalesBoardCycleMovement $right): int => self::compareMovements($left, $right))
                ->map(fn (SalesBoardCycleMovement $movement): SalesBoardBuilderWorkspaceMovementRow => self::movementRow($movement, $anchor))
                ->values()
                ->all(),
            divergenceCount: $divergenceCount,
        );
    }

    /**
     * A linha do movimento com o selo de competência anterior. Na revisão de
     * venda, o valor e a data como a competência anterior os congelou -- a
     * linha da âncora vigente para a unidade.
     */
    private static function movementRow(SalesBoardCycleMovement $movement, ?SalesBoardPriorPosition $anchor): SalesBoardBuilderWorkspaceMovementRow
    {
        $prior = $anchor?->line((int) $movement->construction_unit_id);
        $previousSaleDate = $prior?->saleDate === null ? null : CarbonImmutable::parse($prior->saleDate);
        $previousSaleValue = $prior?->saleValueCents;

        return SalesBoardBuilderWorkspaceMovementRow::fromMovement(
            $movement,
            $movement->timingLabel($anchor?->referenceMonth, $anchor?->isPublished ?? true, $previousSaleValue, $previousSaleDate),
            $previousSaleValue,
            $previousSaleDate,
        );
    }

    /**
     * Os de competências anteriores primeiro; depois a data do evento
     * crescente, sem data no fim -- a quitação não traz o dia --, e a ordem
     * natural da unidade.
     */
    private static function compareMovements(SalesBoardCycleMovement $left, SalesBoardCycleMovement $right): int
    {
        $byOrigin = (int) ($left->timing === null) <=> (int) ($right->timing === null);

        $leftDate = $left->event_date?->toDateString();
        $rightDate = $right->event_date?->toDateString();

        $byDate = match (true) {
            $leftDate === $rightDate => 0,
            $leftDate === null => 1,
            $rightDate === null => -1,
            default => $leftDate <=> $rightDate,
        };

        return $byOrigin
            ?: $byDate
            ?: UnitDisplayOrder::compare($left->block, $left->unit, $right->block, $right->unit)
            ?: [(int) $left->construction_unit_id, (int) $left->getKey()] <=> [(int) $right->construction_unit_id, (int) $right->getKey()];
    }

    /**
     * O valor do balde é a soma do que cada linha contribui, e some inteiro
     * assim que uma delas não souber o próprio valor -- a mesma regra das fases
     * anteriores. Somar só as conhecidas mostraria à construtora um total menor
     * que a realidade com cara de total fechado.
     *
     * @param  Collection<int, SalesBoardCycleLine>  $lines
     */
    private function bucketTotal($lines): ?int
    {
        $total = 0;

        foreach ($lines as $line) {
            $cents = IntegerMoney::cents($line->bucketValue());

            if ($cents === null) {
                return null;
            }

            $total += $cents;
        }

        return $total;
    }
}

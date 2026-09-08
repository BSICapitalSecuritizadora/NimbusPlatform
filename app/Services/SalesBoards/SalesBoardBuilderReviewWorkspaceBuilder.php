<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\DTOs\SalesBoards\SalesBoardBuilderReviewWorkspace;
use App\DTOs\SalesBoards\SalesBoardBuilderWorkspaceBucket;
use App\DTOs\SalesBoards\SalesBoardBuilderWorkspaceMovementRow;
use App\DTOs\SalesBoards\SalesBoardBuilderWorkspaceSection;
use App\DTOs\SalesBoards\SalesBoardBuilderWorkspaceUnitRow;
use App\Enums\SalesBoardBuilderReviewSection as SectionEnum;
use App\Models\SalesBoardBuilderReview;
use App\Models\SalesBoardBuilderReviewSection;
use App\Models\SalesBoardCycleLine;
use App\Models\SalesBoardCycleMovement;
use App\Support\Money\IntegerMoney;
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
 */
class SalesBoardBuilderReviewWorkspaceBuilder
{
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
        );
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
    ): SalesBoardBuilderWorkspaceSection {
        $classification = $section->section->classification();

        if ($classification !== null) {
            $lines = $linesByClassification->get($classification->value, collect());

            $rows = $lines
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
                ->map(fn (SalesBoardCycleMovement $movement): SalesBoardBuilderWorkspaceMovementRow => SalesBoardBuilderWorkspaceMovementRow::fromMovement($movement))
                ->values()
                ->all(),
            divergenceCount: $divergenceCount,
        );
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

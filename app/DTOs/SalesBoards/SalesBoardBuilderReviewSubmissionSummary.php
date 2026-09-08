<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;
use App\Enums\BuilderReviewerType;
use App\Enums\SalesBoardBuilderDivergenceType;
use App\Enums\SalesBoardBuilderReviewSection as SectionEnum;
use App\Enums\SalesBoardBuilderReviewSectionStatus;
use App\Models\SalesBoardBuilderDivergence;
use App\Models\SalesBoardBuilderReview;
use App\Models\SalesBoardCycleBaseline;
use Carbon\CarbonImmutable;

/**
 * O pacote que a Gestão recebe quando a construtora termina.
 *
 * Existe para que a fase seguinte não precise reconstruir isso consultando cinco
 * tabelas e recompondo a lógica de progresso por conta própria -- cada
 * reconstrução seria uma chance de a Gestão ver um número diferente do que a
 * construtora enviou.
 *
 * Traz junto a versão congelada porque a análise é sobre o par: o que o Nimbus
 * apurou e o que a construtora declarou sobre aquilo. Uma das metades sozinha
 * não é analisável.
 */
readonly class SalesBoardBuilderReviewSubmissionSummary extends BaseDTO
{
    /**
     * @param  array<string, SalesBoardBuilderReviewSectionStatus>  $sectionStatuses
     * @param  array<string, int>  $divergenceCountsByType
     * @param  list<SalesBoardBuilderDivergence>  $divergences
     */
    public function __construct(
        public SalesBoardBuilderReview $review,
        public SalesBoardCycleBaseline $baseline,
        public array $sectionStatuses,
        public array $divergenceCountsByType,
        public array $divergences,
        public bool $isFullyConfirmed,
        public int $divergenceCount,
        public ?BuilderReviewerType $reviewerType,
        public ?string $reviewerName,
        public ?string $reviewerEmail,
        public ?CarbonImmutable $submittedAt,
    ) {}

    public static function for(SalesBoardBuilderReview $review): self
    {
        $review->loadMissing(['baseline', 'sections', 'divergences']);

        $statuses = [];

        foreach (SectionEnum::ordered() as $section) {
            $row = $review->sections->firstWhere('section', $section);
            $statuses[$section->value] = $row?->status ?? SalesBoardBuilderReviewSectionStatus::Pending;
        }

        $countsByType = [];

        foreach ($review->divergences as $divergence) {
            $countsByType[$divergence->type->value] = ($countsByType[$divergence->type->value] ?? 0) + 1;
        }

        arsort($countsByType);

        return new self(
            review: $review,
            baseline: $review->baseline,
            sectionStatuses: $statuses,
            divergenceCountsByType: $countsByType,
            divergences: $review->divergences->values()->all(),
            isFullyConfirmed: $review->isFullyConfirmed(),
            divergenceCount: $review->divergences->count(),
            reviewerType: BuilderReviewerType::tryFrom((string) $review->reviewer_type),
            reviewerName: $review->reviewer_name,
            reviewerEmail: $review->reviewer_email,
            submittedAt: $review->submitted_at,
        );
    }

    /**
     * @return list<SectionEnum>
     */
    public function divergentSections(): array
    {
        return array_values(array_map(
            fn (string $value): SectionEnum => SectionEnum::from($value),
            array_keys(array_filter(
                $this->sectionStatuses,
                fn (SalesBoardBuilderReviewSectionStatus $status): bool => $status === SalesBoardBuilderReviewSectionStatus::Divergent,
            )),
        ));
    }

    /**
     * @return list<SalesBoardBuilderDivergence>
     */
    public function divergencesOfType(SalesBoardBuilderDivergenceType $type): array
    {
        return array_values(array_filter(
            $this->divergences,
            fn (SalesBoardBuilderDivergence $divergence): bool => $divergence->type === $type,
        ));
    }

    public function headline(): string
    {
        if ($this->isFullyConfirmed) {
            return 'A construtora confirmou integralmente a posição apresentada.';
        }

        return sprintf(
            'A construtora apontou %d divergência(s) em %d seção(ões).',
            $this->divergenceCount,
            count($this->divergentSections()),
        );
    }
}

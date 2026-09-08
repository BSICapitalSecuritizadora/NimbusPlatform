<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;
use App\Enums\SalesBoardBuilderReviewSection as SectionEnum;
use App\Enums\SalesBoardBuilderReviewStatus;
use App\Models\SalesBoardBuilderDivergence;
use Carbon\CarbonImmutable;

/**
 * Tudo -- e apenas -- o que a construtora precisa para validar a competência.
 *
 * Uma camada própria, e não os models crus, porque um dia esta estrutura vai
 * alimentar uma superfície fora da BSI. Entregar `SalesBoardCycleLine` a ela
 * significaria que qualquer coluna acrescentada ao snapshot no futuro passaria a
 * vazar para fora sem ninguém decidir isso. Aqui a exposição é uma lista
 * explícita: o que não foi projetado, não sai.
 *
 * Fica de fora, deliberadamente: dado pessoal de comprador, política comercial
 * interna da BSI, resumos criptográficos, ids de usuário e qualquer vocabulário
 * técnico das fases anteriores. A construtora não precisa saber o que é
 * `SOURCE_ONLY` para dizer se a venda dela está certa.
 *
 * Tudo vem do snapshot congelado. Se a fonte viva mudou desde a geração, a tela
 * continua mostrando o quadro que a construtora recebeu -- que é exatamente o
 * quadro sobre o qual ela está sendo perguntada.
 */
readonly class SalesBoardBuilderReviewWorkspace extends BaseDTO
{
    /**
     * @param  array<string, SalesBoardBuilderWorkspaceSection>  $sections  indexado pelo valor da seção
     * @param  list<SalesBoardBuilderDivergence>  $divergences
     * @param  list<SalesBoardBuilderWorkspaceBucket>  $buckets
     */
    public function __construct(
        public int $reviewId,
        public string $constructionName,
        public string $referenceMonth,
        public CarbonImmutable $positionDate,
        public int $attempt,
        public SalesBoardBuilderReviewStatus $status,
        public int $unitsTotal,
        public array $buckets,
        public array $sections,
        public array $divergences,
        public int $sectionsResolved,
        public int $sectionsTotal,
        public ?CarbonImmutable $submittedAt,
        public ?string $reviewerName,
        public ?string $overallComment,
    ) {}

    public function section(SectionEnum $section): ?SalesBoardBuilderWorkspaceSection
    {
        return $this->sections[$section->value] ?? null;
    }

    /**
     * @return list<SalesBoardBuilderWorkspaceSection>
     */
    public function positionSections(): array
    {
        return array_values(array_filter(
            $this->sections,
            fn (SalesBoardBuilderWorkspaceSection $section): bool => $section->isPosition(),
        ));
    }

    /**
     * @return list<SalesBoardBuilderWorkspaceSection>
     */
    public function movementSections(): array
    {
        return array_values(array_filter(
            $this->sections,
            fn (SalesBoardBuilderWorkspaceSection $section): bool => ! $section->isPosition(),
        ));
    }

    public function progressPercent(): int
    {
        return $this->sectionsTotal === 0
            ? 0
            : (int) round(($this->sectionsResolved / $this->sectionsTotal) * 100);
    }

    public function progressLabel(): string
    {
        return sprintf('%d de %d seções revisadas', $this->sectionsResolved, $this->sectionsTotal);
    }

    public function divergenceCount(): int
    {
        return count($this->divergences);
    }

    public function isEditable(): bool
    {
        return $this->status === SalesBoardBuilderReviewStatus::Draft;
    }

    public function canSubmit(): bool
    {
        return $this->isEditable() && ($this->sectionsResolved === $this->sectionsTotal);
    }

    /**
     * @return list<SectionEnum>
     */
    public function pendingSections(): array
    {
        return array_values(array_map(
            fn (SalesBoardBuilderWorkspaceSection $section): SectionEnum => $section->section,
            array_filter(
                $this->sections,
                fn (SalesBoardBuilderWorkspaceSection $section): bool => ! $section->status->isResolved(),
            ),
        ));
    }
}

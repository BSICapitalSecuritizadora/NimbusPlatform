<?php

declare(strict_types=1);

namespace App\DTOs\SalesBoards;

use App\DTOs\BaseDTO;
use App\Enums\SalesBoardManagementReviewStatus;
use App\Enums\SalesBoardNonconformityOrigin;
use App\Enums\SalesBoardStaleImpact;
use App\Support\Money\IntegerMoney;
use Carbon\CarbonImmutable;

/**
 * Tudo o que a tela da Gestão mostra, pronto.
 *
 * Montado a partir do baseline congelado, da validação enviada e das pendências
 * materializadas -- nunca da fonte viva. Se um contrato mudou desde a geração, a
 * tela continua mostrando os fatos sobre os quais a construtora foi perguntada e
 * sobre os quais a Gestão está decidindo; o que a mudança faz é aparecer na
 * situação da fonte, que é uma informação diferente e tem lugar próprio.
 */
readonly class SalesBoardManagementReviewWorkspace extends BaseDTO
{
    /**
     * @param  list<SalesBoardBuilderWorkspaceBucket>  $buckets
     * @param  list<SalesBoardManagementNonconformityRow>  $nonconformities
     * @param  list<array{label: string, status: string, color: string, comment: string|null, divergences: int}>  $builderSections
     * @param  array{ready: bool, checks: list<array{label: string, passed: bool, detail: string|null}>, impact: SalesBoardStaleImpact|null}  $gate
     */
    public function __construct(
        public int $reviewId,
        public string $emissionName,
        public string $constructionName,
        public string $referenceMonth,
        public CarbonImmutable $positionDate,
        public string $baselineLabel,
        public int $builderAttempt,
        public int $managementAttempt,
        public SalesBoardManagementReviewStatus $status,
        public bool $isApplicable,
        public int $unitsTotal,
        public array $buckets,
        public ?string $builderReviewerName,
        public ?CarbonImmutable $builderSubmittedAt,
        public bool $builderFullyConfirmed,
        public int $builderDivergenceCount,
        public ?string $builderOverallComment,
        public array $builderSections,
        public array $nonconformities,
        public array $gate,
        public ?SalesBoardPublicationPayload $publicationPreview,
        public ?string $returnReason,
        public ?string $sourceChangeReason,
        public ?CarbonImmutable $approvedAt,
        public ?string $approvedByName,
    ) {}

    public function staleImpact(): ?SalesBoardStaleImpact
    {
        return $this->gate['impact'] ?? null;
    }

    public function isReadyToPublish(): bool
    {
        return (bool) ($this->gate['ready'] ?? false);
    }

    /**
     * A aprovação exige justificativa de fonte alterada?
     */
    public function requiresSourceOverride(): bool
    {
        return $this->staleImpact() === SalesBoardStaleImpact::SourceOnly;
    }

    /**
     * A situação da fonte impede qualquer publicação?
     */
    public function isBlockedBySource(): bool
    {
        return in_array(
            $this->staleImpact(),
            [SalesBoardStaleImpact::Material, SalesBoardStaleImpact::Blocking],
            true,
        );
    }

    /**
     * @return list<SalesBoardManagementNonconformityRow>
     */
    public function nonconformitiesOf(SalesBoardNonconformityOrigin $origin): array
    {
        return array_values(array_filter(
            $this->nonconformities,
            fn (SalesBoardManagementNonconformityRow $row): bool => $row->origin === $origin,
        ));
    }

    public function pendingCount(): int
    {
        return count(array_filter(
            $this->nonconformities,
            fn (SalesBoardManagementNonconformityRow $row): bool => $row->isPending(),
        ));
    }

    public function blockingCount(): int
    {
        return count(array_filter(
            $this->nonconformities,
            fn (SalesBoardManagementNonconformityRow $row): bool => $row->blocksApproval(),
        ));
    }

    public function totalValueCents(): ?int
    {
        $total = 0;

        foreach ($this->buckets as $bucket) {
            if ($bucket->valueCents === null) {
                return null;
            }

            $total += $bucket->valueCents;
        }

        return $total;
    }

    public function formattedTotalValue(): string
    {
        $cents = $this->totalValueCents();

        return $cents === null ? '—' : 'R$ '.IntegerMoney::format($cents);
    }

    public function progressLabel(): string
    {
        $total = count($this->nonconformities);

        if ($total === 0) {
            return 'Nenhuma não conformidade a decidir';
        }

        return sprintf('%d de %d não conformidade(s) decidida(s)', $total - $this->pendingCount(), $total);
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Models\IndexRateSourceGovernanceReview;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Prontidão da fonte externa do índice.
 *
 * A seleção da fonte é hoje POR INDEXADOR, via `pu_indexes.bcb.series`: não
 * existe escolha de fonte por emissão, e o gate não inventa uma. O provedor
 * B3 × BCB SGS 4389 continua sendo o único com dossiê técnico próprio; qualquer
 * outra série configurada é avaliada apenas pela aprovação operacional
 * registrada em `index_rate_source_governance_reviews`. Um indexador sem série
 * configurada bloqueia de forma explicável em vez de assumir uma fonte.
 */
final class PuBaselineIndexSourceReadinessService
{
    public function __construct(
        private readonly CdiSourceDossierGovernanceService $cdiDossier,
    ) {}

    /** @return array<string, mixed> */
    public function evaluate(?PuIndexer $indexer): array
    {
        if ($indexer === null) {
            return $this->missing();
        }

        if (! $indexer->requiresIndexRates()) {
            return [
                ...$this->missing(),
                'required' => false,
                'resolvable' => true,
                'technical_homologation_satisfied' => true,
                'approved' => true,
                'indexer' => $indexer->value,
                'source_label' => 'Não aplicável ao indexador',
                'rate_source_value' => null,
            ];
        }

        $series = config('pu_indexes.bcb.series.'.mb_strtolower($indexer->value));

        if (! is_array($series) || blank($series['source'] ?? null) || blank($series['code'] ?? null)) {
            return [
                ...$this->missing(),
                'resolvable' => true,
                'indexer' => $indexer->value,
            ];
        }

        $sourceCode = sprintf('%s_%s', $series['source'], $series['code']);
        $sourceLabel = sprintf(
            '%s %s',
            str_replace('_', ' ', mb_strtoupper((string) $series['source'])),
            $series['code'],
        );
        // Valor gravado em `index_rates.source`: é ele que permite confrontar
        // cada snapshot carregado com a fonte efetivamente homologada.
        $rateSourceValue = (string) $series['source'];

        if ($indexer === PuIndexer::Cdi && $sourceCode === CdiSourceDossierGovernanceService::SOURCE_CODE) {
            return [
                ...$this->cdiDossier->latest(),
                'required' => true,
                'resolvable' => true,
                'indexer' => $indexer->value,
                'source_code' => $sourceCode,
                'source_label' => $sourceLabel,
                'reference_index_label' => 'Taxa DI B3',
                'rate_source_value' => $rateSourceValue,
            ];
        }

        $approved = IndexRateSourceGovernanceReview::query()
            ->where('source_code', $sourceCode)
            ->where('status', IndexRateSourceGovernanceReview::STATUS_APPROVED)
            ->exists();

        return [
            ...$this->missing(),
            'required' => true,
            'resolvable' => true,
            'indexer' => $indexer->value,
            'source_code' => $sourceCode,
            'source_label' => $sourceLabel,
            'approved' => $approved,
            'rate_source_value' => $rateSourceValue,
        ];
    }

    public function approve(PuIndexer $indexer, User $reviewer, string $notes): IndexRateSourceGovernanceReview
    {
        $diagnostics = $this->evaluate($indexer);

        if (($diagnostics['source_code'] ?? null) !== CdiSourceDossierGovernanceService::SOURCE_CODE) {
            throw ValidationException::withMessages([
                'source' => 'A fonte selecionada não possui ação de aprovação registrada neste provedor.',
            ]);
        }

        return $this->cdiDossier->approveLatest($reviewer, $notes);
    }

    /** @return array<string, mixed> */
    private function missing(): array
    {
        return [
            'required' => true,
            'resolvable' => false,
            'indexer' => null,
            'source_code' => null,
            'source_label' => null,
            'reference_index_label' => null,
            'technical_homologation_satisfied' => false,
            'approved' => false,
            'rate_source_value' => null,
            'artifact_found' => false,
            'artifact_path' => null,
            'report' => null,
            'review' => null,
        ];
    }
}

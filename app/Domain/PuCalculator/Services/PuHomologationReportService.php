<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuExternalValidation;
use App\Models\EmissionPuExternalValidationGap;
use App\Models\EmissionPuExternalValidationRow;

class PuHomologationReportService
{
    /**
     * Monta os dados do relatorio de homologacao a partir de uma versao ja persistida.
     * Nao executa nenhum calculo financeiro: apenas le dados gravados.
     *
     * Recebe a versão explicitamente e por isso é neutra quanto ao papel: o dossiê
     * de uma candidate é justamente o insumo do review maker-checker. Quem decide
     * exposição é o chamador (rota/comando), não este serviço.
     *
     * @return array<string, mixed>
     */
    public function build(EmissionPuCurveVersion $version): array
    {
        $version->loadMissing(['emission', 'generatedBy', 'validatedBy', 'homologatedBy', 'reviewedBy']);
        $emission = $version->emission;
        $snapshot = $version->parameters_snapshot ?? [];
        $validation = $version->validation_summary ?? [];
        $externalValidation = $version->externalValidations()
            ->with([
                'benchmark.createdBy:id,name',
                'benchmark.sourceDocument:id,title',
                'generatedBy:id,name',
                'reviewedBy:id,name',
            ])
            ->latest('id')
            ->first();

        return [
            'emission' => [
                'name' => $emission?->name,
                'identifier' => $this->emissionIdentifier($version),
                'type' => $emission?->type,
                'issued_quantity' => $emission?->issued_quantity,
                'integralized_quantity' => $emission?->calculateIntegralizedQuantity(),
            ],
            'version' => [
                'calculation_version' => $version->calculation_version,
                'status_label' => $version->status->label(),
                'status' => $version->status->value,
                'engine_version' => $version->engine_version,
                'rows_count' => $version->rows_count,
                'obsolete_reason' => $version->obsolete_reason,
                'error_message' => $version->error_message,
                'generated_at' => $version->generated_at?->format('d/m/Y H:i'),
                'generated_by' => $version->generatedBy?->name,
                'validated_at' => $version->validated_at?->format('d/m/Y H:i'),
                'validated_by' => $version->validatedBy?->name,
                'homologated_at' => $version->homologated_at?->format('d/m/Y H:i'),
                'homologated_by' => $version->homologatedBy?->name,
                'curve_role' => $version->curve_role->value,
                'candidate_as_of' => $version->candidate_as_of?->toDateString(),
                'input_fingerprint' => $version->input_fingerprint,
                'curve_checksum' => $version->curve_checksum,
                'internal_validation_status' => $version->internal_validation_status?->value,
                'review_status' => $version->review_status->value,
                'reviewed_at' => $version->reviewed_at?->format('d/m/Y H:i'),
                'reviewed_by' => $version->reviewedBy?->name,
                'review_reason' => $version->review_reason,
                'external_validation_status' => $version->external_validation_status?->value,
            ],
            'parameters' => [
                'indexer' => $snapshot['indexer'] ?? null,
                'indexer_label' => $this->indexerLabel($snapshot['indexer'] ?? null),
                'is_homologated_indexer' => $this->isHomologatedIndexer($snapshot['indexer'] ?? null),
                'calculation_method' => $snapshot['calculation_method'] ?? null,
                'method_version' => $snapshot['method_version'] ?? $version->engine_version,
                'spread_rate' => $snapshot['spread_rate'] ?? null,
                'annual_rate' => $snapshot['annual_rate'] ?? null,
                'initial_unit_value' => $snapshot['initial_unit_value'] ?? null,
                'curve_start_date' => $snapshot['curve_start_date'] ?? null,
                'curve_end_date' => $snapshot['curve_end_date'] ?? null,
                'business_day_basis' => $snapshot['business_day_basis'] ?? null,
                'calendar_code' => $snapshot['calendar_code'] ?? null,
            ],
            'validation' => [
                'has_validation' => $validation !== [],
                'status' => $validation['status'] ?? null,
                'mode' => $validation['mode'] ?? null,
                'total_rows_compared' => $validation['total_rows_compared'] ?? null,
                'total_divergences' => $validation['total_divergences'] ?? null,
                'total_field_divergences' => $validation['total_field_divergences'] ?? null,
                'first_divergence_date' => $validation['first_divergence_date'] ?? null,
                'largest_pu_difference' => $validation['largest_pu_difference'] ?? null,
                'largest_total_value_difference' => $validation['largest_total_value_difference'] ?? null,
                'largest_payment_difference' => $validation['largest_payment_difference'] ?? null,
            ],
            'external_validation' => $this->externalValidation($externalValidation),
            'generated_at' => now()->format('d/m/Y H:i'),
        ];
    }

    public function fileName(EmissionPuCurveVersion $version): string
    {
        return sprintf(
            'homologacao-pu-emissao-%d-%s.pdf',
            $version->emission_id,
            $version->calculation_version,
        );
    }

    private function indexerLabel(?string $indexer): string
    {
        if ($indexer === null) {
            return '—';
        }

        return PuIndexer::tryFrom($indexer)?->label() ?? $indexer;
    }

    private function isHomologatedIndexer(?string $indexer): bool
    {
        return PuIndexer::tryFrom((string) $indexer)?->isHomologated() ?? false;
    }

    private function emissionIdentifier(EmissionPuCurveVersion $version): string
    {
        $emission = $version->emission;

        if ($emission === null) {
            return (string) $version->emission_id;
        }

        $parts = array_filter([
            $emission->type,
            $emission->series !== null ? 'Serie '.$emission->series : null,
            $emission->emission_number !== null ? 'Emissao '.$emission->emission_number : null,
        ]);

        $suffix = $parts !== [] ? ' ('.implode(' / ', $parts).')' : '';

        return sprintf('#%d%s', $emission->id, $suffix);
    }

    /** @return array<string, mixed> */
    private function externalValidation(?EmissionPuExternalValidation $validation): array
    {
        if (! $validation instanceof EmissionPuExternalValidation) {
            return [
                'has_comparison' => false,
                'status' => null,
                'tolerance_policy' => null,
                'differences' => [],
                'coverage_gaps' => [],
            ];
        }

        $benchmark = $validation->benchmark;
        $differences = $validation->rows()
            ->orderBy('reference_date')
            ->limit(25)
            ->get()
            ->map(fn (EmissionPuExternalValidationRow $row): array => [
                'reference_date' => $row->reference_date?->toDateString(),
                'candidate_unit_value' => $row->candidate_unit_value,
                'external_unit_value' => $row->external_unit_value,
                'absolute_difference' => $row->absolute_difference,
                'relative_difference_percentage' => $row->relative_difference_percentage,
                'classification' => $row->classification,
            ])
            ->all();
        $coverageGaps = $validation->gaps()
            ->orderBy('reference_date')
            ->orderBy('gap_type')
            ->limit(25)
            ->get()
            ->map(fn (EmissionPuExternalValidationGap $gap): array => [
                'reference_date' => $gap->reference_date?->toDateString(),
                'gap_type' => $gap->gap_type->value,
            ])
            ->all();

        return [
            'has_comparison' => true,
            'validation_id' => $validation->id,
            'status' => $validation->status->value,
            'benchmark' => [
                'id' => $benchmark?->id,
                'source_type' => $benchmark?->source_type,
                'source_name' => $benchmark?->source_name,
                'source_document' => $benchmark?->sourceDocument?->title,
                'source_document_id' => $benchmark?->source_document_id,
                'source_evidence_id' => $benchmark?->source_evidence_id,
                'reference_as_of' => $benchmark?->reference_as_of?->toDateString(),
                'file_sha256' => $benchmark?->file_sha256,
                'dataset_sha256' => $benchmark?->dataset_sha256,
                'row_count' => $benchmark?->row_count,
                'from_date' => $benchmark?->from_date?->toDateString(),
                'to_date' => $benchmark?->to_date?->toDateString(),
                'imported_by' => $benchmark?->createdBy?->name,
                'imported_at' => $benchmark?->created_at?->format('d/m/Y H:i'),
            ],
            'candidate_checksum' => $validation->candidate_checksum,
            'benchmark_dataset_sha256' => $validation->benchmark_dataset_sha256,
            'comparison_algorithm_version' => $validation->comparison_algorithm_version,
            'comparison_sha256' => $validation->comparison_sha256,
            'coverage_status' => $validation->coverage_status->value,
            'compared_rows' => $validation->compared_rows,
            'candidate_dates_without_reference' => $validation->candidate_dates_without_reference,
            'reference_dates_without_candidate' => $validation->reference_dates_without_candidate,
            'generated_by' => $validation->generatedBy?->name,
            'generated_at' => $validation->created_at?->format('d/m/Y H:i'),
            'reviewed_by' => $validation->reviewedBy?->name,
            'reviewed_at' => $validation->reviewed_at?->format('d/m/Y H:i'),
            'review_reason' => $validation->review_reason,
            'tolerance_policy' => null,
            'differences' => $differences,
            'difference_sample_limit' => 25,
            'coverage_gaps' => $coverageGaps,
            'coverage_gap_sample_limit' => 25,
        ];
    }
}

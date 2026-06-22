<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Models\EmissionPuCurveVersion;

class PuHomologationReportService
{
    /**
     * Monta os dados do relatorio de homologacao a partir de uma versao ja persistida.
     * Nao executa nenhum calculo financeiro: apenas le dados gravados.
     *
     * @return array<string, mixed>
     */
    public function build(EmissionPuCurveVersion $version): array
    {
        $version->loadMissing(['emission', 'generatedBy', 'validatedBy', 'homologatedBy']);
        $emission = $version->emission;
        $snapshot = $version->parameters_snapshot ?? [];
        $validation = $version->validation_summary ?? [];

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
            ],
            'parameters' => [
                'indexer' => $snapshot['indexer'] ?? null,
                'spread_rate' => $snapshot['spread_rate'] ?? null,
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
}

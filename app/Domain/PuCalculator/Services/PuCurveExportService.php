<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Models\Emission;
use App\Models\EmissionPuDailyCurve;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PuCurveExportService
{
    /**
     * @return Collection<int, EmissionPuDailyCurve>
     */
    public function rows(Emission $emission, ?string $calculationVersion = null): Collection
    {
        $resolvedVersion = $calculationVersion ?? EmissionPuDailyCurve::latestCalculationVersionForEmission($emission->id);

        if ($resolvedVersion === null) {
            return collect();
        }

        return EmissionPuDailyCurve::query()
            ->where('emission_id', $emission->id)
            ->where('calculation_version', $resolvedVersion)
            ->orderBy('curve_date')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(Emission $emission, ?string $calculationVersion = null): array
    {
        $rows = $this->rows($emission, $calculationVersion);

        if ($rows->isEmpty()) {
            return [
                'calculation_version' => $calculationVersion,
                'rows_count' => 0,
                'first_date' => null,
                'last_date' => null,
                'last_updated_unit_value' => null,
                'last_residual_unit_value' => null,
                'last_total_value' => null,
                'last_payment_total_value' => null,
            ];
        }

        /** @var EmissionPuDailyCurve $firstRow */
        $firstRow = $rows->first();
        /** @var EmissionPuDailyCurve $lastRow */
        $lastRow = $rows->last();

        return [
            'calculation_version' => $firstRow->calculation_version,
            'rows_count' => $rows->count(),
            'first_date' => $firstRow->curve_date?->toDateString(),
            'last_date' => $lastRow->curve_date?->toDateString(),
            'last_updated_unit_value' => (string) $lastRow->updated_unit_value,
            'last_residual_unit_value' => (string) $lastRow->residual_unit_value,
            'last_total_value' => (string) $lastRow->total_value,
            'last_payment_total_value' => (string) $lastRow->payment_total_value,
        ];
    }

    public function download(Emission $emission, ?string $calculationVersion = null): StreamedResponse
    {
        $rows = $this->rows($emission, $calculationVersion);

        if ($rows->isEmpty()) {
            throw new InvalidArgumentException('Nao existem linhas de curva PU para exportar nesta versao.');
        }

        /** @var EmissionPuDailyCurve $firstRow */
        $firstRow = $rows->first();
        $filename = sprintf(
            'emission-%d-pu-curve-%s.csv',
            $emission->id,
            $firstRow->calculation_version,
        );

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            fwrite($handle, chr(0xEF).chr(0xBB).chr(0xBF));

            fputcsv($handle, [
                'data',
                'versao_calculo',
                'PU_atualizado',
                'PU_residual',
                'juros_real',
                'amortizacao',
                'quantidade',
                'valor_total',
                'pagamento_total',
                'pagamento_juros_total',
                'CDI_usado',
                'data_CDI_usado',
                'fator_DI',
                'fator_spread',
                'fator_combinado',
                'DUP',
                'DUT',
            ], ';');

            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row->curve_date?->toDateString(),
                    $row->calculation_version,
                    (string) $row->updated_unit_value,
                    (string) $row->residual_unit_value,
                    (string) $row->interest_real_unit_value,
                    (string) $row->amortization_unit_value,
                    (string) $row->quantity,
                    (string) $row->total_value,
                    (string) $row->payment_total_value,
                    (string) $row->interest_payment_value,
                    (string) $row->index_rate_value,
                    $row->index_rate_date?->toDateString(),
                    (string) $row->factor_di,
                    (string) $row->factor_spread,
                    (string) $row->factor_spread_di,
                    $row->dup_interest,
                    $row->dut_interest,
                ], ';');
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}

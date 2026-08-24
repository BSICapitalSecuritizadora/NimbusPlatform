<?php

namespace App\Services;

use App\Models\Measurement;
use App\Models\MeasurementAsset;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class MeasurementEngineeringService
{
    /**
     * Validate and persist the Engineering data while the caller holds the
     * measurement workflow transaction.
     *
     * @param  array<int|string, mixed>  $monthlyProgress
     */
    public function validateAndRecord(Measurement $measurement, array $monthlyProgress): void
    {
        $measurement->loadMissing([
            'operation.planSets.construction',
            'assets.planLine',
            'assets.planSet',
        ]);

        $errors = [];

        if ($measurement->reference_month === null) {
            $errors['reference_month'] = 'Informe a competência da medição antes de aprovar a Engenharia.';
        }

        $planSets = $measurement->operation?->planSets ?? collect();

        if ($planSets->isEmpty()) {
            $errors['plan'] = 'A operação precisa possuir ao menos um plano de medição.';
        }

        $assetsByPlanSet = $measurement->assets->keyBy('plan_set_id');
        $validatedLines = [];

        foreach ($planSets as $planSet) {
            $asset = $assetsByPlanSet->get($planSet->getKey());
            $label = $this->planSetLabel($planSet);

            if (! $asset instanceof MeasurementAsset) {
                $errors["assets.{$planSet->getKey()}"] = "Envie o arquivo da medição para {$label}.";

                continue;
            }

            if (blank($asset->storage_path)
                || ! Storage::disk($asset->resolved_storage_disk)->exists($asset->storage_path)) {
                $errors["assets.{$planSet->getKey()}"] = "O arquivo de medição de {$label} não foi encontrado no armazenamento.";
            }

            $line = $asset->planLine;

            if (! $line instanceof MeasurementPlanLine
                || (int) $line->plan_set_id !== (int) $planSet->getKey()
                || (int) $line->operation_id !== (int) $measurement->operation_id) {
                $errors["plan_line.{$planSet->getKey()}"] = "Selecione uma linha válida do plano para {$label}.";

                continue;
            }

            if ($line->measurement_date === null) {
                $errors["measurement_date.{$planSet->getKey()}"] = "Informe a data da medição de {$label}.";
            } elseif ($measurement->reference_month !== null
                && $line->measurement_date->format('Y-m') !== $measurement->reference_month->format('Y-m')) {
                $errors["measurement_date.{$planSet->getKey()}"] = "A data da medição de {$label} não corresponde à competência informada.";
            }

            $monthly = $monthlyProgress[$planSet->getKey()] ?? null;

            if (! is_numeric($monthly) || (float) $monthly <= 0 || (float) $monthly > 100) {
                $errors["realized.{$planSet->getKey()}"] = "Informe para {$label} um percentual realizado maior que zero e menor ou igual a 100%.";

                continue;
            }

            $lockedLine = MeasurementPlanLine::query()->lockForUpdate()->find($line->getKey());

            if (! $lockedLine instanceof MeasurementPlanLine) {
                $errors["plan_line.{$planSet->getKey()}"] = "A linha do plano de {$label} não está mais disponível.";

                continue;
            }

            $previousCumulative = MeasurementPlanLine::query()
                ->where('plan_set_id', $planSet->getKey())
                ->where('sequence_number', '<', $lockedLine->sequence_number)
                ->orderByDesc('sequence_number')
                ->lockForUpdate()
                ->value('realized_cumulative_percent');

            $base = $previousCumulative !== null
                ? (float) $previousCumulative
                : (float) $lockedLine->initial_realized_cumulative_percent;
            $cumulative = round($base + (float) $monthly, 2);

            if ($cumulative > 100) {
                $errors["realized.{$planSet->getKey()}"] = "O percentual acumulado de {$label} não pode ultrapassar 100%.";

                continue;
            }

            $validatedLines[] = [$lockedLine, (float) $monthly, $cumulative];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        foreach ($validatedLines as [$line, $monthly, $cumulative]) {
            $line->forceFill([
                'realized_monthly_percent' => $monthly,
                'realized_cumulative_percent' => $cumulative,
                'measurement_id' => $measurement->getKey(),
            ])->save();
        }
    }

    private function planSetLabel(MeasurementPlanSet $planSet): string
    {
        return $planSet->construction?->development_name ?? $planSet->name;
    }
}

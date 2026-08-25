<?php

namespace App\Services;

use App\Models\Measurement;
use App\Models\MeasurementAsset;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use Illuminate\Validation\ValidationException;

class MeasurementEngineeringService
{
    public function __construct(private MeasurementFileValidationService $fileValidation) {}

    /**
     * Validate and persist the Engineering data while the caller holds the
     * measurement workflow transaction.
     *
     * @param  array<int|string, mixed>  $monthlyProgress
     * @return array{reference_month: ?string, plan_sets: array<int, array{plan_set_id: int, plan_line_id: int, asset_id: int, storage_path: string, storage_disk: string, sha256: string}>}
     */
    public function validateAndRecord(Measurement $measurement, array $monthlyProgress): array
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

        if ($measurement->assets->count() !== $planSets->count()
            || $assetsByPlanSet->count() !== $planSets->count()) {
            $errors['assets.coverage'] = 'Envie exatamente um arquivo para cada empreendimento da operação.';
        }

        foreach ($planSets as $planSet) {
            $asset = $assetsByPlanSet->get($planSet->getKey());
            $label = $this->planSetLabel($planSet);

            if (! $asset instanceof MeasurementAsset) {
                $errors["assets.{$planSet->getKey()}"] = "Envie o arquivo da medição para {$label}.";

                continue;
            }

            try {
                $this->fileValidation->validateAsset($asset->storage_path, $asset->resolved_storage_disk);
            } catch (ValidationException) {
                $errors["assets.{$planSet->getKey()}"] = "O arquivo de medição de {$label} não foi encontrado no armazenamento.";
            }

            if (! is_string($asset->sha256) || mb_strlen($asset->sha256) !== 64) {
                $errors["assets.{$planSet->getKey()}.sha256"] = "O arquivo de medição de {$label} não possui SHA-256 válido.";
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

            $validatedLines[] = [$lockedLine, (float) $monthly, $cumulative, $asset];
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

        return [
            'reference_month' => $measurement->reference_month?->toDateString(),
            'plan_sets' => collect($validatedLines)
                ->map(fn (array $validated): array => [
                    'plan_set_id' => (int) $validated[0]->plan_set_id,
                    'plan_line_id' => (int) $validated[0]->getKey(),
                    'asset_id' => (int) $validated[3]->getKey(),
                    'storage_path' => (string) $validated[3]->storage_path,
                    'storage_disk' => $validated[3]->resolved_storage_disk,
                    'sha256' => (string) $validated[3]->sha256,
                ])
                ->sortBy('plan_set_id')
                ->values()
                ->all(),
        ];
    }

    private function planSetLabel(MeasurementPlanSet $planSet): string
    {
        return $planSet->construction?->development_name ?? $planSet->name;
    }
}

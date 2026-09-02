<?php

namespace App\Services;

use App\Exceptions\MeasurementWorkflowException;
use App\Models\Measurement;
use App\Models\MeasurementReview;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

class OperationContextMutationService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Operation $operation, array $attributes): Operation
    {
        return DB::transaction(function () use ($operation, $attributes): Operation {
            // A operação é travada antes de qualquer verificação do `updating`:
            // a situação decide se os responsáveis podem mudar, e ler essa
            // situação fora do lock deixaria a mesma janela que o encerramento
            // fecha do outro lado.
            Operation::query()
                ->whereKey($operation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $operation->fill($attributes);
            $operation->save();

            return $operation->refresh();
        });
    }

    /**
     * Serialize an emission change with Engineering approval using the common
     * lock order: Operation, Measurements ordered by id, then stage-one reviews.
     */
    public function assertEmissionCanChange(Operation $operation): void
    {
        if (DB::transactionLevel() < 1) {
            throw new MeasurementWorkflowException('A alteração da emissão deve ocorrer pelo fluxo transacional da operação.');
        }

        Operation::query()
            ->whereKey($operation->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        $measurementIds = Measurement::query()
            ->where('operation_id', $operation->getKey())
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id');

        if ($measurementIds->isEmpty()) {
            return;
        }

        $hasApprovedEngineering = MeasurementReview::query()
            ->whereIn('measurement_id', $measurementIds)
            ->where('stage', MeasurementWorkflow::STAGE_ENGINEERING)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['status'])
            ->contains('status', 'approved');

        if ($hasApprovedEngineering) {
            throw new MeasurementWorkflowException('O contexto de uma operação aprovada pela Engenharia está bloqueado.');
        }
    }
}

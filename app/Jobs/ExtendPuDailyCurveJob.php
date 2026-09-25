<?php

namespace App\Jobs;

use App\Domain\PuCalculator\Services\PuCurveExtensionService;
use App\Models\Emission;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Extensão diária da curva vigente com o CDI recém-publicado.
 *
 * Usa a mesma trava da geração completa (`GeneratePuDailyCurveJob`): as duas
 * nunca escrevem na mesma emissão ao mesmo tempo. Quando não há versão vigente,
 * ou o passado de uma curva comum mudou, a geração completa é enfileirada depois
 * que a trava é liberada.
 */
class ExtendPuDailyCurveJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(
        public readonly int $emissionId,
    ) {}

    public function handle(PuCurveExtensionService $extensions): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $lock = Cache::lock(sprintf('pu_curve_generation_%d_lock', $this->emissionId), 1800);

        if (! $lock->get()) {
            return;
        }

        try {
            $result = $extensions->extend(Emission::query()->findOrFail($this->emissionId));
        } finally {
            $lock->release();
        }

        Log::info('ExtendPuDailyCurveJob finished', [
            'emission_id' => $this->emissionId,
            'action' => $result->action,
            'appended_rows' => $result->appendedRows,
            'first_divergent_date' => $result->firstDivergentDate,
        ]);

        if ($result->requiresFullGeneration()) {
            GeneratePuDailyCurveJob::dispatch($this->emissionId, null, false);
        }
    }
}

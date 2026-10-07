<?php

namespace App\Jobs;

use App\Domain\PuCalculator\DTOs\PuCurveExtensionResult;
use App\Domain\PuCalculator\Services\PuCurveExtensionService;
use App\Models\Emission;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Extensão diária com o índice realizado recém-publicado.
 *
 * Duas finalidades, em ordem e independentes:
 *
 *  1. a curva OFICIAL (homologada vigente) avança primeiro -- uma versão mais
 *     nova, gerada, validada ou com erro, nunca a desloca nem a bloqueia;
 *  2. a versão de TRABALHO (não homologada, em revisão) avança depois. Sem curva
 *     oficial, é o comportamento de antes desta separação.
 *
 * Usa a mesma trava da geração completa (`GeneratePuDailyCurveJob`): as duas
 * nunca escrevem na mesma emissão ao mesmo tempo. Quando a versão de trabalho não
 * existe, ou o passado de uma curva comum mudou, a geração completa é enfileirada
 * depois que a trava é liberada -- nunca pela extensão oficial.
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

        $results = [];
        $failure = null;

        try {
            foreach (['official', 'working'] as $purpose) {
                try {
                    $emission = Emission::query()->findOrFail($this->emissionId);
                    $results[$purpose] = $purpose === 'official'
                        ? $extensions->extendOfficial($emission)
                        : $extensions->extendWorking($emission);
                } catch (Throwable $exception) {
                    // A falha de uma finalidade fica gravada na versão pelo serviço e não
                    // impede a outra; o job falha no fim, para aparecer na fila.
                    $failure ??= $exception;
                    Log::error('ExtendPuDailyCurveJob purpose failed', [
                        'emission_id' => $this->emissionId,
                        'purpose' => $purpose,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        } finally {
            $lock->release();
        }

        Log::info('ExtendPuDailyCurveJob finished', [
            'emission_id' => $this->emissionId,
            'results' => array_map(fn (PuCurveExtensionResult $result): array => $result->toArray(), $results),
        ]);

        if (($results['working'] ?? null)?->requiresFullGeneration()) {
            GeneratePuDailyCurveJob::dispatch($this->emissionId, null, false);
        }

        if ($failure instanceof Throwable) {
            throw $failure;
        }
    }
}

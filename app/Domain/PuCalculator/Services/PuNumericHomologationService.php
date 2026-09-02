<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuNumericHomologationPlan;
use App\Domain\PuCalculator\DTOs\PuNumericHomologationResult;
use App\Domain\PuCalculator\DTOs\PuNumericHomologationValidationResult;
use App\Models\Emission;
use App\Models\EmissionPuParameter;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Fase 2B.5.15 — homologação numérica 100% in-memory, read-only e reprodutível.
 *
 * A candidate NÃO é persistida: `EmissionPuDailyCurve` é consumida operacionalmente
 * e consumidores selecionam a última `calculation_version` sem filtrar candidate,
 * então gravar uma curva de homologação obsoletaria versões e contaminaria leitura
 * operacional. Persistência governada de candidate, maker-checker, aprovação e
 * ativação ficam para uma fase própria — sem artefato persistido não existe
 * workflow de revisão a implementar aqui.
 */
final class PuNumericHomologationService
{
    public const ACTION_NOT_READY = 'numeric_homologation_not_ready';

    public const ACTION_STATE_CHANGED = 'numeric_homologation_state_changed';

    public const ACTION_INTERNAL_VALIDATION_FAILED = 'internal_validation_failed';

    /**
     * Candidate gerada em memória + validação interna aprovada, sem nenhum blocker
     * interno. NÃO significa persistida, aprovada, validada externamente nem apta
     * para produção.
     */
    public const ACTION_READY_FOR_REVIEW = 'ready_for_review';

    public function __construct(
        private readonly PuNumericHomologationPlanService $plans,
        private readonly PuCandidateCurveService $candidateCurves,
        private readonly PuNumericHomologationValidationService $validation,
        private readonly PuNumericHomologationFinancialDiffService $financialDiff,
    ) {}

    public function evaluate(Emission $emission, CarbonImmutable $asOf): PuNumericHomologationResult
    {
        $asOf = $asOf->startOfDay();
        $preCalculationPlan = $this->plans->plan($emission, $asOf);

        if (! $preCalculationPlan->canEvaluate) {
            return new PuNumericHomologationResult(
                action: self::ACTION_NOT_READY,
                reason: $preCalculationPlan->reason,
                plan: $preCalculationPlan,
                candidate: null,
                validation: null,
                comparison: null,
            );
        }

        try {
            $candidate = $this->candidateCurves->generate($emission, $preCalculationPlan);
        } catch (Throwable $exception) {
            $failurePlan = $this->plans->plan($emission, $asOf);

            if (! $failurePlan->canEvaluate
                || ! $this->sameInputs($preCalculationPlan->inputFingerprint, $failurePlan->inputFingerprint)) {
                return $this->stateChanged($failurePlan, 'Um pré-requisito desapareceu durante a execução da engine.');
            }

            return new PuNumericHomologationResult(
                action: self::ACTION_INTERNAL_VALIDATION_FAILED,
                reason: 'A engine oficial não concluiu a geração isolada: '.$exception->getMessage(),
                plan: $failurePlan,
                candidate: null,
                validation: new PuNumericHomologationValidationResult(
                    status: 'failed',
                    validationCount: 1,
                    blockingFailures: [[
                        'code' => 'official_engine_execution_failed',
                        'message' => $exception->getMessage(),
                        'context' => [],
                    ]],
                    warnings: [],
                    information: [],
                    rateSamples: [],
                ),
                comparison: null,
            );
        }

        $postCalculationPlan = $this->plans->plan($emission, $asOf);

        if (! $postCalculationPlan->canEvaluate
            || ! $this->sameInputs($preCalculationPlan->inputFingerprint, $postCalculationPlan->inputFingerprint)) {
            return $this->stateChanged($postCalculationPlan, 'Os inputs mudaram entre a geração e a validação; a curva em memória foi descartada.');
        }

        $parameter = EmissionPuParameter::query()
            ->whereBelongsTo($emission)
            ->whereKey($postCalculationPlan->parameterId)
            ->first();

        if (! $parameter instanceof EmissionPuParameter) {
            return $this->stateChanged($postCalculationPlan, 'O parâmetro persistido deixou de existir antes da validação.');
        }

        $validation = $this->validation->validate($candidate, $postCalculationPlan, $parameter);

        if (! $validation->passed()) {
            return new PuNumericHomologationResult(
                action: self::ACTION_INTERNAL_VALIDATION_FAILED,
                reason: 'A candidate foi calculada, mas possui anomalias internas bloqueantes.',
                plan: $postCalculationPlan,
                candidate: $candidate,
                validation: $validation,
                comparison: null,
            );
        }

        $comparison = $this->financialDiff->compare(
            $candidate,
            $postCalculationPlan->externalReference,
        );
        $validation = new PuNumericHomologationValidationResult(
            status: $validation->status,
            validationCount: $validation->validationCount,
            blockingFailures: $validation->blockingFailures,
            warnings: [
                ...$validation->warnings,
                ...($comparison->status === 'unavailable' ? [[
                    'code' => 'external_benchmark_unavailable',
                    'message' => $comparison->reason,
                    'context' => ['blocks_internal_numeric_homologation' => false],
                ]] : []),
            ],
            information: $validation->information,
            rateSamples: $validation->rateSamples,
        );

        return new PuNumericHomologationResult(
            action: self::ACTION_READY_FOR_REVIEW,
            reason: 'A engine oficial gerou uma candidate determinística e todas as validações internas passaram; benchmark externo permanece separado.',
            plan: $postCalculationPlan,
            candidate: $candidate,
            validation: $validation,
            comparison: $comparison,
        );
    }

    private function sameInputs(?string $expected, ?string $actual): bool
    {
        return is_string($expected)
            && is_string($actual)
            && hash_equals($expected, $actual);
    }

    private function stateChanged(
        PuNumericHomologationPlan $plan,
        string $reason,
    ): PuNumericHomologationResult {
        return new PuNumericHomologationResult(
            action: self::ACTION_STATE_CHANGED,
            reason: $reason,
            plan: $plan,
            candidate: null,
            validation: null,
            comparison: null,
        );
    }
}

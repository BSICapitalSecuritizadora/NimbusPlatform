<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuBaselineReadinessReport;
use App\Domain\PuCalculator\DTOs\PuNumericHomologationPlan;
use App\Domain\PuCalculator\Enums\PuBaselineReadinessStatus;
use App\Models\Emission;
use App\Models\EmissionPuBaselineEvidence;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuDailyCurve;
use App\Models\EmissionPuParameter;
use Carbon\CarbonImmutable;

class PuNumericHomologationPlanService
{
    public const ACTION_NOT_READY = 'numeric_homologation_not_ready';

    public const ACTION_READY_TO_EVALUATE = 'ready_to_evaluate';

    public const REQUIRED_READINESS = 'ready_for_numeric_homologation';

    public function __construct(
        private readonly PuBaselineReadinessService $readiness,
        private readonly PuNumericPreparationPlanService $preparationPlans,
        private readonly PuNumericHomologationFingerprintService $fingerprints,
    ) {}

    public function plan(Emission $emission, CarbonImmutable $asOf): PuNumericHomologationPlan
    {
        $asOf = $asOf->startOfDay();
        $freshEmission = $emission->fresh() ?? $emission;
        $preparation = $this->preparationPlans->plan($freshEmission, $asOf);
        $readiness = $this->readiness->evaluate($freshEmission, $asOf);
        $parameter = EmissionPuParameter::query()->whereBelongsTo($freshEmission)->first();
        $readinessAllowsEvaluation = in_array($readiness->status, [
            PuBaselineReadinessStatus::ReadyForNumericHomologation,
            PuBaselineReadinessStatus::ExternallyValidated,
        ], true);
        $canEvaluate = $parameter instanceof EmissionPuParameter
            && $readinessAllowsEvaluation
            && $preparation->state === PuNumericPreparationPlanService::STATE_READY
            && $preparation->missingRateDates === []
            && $preparation->conflictingRates === []
            && $preparation->missingEvents === []
            && $preparation->conflictingEvents === [];
        $fingerprint = null;
        $inputPayload = [];

        if ($canEvaluate) {
            $input = $this->fingerprints->input($parameter, $preparation, $readiness, $asOf);
            $fingerprint = $input['fingerprint'];
            $inputPayload = $input['payload'];
        }

        return new PuNumericHomologationPlan(
            emissionId: $freshEmission->id,
            asOf: $asOf->toDateString(),
            readiness: $readiness->status->value,
            requiredReadiness: self::REQUIRED_READINESS,
            action: $canEvaluate ? self::ACTION_READY_TO_EVALUATE : self::ACTION_NOT_READY,
            reason: $canEvaluate
                ? 'Os parâmetros e todos os snapshots/eventos exatos estão prontos para avaliação isolada.'
                : $this->notReadyReason($parameter, $readiness, $preparation->reason),
            canEvaluate: $canEvaluate,
            parameterId: $parameter?->id,
            parameterSnapshot: $parameter instanceof EmissionPuParameter
                ? $this->fingerprints->parameterSnapshot($parameter)
                : [],
            curveStartDate: $parameter?->curve_start_date?->toDateString()
                ?? $preparation->curveStartDate,
            curveEndDate: $parameter?->curve_end_date?->toDateString(),
            homologationEndDate: $canEvaluate ? $preparation->homologationEndDate : null,
            calendarWindow: $preparation->calendarWindow,
            rateWindow: $preparation->rateWindow,
            requiredRateDates: $canEvaluate ? $preparation->requiredRateDates : [],
            rates: $canEvaluate ? $preparation->presentRates : [],
            events: $canEvaluate ? $preparation->presentEvents : [],
            inputFingerprint: $fingerprint,
            inputPayload: $inputPayload,
            existingCurves: $this->existingCurves($freshEmission),
            externalReference: $this->externalReference($freshEmission, $readiness),
        );
    }

    private function notReadyReason(
        ?EmissionPuParameter $parameter,
        PuBaselineReadinessReport $readiness,
        string $preparationReason,
    ): string {
        if (! $parameter instanceof EmissionPuParameter) {
            return 'EmissionPuParameter ausente; a engine candidata não será chamada.';
        }

        if (! in_array($readiness->status, [
            PuBaselineReadinessStatus::ReadyForNumericHomologation,
            PuBaselineReadinessStatus::ExternallyValidated,
        ], true)) {
            return sprintf(
                'Readiness atual [%s] não permite homologação numérica: %s',
                $readiness->status->value,
                $preparationReason,
            );
        }

        return $preparationReason;
    }

    /** @return array<string, int|array<string, int>> */
    private function existingCurves(Emission $emission): array
    {
        return [
            'version_count' => EmissionPuCurveVersion::query()->whereBelongsTo($emission)->count(),
            'row_count' => EmissionPuDailyCurve::query()->whereBelongsTo($emission)->count(),
            'versions_by_status' => EmissionPuCurveVersion::query()
                ->whereBelongsTo($emission)
                ->selectRaw('status, COUNT(*) as aggregate')
                ->groupBy('status')
                ->pluck('aggregate', 'status')
                ->map(fn (mixed $count): int => (int) $count)
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function externalReference(
        Emission $emission,
        PuBaselineReadinessReport $readiness,
    ): array {
        $requirement = $readiness->requirement('external_independent_validation');
        $evidence = EmissionPuBaselineEvidence::query()
            ->with('document:id,title')
            ->whereBelongsTo($emission)
            ->where('evidence_type', 'external_pu_reference')
            ->latest('id')
            ->first();

        return [
            'availability' => $evidence instanceof EmissionPuBaselineEvidence
                ? 'governance_evidence_only'
                : 'unavailable',
            'machine_readable_curve_available' => false,
            'reason' => $evidence instanceof EmissionPuBaselineEvidence
                ? 'A evidência não possui linhas de PU estruturadas e vinculadas para comparação automática.'
                : 'Nenhum benchmark externo aprovado foi vinculado à emissão.',
            'requirement_status' => $requirement->status->value,
            'source' => $evidence?->document?->title,
            'reference' => $evidence?->reference,
            'document_id' => $evidence?->document_id,
            'status' => $evidence?->status->value,
            'confidence' => $evidence?->confidence,
        ];
    }
}

<?php

namespace App\Jobs;

use App\Actions\Emissions\ValidatePuDailyCurve;
use App\Domain\PuCalculator\Enums\PuValidationMode;
use App\Domain\PuCalculator\Enums\PuValidationStatus;
use App\Domain\PuCalculator\Exceptions\PuCurveGovernanceException;
use App\Domain\PuCalculator\Services\PuCurveVersionService;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ValidatePuCurveJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(
        public readonly int $emissionId,
        public readonly string $spreadsheetPath,
        public readonly ?string $calculationVersion = null,
        public readonly string $mode = 'raw-scale',
        public readonly ?string $rangeStart = null,
        public readonly ?string $rangeEnd = null,
        public readonly ?int $requestedByUserId = null,
    ) {}

    public function handle(
        ValidatePuDailyCurve $validatePuDailyCurve,
        PuCurveVersionService $versionService,
    ): void {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        try {
            $emission = Emission::findOrFail($this->emissionId);

            // A validação age sobre a versão escolhida por quem a pediu -- nunca
            // sobre "a mais recente" no momento em que o job roda. O status é
            // conferido antes de comparar e relido sob trava ao gravar o resultado.
            $version = $this->targetVersion($emission, $versionService);

            $report = $validatePuDailyCurve->handle(
                $emission,
                $this->spreadsheetPath,
                $version->calculation_version,
                PuValidationMode::from($this->mode),
                $this->rangeStart !== null ? CarbonImmutable::parse($this->rangeStart) : null,
                $this->rangeEnd !== null ? CarbonImmutable::parse($this->rangeEnd) : null,
                $this->requestedByUserId,
            );

            $approved = $report->status === PuValidationStatus::Approved;

            $versionService->markValidated($version, $approved, [
                'mode' => $report->mode->value,
                'status' => $report->status->value,
                'total_rows_compared' => $report->totalRowsCompared,
                'total_divergences' => $report->totalDivergences,
                'total_field_divergences' => $report->totalFieldDivergences,
                'first_divergence_date' => $report->firstDivergenceDate?->toDateString(),
                'largest_pu_difference' => $report->largestPuDifference,
                'largest_total_value_difference' => $report->largestTotalValueDifference,
                'largest_payment_difference' => $report->largestPaymentDifference,
            ], $this->requestedByUserId);

            Cache::put($this->cacheKey(), [
                'status' => 'completed',
                'validation_status' => $report->status->value,
                'calculation_version' => $version->calculation_version,
                'total_rows_compared' => $report->totalRowsCompared,
                'total_divergences' => $report->totalDivergences,
                'total_field_divergences' => $report->totalFieldDivergences,
            ], 1800);
        } catch (PuCurveGovernanceException $exception) {
            // Recusa de governança (versão inexistente, ambígua ou num status que
            // não aceita validação): não é falha de infraestrutura, e nada foi
            // gravado na versão.
            Cache::put($this->cacheKey(), [
                'status' => 'failed',
                'error' => $exception->getMessage(),
            ], 1800);
        } catch (\Throwable $exception) {
            Log::error('ValidatePuCurveJob failed', [
                'emission_id' => $this->emissionId,
                'error' => $exception->getMessage(),
            ]);

            Cache::put($this->cacheKey(), [
                'status' => 'failed',
                'error' => $exception->getMessage(),
            ], 1800);

            throw $exception;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Cache::put($this->cacheKey(), [
            'status' => 'failed',
            'error' => $exception->getMessage(),
        ], 1800);
    }

    private function targetVersion(Emission $emission, PuCurveVersionService $versionService): EmissionPuCurveVersion
    {
        if (blank($this->calculationVersion)) {
            throw new PuCurveGovernanceException('Informe a versão da curva a validar.');
        }

        $version = $versionService->findByCalculationVersion($emission, $this->calculationVersion);

        if (! $version instanceof EmissionPuCurveVersion) {
            throw new PuCurveGovernanceException(sprintf('A versão %s não existe nesta emissão.', $this->calculationVersion));
        }

        if (! $version->status->acceptsValidationResult()) {
            throw new PuCurveGovernanceException(sprintf(
                'A versão %s está %s e não aceita validação.',
                $version->calculation_version,
                mb_strtolower($version->status->label()),
            ));
        }

        return $version;
    }

    private function cacheKey(): string
    {
        return sprintf('pu_curve_validation_%d_status', $this->emissionId);
    }
}

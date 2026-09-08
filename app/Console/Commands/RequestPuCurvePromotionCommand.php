<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\PuCalculator\DTOs\PuCurvePromotionPlan;
use App\Domain\PuCalculator\DTOs\PuCurvePromotionResult;
use App\Domain\PuCalculator\Services\PuCurvePromotionRequestService;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Comando fino: resolve ids/actor/opções e delega ao service. Nenhuma regra de
 * promoção vive aqui.
 */
class RequestPuCurvePromotionCommand extends Command
{
    protected $signature = 'pu:curve-candidate:request-promotion
                            {version : EmissionPuCurveVersion id of the externally validated candidate}
                            {--actor= : Explicit authorized promotion requester, required for --write}
                            {--dry-run : Inspect without mutating (default)}
                            {--write : Record the promotion request after locked integrity checks}';

    protected $description = 'Phase 2B.5.18 — Inspect or record a controlled operational promotion request';

    public function handle(PuCurvePromotionRequestService $requests): int
    {
        $write = (bool) $this->option('write');

        if ((bool) $this->option('dry-run') && $write) {
            $this->components->warn('Both --dry-run and --write were passed; running read-only only.');
            $write = false;
        }

        try {
            $candidate = EmissionPuCurveVersion::query()->whereKey($this->versionId())->first();

            if (! $candidate instanceof EmissionPuCurveVersion) {
                throw new RuntimeException(sprintf('Curve version #%d does not exist.', $this->versionId()));
            }

            $emission = $candidate->emission()->first();

            if (! $emission instanceof Emission) {
                throw new RuntimeException(sprintf('Curve version #%d has no emission.', $candidate->id));
            }

            $actor = filled($this->option('actor')) ? (string) $this->option('actor') : null;
            $result = $write
                ? $requests->write($emission, $candidate, $actor)
                : $requests->inspect($emission, $candidate, $actor);
            $this->renderResult($result, $write);

            return in_array($result->action, [
                PuCurvePromotionPlan::ACTION_READY_TO_REQUEST,
                PuCurvePromotionRequestService::ACTION_REQUESTED,
                PuCurvePromotionPlan::ACTION_ALREADY_REQUESTED,
            ], true) ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function versionId(): int
    {
        $value = trim((string) $this->argument('version'));

        if (! ctype_digit($value) || (int) $value < 1) {
            throw new RuntimeException('version must be a positive id.');
        }

        return (int) $value;
    }

    private function renderResult(PuCurvePromotionResult $result, bool $write): void
    {
        $plan = $result->plan;

        $this->components->info('Phase 2B.5.18 — Operational Promotion Request '.($write ? 'Write' : 'Dry-run'));
        $this->line('Emission: '.($plan === null ? 'not available' : '#'.$plan->emissionId));
        $this->line('Candidate: '.($result->candidateVersionId === null ? 'not available' : '#'.$result->candidateVersionId));
        $this->line('Calculation version: '.($result->calculationVersion ?? 'not available'));
        $this->line('Candidate checksum: '.($plan?->candidateChecksum ?? 'not available'));
        $this->line('External validation: '.($result->externalValidationId === null ? 'not available' : '#'.$result->externalValidationId));
        $this->line('Comparison SHA-256: '.($plan?->comparisonChecksum ?? 'not available'));
        $this->line('Benchmark SHA-256: '.($plan?->benchmarkChecksum ?? 'not available'));
        $this->line('Current operational version: '.($result->previousOperationalVersionId === null
            ? 'none'
            : '#'.$result->previousOperationalVersionId));
        $this->line('Promotion: '.($result->promotionId === null ? 'none' : '#'.$result->promotionId));
        $this->line('Promotion status: '.($result->promotionStatus ?? 'not available'));
        $this->line('Requester: '.($result->requesterId === null ? 'not resolved' : '#'.$result->requesterId));
        $this->line('Action: '.$result->action);
        $this->line('Reason: '.$result->reason);
        $this->line('Writes: '.$result->writes);
        $this->line('No operational switch performed: the candidate stays candidate and the operational curve is untouched.');
    }
}

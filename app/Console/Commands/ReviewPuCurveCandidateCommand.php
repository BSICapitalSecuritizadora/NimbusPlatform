<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\PuCalculator\DTOs\PuCandidateCurveReviewResult;
use App\Domain\PuCalculator\Enums\PuCandidateReviewDecision;
use App\Domain\PuCalculator\Services\PuCandidateCurveReviewService;
use App\Models\EmissionPuCurveVersion;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Decisão maker-checker sobre uma candidate já persistida. Aprovar aqui significa
 * apenas "validada internamente": a curva continua candidate, continua invisível
 * para toda leitura operacional, e nenhuma promoção acontece nesta fase.
 */
class ReviewPuCurveCandidateCommand extends Command
{
    protected $signature = 'pu:curve-candidate:review
                            {version : Candidate EmissionPuCurveVersion id}
                            {--approve : Record an internal approval decision}
                            {--reject : Record an internal rejection decision}
                            {--reason= : Decision reason, mandatory for --reject}
                            {--reviewer= : Explicit authorized checker (user id or email), required for --write}
                            {--dry-run : Preflight the decision without mutating (default)}
                            {--write : Record the final decision after re-checking every guard under lock}';

    protected $description = 'Phase 2B.5.16 — Preflight or record the maker-checker review of a persisted PU candidate curve';

    public function handle(PuCandidateCurveReviewService $review): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $write = (bool) $this->option('write');

        if (! $dryRun && ! $write) {
            $dryRun = true;
        }

        if ($dryRun && $write) {
            $this->components->warn('Both --dry-run and --write were passed; running in dry-run mode only.');
            $write = false;
        }

        try {
            $decision = $this->decision();
            $version = EmissionPuCurveVersion::query()->whereKey($this->versionId())->first();
            $reason = filled($this->option('reason')) ? (string) $this->option('reason') : null;
            $reviewerIdentifier = filled($this->option('reviewer')) ? (string) $this->option('reviewer') : null;
            $result = $write
                ? $review->write($version, $decision, $reviewerIdentifier, $reason)
                : $review->inspect($version, $decision, $reason);

            $this->renderResult($result, $write);

            if (! $write) {
                return $result->action === PuCandidateCurveReviewService::ACTION_READY
                    ? self::SUCCESS
                    : self::FAILURE;
            }

            return in_array($result->action, [
                PuCandidateCurveReviewService::ACTION_APPROVED,
                PuCandidateCurveReviewService::ACTION_REJECTED,
                PuCandidateCurveReviewService::ACTION_ALREADY_APPROVED,
                PuCandidateCurveReviewService::ACTION_ALREADY_REJECTED,
            ], true) ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            if ($this->output->isVerbose()) {
                $this->line($exception->getTraceAsString());
            }

            return self::FAILURE;
        }
    }

    private function decision(): PuCandidateReviewDecision
    {
        $approve = (bool) $this->option('approve');
        $reject = (bool) $this->option('reject');

        if ($approve && $reject) {
            throw new RuntimeException('--approve and --reject are mutually exclusive.');
        }

        if (! $approve && ! $reject) {
            throw new RuntimeException('Exactly one of --approve or --reject is required.');
        }

        return $approve ? PuCandidateReviewDecision::Approve : PuCandidateReviewDecision::Reject;
    }

    private function versionId(): int
    {
        $requested = trim((string) $this->argument('version'));

        if (! ctype_digit($requested) || (int) $requested < 1) {
            throw new RuntimeException('The version argument must be a positive EmissionPuCurveVersion id.');
        }

        return (int) $requested;
    }

    private function renderResult(PuCandidateCurveReviewResult $result, bool $write): void
    {
        $this->components->info(sprintf(
            'Phase 2B.5.16 — Candidate PU Curve Review (%s)',
            $write ? 'write' : 'dry-run',
        ));
        $this->line('Candidate version: '.($result->candidateVersionId === null
            ? 'not found'
            : '#'.$result->candidateVersionId));
        $this->line('Calculation version: '.($result->calculationVersion ?? 'not available'));
        $this->line('Curve role: '.($result->curveRole ?? 'not available'));
        $this->line('Internal validation: '.($result->internalValidationStatus ?? 'not available'));
        $this->line('External validation: '.($result->externalValidationStatus ?? 'not available'));
        $this->line('Review status: '.($result->reviewStatus ?? 'not available'));
        $this->line('Maker: '.($result->makerId === null ? 'not recorded' : '#'.$result->makerId));
        $this->line('Reviewer: '.($result->reviewerId === null ? 'not resolved' : '#'.$result->reviewerId));
        $this->line('Decision: '.($result->decision ?? 'none'));
        $this->newLine();
        $this->line('Action: '.$result->action);
        $this->line('Reason: '.$result->reason);
        $this->line('Writes: '.$result->writes);
        $this->newLine();
        $this->line('No candidate row modified.');
        $this->line('No operational curve modified.');
        $this->line('No PuHistory or Payment generated.');
        $this->line('An internally approved candidate is still a candidate: no promotion happens here.');
    }
}

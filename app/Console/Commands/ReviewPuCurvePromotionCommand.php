<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\PuCalculator\DTOs\PuCurvePromotionResult;
use App\Domain\PuCalculator\Enums\PuCurvePromotionDecision;
use App\Domain\PuCalculator\Services\PuCurvePromotionReviewService;
use App\Models\EmissionPuCurvePromotion;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class ReviewPuCurvePromotionCommand extends Command
{
    protected $signature = 'pu:curve-promotion:review
                            {promotion : EmissionPuCurvePromotion id}
                            {--approve : Approve the operational promotion}
                            {--reject : Reject the operational promotion}
                            {--reason= : Decision reason, mandatory for --reject}
                            {--reviewer= : Explicit authorized independent reviewer, required for --write}
                            {--dry-run : Inspect without mutating (default)}
                            {--write : Record the final review decision after locked integrity checks}';

    protected $description = 'Phase 2B.5.18 — Inspect or record an independent operational promotion decision';

    public function handle(PuCurvePromotionReviewService $reviews): int
    {
        $write = (bool) $this->option('write');

        if ((bool) $this->option('dry-run') && $write) {
            $this->components->warn('Both --dry-run and --write were passed; running read-only only.');
            $write = false;
        }

        try {
            $decision = $this->decision();
            $promotion = EmissionPuCurvePromotion::query()->whereKey($this->promotionId())->first();
            $reason = filled($this->option('reason')) ? (string) $this->option('reason') : null;
            $reviewer = filled($this->option('reviewer')) ? (string) $this->option('reviewer') : null;
            $result = $write
                ? $reviews->write($promotion, $decision, $reviewer, $reason)
                : $reviews->inspect($promotion, $decision, $reason, $reviewer);
            $this->renderResult($result, $write);

            return in_array($result->action, [
                PuCurvePromotionReviewService::ACTION_READY,
                PuCurvePromotionReviewService::ACTION_APPROVED,
                PuCurvePromotionReviewService::ACTION_REJECTED,
                PuCurvePromotionReviewService::ACTION_ALREADY_APPROVED,
                PuCurvePromotionReviewService::ACTION_ALREADY_REJECTED,
            ], true) ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function decision(): PuCurvePromotionDecision
    {
        $approve = (bool) $this->option('approve');
        $reject = (bool) $this->option('reject');

        if ($approve === $reject) {
            throw new RuntimeException('Exactly one of --approve or --reject is required.');
        }

        return $approve ? PuCurvePromotionDecision::Approve : PuCurvePromotionDecision::Reject;
    }

    private function promotionId(): int
    {
        $value = trim((string) $this->argument('promotion'));

        if (! ctype_digit($value) || (int) $value < 1) {
            throw new RuntimeException('promotion must be a positive id.');
        }

        return (int) $value;
    }

    private function renderResult(PuCurvePromotionResult $result, bool $write): void
    {
        $this->components->info('Phase 2B.5.18 — Operational Promotion Review '.($write ? 'Write' : 'Dry-run'));
        $this->line('Promotion: '.($result->promotionId === null ? 'not available' : '#'.$result->promotionId));
        $this->line('Candidate: '.($result->candidateVersionId === null ? 'not available' : '#'.$result->candidateVersionId));
        $this->line('Calculation version: '.($result->calculationVersion ?? 'not available'));
        $this->line('External validation: '.($result->externalValidationId === null ? 'not available' : '#'.$result->externalValidationId));
        $this->line('Pre-promotion operational version: '.($result->previousOperationalVersionId === null
            ? 'none'
            : '#'.$result->previousOperationalVersionId));
        $this->line('Requester: '.($result->requesterId === null ? 'not available' : '#'.$result->requesterId));
        $this->line('Reviewer: '.($result->reviewerId === null ? 'not resolved' : '#'.$result->reviewerId));
        $this->line('Decision: '.($result->decision ?? 'not available'));
        $this->line('Promotion status: '.($result->promotionStatus ?? 'not available'));
        $this->line('Action: '.$result->action);
        $this->line('Reason: '.$result->reason);
        $this->line('Writes: '.$result->writes);
        $this->line('Approval never switches the curve: an explicit execution is still required.');
    }
}

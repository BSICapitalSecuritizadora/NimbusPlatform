<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\PuCalculator\DTOs\PuCurvePromotionResult;
use App\Domain\PuCalculator\Services\PuCurveOperationalPromotionService;
use App\Models\EmissionPuCurvePromotion;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class ExecutePuCurvePromotionCommand extends Command
{
    protected $signature = 'pu:curve-promotion:execute
                            {promotion : EmissionPuCurvePromotion id}
                            {--actor= : Explicit authorized promotion executor, required for --write}
                            {--dry-run : Preflight without mutating (default)}
                            {--write : Execute the atomic operational switch after revalidating everything}';

    protected $description = 'Phase 2B.5.18 — Preflight or execute the atomic operational curve promotion';

    public function handle(PuCurveOperationalPromotionService $promotions): int
    {
        $write = (bool) $this->option('write');

        if ((bool) $this->option('dry-run') && $write) {
            $this->components->warn('Both --dry-run and --write were passed; running read-only only.');
            $write = false;
        }

        try {
            $promotion = EmissionPuCurvePromotion::query()->whereKey($this->promotionId())->first();
            $actor = filled($this->option('actor')) ? (string) $this->option('actor') : null;
            $result = $write
                ? $promotions->write($promotion, $actor)
                : $promotions->inspect($promotion, $actor);
            $this->renderResult($result, $write);

            return in_array($result->action, [
                PuCurveOperationalPromotionService::ACTION_READY,
                PuCurveOperationalPromotionService::ACTION_PROMOTED,
                PuCurveOperationalPromotionService::ACTION_ALREADY_EXECUTED,
            ], true) ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
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
        $this->components->info('Phase 2B.5.18 — Operational Promotion Execution '.($write ? 'Write' : 'Preflight'));
        $this->line('Promotion: '.($result->promotionId === null ? 'not available' : '#'.$result->promotionId));
        $this->line('Promotion status: '.($result->promotionStatus ?? 'not available'));
        $this->line('Candidate: '.($result->candidateVersionId === null ? 'not available' : '#'.$result->candidateVersionId));
        $this->line('Calculation version: '.($result->calculationVersion ?? 'not available'));
        $this->line('Curve role: '.($result->curveRole ?? 'unchanged'));
        $this->line('Previous operational version: '.($result->previousOperationalVersionId === null
            ? 'none'
            : '#'.$result->previousOperationalVersionId));
        $this->line('New operational version: '.($result->newOperationalVersionId === null
            ? 'not promoted'
            : '#'.$result->newOperationalVersionId));
        $this->line('External validation: '.($result->externalValidationId === null ? 'not available' : '#'.$result->externalValidationId));
        $this->line('Requester: '.($result->requesterId === null ? 'not available' : '#'.$result->requesterId));
        $this->line('Reviewer: '.($result->reviewerId === null ? 'not available' : '#'.$result->reviewerId));
        $this->line('Executor: '.($result->executorId === null ? 'not resolved' : '#'.$result->executorId));
        $this->line('Action: '.$result->action);
        $this->line('Reason: '.$result->reason);
        $this->line('Writes: '.$result->writes);
        $this->line('No curve was recalculated, no daily row was rewritten and no external artifact was mutated.');
    }
}

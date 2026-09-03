<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\PuCalculator\DTOs\PuCandidateExternalValidationResult;
use App\Domain\PuCalculator\Enums\PuExternalValidationDecision;
use App\Domain\PuCalculator\Services\PuCandidateExternalValidationService;
use App\Models\EmissionPuExternalValidation;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class ReviewPuCandidateExternalValidationCommand extends Command
{
    protected $signature = 'pu:curve-candidate:review-external-validation
                            {validation : EmissionPuExternalValidation id}
                            {--validate : Record independent external validation}
                            {--reject : Record independent external rejection}
                            {--reason= : Decision reason, mandatory for --reject}
                            {--reviewer= : Explicit authorized independent reviewer, required for --write}
                            {--dry-run : Inspect without mutating (default)}
                            {--write : Record the final decision after locked integrity checks}';

    protected $description = 'Phase 2B.5.17 — Inspect or record an independent external PU validation decision';

    public function handle(PuCandidateExternalValidationService $reviews): int
    {
        $write = (bool) $this->option('write');
        $dryRun = (bool) $this->option('dry-run') || ! $write;

        if ($dryRun && $write) {
            $this->components->warn('Both --dry-run and --write were passed; running read-only only.');
            $write = false;
        }

        try {
            $decision = $this->decision();
            $validation = EmissionPuExternalValidation::query()
                ->whereKey($this->validationId())
                ->first();
            $reason = filled($this->option('reason')) ? (string) $this->option('reason') : null;
            $reviewer = filled($this->option('reviewer')) ? (string) $this->option('reviewer') : null;
            $result = $write
                ? $reviews->write($validation, $decision, $reviewer, $reason)
                : $reviews->inspect($validation, $decision, $reason, $reviewer);
            $this->renderResult($result, $write);

            return in_array($result->action, [
                PuCandidateExternalValidationService::ACTION_READY,
                PuCandidateExternalValidationService::ACTION_VALIDATED,
                PuCandidateExternalValidationService::ACTION_REJECTED,
                PuCandidateExternalValidationService::ACTION_ALREADY_VALIDATED,
                PuCandidateExternalValidationService::ACTION_ALREADY_REJECTED,
            ], true) ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function decision(): PuExternalValidationDecision
    {
        $validate = (bool) $this->option('validate');
        $reject = (bool) $this->option('reject');

        if ($validate === $reject) {
            throw new RuntimeException('Exactly one of --validate or --reject is required.');
        }

        return $validate ? PuExternalValidationDecision::Validate : PuExternalValidationDecision::Reject;
    }

    private function validationId(): int
    {
        $value = trim((string) $this->argument('validation'));

        if (! ctype_digit($value) || (int) $value < 1) {
            throw new RuntimeException('validation must be a positive id.');
        }

        return (int) $value;
    }

    private function renderResult(PuCandidateExternalValidationResult $result, bool $write): void
    {
        $this->components->info('Phase 2B.5.17 — External PU Validation Review '.($write ? 'Write' : 'Dry-run'));
        $this->line('Validation dossier: '.($result->externalValidationId === null ? 'not available' : '#'.$result->externalValidationId));
        $this->line('Candidate: '.($result->candidateVersionId === null ? 'not available' : '#'.$result->candidateVersionId));
        $this->line('Benchmark: '.($result->benchmarkId === null ? 'not available' : '#'.$result->benchmarkId));
        $this->line('Decision: '.($result->decision ?? 'not available'));
        $this->line('External validation status: '.($result->externalValidationStatus ?? 'not available'));
        $this->line('Reviewer: '.($result->reviewerId === null ? 'not resolved' : '#'.$result->reviewerId));
        $this->line('Action: '.$result->action);
        $this->line('Reason: '.$result->reason);
        $this->line('Writes: '.$result->writes);
        $this->line('Candidate role, internal review and operational curves remain unchanged.');
    }
}

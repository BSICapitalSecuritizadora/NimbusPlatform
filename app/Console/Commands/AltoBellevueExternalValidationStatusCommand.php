<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\PuCalculator\Enums\PuCurveReviewStatus;
use App\Domain\PuCalculator\Services\AltoBellevuePrerequisiteCloser;
use App\Domain\PuCalculator\Services\PuCandidateExternalValidationEligibilityService;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class AltoBellevueExternalValidationStatusCommand extends Command
{
    protected $signature = 'pu:alto-bellevue:external-validation-status';

    protected $description = 'Phase 2B.5.17 — Read-only Alto Bellevue external-validation readiness';

    public function handle(PuCandidateExternalValidationEligibilityService $eligibility): int
    {
        try {
            $emission = $this->resolveEmission();
            $candidate = EmissionPuCurveVersion::query()
                ->whereBelongsTo($emission)
                ->candidate()
                ->where('review_status', PuCurveReviewStatus::Approved->value)
                ->latest('id')
                ->first();
            $result = $eligibility->inspect($candidate);

            $this->components->info('Phase 2B.5.17 — Alto Bellevue External Validation Status');
            $this->line(sprintf('Emission: #%d %s', $emission->id, $emission->name));
            $this->line('Candidate: '.($candidate instanceof EmissionPuCurveVersion ? '#'.$candidate->id : 'none'));
            $this->line('External validation: '.($result['ready'] ? 'ready' : 'not ready'));
            $this->line('Action: '.$result['action']);
            $this->line('Reason: '.$result['reason']);
            $this->line('Writes: 0');
            $this->line('No benchmark parsed, no comparison generated and no operational effect occurred.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function resolveEmission(): Emission
    {
        $emissions = Emission::query()
            ->where('if_code', AltoBellevuePrerequisiteCloser::IF_CODE)
            ->where('isin_code', AltoBellevuePrerequisiteCloser::ISIN_CODE)
            ->get();

        if ($emissions->count() !== 1) {
            throw new RuntimeException(sprintf(
                'Expected single Alto Bellevue emission by IF %s ISIN %s; found %d.',
                AltoBellevuePrerequisiteCloser::IF_CODE,
                AltoBellevuePrerequisiteCloser::ISIN_CODE,
                $emissions->count(),
            ));
        }

        return $emissions->sole();
    }
}

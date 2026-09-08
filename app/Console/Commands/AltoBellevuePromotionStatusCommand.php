<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\PuCalculator\DTOs\PuCurvePromotionPlan;
use App\Domain\PuCalculator\Services\AltoBellevuePrerequisiteCloser;
use App\Domain\PuCalculator\Services\PuCurvePromotionPlanService;
use App\Models\Emission;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Diagnóstico read-only da promoção operacional do Alto Bellevue. Zero escritas
 * por construção: o comando não tem flag de write.
 */
class AltoBellevuePromotionStatusCommand extends Command
{
    protected $signature = 'pu:alto-bellevue:promotion-status';

    protected $description = 'Phase 2B.5.18 — Read-only Alto Bellevue operational promotion readiness';

    public function handle(PuCurvePromotionPlanService $plans): int
    {
        try {
            $emission = $this->resolveEmission();
            $plan = $plans->plan($emission);

            $this->components->info('Phase 2B.5.18 — Alto Bellevue Operational Promotion Status');
            $this->line(sprintf('Emission: #%d %s', $emission->id, $emission->name));
            $this->line('Candidate: '.($plan->candidateVersionId === null ? 'none' : '#'.$plan->candidateVersionId));
            $this->line('External validation: '.($plan->externalValidationId === null
                ? 'not available'
                : '#'.$plan->externalValidationId));
            $this->line('Current operational version: '.($plan->currentOperationalVersionId === null
                ? 'none'
                : '#'.$plan->currentOperationalVersionId));
            $this->line('Promotion: '.($plan->readyToRequest() ? 'ready to request' : 'not ready'));
            $this->line('Promotion dossier: '.($plan->promotionId === null ? 'none' : '#'.$plan->promotionId));
            $this->line('Action: '.$plan->action);
            $this->line('Reason: '.$plan->reason);
            $this->line('Writes: 0');

            foreach ([
                'No promotion request created.',
                'No promotion review recorded.',
                'No operational switch executed.',
                'No operational curve modified.',
                'No financial curve row modified.',
                'No BCB fetch performed.',
                'No financial prerequisite modified.',
                'No PuHistory or Payment generated.',
            ] as $guarantee) {
                $this->line($guarantee);
            }

            return $plan->action === PuCurvePromotionPlan::ACTION_INTEGRITY_FAILURE
                ? self::FAILURE
                : self::SUCCESS;
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

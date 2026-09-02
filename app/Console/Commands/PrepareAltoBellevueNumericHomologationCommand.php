<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\PuCalculator\DTOs\PuNumericPreparationPlan;
use App\Domain\PuCalculator\DTOs\PuNumericPreparationResult;
use App\Domain\PuCalculator\Services\AltoBellevuePrerequisiteCloser;
use App\Domain\PuCalculator\Services\PuEventMaterializationService;
use App\Domain\PuCalculator\Services\PuIndexSnapshotPreparationService;
use App\Domain\PuCalculator\Services\PuNumericPreparationPlanService;
use App\Models\Emission;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class PrepareAltoBellevueNumericHomologationCommand extends Command
{
    protected $signature = 'pu:alto-bellevue:prepare-numeric-homologation
                            {--dry-run : Build the deterministic read-only plan (default)}
                            {--write-rates : Fetch, validate and create only missing exact CDI snapshots}
                            {--write-events : Create only missing contractual PU events}
                            {--actor= : Explicit authorized actor (user id or email), required for writes}
                            {--as-of= : Homologation cutoff date in YYYY-MM-DD (default: today)}
                            {--details : Print complete rate dates and event requirements}';

    protected $description = 'Phase 2B.5.14 — Prepare exact CDI snapshots and contractual PU events without generating a curve';

    public function handle(
        PuNumericPreparationPlanService $plans,
        PuIndexSnapshotPreparationService $snapshots,
        PuEventMaterializationService $events,
    ): int {
        $dryRun = (bool) $this->option('dry-run');
        $writeRates = (bool) $this->option('write-rates');
        $writeEvents = (bool) $this->option('write-events');

        if (! $dryRun && ! $writeRates && ! $writeEvents) {
            $dryRun = true;
        }

        if ($dryRun && ($writeRates || $writeEvents)) {
            $this->components->warn('Dry-run was combined with write options; running read-only only.');
            $writeRates = false;
            $writeEvents = false;
        }

        try {
            $emission = $this->resolveEmission();
            $asOf = $this->asOf();
            $actorIdentifier = filled($this->option('actor'))
                ? (string) $this->option('actor')
                : null;
            $results = [];

            if ($writeRates) {
                $results[] = $snapshots->write($emission, $actorIdentifier, $asOf);
            }

            if ($writeEvents) {
                $results[] = $events->write($emission, $actorIdentifier, $asOf);
            }

            $plan = $plans->plan($emission, $asOf);
            $writes = array_sum(array_map(
                fn (PuNumericPreparationResult $result): int => $result->writes,
                $results,
            ));

            $this->renderPlan($plan, $results, $writes, $dryRun);

            if ($dryRun) {
                return self::SUCCESS;
            }

            return collect($results)->every(fn (PuNumericPreparationResult $result): bool => in_array(
                $result->action,
                [
                    PuIndexSnapshotPreparationService::ACTION_RATES_PREPARED,
                    PuIndexSnapshotPreparationService::ACTION_ALREADY_PRESENT,
                    PuEventMaterializationService::ACTION_EVENTS_MATERIALIZED,
                    PuEventMaterializationService::ACTION_ALREADY_PRESENT,
                ],
                true,
            )) ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            if ($this->output->isVerbose()) {
                $this->line($exception->getTraceAsString());
            }

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

    /**
     * Aceita apenas uma data real em YYYY-MM-DD. Formatos alternativos e datas
     * inexistentes (overflow de calendário) são rejeitados com mensagem própria,
     * sem stack trace interno e sem qualquer data padrão hardcoded.
     */
    private function asOf(): CarbonImmutable
    {
        $value = $this->option('as-of');

        if (! filled($value)) {
            return CarbonImmutable::today();
        }

        $requested = trim((string) $value);

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $requested);
        } catch (Throwable) {
            throw new RuntimeException('--as-of must use a real date in YYYY-MM-DD format.');
        }

        if (! $date instanceof CarbonImmutable || $date->toDateString() !== $requested) {
            throw new RuntimeException('--as-of must use a real date in YYYY-MM-DD format.');
        }

        return $date;
    }

    /** @param list<PuNumericPreparationResult> $results */
    private function renderPlan(
        PuNumericPreparationPlan $plan,
        array $results,
        int $writes,
        bool $dryRun,
    ): void {
        $this->components->info($dryRun
            ? 'Phase 2B.5.14 — Dry-run (read-only, no BCB fetch)'
            : 'Phase 2B.5.14 — Controlled numeric prerequisite preparation');
        $this->line('Readiness: '.$plan->readinessBefore);
        $this->line('Readiness after hypothetical preparation: '.$plan->readinessAfterHypothetical);
        $this->line('EmissionPuParameter: '.($plan->parameterId === null ? 'absent' : 'present #'.$plan->parameterId));
        $this->line('Parameter fingerprint: '.($plan->parameterFingerprint ?? 'not available'));
        $this->line('curve_start_date: '.($plan->curveStartDate ?? 'PENDING'));
        $this->line('curve_end_date: '.($plan->curveEndDate ?? 'PENDING'));
        $this->line('Action: '.$plan->action);
        $this->line('Reason: '.$plan->reason);
        $this->newLine();

        $this->line('Calendar window: '.$this->window(
            $plan->calendarWindow['from'] ?? null,
            $plan->calendarWindow['to'] ?? null,
        ));
        $this->line('Rate window: '.$this->window(
            $plan->rateWindow['from'] ?? null,
            $plan->rateWindow['to'] ?? null,
        ));
        $this->line('Rate source: '.($plan->rateSource ?? 'not resolved'));
        $this->line('Required rates: '.count($plan->requiredRateDates));
        $this->line('Present rates: '.count($plan->presentRates));
        $this->line('Rates to load: '.count($plan->missingRateDates));
        $this->line('Rate conflicts: '.count($plan->conflictingRates));
        $this->line('Expected events: '.count($plan->eventRequirements));
        $this->line('Present events: '.count($plan->presentEvents));
        $this->line('Events to materialize: '.count($plan->missingEvents));
        $this->line('Event conflicts: '.count($plan->conflictingEvents));

        if ($results === []) {
            $this->line('No BCB fetch performed.');
        }

        if ($results !== []) {
            $this->newLine();
            $this->components->info('Requested operations');

            foreach ($results as $result) {
                $this->line(sprintf(
                    '%s — writes=%d — %s',
                    $result->action,
                    $result->writes,
                    $result->reason,
                ));
            }
        }

        $this->newLine();
        $this->line('Required rate dates: '.$this->summarize($plan->requiredRateDates));
        $this->line('Missing rate dates: '.$this->summarize($plan->missingRateDates));
        $this->line('Event requirements: '.$this->summarize($plan->eventRequirements));
        $this->line('Financial effects guard: '.$this->json($plan->financialEffects));
        $this->line('Writes: '.$writes);
        $this->line('No curve, PuHistory, Payment, aggregate balance or external homologation is generated.');
    }

    private function window(mixed $from, mixed $to): string
    {
        return is_string($from) && is_string($to) ? $from.' to '.$to : 'not resolved';
    }

    /** @param list<mixed> $items */
    private function summarize(array $items): string
    {
        if ($items === []) {
            return '[]';
        }

        if ((bool) $this->option('details') || count($items) <= 10) {
            return $this->json($items);
        }

        return $this->json([
            'count' => count($items),
            'first' => array_slice($items, 0, 5),
            'last' => array_slice($items, -5),
        ]);
    }

    private function json(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}

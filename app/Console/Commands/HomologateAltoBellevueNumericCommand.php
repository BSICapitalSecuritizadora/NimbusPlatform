<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\PuCalculator\DTOs\PuNumericHomologationResult;
use App\Domain\PuCalculator\Services\AltoBellevuePrerequisiteCloser;
use App\Domain\PuCalculator\Services\PuNumericHomologationService;
use App\Models\Emission;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class HomologateAltoBellevueNumericCommand extends Command
{
    protected $signature = 'pu:alto-bellevue:homologate-numeric
                            {--dry-run : Evaluate an isolated candidate in memory (default)}
                            {--as-of= : Homologation cutoff date in YYYY-MM-DD (default: today)}
                            {--details : Print checkpoints, rate samples and validation diagnostics}';

    protected $description = 'Phase 2B.5.15 — Evaluate a reproducible isolated PU candidate without operational persistence';

    public function handle(PuNumericHomologationService $homologation): int
    {
        try {
            $emission = $this->resolveEmission();
            $asOf = $this->asOf();
            $result = $homologation->evaluate($emission, $asOf);
            $this->renderResult($emission, $result);

            return self::SUCCESS;
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

    private function renderResult(Emission $emission, PuNumericHomologationResult $result): void
    {
        $plan = $result->plan;
        $candidate = $result->candidate;
        $validation = $result->validation;
        $comparison = $result->comparison;
        $this->components->info('Phase 2B.5.15 — Numeric PU Homologation Dry-run');
        $this->line(sprintf('Emission: #%d %s', $emission->id, $emission->name));
        $this->line('asOf: '.$plan->asOf);
        $this->line('Readiness: '.$plan->readiness);
        $this->line('Required readiness: '.$plan->requiredReadiness);
        $this->line('EmissionPuParameter: '.($plan->parameterId === null ? 'absent' : 'present #'.$plan->parameterId));
        $this->line('curve_start_date: '.($plan->curveStartDate ?? 'PENDING'));
        $this->line('curve_end_date: '.($plan->curveEndDate ?? 'PENDING'));
        $this->line('Calendar window: '.$this->window(
            $plan->calendarWindow['from'] ?? null,
            $plan->calendarWindow['to'] ?? null,
        ));
        $this->line('Rate window: '.$this->window(
            $plan->rateWindow['from'] ?? null,
            $plan->rateWindow['to'] ?? null,
        ));
        $this->line('Action: '.$result->action);
        $this->line('Reason: '.$result->reason);
        $this->newLine();
        $this->line('Candidate curve: '.($candidate === null ? 'not generated' : 'generated in memory'));
        $this->line('Candidate rows: '.($candidate?->rowCount ?? 0));
        $this->line('Candidate window: '.$this->window($candidate?->from, $candidate?->to));
        $this->line('Input fingerprint: '.($plan->inputFingerprint ?? 'not available'));
        $this->line('Curve checksum: '.($candidate?->checksum ?? 'not available'));
        $this->line('Internal validation: '.($validation?->status ?? 'not executed'));
        $this->line('Blocking failures: '.count($validation?->blockingFailures ?? []));
        $this->line('Warnings: '.count($validation?->warnings ?? []));
        $this->line('External comparison: '.($comparison?->status ?? 'not evaluated'));
        $this->line('Writes: '.$result->writes);
        $this->newLine();
        $this->line('No BCB fetch performed.');
        $this->line('No rate/event/parameter preparation performed.');
        $this->line('No candidate curve persisted.');
        $this->line('No operational curve modified.');
        $this->line('No PuHistory or Payment generated.');

        if (! (bool) $this->option('details') || $candidate === null) {
            return;
        }

        $this->newLine();
        $this->components->info('Reproducible dossier details');
        $this->line('Candidate summary: '.$this->json($candidate->summary()));
        $this->line('Rate samples: '.$this->json($validation?->rateSamples ?? []));
        $this->line('Validation blockers: '.$this->json($validation?->blockingFailures ?? []));
        $this->line('Validation warnings: '.$this->json($validation?->warnings ?? []));
        $this->line('External comparison: '.$this->json($comparison?->toArray()));
    }

    private function window(mixed $from, mixed $to): string
    {
        return is_string($from) && is_string($to) ? $from.' to '.$to : 'not resolved';
    }

    private function json(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}

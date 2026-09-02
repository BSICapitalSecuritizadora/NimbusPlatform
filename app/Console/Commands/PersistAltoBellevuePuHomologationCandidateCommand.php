<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\PuCalculator\DTOs\PuCandidateCurvePersistenceResult;
use App\Domain\PuCalculator\Services\AltoBellevuePrerequisiteCloser;
use App\Domain\PuCalculator\Services\PuCandidateCurvePersistencePlanService;
use App\Domain\PuCalculator\Services\PuCandidateCurvePersistenceService;
use App\Domain\PuCalculator\Services\PuNumericHomologationService;
use App\Models\Emission;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Piloto fino da 2B.5.16: o comando resolve a emissão do Alto Bellevue, um único
 * `asOf` e delega tudo ao serviço genérico. Nenhuma regra de governança vive aqui.
 */
class PersistAltoBellevuePuHomologationCandidateCommand extends Command
{
    protected $signature = 'pu:alto-bellevue:persist-homologation-candidate
                            {--dry-run : Plan the candidate persistence without mutating (default)}
                            {--write : Persist the validated candidate after re-checking every guard under lock}
                            {--actor= : Explicit authorized maker (user id or email), required for --write}
                            {--as-of= : Homologation cutoff date in YYYY-MM-DD (default: today)}
                            {--details : Print the validation, comparison and candidate inventory payloads}';

    protected $description = 'Phase 2B.5.16 — Dry-run or append-only persist the internally validated PU candidate curve';

    public function handle(PuCandidateCurvePersistenceService $persistence): int
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
            $emission = $this->resolveEmission();
            $asOf = $this->asOf();
            $actorIdentifier = filled($this->option('actor')) ? (string) $this->option('actor') : null;
            $result = $write
                ? $persistence->write($emission, $asOf, $actorIdentifier)
                : $persistence->dryRun($emission, $asOf);

            $this->renderResult($emission, $result, $write);

            if (! $write) {
                return self::SUCCESS;
            }

            return in_array($result->action, [
                PuCandidateCurvePersistenceService::ACTION_PERSISTED,
                PuCandidateCurvePersistencePlanService::ACTION_ALREADY_PERSISTED,
            ], true) ? self::SUCCESS : self::FAILURE;
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

    private function renderResult(
        Emission $emission,
        PuCandidateCurvePersistenceResult $result,
        bool $write,
    ): void {
        $plan = $result->plan;
        $homologation = $plan->homologation;
        $candidate = $homologation->candidate;
        $this->components->info(sprintf(
            'Phase 2B.5.16 — Candidate PU Curve Persistence (%s)',
            $write ? 'write' : 'dry-run',
        ));
        $this->line(sprintf('Emission: #%d %s', $emission->id, $emission->name));
        $this->line('asOf: '.$homologation->plan->asOf);
        $this->newLine();
        $this->line('Numeric homologation: '.(
            $homologation->action === PuNumericHomologationService::ACTION_READY_FOR_REVIEW
                ? 'ready'
                : 'not ready'
        ));
        $this->line('Numeric homologation action: '.$homologation->action);
        $this->line('EmissionPuParameter: '.($homologation->plan->parameterId === null
            ? 'absent'
            : 'present #'.$homologation->plan->parameterId));
        $this->line('Readiness: '.$homologation->plan->readiness);
        $this->line('Internal validation: '.($homologation->validation?->status ?? 'not executed'));
        $this->line('External comparison: '.($homologation->comparison?->status ?? 'not evaluated'));
        $this->newLine();
        $this->line('Candidate persistence: '.(
            $plan->action === PuCandidateCurvePersistencePlanService::ACTION_READY_TO_PERSIST
                ? 'ready'
                : 'not ready'
        ));
        $this->line('Current operational version: '.($plan->currentOperationalVersionId === null
            ? 'none'
            : sprintf('#%d (%s)', $plan->currentOperationalVersionId, (string) $plan->currentOperationalCalculationVersion)));
        $this->line('Existing candidates: '.$plan->candidateCount);
        $this->line('Identical candidate: '.($plan->identicalCandidateId === null
            ? 'none'
            : '#'.$plan->identicalCandidateId));
        $this->line('Divergent candidates: '.(
            $plan->divergentCandidateIds === []
                ? 'none'
                : implode(', ', array_map(fn (int $id): string => '#'.$id, $plan->divergentCandidateIds))
        ));
        $this->line('Input fingerprint: '.($homologation->plan->inputFingerprint ?? 'not available'));
        $this->line('Curve checksum: '.($candidate?->checksum ?? 'not available'));
        $this->line('Candidate rows: '.($candidate?->rowCount ?? 0));
        $this->newLine();
        $this->line('Action: '.$result->action);
        $this->line('Reason: '.$result->reason);
        $this->line('Persisted candidate version: '.($result->candidateVersionId === null
            ? 'none'
            : '#'.$result->candidateVersionId));
        $this->line('Calculation version: '.($result->calculationVersion ?? 'not allocated'));
        $this->line('Maker: '.($result->actorId === null ? 'not resolved' : '#'.$result->actorId));
        $this->line('Writes: '.$result->writes);
        $this->newLine();
        $this->line('No BCB fetch performed.');
        $this->line('No rate/event/parameter preparation performed.');

        if ($result->action !== PuCandidateCurvePersistenceService::ACTION_PERSISTED) {
            $this->line('No candidate persisted.');
        }

        $this->line('No operational curve modified.');
        $this->line('No PuHistory or Payment generated.');
        $this->line('No candidate promoted to operational.');

        if (! (bool) $this->option('details')) {
            return;
        }

        $this->newLine();
        $this->components->info('Candidate governance details');
        $this->line('Candidate summary: '.$this->json($candidate?->summary()));
        $this->line('Internal validation: '.$this->json($homologation->validation?->toArray()));
        $this->line('External comparison: '.$this->json($homologation->comparison?->toArray()));
        $this->line('Existing candidate ids: '.$this->json($plan->divergentCandidateIds));
    }

    private function json(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}

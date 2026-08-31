<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\PuCalculator\DTOs\PuBaselineCandidatePersistenceResult;
use App\Domain\PuCalculator\Services\AltoBellevuePrerequisiteCloser;
use App\Domain\PuCalculator\Services\PuBaselineCandidatePersistenceService;
use App\Models\Emission;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class PersistAltoBellevuePuBaselineCandidateCommand extends Command
{
    protected $signature = 'pu:alto-bellevue:persist-candidate
                            {--dry-run : Compare the proven candidate without mutating}
                            {--write : Create the parameter after re-evaluating every guard under lock}
                            {--actor= : Explicit authorized actor (user id or email), required for --write}';

    protected $description = 'Phase 2B.5.13 — Safely dry-run or create-only persist the Alto Bellevue proven PU candidate';

    public function handle(PuBaselineCandidatePersistenceService $persistence): int
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
            $actorIdentifier = filled($this->option('actor'))
                ? (string) $this->option('actor')
                : null;

            if ($write && $actorIdentifier === null) {
                $result = $persistence->dryRun($emission)->withOutcome(
                    action: PuBaselineCandidatePersistenceService::ACTION_ACTOR_REQUIRED,
                    reason: 'Um --actor=<id|email> explícito é obrigatório para --write.',
                );
            } else {
                $result = $write
                    ? $persistence->write($emission, $actorIdentifier)
                    : $persistence->dryRun($emission);
            }

            $this->renderResult($result, $write);

            if (! $write) {
                return self::SUCCESS;
            }

            return in_array($result->action, [
                PuBaselineCandidatePersistenceService::ACTION_CREATED,
                PuBaselineCandidatePersistenceService::ACTION_ALREADY_MATCHES,
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

    private function renderResult(
        PuBaselineCandidatePersistenceResult $result,
        bool $write,
    ): void {
        $this->components->info($write
            ? 'Phase 2B.5.13 — Explicit audited write'
            : 'Phase 2B.5.13 — Dry-run (read-only)');
        $this->line('Readiness: '.$result->readinessStatus);
        $this->line('Pending fields: '.$this->json($result->pendingFields));
        $this->line('Blocking candidate requirements: '.$this->json($result->blockingRequirements));
        $this->line('Action: '.$result->action);
        $this->line('Reason: '.$result->reason);
        $this->line('Writes: '.$result->writes);
        $this->line('Diagnostic candidate fingerprint (persistence is governed by readiness): '.$result->candidateFingerprint);
        $this->newLine();

        $this->components->info('Candidate → EmissionPuParameter mapping');
        $this->table(
            ['candidate field', 'parameter field', 'persisted?', 'reason'],
            collect($result->mapping)->map(fn (array $mapping): array => [
                $mapping['candidate_field'],
                $mapping['parameter_field'] ?? 'no direct column',
                $mapping['persisted'] ? 'yes' : 'no',
                $mapping['reason'],
            ])->all(),
        );

        $this->newLine();
        $this->components->info('Configuration comparison');
        $configurationFields = collect(array_keys($result->candidate))
            ->merge(array_keys($result->proposedConfiguration))
            ->unique()
            ->values();
        $this->table(
            ['field', 'candidate', 'existing_configuration', 'proposed_configuration'],
            $configurationFields->map(fn (string $field): array => [
                $field,
                $this->display($result->candidate[$field] ?? null),
                $this->display($result->existingConfiguration[$field] ?? null),
                $this->display($result->proposedConfiguration[$field] ?? null),
            ])->all(),
        );

        $this->line('Diff: '.$this->json($result->diff));
        $this->line('Existing financial effects: '.$this->json($result->financialEffects));
        $this->line('Provenance: '.$this->json($result->provenance));
        $this->line('Future snapshot window (report only; no import): '.$this->json(
            $result->futureSnapshotWindow,
        ));
        $this->newLine();
        $this->line('No index rates, PU events, curves, PuHistory or Payment rows are created by this command.');
    }

    private function display(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        return is_scalar($value) ? (string) $value : $this->json($value);
    }

    private function json(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}

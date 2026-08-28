<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\LegalInstruments\AltoBellevueContractEvidenceReview;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

class ReviewAltoBellevueContractEvidenceCommand extends Command
{
    protected $signature = 'legal-instruments:review-alto-bellevue-baseline
                            {--dry-run : List inventory and planned decisions without confirming}
                            {--write : Confirm eligible pending fields through the normal review workflow}
                            {--reviewer= : Explicit reviewer (user id or email) required for --write}';

    protected $description = 'Phase 2B.5.9 — Review and confirm the 19 pending CRI Alto Bellevue contractual evidences via the governing Securitization Agreement';

    public function handle(AltoBellevueContractEvidenceReview $service): int
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
            $inventory = $service->inventory();

            $this->components->info($dryRun
                ? 'Phase 2B.5.9 — Inventory (read-only, no mutation)'
                : 'Phase 2B.5.9 — Review and confirmation (workflow)');

            $this->line(sprintf('Pending at start: %d', count($inventory)));
            $this->newLine();

            if ($inventory === []) {
                $this->components->warn('No pending_review records found for the governing Securitization Agreement. Already confirmed or no backfill yet.');
                $this->line('Run: php artisan legal-instruments:structure-alto-bellevue-baseline --write  (Phase 2B.5.8) first.');

                return self::SUCCESS;
            }

            $this->table(
                ['id', 'field_key', 'canonical', 'type', 'doc', 'clause/page', 'evidence_level / conf', 'class', 'scope'],
                collect($inventory)->map(fn (array $row): array => [
                    $row['id'],
                    $row['field_key'],
                    is_float($row['canonical_value']) ? rtrim(rtrim(number_format($row['canonical_value'], 8, '.', ''), '0'), '.') : (string) $row['canonical_value'],
                    $row['value_type'],
                    sprintf('#%s %s', $row['document_id'] ?? '—', Str::limit($row['document'] ?? '', 28)),
                    trim(sprintf('%s / p.%s', $row['clause'] ?? '—', $row['page'] ?? '—')),
                    sprintf('%s / %.2f', $row['evidence_level'], (float) ($row['confidence_score'] ?? 0)),
                    str_contains($row['classification'], 'A —') ? 'A explicit' : 'B inferred',
                    $row['governing_scope_valid'] ? 'governing CRI' : 'out-of-scope',
                ])->all(),
            );

            // Classification summary
            $explicit = collect($inventory)->filter(fn (array $row): bool => str_contains($row['classification'], 'A —'))->count();
            $inferred = collect($inventory)->filter(fn (array $row): bool => str_contains($row['classification'], 'B —'))->count();
            $this->newLine();
            $this->line(sprintf('Classification: <info>%d explicit (A)</info> + <comment>%d inferred/operational (B)</comment> = %d total', $explicit, $inferred, count($inventory)));
            $this->line(sprintf('Governing scope: %d / %d are scoped to CRI Securitization Agreement (Termo)', collect($inventory)->where('governing_scope_valid', true)->count(), count($inventory)));

            // Review plan
            $plan = $service->reviewPlan();
            $this->newLine();
            $this->components->info('Review plan (one decision per field_key)');
            $this->table(
                ['field_key', 'decision', 'classification', 'reason (excerpt)'],
                collect($plan)->map(fn (array $d): array => [
                    $d['field_key'],
                    $d['decision'],
                    str_contains($d['classification'], 'A —') ? 'A' : 'B',
                    Str::limit($d['reason'], 90),
                ])->all(),
            );

            $confirmCount = collect($plan)->where('decision', 'confirm')->count();
            $keepCount = collect($plan)->where('decision', 'keep_pending')->count();
            $rejectCount = collect($plan)->where('decision', 'reject')->count();
            $this->line(sprintf('Planned: <info>%d confirm</info>, %d keep_pending, %d reject', $confirmCount, $keepCount, $rejectCount));

            if ($dryRun) {
                $this->newLine();
                $this->components->warn('Dry-run: no field status was changed. No PU engine, calendar, DI source, or PU parameter was touched.');
                $this->line('To execute the audited workflow: php artisan legal-instruments:review-alto-bellevue-baseline --write');

                return self::SUCCESS;
            }

            // Reviewer governance (Phase 2B.5.10): require explicit --reviewer for auditable
            // legal/financial approvals. Do not silently fall back to first admin.
            $reviewerIdentifier = $this->option('reviewer');

            if (! filled($reviewerIdentifier)) {
                $this->components->error('Explicit --reviewer=<user_id|email> is required for --write. No silent fallback to first admin is allowed for auditable approvals.');
                $this->line('Available reviewers (active users with legal-instruments.confirm_change):');
                $candidates = User::query()->whereHas('permissions', fn ($q) => $q->where('name', 'legal-instruments.confirm_change'))->orWhereHas('roles', fn ($q) => $q->whereIn('name', ['super-admin', 'admin']))->with('roles')->limit(20)->get();
                foreach ($candidates as $u) {
                    $this->line(sprintf('  - #%d %s (%s) roles: %s', $u->id, $u->email, $u->name, $u->roles->pluck('name')->implode(', ')));
                }
                $this->line('Example: php artisan legal-instruments:review-alto-bellevue-baseline --write --reviewer=admin@bsi.local');

                return self::FAILURE;
            }

            $reviewer = $this->resolveReviewer($reviewerIdentifier);

            if (! $reviewer) {
                $this->components->error(sprintf('Reviewer "%s" not found, inactive, or missing permission legal-instruments.confirm_change.', $reviewerIdentifier));

                return self::FAILURE;
            }

            $this->newLine();
            $this->components->info(sprintf('Confirming %d fields via InstrumentChangeReviewService as %s (#%d) ...', $confirmCount, $reviewer->email, $reviewer->id));

            $result = $service->execute($reviewer, null, false);

            $this->newLine();
            $this->table(
                ['metric', 'value'],
                [
                    ['pending_at_start', $result['pending_at_start']],
                    ['confirmed', $result['confirmed']],
                    ['kept_pending', $result['kept_pending']],
                    ['rejected', $result['rejected']],
                    ['ccb_preserved (7.50% untouched)', $result['ccb_preserved'] ? 'yes' : 'NO — check!'],
                    ['matricula_mae_preserved (2026-04-30)', $result['matricula_mae_preserved'] ? 'yes' : 'NO — check!'],
                ],
            );

            if ($result['read_only_evaluation']) {
                $eval = $result['read_only_evaluation'];
                $this->newLine();
                $this->components->info(sprintf('Read-only readiness after review: %s', $eval['status']));
                $this->line('Candidate (non-persisted, evidence-driven):');
                foreach ($eval['candidateConfiguration'] as $k => $v) {
                    $this->line(sprintf('  %-55s %s', $k, is_bool($v) ? ($v ? 'true' : 'false') : (string) $v));
                }
                $this->newLine();
                $this->line('Pending fields (should be [] for contractual evidence after confirm): '.json_encode($eval['pendingFields']));
                $this->newLine();
                $this->line('Blocking requirements remaining (expected operational blockers):');
                foreach ($eval['requirements'] as $code => $req) {
                    if ($req['status'] !== 'satisfied') {
                        $this->line(sprintf('  - %s: %s (blocks: %s)', $code, $req['status'], implode(', ', $req['blocks'])));
                    }
                }
            }

            $this->newLine();
            $this->components->info('Done. No calendar years, DI source, index_rates, PU events, curves or payments were approved or created.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());
            if ($this->output->isVerbose()) {
                $this->line($e->getTraceAsString());
            }

            return self::FAILURE;
        }
    }

    private function resolveReviewer(?string $identifier = null): ?User
    {
        if (! filled($identifier)) {
            return null;
        }

        $user = is_numeric($identifier)
            ? User::find((int) $identifier)
            : User::where('email', $identifier)->first();

        if (! $user instanceof User) {
            return null;
        }

        if (! $user->isActive() || ! $user->isApproved()) {
            return null;
        }

        // Must have the required permission via role or direct permission
        if (! $user->can('legal-instruments.confirm_change') && ! $user->can('legal-instruments.review_changes')) {
            // Check via Spatie directly as fallback for test fixtures
            $hasPermission = $user->hasPermissionTo('legal-instruments.confirm_change') || $user->hasRole(['super-admin', 'admin']);
            if (! $hasPermission) {
                return null;
            }
        }

        return $user;
    }

    /**
     * Diagnosis of admin@bsi.local for Phase 2B.5.10 Section 5.
     * Read-only audit of how the account was created, roles, permissions, etc.
     *
     * @return array<string, mixed>
     */
    public function auditReviewer(string $email): array
    {
        $user = User::where('email', $email)->first();

        if (! $user instanceof User) {
            return ['found' => false, 'email' => $email];
        }

        return [
            'found' => true,
            'id' => $user->id,
            'email' => $user->email,
            'name' => $user->name,
            'is_active' => $user->is_active,
            'approved_at' => $user->approved_at?->toIso8601String(),
            'roles' => $user->roles->pluck('name')->all(),
            'permissions' => $user->getAllPermissions()->pluck('name')->all(),
            'can_confirm' => $user->can('legal-instruments.confirm_change'),
            'can_review' => $user->can('legal-instruments.review_changes'),
            'is_super_admin' => $user->hasRole('super-admin'),
            'is_admin' => $user->hasRole('admin'),
            'is_seed_account' => $email === 'admin@bsi.local',
            'created_at' => $user->created_at?->toIso8601String(),
            'audit_note' => $email === 'admin@bsi.local'
                ? 'Seed account from InitialDemoSeeder (firstOrCreate admin@bsi.local, role super-admin). Valid authorized account for demo/dev; for production audited approvals prefer explicit human reviewer via --reviewer=<email|id> to avoid generic account usage.'
                : 'Human reviewer account.',
        ];
    }
}

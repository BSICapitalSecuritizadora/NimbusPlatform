<?php

namespace App\Services\Funds;

use App\Exceptions\Funds\FundAllocationException;
use App\Models\BankApplication;
use App\Models\BankApplicationBalanceSnapshot;
use App\Models\Fund;
use App\Models\FundAllocationLine;
use App\Models\FundAllocationPeriod;
use App\Models\FundAllocationRevision;
use App\Models\User;
use App\Support\Funds\FundAllocationMoney;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Monthly fund-allocation domain: reference balances, drafts, governed
 * approval and corrections.
 *
 * Every mutating operation enforces its Spatie permission here, at the
 * domain layer — never only in UI visibility. All money math runs on
 * integer cents ({@see FundAllocationMoney}); binary floating point never
 * touches a validated total.
 *
 * Concurrency: each operation runs in a single transaction following one
 * global lock order — BankApplication → FundAllocationPeriod →
 * FundAllocationRevision → Fund rows (ascending id) → current snapshot row.
 * Every path acquires shared rows in that order and never in reverse, so
 * lock-ordering deadlocks cannot arise between approvals, draft mutations,
 * reference recordings and Fund linkage edits. Approval and submission
 * re-validate the pinned snapshot version, the exact total and every Fund
 * association against locked (current) reads, so a concurrent Fund edit or
 * reference recording either commits first (and is then observed, rejecting
 * the stale revision) or waits for the approval to finish — it can never
 * slip unnoticed between validation and commit.
 *
 * Audit: every financial action and decision emits an event to the
 * `fund_allocations` activity log (protected retention) inside the same
 * transaction, so a rolled-back operation leaves no trail behind, and
 * rejected approvals are recorded after the rollback with their reason.
 */
class FundAllocationService
{
    public const AUDIT_LOG = 'fund_allocations';

    public const PERMISSION_VIEW = 'fund-allocations.view';

    public const PERMISSION_CREATE = 'fund-allocations.create';

    public const PERMISSION_SUBMIT = 'fund-allocations.submit';

    public const PERMISSION_APPROVE = 'fund-allocations.approve';

    public const PERMISSION_CORRECT = 'fund-allocations.correct';

    public const PERMISSION_MANAGE_REFERENCES = 'fund-allocations.manage_references';

    /**
     * Normalize a competence input to the first day of its month (Y-m-d).
     */
    public static function normalizeCompetence(string $competence): string
    {
        if (! preg_match('/^(\d{4})-(0[1-9]|1[0-2])(?:-\d{2})?$/', trim($competence), $matches)) {
            throw FundAllocationException::invalid("Competência inválida: '{$competence}'. Use AAAA-MM.");
        }

        return sprintf('%s-%s-01', $matches[1], $matches[2]);
    }

    /**
     * Record an explicitly supplied manual reference balance.
     *
     * Versions are append-only: each new recording supersedes the previous
     * current version for (application, competence) without touching it, so
     * approvals pinned to the older version keep their exact basis.
     */
    public function recordReferenceBalance(
        User $actor,
        BankApplication $application,
        string $competence,
        string|int|float $amount,
        ?string $note = null,
        ?DateTimeInterface $capturedAt = null,
    ): BankApplicationBalanceSnapshot {
        $this->requirePermission($actor, self::PERMISSION_MANAGE_REFERENCES, 'registrar saldos de referência');

        $competenceDate = self::normalizeCompetence($competence);
        $cents = $this->toCents($amount, 'saldo de referência');

        if ($cents < 0) {
            throw FundAllocationException::invalid('O saldo de referência não pode ser negativo.');
        }

        if ($note !== null && mb_strlen($note) > 2000) {
            throw FundAllocationException::invalid('A observação do saldo de referência deve ter até 2000 caracteres.');
        }

        return DB::transaction(function () use ($actor, $application, $competenceDate, $cents, $note, $capturedAt): BankApplicationBalanceSnapshot {
            // First in the global lock order: the application row serializes
            // version numbering against approvals, submissions and drafts,
            // which lock the same row before touching periods.
            $this->lockApplication((int) $application->getKey());

            $previous = $this->currentSnapshotQuery($application, $competenceDate)->lockForUpdate()->first();

            $snapshot = BankApplicationBalanceSnapshot::query()->create([
                'bank_application_id' => $application->getKey(),
                'competence' => $competenceDate,
                'amount' => FundAllocationMoney::fromCents($cents),
                'source' => BankApplicationBalanceSnapshot::SOURCE_MANUAL,
                'captured_at' => $capturedAt ?? now(),
                'recorded_by_user_id' => $actor->getKey(),
                'note' => $note !== null ? trim($note) ?: null : null,
                'version' => $previous instanceof BankApplicationBalanceSnapshot ? $previous->version + 1 : 1,
                'supersedes_snapshot_id' => $previous?->getKey(),
            ]);

            activity(self::AUDIT_LOG)
                ->performedOn($snapshot)
                ->causedBy($actor)
                ->withProperties([
                    'event' => 'reference_recorded',
                    'bank_application_id' => $application->getKey(),
                    'competence' => $competenceDate,
                    'snapshot_id' => $snapshot->getKey(),
                    'version' => $snapshot->version,
                    'supersedes_snapshot_id' => $snapshot->supersedes_snapshot_id,
                    'amount' => FundAllocationMoney::fromCents($cents),
                    'source' => BankApplicationBalanceSnapshot::SOURCE_MANUAL,
                ])
                ->log('reference_recorded');

            return $snapshot->refresh();
        });
    }

    /**
     * Create the first distribution draft for a period.
     *
     * Later distributions for the same period are corrections and must go
     * through {@see self::proposeCorrection()} with a reason.
     *
     * @param  list<array{fund_id: int, amount: string|int|float}>  $lines
     */
    public function createDraft(
        User $actor,
        BankApplication $application,
        string $competence,
        int $snapshotId,
        array $lines,
    ): FundAllocationRevision {
        $this->requirePermission($actor, self::PERMISSION_CREATE, 'criar rascunhos de alocação');

        $competenceDate = self::normalizeCompetence($competence);

        return DB::transaction(function () use ($actor, $application, $competenceDate, $snapshotId, $lines): FundAllocationRevision {
            $this->lockApplication((int) $application->getKey());
            $period = $this->resolvePeriodForUpdate($application, $competenceDate);

            // Input first: a superseded or foreign reference is reported even
            // when the period state would also refuse the draft.
            $snapshot = $this->requireCurrentSnapshot($application, $competenceDate, $snapshotId);

            if ($period->hasOpenRevision()) {
                throw FundAllocationException::invalid('Já existe um rascunho ou revisão em análise para esta competência. Conclua ou descarte a revisão aberta antes de criar outra.');
            }

            if ($period->hasEffectiveRevision()) {
                throw FundAllocationException::invalid('Esta competência já possui alocação aprovada. Proponha uma correção com justificativa em vez de um novo rascunho.');
            }

            $validated = $this->validateLines($application, $lines);

            $revision = $this->storeRevision(
                $period,
                $snapshot,
                $actor,
                $validated,
                supersedesRevisionId: null,
                correctionReason: null,
            );

            $period->forceFill(['open_revision_id' => $revision->getKey()])->save();

            $this->auditRevision($revision->refresh(), $actor, 'draft_created', [
                'is_correction' => false,
            ]);

            return $revision->refresh()->load(['lines', 'snapshot']);
        });
    }

    /**
     * Replace the lines of a draft revision.
     *
     * @param  list<array{fund_id: int, amount: string|int|float}>  $lines
     */
    public function updateDraft(User $actor, FundAllocationRevision $revision, array $lines): FundAllocationRevision
    {
        $revision = $this->freshRevision($revision);
        $this->requirePermission($actor, $this->draftPermission($revision), 'editar rascunhos de alocação');

        return DB::transaction(function () use ($actor, $revision, $lines): FundAllocationRevision {
            $this->lockApplicationForRevision($revision);
            $period = $this->lockPeriod($revision);
            $locked = $this->lockRevision($revision);

            if (! $locked->isDraft()) {
                throw FundAllocationException::invalidTransition($locked->status, FundAllocationRevision::STATUS_DRAFT);
            }

            if ((int) $period->open_revision_id !== (int) $locked->getKey()) {
                throw FundAllocationException::superseded($locked->status);
            }

            $application = $period->bankApplication()->firstOrFail();
            $validated = $this->validateLines($application, $lines);

            $locked->lines()->delete();
            $this->storeLines($locked, $validated);

            $snapshotCents = FundAllocationMoney::toCents((string) $locked->snapshot->amount);
            $locked->forceFill([
                'total_amount' => FundAllocationMoney::fromCents($validated['total_cents']),
                'unallocated_amount' => FundAllocationMoney::fromCents($snapshotCents - $validated['total_cents']),
            ])->save();

            $this->auditRevision($locked->refresh(), $actor, 'draft_updated', []);

            return $locked->refresh()->load(['lines', 'snapshot']);
        });
    }

    /**
     * Discard a draft or pending-review revision. The row and its lines stay
     * as inert history; the period accepts a new open revision afterwards.
     */
    public function discardDraft(User $actor, FundAllocationRevision $revision, ?string $reason = null): FundAllocationRevision
    {
        $revision = $this->freshRevision($revision);
        $this->requirePermission($actor, $this->draftPermission($revision), 'descartar rascunhos de alocação');

        if ($reason !== null && mb_strlen($reason) > 2000) {
            throw FundAllocationException::invalid('O motivo do descarte deve ter até 2000 caracteres.');
        }

        return DB::transaction(function () use ($actor, $revision, $reason): FundAllocationRevision {
            $this->lockApplicationForRevision($revision);
            $period = $this->lockPeriod($revision);
            $locked = $this->lockRevision($revision);

            if (! $locked->isOpen()) {
                throw FundAllocationException::invalidTransition($locked->status, FundAllocationRevision::STATUS_DISCARDED);
            }

            if ((int) $period->open_revision_id !== (int) $locked->getKey()) {
                throw FundAllocationException::superseded($locked->status);
            }

            $locked->forceFill(['status' => FundAllocationRevision::STATUS_DISCARDED])->save();
            $period->forceFill(['open_revision_id' => null])->save();

            $this->auditRevision($locked->refresh(), $actor, 'draft_discarded', [
                'reason' => $reason !== null ? trim($reason) ?: null : null,
            ]);

            return $locked->refresh()->load(['lines', 'snapshot']);
        });
    }

    /**
     * Move a draft to pending review.
     */
    public function submitForReview(User $actor, FundAllocationRevision $revision): FundAllocationRevision
    {
        $this->requirePermission($actor, self::PERMISSION_SUBMIT, 'enviar alocações para análise');

        $revision = $this->freshRevision($revision);

        return DB::transaction(function () use ($actor, $revision): FundAllocationRevision {
            $this->lockApplicationForRevision($revision);
            $period = $this->lockPeriod($revision);
            $locked = $this->lockRevision($revision);

            if (! $locked->isDraft()) {
                throw FundAllocationException::invalidTransition($locked->status, FundAllocationRevision::STATUS_PENDING_REVIEW);
            }

            if ((int) $period->open_revision_id !== (int) $locked->getKey()) {
                throw FundAllocationException::superseded($locked->status);
            }

            // A newer reference recorded after drafting blocks submission: the
            // totals were validated against a stale balance.
            $this->requireSnapshotStillCurrent($period, $locked);

            // Same for Fund linkage: a fund unlinked or moved after drafting
            // blocks submission instead of reaching approval stale.
            $this->assertLinesMatchCurrentLinkage($period, $locked);

            $locked->forceFill([
                'status' => FundAllocationRevision::STATUS_PENDING_REVIEW,
                'submitted_by_user_id' => $actor->getKey(),
                'submitted_at' => now(),
            ])->save();

            $this->auditRevision($locked->refresh(), $actor, 'submitted_for_review', []);

            return $locked->refresh()->load(['lines', 'snapshot']);
        });
    }

    /**
     * Approve a pending revision atomically for the whole period.
     *
     * The idempotency key makes retries safe: repeating the call with the
     * same key after a success replays the approved revision instead of
     * creating anything new, while a different key against an already
     * approved revision is rejected as a duplicate submission.
     */
    public function approve(User $actor, FundAllocationRevision $revision, string $idempotencyKey): FundAllocationRevision
    {
        $this->requirePermission($actor, self::PERMISSION_APPROVE, 'aprovar alocações');

        if (trim($idempotencyKey) === '') {
            throw FundAllocationException::invalid('A aprovação exige uma chave de idempotência.');
        }

        if (mb_strlen($idempotencyKey) > 64) {
            throw FundAllocationException::invalid('A chave de idempotência deve ter até 64 caracteres.');
        }

        try {
            return DB::transaction(function () use ($actor, $revision, $idempotencyKey): FundAllocationRevision {
                $this->lockApplicationForRevision($revision);
                $period = $this->lockPeriod($revision);
                $locked = $this->lockRevision($revision);

                // Idempotent replay: same key, still the effective revision.
                if ($locked->isApproved()) {
                    if ($locked->approval_idempotency_key === $idempotencyKey && (int) $period->effective_revision_id === (int) $locked->getKey()) {
                        return $locked->load(['lines', 'snapshot']);
                    }

                    throw FundAllocationException::alreadyApproved();
                }

                if (! $locked->isPendingReview()) {
                    throw FundAllocationException::invalidTransition($locked->status, FundAllocationRevision::STATUS_APPROVED);
                }

                if ((int) $period->open_revision_id !== (int) $locked->getKey()) {
                    throw FundAllocationException::superseded($locked->status);
                }

                // Segregation of duties: the approver is a second user.
                if ((int) $actor->getKey() === (int) $locked->proposed_by_user_id
                    || ($locked->submitted_by_user_id !== null && (int) $actor->getKey() === (int) $locked->submitted_by_user_id)
                ) {
                    throw FundAllocationException::segregationOfDuties();
                }

                // Stale reference: a newer snapshot version blocks approval.
                $this->requireSnapshotStillCurrent($period, $locked);

                // Re-validate every Fund association against locked (current)
                // reads: the FOR UPDATE fund rows serialize against concurrent
                // Fund edits, so a fund unlinked from the application (or moved
                // to another emission) is either observed here — rejecting the
                // approval — or waits for this transaction to finish. It can
                // never slip unnoticed between validation and commit.
                $snapshotCents = FundAllocationMoney::toCents((string) $locked->snapshot->amount);
                $lines = $locked->lines()->orderBy('fund_id')->get();
                $funds = $this->lockedFundsForLines($lines);
                $totalCents = 0;

                foreach ($lines as $line) {
                    $fund = $funds->get($line->fund_id);

                    if (! $fund instanceof Fund) {
                        throw FundAllocationException::invalidLines(
                            "O fundo #{$line->fund_id} não existe mais. Descarte esta revisão e proponha novamente.",
                            ['fund_id' => $line->fund_id]
                        );
                    }

                    if ((int) $fund->bank_application_id !== (int) $period->bank_application_id) {
                        throw FundAllocationException::invalidLines(
                            "O fundo #{$fund->getKey()} não está mais vinculado a esta aplicação bancária. Descarte esta revisão e proponha novamente.",
                            ['fund_id' => $fund->getKey()]
                        );
                    }

                    if ((int) $fund->emission_id !== (int) $line->emission_id) {
                        throw FundAllocationException::invalidLines(
                            "O fundo #{$fund->getKey()} mudou de operação após a proposta. Descarte esta revisão e proponha novamente.",
                            ['fund_id' => $fund->getKey()]
                        );
                    }

                    $totalCents += FundAllocationMoney::toCents((string) $line->amount);
                }

                if ($totalCents > $snapshotCents) {
                    throw FundAllocationException::invalidLines(
                        'O total alocado ('.$this->formatCents($totalCents).') excede o saldo de referência ('.$this->formatCents($snapshotCents).').',
                        ['total_cents' => $totalCents, 'reference_cents' => $snapshotCents]
                    );
                }

                $previousEffectiveId = $period->effective_revision_id;

                $locked->forceFill([
                    'status' => FundAllocationRevision::STATUS_APPROVED,
                    'reference_amount' => FundAllocationMoney::fromCents($snapshotCents),
                    'total_amount' => FundAllocationMoney::fromCents($totalCents),
                    'unallocated_amount' => FundAllocationMoney::fromCents($snapshotCents - $totalCents),
                    'approved_by_user_id' => $actor->getKey(),
                    'approved_at' => now(),
                    'approval_idempotency_key' => $idempotencyKey,
                ])->save();

                if ($previousEffectiveId !== null) {
                    FundAllocationRevision::query()
                        ->whereKey($previousEffectiveId)
                        ->where('status', FundAllocationRevision::STATUS_APPROVED)
                        ->update(['status' => FundAllocationRevision::STATUS_SUPERSEDED]);
                }

                $period->forceFill([
                    'effective_revision_id' => $locked->getKey(),
                    'open_revision_id' => null,
                ])->save();

                $this->auditRevision($locked->refresh(), $actor, 'approved', [
                    'superseded_revision_id' => $previousEffectiveId,
                    'idempotency_key' => $idempotencyKey,
                ]);

                return $locked->refresh()->load(['lines', 'snapshot']);
            });
        } catch (FundAllocationException $exception) {
            // Rejected approvals are decisions too: record the reason after
            // the rollback so the attempt itself stays auditable.
            $this->auditApprovalRejection($revision, $actor, $exception, $idempotencyKey);

            throw $exception;
        }
    }

    /**
     * Propose a correction as a new draft revision.
     *
     * The previously effective revision is untouched until (and unless) the
     * correction is approved; the reason, the superseded revision and the
     * new reference snapshot (when it changed) are all preserved.
     *
     * @param  list<array{fund_id: int, amount: string|int|float}>  $lines
     */
    public function proposeCorrection(
        User $actor,
        BankApplication $application,
        string $competence,
        int $snapshotId,
        array $lines,
        string $reason,
    ): FundAllocationRevision {
        $this->requirePermission($actor, self::PERMISSION_CORRECT, 'propor correções de alocação');

        $competenceDate = self::normalizeCompetence($competence);

        if (trim($reason) === '') {
            throw FundAllocationException::invalid('A correção exige um motivo.');
        }

        if (mb_strlen($reason) > 2000) {
            throw FundAllocationException::invalid('O motivo da correção deve ter até 2000 caracteres.');
        }

        return DB::transaction(function () use ($actor, $application, $competenceDate, $snapshotId, $lines, $reason): FundAllocationRevision {
            $this->lockApplication((int) $application->getKey());
            $period = $this->resolvePeriodForUpdate($application, $competenceDate);

            // Input first, same as draft creation.
            $snapshot = $this->requireCurrentSnapshot($application, $competenceDate, $snapshotId);

            if ($period->hasOpenRevision()) {
                throw FundAllocationException::invalid('Já existe um rascunho ou revisão em análise para esta competência. Conclua ou descarte a revisão aberta antes de propor a correção.');
            }

            if (! $period->hasEffectiveRevision()) {
                throw FundAllocationException::invalid('Esta competência ainda não possui alocação aprovada. Crie o primeiro rascunho em vez de uma correção.');
            }

            $validated = $this->validateLines($application, $lines);

            $revision = $this->storeRevision(
                $period,
                $snapshot,
                $actor,
                $validated,
                supersedesRevisionId: (int) $period->effective_revision_id,
                correctionReason: trim($reason),
            );

            $period->forceFill(['open_revision_id' => $revision->getKey()])->save();

            $this->auditRevision($revision->refresh(), $actor, 'correction_proposed', [
                'is_correction' => true,
            ]);

            return $revision->refresh()->load(['lines', 'snapshot']);
        });
    }

    /**
     * The deterministic effective revision for an application and competence:
     * the single revision the period aggregate points at, if any.
     */
    public function effectiveRevision(User $actor, BankApplication $application, string $competence): ?FundAllocationRevision
    {
        $this->requirePermission($actor, self::PERMISSION_VIEW, 'visualizar alocações');

        $period = FundAllocationPeriod::query()
            ->where('bank_application_id', $application->getKey())
            ->whereDate('competence', self::normalizeCompetence($competence))
            ->first();

        if (! $period instanceof FundAllocationPeriod || ! $period->hasEffectiveRevision()) {
            return null;
        }

        return FundAllocationRevision::query()
            ->whereKey($period->effective_revision_id)
            ->where('status', FundAllocationRevision::STATUS_APPROVED)
            ->with(['lines', 'snapshot'])
            ->first();
    }

    /**
     * The current (latest-version) reference snapshot, if any.
     */
    public function currentSnapshot(User $actor, BankApplication $application, string $competence): ?BankApplicationBalanceSnapshot
    {
        $this->requirePermission($actor, self::PERMISSION_VIEW, 'visualizar alocações');

        return $this->currentSnapshotQuery($application, self::normalizeCompetence($competence))->first();
    }

    /**
     * @param  list<array{fund_id: int, amount: string|int|float}>  $lines
     * @return array{rows: list<array{fund_id: int, emission_id: int, cents: int}>, total_cents: int}
     */
    private function validateLines(BankApplication $application, array $lines): array
    {
        $seen = [];
        $parsed = [];

        foreach (array_values($lines) as $index => $line) {
            if (! is_array($line) || ! array_key_exists('fund_id', $line) || ! array_key_exists('amount', $line)) {
                throw FundAllocationException::invalidLines(
                    "Linha {$index}: informe fund_id e amount.",
                    ['index' => $index]
                );
            }

            $fundId = $line['fund_id'];

            if (! is_int($fundId) && ! (is_string($fundId) && ctype_digit($fundId))) {
                throw FundAllocationException::invalidLines(
                    "Linha {$index}: fund_id inválido.",
                    ['index' => $index]
                );
            }

            $fundId = (int) $fundId;

            if (isset($seen[$fundId])) {
                throw FundAllocationException::invalidLines(
                    "O fundo #{$fundId} aparece mais de uma vez na mesma revisão.",
                    ['fund_id' => $fundId]
                );
            }

            $seen[$fundId] = true;

            $cents = $this->toCents($line['amount'], "linha do fundo #{$fundId}");

            if ($cents < 0) {
                throw FundAllocationException::invalidLines(
                    "O valor alocado ao fundo #{$fundId} não pode ser negativo.",
                    ['fund_id' => $fundId]
                );
            }

            $parsed[] = ['fund_id' => $fundId, 'cents' => $cents];
        }

        // Locked bulk read, last in the lock order: the frozen emission_id
        // captured below matches the Fund state at commit time, not a stale
        // snapshot taken before a concurrent Fund edit.
        $funds = Fund::query()
            ->whereIn('id', array_column($parsed, 'fund_id'))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $rows = [];
        $totalCents = 0;

        foreach ($parsed as $item) {
            $fundId = $item['fund_id'];
            $fund = $funds->get($fundId);

            if (! $fund instanceof Fund) {
                throw FundAllocationException::invalidLines(
                    "O fundo #{$fundId} não existe.",
                    ['fund_id' => $fundId]
                );
            }

            if ((int) $fund->bank_application_id !== (int) $application->getKey()) {
                throw FundAllocationException::invalidLines(
                    "O fundo #{$fundId} não pertence a esta aplicação bancária.",
                    ['fund_id' => $fundId]
                );
            }

            $rows[] = ['fund_id' => $fundId, 'emission_id' => (int) $fund->emission_id, 'cents' => $item['cents']];
            $totalCents += $item['cents'];
        }

        return ['rows' => $rows, 'total_cents' => $totalCents];
    }

    /**
     * @param  array{rows: list<array{fund_id: int, emission_id: int, cents: int}>, total_cents: int}  $validated
     */
    private function storeRevision(
        FundAllocationPeriod $period,
        BankApplicationBalanceSnapshot $snapshot,
        User $actor,
        array $validated,
        ?int $supersedesRevisionId,
        ?string $correctionReason,
    ): FundAllocationRevision {
        $snapshotCents = FundAllocationMoney::toCents((string) $snapshot->amount);

        if ($validated['total_cents'] > $snapshotCents) {
            throw FundAllocationException::invalidLines(
                'O total alocado ('.$this->formatCents($validated['total_cents']).') excede o saldo de referência ('.$this->formatCents($snapshotCents).').',
                ['total_cents' => $validated['total_cents'], 'reference_cents' => $snapshotCents]
            );
        }

        $revisionNumber = ((int) $period->revisions()->max('revision_number')) + 1;

        $revision = FundAllocationRevision::query()->create([
            'fund_allocation_period_id' => $period->getKey(),
            'revision_number' => $revisionNumber,
            'status' => FundAllocationRevision::STATUS_DRAFT,
            'bank_application_balance_snapshot_id' => $snapshot->getKey(),
            'reference_amount' => FundAllocationMoney::fromCents($snapshotCents),
            'total_amount' => FundAllocationMoney::fromCents($validated['total_cents']),
            'unallocated_amount' => FundAllocationMoney::fromCents($snapshotCents - $validated['total_cents']),
            'proposed_by_user_id' => $actor->getKey(),
            'supersedes_revision_id' => $supersedesRevisionId,
            'correction_reason' => $correctionReason,
        ]);

        $this->storeLines($revision, $validated);

        return $revision;
    }

    /**
     * @param  array{rows: list<array{fund_id: int, emission_id: int, cents: int}>, total_cents: int}  $validated
     */
    private function storeLines(FundAllocationRevision $revision, array $validated): void
    {
        foreach ($validated['rows'] as $row) {
            FundAllocationLine::query()->create([
                'fund_allocation_revision_id' => $revision->getKey(),
                'fund_id' => $row['fund_id'],
                'emission_id' => $row['emission_id'],
                'amount' => FundAllocationMoney::fromCents($row['cents']),
            ]);
        }
    }

    /**
     * Resolve the period row, creating it on first use, and hold it locked
     * for the surrounding transaction. Concurrent creators race on the
     * unique key; the loser re-reads the winner's row under lock.
     */
    private function resolvePeriodForUpdate(BankApplication $application, string $competenceDate): FundAllocationPeriod
    {
        $period = FundAllocationPeriod::query()
            ->where('bank_application_id', $application->getKey())
            ->whereDate('competence', $competenceDate)
            ->lockForUpdate()
            ->first();

        if ($period instanceof FundAllocationPeriod) {
            return $period;
        }

        try {
            return FundAllocationPeriod::query()->create([
                'bank_application_id' => $application->getKey(),
                'competence' => $competenceDate,
            ]);
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            return FundAllocationPeriod::query()
                ->where('bank_application_id', $application->getKey())
                ->whereDate('competence', $competenceDate)
                ->lockForUpdate()
                ->firstOrFail();
        }
    }

    private function lockPeriod(FundAllocationRevision $revision): FundAllocationPeriod
    {
        return FundAllocationPeriod::query()
            ->whereKey($revision->fund_allocation_period_id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockRevision(FundAllocationRevision $revision): FundAllocationRevision
    {
        return FundAllocationRevision::query()
            ->whereKey($revision->getKey())
            ->lockForUpdate()
            ->with(['snapshot', 'period'])
            ->firstOrFail();
    }

    private function lockApplication(int $applicationId): BankApplication
    {
        return BankApplication::query()
            ->whereKey($applicationId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Lock the period's application row before the period itself.
     *
     * The application id comes from an unlocked preliminary read — ids are
     * immutable, so it can only identify the row to lock. Correctness never
     * depends on it: every decision is re-validated after the locks below.
     */
    private function lockApplicationForRevision(FundAllocationRevision $revision): BankApplication
    {
        $applicationId = FundAllocationPeriod::query()
            ->whereKey($revision->fund_allocation_period_id)
            ->value('bank_application_id');

        return $this->lockApplication((int) $applicationId);
    }

    /**
     * @param  Collection<int, FundAllocationLine>  $lines
     * @return Collection<int, Fund>
     */
    private function lockedFundsForLines($lines)
    {
        if ($lines->isEmpty()) {
            return collect();
        }

        return Fund::query()
            ->whereIn('id', $lines->pluck('fund_id')->unique()->values()->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    /**
     * Re-validate stored lines against the Funds' current linkage.
     *
     * Shared by submission and approval so a stale draft is blocked before
     * reaching pending review, and a Fund edit committed after submission
     * still rejects the approval. Reads are locked (current), never a
     * repeatable-read snapshot from before a concurrent Fund edit.
     */
    private function assertLinesMatchCurrentLinkage(FundAllocationPeriod $period, FundAllocationRevision $revision): void
    {
        $lines = $revision->lines()->orderBy('fund_id')->get();
        $funds = $this->lockedFundsForLines($lines);

        foreach ($lines as $line) {
            $fund = $funds->get($line->fund_id);

            if (! $fund instanceof Fund) {
                throw FundAllocationException::invalidLines(
                    "O fundo #{$line->fund_id} não existe mais. Descarte esta revisão e proponha novamente.",
                    ['fund_id' => $line->fund_id]
                );
            }

            if ((int) $fund->bank_application_id !== (int) $period->bank_application_id) {
                throw FundAllocationException::invalidLines(
                    "O fundo #{$fund->getKey()} não está mais vinculado a esta aplicação bancária. Descarte esta revisão e proponha novamente.",
                    ['fund_id' => $fund->getKey()]
                );
            }

            if ((int) $fund->emission_id !== (int) $line->emission_id) {
                throw FundAllocationException::invalidLines(
                    "O fundo #{$fund->getKey()} mudou de operação após a proposta. Descarte esta revisão e proponha novamente.",
                    ['fund_id' => $fund->getKey()]
                );
            }
        }
    }

    private function freshRevision(FundAllocationRevision $revision): FundAllocationRevision
    {
        return FundAllocationRevision::query()->whereKey($revision->getKey())->firstOrFail();
    }

    /**
     * The snapshot must belong to this (application, competence) and still be
     * the current version: drafting against a superseded reference would only
     * fail later at approval.
     */
    private function requireCurrentSnapshot(BankApplication $application, string $competenceDate, int $snapshotId): BankApplicationBalanceSnapshot
    {
        $snapshot = BankApplicationBalanceSnapshot::query()->whereKey($snapshotId)->first();

        if (! $snapshot instanceof BankApplicationBalanceSnapshot
            || (int) $snapshot->bank_application_id !== (int) $application->getKey()
            || $snapshot->competence->toDateString() !== $competenceDate
        ) {
            throw FundAllocationException::invalid('O saldo de referência informado não pertence a esta aplicação e competência.');
        }

        // Locked (current) read, last in the lock order: under REPEATABLE READ
        // an unlocked read could miss a reference version committed by a
        // concurrent recording after this transaction's first read.
        $current = $this->currentSnapshotQuery($application, $competenceDate)->lockForUpdate()->first();

        if (! $current instanceof BankApplicationBalanceSnapshot || (int) $current->getKey() !== (int) $snapshot->getKey()) {
            throw FundAllocationException::staleReference();
        }

        return $snapshot;
    }

    private function requireSnapshotStillCurrent(FundAllocationPeriod $period, FundAllocationRevision $revision): void
    {
        $current = $this->currentSnapshotQuery($period->bankApplication()->firstOrFail(), $period->competence->toDateString())->lockForUpdate()->first();

        if (! $current instanceof BankApplicationBalanceSnapshot
            || (int) $current->getKey() !== (int) $revision->bank_application_balance_snapshot_id
        ) {
            throw FundAllocationException::staleReference();
        }
    }

    /**
     * @return Builder<BankApplicationBalanceSnapshot>
     */
    private function currentSnapshotQuery(BankApplication $application, string $competenceDate): Builder
    {
        return BankApplicationBalanceSnapshot::query()
            ->where('bank_application_id', $application->getKey())
            ->whereDate('competence', $competenceDate)
            ->orderByDesc('version');
    }

    /**
     * Correction drafts are governed by the correction permission; first
     * drafts by the creation permission.
     */
    private function draftPermission(FundAllocationRevision $revision): string
    {
        return $revision->isCorrection() ? self::PERMISSION_CORRECT : self::PERMISSION_CREATE;
    }

    private function requirePermission(User $actor, string $permission, string $action): void
    {
        if (! $actor->can($permission)) {
            throw FundAllocationException::forbidden($action);
        }
    }

    private function toCents(string|int|float $value, string $field): int
    {
        try {
            return FundAllocationMoney::toCents($value);
        } catch (InvalidArgumentException $exception) {
            throw FundAllocationException::invalid("Valor inválido para {$field}.");
        }
    }

    /**
     * Display formatting without floating point: thousands-separated BRL.
     */
    private function formatCents(int $cents): string
    {
        $plain = FundAllocationMoney::fromCents($cents);
        $sign = str_starts_with($plain, '-') ? '-' : '';
        [$units, $fraction] = explode('.', ltrim($plain, '-'));
        $grouped = implode('.', str_split(strrev($units), 3));
        $grouped = strrev($grouped);

        return "R\$ {$sign}{$grouped},{$fraction}";
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return in_array($exception->errorInfo[1] ?? null, [1062, 19], true)
            || ($exception->getCode() === '23000');
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function auditRevision(FundAllocationRevision $revision, User $actor, string $event, array $extra): void
    {
        $period = $revision->period()->firstOrFail();
        $snapshot = $revision->snapshot()->firstOrFail();

        activity(self::AUDIT_LOG)
            ->performedOn($revision)
            ->causedBy($actor)
            ->withProperties(array_merge([
                'event' => $event,
                'bank_application_id' => $period->bank_application_id,
                'competence' => $period->competence->toDateString(),
                'fund_allocation_period_id' => $period->getKey(),
                'revision_id' => $revision->getKey(),
                'revision_number' => $revision->revision_number,
                'status' => $revision->status,
                'snapshot_id' => $snapshot->getKey(),
                'snapshot_version' => $snapshot->version,
                'reference_amount' => (string) $revision->reference_amount,
                'total_amount' => (string) $revision->total_amount,
                'unallocated_amount' => (string) $revision->unallocated_amount,
                'line_count' => $revision->lines()->count(),
                'proposed_by_user_id' => $revision->proposed_by_user_id,
                'submitted_by_user_id' => $revision->submitted_by_user_id,
                'approved_by_user_id' => $revision->approved_by_user_id,
                'supersedes_revision_id' => $revision->supersedes_revision_id,
                'correction_reason' => $revision->correction_reason,
            ], $extra))
            ->log($event);
    }

    private function auditApprovalRejection(
        FundAllocationRevision $revision,
        User $actor,
        FundAllocationException $exception,
        string $idempotencyKey,
    ): void {
        $stored = FundAllocationRevision::query()->whereKey($revision->getKey())->with('period')->first();

        if (! $stored instanceof FundAllocationRevision || ! $stored->period instanceof FundAllocationPeriod) {
            return;
        }

        activity(self::AUDIT_LOG)
            ->performedOn($stored)
            ->causedBy($actor)
            ->withProperties([
                'event' => 'approval_rejected',
                'bank_application_id' => $stored->period->bank_application_id,
                'competence' => $stored->period->competence->toDateString(),
                'fund_allocation_period_id' => $stored->period->getKey(),
                'revision_id' => $stored->getKey(),
                'revision_number' => $stored->revision_number,
                'status' => $stored->status,
                'reason' => $exception->getMessage(),
                'idempotency_key' => $idempotencyKey,
            ])
            ->log('approval_rejected');
    }
}

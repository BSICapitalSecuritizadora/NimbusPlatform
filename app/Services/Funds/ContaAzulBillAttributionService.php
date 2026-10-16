<?php

namespace App\Services\Funds;

use App\Exceptions\Funds\ContaAzulReconciliationException;
use App\Models\ContaAzulBillAttribution;
use App\Models\ContaAzulImportedBill;
use App\Models\Emission;
use App\Models\ExpenseHistory;
use App\Models\User;
use App\Support\BankIdentifierMasker;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Governed Emission attribution for staged Conta Azul bills (Phase 3B).
 *
 * A staged bill is external evidence, not an operational Expense: recording
 * a decision here sets the bill's single effective attribution and appends
 * one row to the attribution history. It never creates, updates or deletes
 * an Expense or ExpenseHistory, never touches allocations, balances or
 * Guarantee inputs, and never infers ownership from BankAccount sharing or
 * from allocation percentages.
 *
 * Every entry point enforces its permission at this layer — the Filament UI
 * is convenience, not protection — and runs inside a row-locked
 * transaction: two users racing the same bill serialize, the loser sees the
 * winner's committed decision, and repeated submissions resolve to the
 * existing event instead of duplicating it.
 *
 * Decisions pin the source version they were taken against
 * (`source_fingerprint`). A reviewer always decides against the fingerprint
 * shown on screen; when the source moved meanwhile, the decision is
 * refused and the reviewer reopens the current evidence.
 */
class ContaAzulBillAttributionService
{
    public const AUDIT_LOG = 'conta_azul_imports';

    public const PERMISSION_ATTRIBUTE = 'conta-azul-imports.attribute';

    public const PERMISSION_CORRECT = 'conta-azul-imports.correct';

    public const MIN_REASON_LENGTH = 10;

    public const MIN_EXCEPTIONAL_REASON_LENGTH = 20;

    /**
     * Attribute a pending bill to an Emission.
     *
     * @param  array{allow_incomplete?: bool, incomplete_exception_reason?: ?string, exceptional_confirmed?: bool}  $options
     */
    public function attribute(
        User $actor,
        ContaAzulImportedBill $bill,
        Emission $emission,
        string $reason,
        string $expectedFingerprint,
        array $options = [],
    ): ContaAzulBillAttribution {
        $this->requirePermission($actor, self::PERMISSION_ATTRIBUTE, 'atribuir títulos importados');

        $allowIncomplete = (bool) ($options['allow_incomplete'] ?? false);
        $exceptionReason = $options['incomplete_exception_reason'] ?? null;
        $exceptionalConfirmed = (bool) ($options['exceptional_confirmed'] ?? false);

        return DB::transaction(function () use ($actor, $bill, $emission, $reason, $expectedFingerprint, $allowIncomplete, $exceptionReason, $exceptionalConfirmed): ContaAzulBillAttribution {
            $locked = ContaAzulImportedBill::query()->whereKey($bill->getKey())->lockForUpdate()->firstOrFail();

            $this->requireFreshEvidence($locked, $expectedFingerprint);

            $key = ContaAzulBillAttribution::idempotencyKey(
                (int) $locked->getKey(),
                ContaAzulBillAttribution::ACTION_ATTRIBUTED,
                (int) $emission->getKey(),
                $locked->payload_fingerprint,
                $reason,
            );

            $existing = ContaAzulBillAttribution::query()->where('idempotency_key', $key)->first();

            if ($existing instanceof ContaAzulBillAttribution) {
                return $existing;
            }

            if ($locked->isAttributed()) {
                throw ContaAzulReconciliationException::alreadyAttributed();
            }

            if ($locked->reconciliation_status === ContaAzulImportedBill::RECONCILIATION_INSUFFICIENT_INFORMATION) {
                throw ContaAzulReconciliationException::billNotReady($locked->reconciliation_status);
            }

            if ($locked->reconciliation_status !== ContaAzulImportedBill::RECONCILIATION_PENDING
                && $locked->reconciliation_status !== ContaAzulImportedBill::RECONCILIATION_READY_FOR_REVIEW) {
                throw ContaAzulReconciliationException::billNotReady($locked->reconciliation_status);
            }

            if ($locked->import_status === ContaAzulImportedBill::IMPORT_ERROR) {
                throw ContaAzulReconciliationException::billNotReady('erro de importação');
            }

            $exceptional = ! $this->isLinkedToAccount($locked, (int) $emission->getKey());
            $this->requireEmissionAccess($actor, $emission, $exceptional);
            $this->requireReason($reason, $exceptional);

            if ($exceptional && ! $exceptionalConfirmed) {
                throw ContaAzulReconciliationException::exceptionalAttributionRequiresJustification();
            }

            $incompleteException = null;

            if (! $locked->hasVerifiedContent()) {
                if (! $allowIncomplete) {
                    throw ContaAzulReconciliationException::incompleteImport();
                }

                if (! is_string($exceptionReason) || mb_strlen(trim($exceptionReason)) < self::MIN_REASON_LENGTH) {
                    throw ContaAzulReconciliationException::reasonRequired(self::MIN_REASON_LENGTH);
                }

                $incompleteException = trim($exceptionReason);
            }

            $attribution = $this->recordDecision($locked, [
                'action' => ContaAzulBillAttribution::ACTION_ATTRIBUTED,
                'from_emission_id' => null,
                'to_emission_id' => $emission->getKey(),
                'decided_by_user_id' => $actor->getKey(),
                'reason' => trim($reason),
                'source_fingerprint' => $locked->payload_fingerprint,
                'is_exceptional' => $exceptional,
                'idempotency_key' => $key,
            ]);

            $locked->update([
                'attributed_emission_id' => $emission->getKey(),
                'attribution_source_fingerprint' => $locked->payload_fingerprint,
                'attributed_by_user_id' => $actor->getKey(),
                'attributed_at' => now(),
                'attribution_reason' => trim($reason),
                'reconciliation_status' => ContaAzulImportedBill::RECONCILIATION_ATTRIBUTED,
                'needs_re_review' => false,
                're_review_reason' => null,
            ]);

            $legacy = $this->legacyDisagreement($locked, (int) $emission->getKey());

            $this->audit($actor, $locked, 'bill_attributed', [
                'attribution_id' => $attribution->getKey(),
                'to_emission_id' => $emission->getKey(),
                'source_fingerprint' => $locked->payload_fingerprint,
                'is_exceptional' => $exceptional,
                'incomplete_exception_reason' => $incompleteException,
                'legacy_disagreement' => $legacy !== null ? $legacy : false,
            ]);

            return $attribution->refresh();
        });
    }

    /**
     * Correct the effective attribution through a governed action.
     *
     * The previous decision is preserved verbatim in the append-only
     * history; only the effective columns move. Correcting to the same
     * Emission re-confirms the decision against the current source version
     * and clears a pending renewed review.
     *
     * @param  array{exceptional_confirmed?: bool}  $options
     */
    public function correct(
        User $actor,
        ContaAzulImportedBill $bill,
        Emission $emission,
        string $reason,
        string $expectedFingerprint,
        int $expectedAttributionId,
        array $options = [],
    ): ContaAzulBillAttribution {
        $this->requirePermission($actor, self::PERMISSION_CORRECT, 'corrigir atribuições de títulos');

        $exceptionalConfirmed = (bool) ($options['exceptional_confirmed'] ?? false);

        return DB::transaction(function () use ($actor, $bill, $emission, $reason, $expectedFingerprint, $expectedAttributionId, $exceptionalConfirmed): ContaAzulBillAttribution {
            $locked = ContaAzulImportedBill::query()->whereKey($bill->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isAttributed()) {
                throw ContaAzulReconciliationException::notAttributed();
            }

            $this->requireFreshEvidence($locked, $expectedFingerprint);

            $latest = $locked->latestAttribution();

            if (! $latest instanceof ContaAzulBillAttribution || (int) $latest->getKey() !== $expectedAttributionId) {
                throw ContaAzulReconciliationException::staleDecision();
            }

            $key = ContaAzulBillAttribution::idempotencyKey(
                (int) $locked->getKey(),
                ContaAzulBillAttribution::ACTION_CORRECTED,
                (int) $emission->getKey(),
                $locked->payload_fingerprint,
                $reason,
            );

            $existing = ContaAzulBillAttribution::query()->where('idempotency_key', $key)->first();

            if ($existing instanceof ContaAzulBillAttribution) {
                return $existing;
            }

            $exceptional = ! $this->isLinkedToAccount($locked, (int) $emission->getKey());
            $this->requireEmissionAccess($actor, $emission, $exceptional);
            $this->requireReason($reason, $exceptional);

            if ($exceptional && ! $exceptionalConfirmed) {
                throw ContaAzulReconciliationException::exceptionalAttributionRequiresJustification();
            }

            $attribution = $this->recordDecision($locked, [
                'action' => ContaAzulBillAttribution::ACTION_CORRECTED,
                'from_emission_id' => $locked->attributed_emission_id,
                'to_emission_id' => $emission->getKey(),
                'decided_by_user_id' => $actor->getKey(),
                'reason' => trim($reason),
                'source_fingerprint' => $locked->payload_fingerprint,
                'is_exceptional' => $exceptional,
                'idempotency_key' => $key,
            ]);

            $locked->update([
                'attributed_emission_id' => $emission->getKey(),
                'attribution_source_fingerprint' => $locked->payload_fingerprint,
                'attributed_by_user_id' => $actor->getKey(),
                'attributed_at' => now(),
                'attribution_reason' => trim($reason),
                'needs_re_review' => false,
                're_review_reason' => null,
            ]);

            $legacy = $this->legacyDisagreement($locked, (int) $emission->getKey());

            $this->audit($actor, $locked, 'bill_attribution_corrected', [
                'attribution_id' => $attribution->getKey(),
                'from_emission_id' => $attribution->from_emission_id,
                'to_emission_id' => $emission->getKey(),
                'source_fingerprint' => $locked->payload_fingerprint,
                'is_exceptional' => $exceptional,
                'legacy_disagreement' => $legacy !== null ? $legacy : false,
            ]);

            return $attribution->refresh();
        });
    }

    /**
     * Flag a bill as missing critical information. Reviewable exception:
     * the bill leaves the attributable states until it is ready.
     */
    public function markInsufficient(User $actor, ContaAzulImportedBill $bill, string $note): ContaAzulImportedBill
    {
        $this->requirePermission($actor, self::PERMISSION_ATTRIBUTE, 'revisar títulos importados');

        if (mb_strlen(trim($note)) < self::MIN_REASON_LENGTH) {
            throw ContaAzulReconciliationException::reasonRequired(self::MIN_REASON_LENGTH);
        }

        return DB::transaction(function () use ($actor, $bill, $note): ContaAzulImportedBill {
            $locked = ContaAzulImportedBill::query()->whereKey($bill->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isAttributed()) {
                throw ContaAzulReconciliationException::invalidTransition('títulos atribuídos mudam apenas por correção governada');
            }

            if ($locked->reconciliation_status === ContaAzulImportedBill::RECONCILIATION_INSUFFICIENT_INFORMATION) {
                return $locked;
            }

            $locked->update([
                'reconciliation_status' => ContaAzulImportedBill::RECONCILIATION_INSUFFICIENT_INFORMATION,
                'reconciliation_note' => trim($note),
            ]);

            $this->audit($actor, $locked, 'bill_marked_insufficient', []);

            return $locked->refresh();
        });
    }

    /**
     * Mark a reviewed bill as ready for attribution.
     */
    public function markReady(User $actor, ContaAzulImportedBill $bill, ?string $note = null): ContaAzulImportedBill
    {
        $this->requirePermission($actor, self::PERMISSION_ATTRIBUTE, 'revisar títulos importados');

        return DB::transaction(function () use ($actor, $bill, $note): ContaAzulImportedBill {
            $locked = ContaAzulImportedBill::query()->whereKey($bill->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isAttributed()) {
                throw ContaAzulReconciliationException::invalidTransition('títulos atribuídos mudam apenas por correção governada');
            }

            if ($locked->reconciliation_status === ContaAzulImportedBill::RECONCILIATION_READY_FOR_REVIEW) {
                return $locked;
            }

            $locked->update([
                'reconciliation_status' => ContaAzulImportedBill::RECONCILIATION_READY_FOR_REVIEW,
                'reconciliation_note' => $note !== null && trim($note) !== '' ? trim($note) : $locked->reconciliation_note,
            ]);

            $this->audit($actor, $locked, 'bill_marked_ready', []);

            return $locked->refresh();
        });
    }

    /**
     * Contextual Emission candidates: Emissions linked to the bill's
     * BankAccount that the actor may see. Context only — choosing one still
     * is an explicit, justified decision, never an inference.
     *
     * @return Collection<int, Emission>
     */
    public function candidateEmissions(User $actor, ContaAzulImportedBill $bill): Collection
    {
        if (! $actor->can('emissions.view')) {
            return collect();
        }

        $account = $bill->bankAccount()->firstOrFail();

        return $account->linkedEmissions()->filter(
            fn (Emission $emission): bool => $actor->can('view', $emission)
        )->values();
    }

    /**
     * Whether the legacy operational workflow already represents this bill,
     * and whether its Emission disagrees with the given target. Read-only:
     * describing the disagreement never rewrites the legacy row.
     *
     * @return ?array{legacy_expense_id: int, legacy_emission_id: ?int}
     */
    public function legacyDisagreement(ContaAzulImportedBill $bill, int $targetEmissionId): ?array
    {
        $history = $bill->findLegacyHistory();

        if (! $history instanceof ExpenseHistory) {
            return null;
        }

        $legacyEmissionId = $history->expense()->value('emission_id');

        if ($legacyEmissionId === null || (int) $legacyEmissionId === $targetEmissionId) {
            return null;
        }

        return [
            'legacy_expense_id' => (int) $history->expense_id,
            'legacy_emission_id' => (int) $legacyEmissionId,
        ];
    }

    private function isLinkedToAccount(ContaAzulImportedBill $bill, int $emissionId): bool
    {
        $account = $bill->bankAccount()->firstOrFail();

        return (clone $account->linkedFundQuery())->where('emission_id', $emissionId)->exists();
    }

    private function requireEmissionAccess(User $actor, Emission $emission, bool $exceptional): void
    {
        if ($exceptional) {
            if (! $actor->can('update', $emission)) {
                throw ContaAzulReconciliationException::forbidden('atribuir títulos a emissões fora da conta (exige emissões: editar)');
            }

            return;
        }

        if (! $actor->can('view', $emission)) {
            throw ContaAzulReconciliationException::emissionNotAccessible();
        }
    }

    private function requireReason(string $reason, bool $exceptional): void
    {
        $minimum = $exceptional ? self::MIN_EXCEPTIONAL_REASON_LENGTH : self::MIN_REASON_LENGTH;

        if (mb_strlen(trim($reason)) < $minimum) {
            throw ContaAzulReconciliationException::reasonRequired($minimum);
        }
    }

    private function requireFreshEvidence(ContaAzulImportedBill $bill, string $expectedFingerprint): void
    {
        if (trim($expectedFingerprint) === '' || ! hash_equals($bill->payload_fingerprint, trim($expectedFingerprint))) {
            throw ContaAzulReconciliationException::staleSourceVersion();
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function recordDecision(ContaAzulImportedBill $bill, array $attributes): ContaAzulBillAttribution
    {
        $attributes['conta_azul_imported_bill_id'] = $bill->getKey();
        $attributes['decided_at'] = now();

        try {
            return ContaAzulBillAttribution::query()->create($attributes);
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            $existing = ContaAzulBillAttribution::query()
                ->where('idempotency_key', $attributes['idempotency_key'])
                ->first();

            if ($existing instanceof ContaAzulBillAttribution) {
                return $existing;
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function audit(User $actor, ContaAzulImportedBill $bill, string $event, array $properties): void
    {
        activity(self::AUDIT_LOG)
            ->performedOn($bill)
            ->causedBy($actor)
            ->withProperties(array_merge([
                'event' => $event,
                'imported_bill_id' => $bill->getKey(),
                'conta_azul_bill_id' => $bill->maskedBillId(),
                'bank_account_id' => $bill->bank_account_id,
                'conta_azul_account_id' => BankIdentifierMasker::maskContaAzulId($bill->conta_azul_account_id),
                'reconciliation_status' => $bill->reconciliation_status,
                'attributed_emission_id' => $bill->attributed_emission_id,
                'payload_fingerprint' => $bill->payload_fingerprint,
            ], $properties))
            ->log($event);
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return in_array($exception->errorInfo[1] ?? null, [1062, 19], true)
            || ($exception->getCode() === '23000');
    }

    private function requirePermission(User $actor, string $permission, string $action): void
    {
        if (! $actor->can($permission)) {
            throw ContaAzulReconciliationException::forbidden($action);
        }
    }
}

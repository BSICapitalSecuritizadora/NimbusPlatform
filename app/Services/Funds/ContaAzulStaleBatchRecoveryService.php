<?php

namespace App\Services\Funds;

use App\Exceptions\Funds\ContaAzulReconciliationException;
use App\Models\ContaAzulImportBatch;
use App\Models\User;
use App\Support\BankIdentifierMasker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Safe recovery for stale Conta Azul import batches (Phase 3B).
 *
 * A process killed by SIGKILL can leave a batch in `running` forever. This
 * service moves genuinely abandoned batches to the terminal, retriable
 * `abandoned` status — with actor, reason, timestamp and previous status
 * recorded — so a retry can reference them. It never deletes staged bills,
 * never marks an incomplete batch completed, and never touches an import
 * with recent activity: freshness is proven by the heartbeat the ingestion
 * pipeline writes on creation, every page and finalization.
 *
 * Recovery runs only when explicitly invoked (Filament action or Artisan
 * command). Phase 3B wires no background schedule.
 */
class ContaAzulStaleBatchRecoveryService
{
    public const AUDIT_LOG = 'conta_azul_imports';

    public const PERMISSION_RECOVER = 'conta-azul-imports.recover';

    public const MIN_REASON_LENGTH = 10;

    /**
     * Minutes of silence after which a running batch counts as abandoned.
     */
    public function staleTimeoutMinutes(): int
    {
        $configured = config('conta-azul.stale_batch_timeout_minutes', 60);

        if (! is_numeric($configured) || (int) $configured < 1) {
            return 60;
        }

        return (int) $configured;
    }

    /**
     * Running batches whose heartbeat expired. Ordered oldest first so the
     * longest-abandoned work is recovered first.
     *
     * @return Builder<ContaAzulImportBatch>
     */
    public function staleBatches(): Builder
    {
        $cutoff = now()->subMinutes($this->staleTimeoutMinutes());

        return ContaAzulImportBatch::query()
            ->where('status', ContaAzulImportBatch::STATUS_RUNNING)
            ->where(function (Builder $query) use ($cutoff): void {
                $query->where('last_heartbeat_at', '<', $cutoff)
                    ->orWhere(function (Builder $nested) use ($cutoff): void {
                        $nested->whereNull('last_heartbeat_at')->where('started_at', '<', $cutoff);
                    })
                    ->orWhere(function (Builder $nested) use ($cutoff): void {
                        $nested->whereNull('last_heartbeat_at')->whereNull('started_at')->where('created_at', '<', $cutoff);
                    });
            })
            ->orderBy('id');
    }

    /**
     * Recover one abandoned batch. The row lock serializes concurrent
     * recoveries and races against a finishing import: whoever commits
     * first wins, the other sees the new state and is refused.
     */
    public function recover(User $actor, ContaAzulImportBatch $batch, string $reason): ContaAzulImportBatch
    {
        if (! $actor->can(self::PERMISSION_RECOVER)) {
            throw ContaAzulReconciliationException::forbidden('recuperar importações interrompidas');
        }

        if (mb_strlen(trim($reason)) < self::MIN_REASON_LENGTH) {
            throw ContaAzulReconciliationException::reasonRequired(self::MIN_REASON_LENGTH);
        }

        return DB::transaction(function () use ($actor, $batch, $reason): ContaAzulImportBatch {
            $locked = ContaAzulImportBatch::query()->whereKey($batch->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== ContaAzulImportBatch::STATUS_RUNNING) {
                throw ContaAzulReconciliationException::batchNotRecoverable($locked->status);
            }

            if (! $locked->isStale($this->staleTimeoutMinutes())) {
                throw ContaAzulReconciliationException::batchActive();
            }

            $locked->update([
                'status' => ContaAzulImportBatch::STATUS_ABANDONED,
                'error_code' => 'stale_recovered',
                'error_message' => mb_substr('Importação interrompida recuperada: '.trim($reason), 0, 2000),
                'finished_at' => now(),
                'recovered_by_user_id' => $actor->getKey(),
                'recovered_at' => now(),
                'recovered_from_status' => ContaAzulImportBatch::STATUS_RUNNING,
                'recovery_reason' => trim($reason),
            ]);

            activity(self::AUDIT_LOG)
                ->performedOn($locked)
                ->causedBy($actor)
                ->withProperties([
                    'event' => 'import_batch_recovered',
                    'import_batch_id' => $locked->getKey(),
                    'bank_account_id' => $locked->bank_account_id,
                    'conta_azul_account_id' => BankIdentifierMasker::maskContaAzulId($locked->conta_azul_account_id),
                    'previous_status' => ContaAzulImportBatch::STATUS_RUNNING,
                    'status' => ContaAzulImportBatch::STATUS_ABANDONED,
                    'pages_completed' => $locked->pages_completed,
                    'records_received' => $locked->records_received,
                    'bills_staged' => $locked->bills_staged,
                    'bills_updated' => $locked->bills_updated,
                    'bills_reobserved' => $locked->bills_reobserved,
                    'stale_timeout_minutes' => $this->staleTimeoutMinutes(),
                    'reason' => mb_substr(trim($reason), 0, 2000),
                ])
                ->log('import_batch_recovered');

            return $locked->refresh();
        });
    }
}

<?php

namespace App\Models;

use App\Support\BankIdentifierMasker;
use Carbon\CarbonInterface;
use Database\Factories\ContaAzulImportBatchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One account-level Conta Azul ingestion attempt (Phase 3A staging).
 *
 * A batch fetches bills for exactly one physical Conta Azul financial
 * account — identified through its BankAccount — over an explicit date
 * range. It is never per-Fund and never per-Emission: sharing a BankAccount
 * across Funds or Emissions does not multiply fetches.
 *
 * Lifecycle: pending → running → completed | partial | failed, plus the
 * Phase 3B `abandoned` terminal state for recovered stale batches. Terminal
 * states are final; a retry is a NEW batch with `retries_batch_id` pointing
 * back. `completed` additionally guarantees the page stream ended without
 * failure and records_received reached the advertised total — incomplete
 * retrieval is never presented as completed.
 *
 * Staging only: no operational reader (Expense, Guarantee, allocation,
 * report) touches this table.
 */
class ContaAzulImportBatch extends Model
{
    /** @use HasFactory<ContaAzulImportBatchFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_FAILED = 'failed';

    public const STATUS_ABANDONED = 'abandoned';

    /**
     * @var array<string, string>
     */
    public const STATUS_OPTIONS = [
        self::STATUS_PENDING => 'Pendente',
        self::STATUS_RUNNING => 'Em execução',
        self::STATUS_COMPLETED => 'Concluído',
        self::STATUS_PARTIAL => 'Parcial',
        self::STATUS_FAILED => 'Falhou',
        self::STATUS_ABANDONED => 'Abandonado (recuperado)',
    ];

    /**
     * @var list<string>
     */
    public const TERMINAL_STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_PARTIAL,
        self::STATUS_FAILED,
        self::STATUS_ABANDONED,
    ];

    /**
     * @var list<string>
     */
    public const RETRIABLE_STATUSES = [
        self::STATUS_PARTIAL,
        self::STATUS_FAILED,
        self::STATUS_ABANDONED,
    ];

    protected $fillable = [
        'bank_account_id',
        'conta_azul_account_id',
        'integration_context',
        'date_from',
        'date_to',
        'status',
        'pages_requested',
        'pages_completed',
        'records_received',
        'bills_staged',
        'bills_updated',
        'bills_skipped',
        'bills_reobserved',
        'error_code',
        'error_message',
        'retries_batch_id',
        'requested_by_user_id',
        'started_at',
        'finished_at',
        'last_heartbeat_at',
        'recovered_by_user_id',
        'recovered_at',
        'recovered_from_status',
        'recovery_reason',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    protected function casts(): array
    {
        return [
            'date_from' => 'date',
            'date_to' => 'date',
            'pages_requested' => 'integer',
            'pages_completed' => 'integer',
            'records_received' => 'integer',
            'bills_staged' => 'integer',
            'bills_updated' => 'integer',
            'bills_skipped' => 'integer',
            'bills_reobserved' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
            'recovered_at' => 'datetime',
        ];
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function retriesBatch(): BelongsTo
    {
        return $this->belongsTo(self::class, 'retries_batch_id');
    }

    public function retries(): HasMany
    {
        return $this->hasMany(self::class, 'retries_batch_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /**
     * Bills first seen by this batch.
     */
    public function bills(): HasMany
    {
        return $this->hasMany(ContaAzulImportedBill::class);
    }

    /**
     * Bills observed by this batch, first-seen or reimported.
     */
    public function observedBills(): HasMany
    {
        return $this->hasMany(ContaAzulImportedBill::class, 'last_seen_batch_id');
    }

    /**
     * Bills whose current content this batch established.
     */
    public function contentBills(): HasMany
    {
        return $this->hasMany(ContaAzulImportedBill::class, 'content_batch_id');
    }

    public function recoveredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recovered_by_user_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    public function isRetriable(): bool
    {
        return in_array($this->status, self::RETRIABLE_STATUSES, true);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isAbandoned(): bool
    {
        return $this->status === self::STATUS_ABANDONED;
    }

    /**
     * Most recent proof of life: heartbeat, then start, then creation.
     */
    public function lastActivityAt(): ?CarbonInterface
    {
        return $this->last_heartbeat_at ?? $this->started_at ?? $this->created_at;
    }

    /**
     * Whether this running batch is abandoned work rather than an active
     * import. Only `running` batches can be stale; anything else answers
     * false regardless of age.
     */
    public function isStale(int $timeoutMinutes): bool
    {
        if ($this->status !== self::STATUS_RUNNING) {
            return false;
        }

        $activity = $this->lastActivityAt();

        if (! $activity instanceof CarbonInterface) {
            return false;
        }

        return $activity->diffInMinutes(now()) >= $timeoutMinutes;
    }

    /**
     * Masked financial-account id for logs and audit trails.
     */
    public function maskedAccountId(): string
    {
        return BankIdentifierMasker::maskContaAzulId($this->conta_azul_account_id);
    }
}

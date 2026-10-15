<?php

namespace App\Models;

use Database\Factories\BankApplicationBalanceSnapshotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An immutable financial reference balance for a physical application.
 *
 * Phase 2A records explicitly supplied manual references only
 * ({@see self::SOURCE_MANUAL}). Future Bradesco integration adds
 * bank-confirmed rows; historical rows are never rewritten or relabeled.
 *
 * Auditing is explicit and service-driven (log `fund_allocations`), not via
 * LogsActivity: the service emits one governed event per financial action,
 * and model-level logging would duplicate that trail into `default`.
 */
class BankApplicationBalanceSnapshot extends Model
{
    /** @use HasFactory<BankApplicationBalanceSnapshotFactory> */
    use HasFactory;

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_BRADESCO = 'bradesco';

    protected $fillable = [
        'bank_application_id',
        'competence',
        'amount',
        'source',
        'captured_at',
        'recorded_by_user_id',
        'note',
        'version',
        'supersedes_snapshot_id',
    ];

    /**
     * Snapshots are immutable: only creation is allowed. Corrections are new
     * versions, never in-place edits.
     */
    protected static function booted(): void
    {
        static::updating(function (BankApplicationBalanceSnapshot $snapshot): void {
            throw new \LogicException('Reference balance snapshots are immutable; record a new version instead.');
        });

        static::deleting(function (BankApplicationBalanceSnapshot $snapshot): void {
            throw new \LogicException('Reference balance snapshots cannot be deleted; they anchor approved allocations.');
        });
    }

    protected function casts(): array
    {
        return [
            'competence' => 'date',
            'amount' => 'decimal:2',
            'captured_at' => 'datetime',
        ];
    }

    public function bankApplication(): BelongsTo
    {
        return $this->belongsTo(BankApplication::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_snapshot_id');
    }

    public function isManual(): bool
    {
        return $this->source === self::SOURCE_MANUAL;
    }

    /**
     * Human-safe source label. Manual amounts are never presented as
     * bank-confirmed balances.
     */
    public function sourceLabel(): string
    {
        return match ($this->source) {
            self::SOURCE_MANUAL => 'Manual (informado pelo usuário)',
            self::SOURCE_BRADESCO => 'Bradesco (confirmado pelo banco)',
            default => (string) $this->source,
        };
    }
}

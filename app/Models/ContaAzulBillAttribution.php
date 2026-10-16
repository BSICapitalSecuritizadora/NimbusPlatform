<?php

namespace App\Models;

use Database\Factories\ContaAzulBillAttributionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Emission attribution decision for a staged Conta Azul bill (Phase 3B).
 *
 * Append-only: the first row records the attribution, every later row
 * records a governed correction. Rows are never updated or deleted, so the
 * bill's effective decision (`attributed_emission_id` on the bill) always
 * has its full `who-decided-what-when-and-why` trail behind it.
 *
 * `source_fingerprint` pins the source version the decision was taken
 * against. Staging-only: recording a decision never creates or rewrites an
 * operational Expense or ExpenseHistory.
 */
class ContaAzulBillAttribution extends Model
{
    /** @use HasFactory<ContaAzulBillAttributionFactory> */
    use HasFactory;

    public const ACTION_ATTRIBUTED = 'attributed';

    public const ACTION_CORRECTED = 'corrected';

    /**
     * @var array<string, string>
     */
    public const ACTION_OPTIONS = [
        self::ACTION_ATTRIBUTED => 'Atribuído',
        self::ACTION_CORRECTED => 'Corrigido',
    ];

    protected $fillable = [
        'conta_azul_imported_bill_id',
        'action',
        'from_emission_id',
        'to_emission_id',
        'decided_by_user_id',
        'decided_at',
        'reason',
        'source_fingerprint',
        'is_exceptional',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
            'is_exceptional' => 'boolean',
        ];
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(ContaAzulImportedBill::class, 'conta_azul_imported_bill_id');
    }

    public function fromEmission(): BelongsTo
    {
        return $this->belongsTo(Emission::class, 'from_emission_id');
    }

    public function toEmission(): BelongsTo
    {
        return $this->belongsTo(Emission::class, 'to_emission_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    /**
     * Deterministic idempotency key: repeating the same decision resolves
     * to the existing row instead of recording a duplicate event.
     */
    public static function idempotencyKey(int $billId, string $action, ?int $toEmissionId, string $sourceFingerprint, string $reason): string
    {
        return hash('sha256', implode('|', [
            $billId,
            $action,
            $toEmissionId ?? 'none',
            $sourceFingerprint,
            mb_substr(trim($reason), 0, 2000),
        ]));
    }
}

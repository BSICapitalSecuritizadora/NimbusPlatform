<?php

namespace App\Models;

use App\Support\BankIdentifierMasker;
use Database\Factories\ContaAzulImportedBillFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One staged Conta Azul bill (Phase 3A staging).
 *
 * Identity is the global external bill id (`conta_azul_bill_id`), the same
 * scope the legacy integration already uses: one bill is one row even when
 * several Funds or Emissions share the BankAccount it was fetched through.
 * Reimports are idempotent — an unchanged bill only advances
 * `last_seen_batch_id`; a changed bill (fingerprint mismatch) updates its
 * content and is traced as `import_status = updated`.
 *
 * Parentage columns record where the bill was FIRST seen and are never
 * rewritten by later imports. `reconciliation_status` starts at `pending`
 * and is only advanced by the governed Phase 3B workflow — the ingestion
 * pipeline never attributes a bill to an Emission and never creates an
 * operational Expense.
 */
class ContaAzulImportedBill extends Model
{
    /** @use HasFactory<ContaAzulImportedBillFactory> */
    use HasFactory;

    public const IMPORT_STAGED = 'staged';

    public const IMPORT_UPDATED = 'updated';

    public const IMPORT_ERROR = 'error';

    /**
     * @var array<string, string>
     */
    public const IMPORT_STATUS_OPTIONS = [
        self::IMPORT_STAGED => 'Importado',
        self::IMPORT_UPDATED => 'Atualizado',
        self::IMPORT_ERROR => 'Erro de importação',
    ];

    public const RECONCILIATION_PENDING = 'pending';

    public const RECONCILIATION_INSUFFICIENT_INFORMATION = 'insufficient_information';

    public const RECONCILIATION_READY_FOR_REVIEW = 'ready_for_review';

    public const RECONCILIATION_ATTRIBUTED = 'attributed';

    /**
     * @var array<string, string>
     */
    public const RECONCILIATION_STATUS_OPTIONS = [
        self::RECONCILIATION_PENDING => 'Aguardando atribuição',
        self::RECONCILIATION_INSUFFICIENT_INFORMATION => 'Informação insuficiente',
        self::RECONCILIATION_READY_FOR_REVIEW => 'Pronto para revisão',
        self::RECONCILIATION_ATTRIBUTED => 'Atribuído',
    ];

    protected $fillable = [
        'conta_azul_import_batch_id',
        'last_seen_batch_id',
        'content_batch_id',
        'bank_account_id',
        'conta_azul_bill_id',
        'conta_azul_account_id',
        'description',
        'total_amount',
        'paid_amount',
        'unpaid_amount',
        'due_date',
        'payment_date',
        'competence_date',
        'external_created_at',
        'external_updated_at',
        'external_status',
        'external_status_translated',
        'categories',
        'mapped_category',
        'cost_centers',
        'supplier_name',
        'supplier_external_id',
        'payload_fingerprint',
        'source_payload',
        'import_status',
        'last_import_error',
        'reconciliation_status',
        'reconciliation_note',
        'attributed_emission_id',
        'attribution_source_fingerprint',
        'attributed_by_user_id',
        'attributed_at',
        'attribution_reason',
        'needs_re_review',
        're_review_reason',
        'previous_payload_fingerprint',
    ];

    protected $attributes = [
        'import_status' => self::IMPORT_STAGED,
        'reconciliation_status' => self::RECONCILIATION_PENDING,
        'needs_re_review' => false,
    ];

    /**
     * The raw payload is provenance, not display data: it stays out of every
     * serialization boundary by default.
     *
     * @var list<string>
     */
    protected $hidden = [
        'source_payload',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'unpaid_amount' => 'decimal:2',
            'due_date' => 'date',
            'payment_date' => 'date',
            'competence_date' => 'date',
            'external_created_at' => 'datetime',
            'external_updated_at' => 'datetime',
            'categories' => 'array',
            'cost_centers' => 'array',
            'source_payload' => 'encrypted:array',
            'attributed_at' => 'datetime',
            'needs_re_review' => 'boolean',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ContaAzulImportBatch::class, 'conta_azul_import_batch_id');
    }

    public function lastSeenBatch(): BelongsTo
    {
        return $this->belongsTo(ContaAzulImportBatch::class, 'last_seen_batch_id');
    }

    /**
     * Batch that last established the current content.
     *
     * Set on creation and on every content-changing reimport; plain
     * re-observation never moves it. Completeness reads from here (or from
     * a completed re-observing batch), never blindly from first-seen.
     */
    public function contentBatch(): BelongsTo
    {
        return $this->belongsTo(ContaAzulImportBatch::class, 'content_batch_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function attributedEmission(): BelongsTo
    {
        return $this->belongsTo(Emission::class, 'attributed_emission_id');
    }

    public function attributedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attributed_by_user_id');
    }

    /**
     * Every attribution decision ever made for this bill, oldest first.
     * Append-only: the effective decision lives on the bill row.
     *
     * @return HasMany<ContaAzulBillAttribution, $this>
     */
    public function attributions(): HasMany
    {
        return $this->hasMany(ContaAzulBillAttribution::class, 'conta_azul_imported_bill_id')->orderBy('id');
    }

    public function latestAttribution(): ?ContaAzulBillAttribution
    {
        return $this->attributions()->reorder()->orderByDesc('id')->first();
    }

    public function isPendingAttribution(): bool
    {
        return $this->reconciliation_status === self::RECONCILIATION_PENDING;
    }

    public function isAttributed(): bool
    {
        return $this->reconciliation_status === self::RECONCILIATION_ATTRIBUTED
            && $this->attributed_emission_id !== null;
    }

    /**
     * Whether the current content was verified by a complete import.
     *
     * Verified means the content-establishing batch completed, or a later
     * batch re-observed the unchanged content through to completion. A bill
     * whose content only ever passed through partial or failed batches is
     * attributable solely through the justified exception path.
     */
    public function hasVerifiedContent(): bool
    {
        if ($this->import_status === self::IMPORT_ERROR) {
            return false;
        }

        $contentBatch = $this->relationLoaded('contentBatch')
            ? $this->contentBatch
            : $this->contentBatch()->first();

        if ($contentBatch instanceof ContaAzulImportBatch && $contentBatch->isCompleted()) {
            return true;
        }

        $lastSeen = $this->relationLoaded('lastSeenBatch')
            ? $this->lastSeenBatch
            : $this->lastSeenBatch()->first();

        return $lastSeen instanceof ContaAzulImportBatch && $lastSeen->isCompleted();
    }

    /**
     * Read-only legacy awareness: the operational history row (if any) that
     * already represents this external bill. Never written through here.
     */
    public function findLegacyHistory(): ?ExpenseHistory
    {
        return ExpenseHistory::query()
            ->where('conta_azul_bill_id', $this->conta_azul_bill_id)
            ->first();
    }

    /**
     * Masked external bill id for logs and audit trails.
     */
    public function maskedBillId(): string
    {
        return BankIdentifierMasker::maskContaAzulId($this->conta_azul_bill_id);
    }
}

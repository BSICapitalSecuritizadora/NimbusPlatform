<?php

namespace App\Models;

use App\Concerns\DerivesStoredFileMetadata;
use App\Services\DocumentStorageService;
use App\Services\MeasurementFileValidationService;
use Database\Factories\MeasurementPaymentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class MeasurementPayment extends Model
{
    /** @use HasFactory<MeasurementPaymentFactory> */
    use DerivesStoredFileMetadata, HasFactory, LogsActivity;

    protected $attributes = [
        'receipt_disk' => DocumentStorageService::DEFAULT_PRIVATE_DISK,
    ];

    protected $fillable = [
        'operation_id',
        'measurement_id',
        'plan_set_id',
        'pay_date',
        'amount',
        'method',
        'notes',
        'receipt_path',
        'receipt_disk',
        'receipt_sha256',
        'receipt_size',
        'receipt_mime_type',
        'receipt_uploaded_by',
        'receipt_uploaded_at',
        'created_by',
        'financial_rule_id',
        'financial_assessment',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $payment): void {
            if ($payment->exists && $payment->isDirty(['receipt_path', 'receipt_disk', 'receipt_sha256', 'receipt_size', 'receipt_mime_type', 'receipt_uploaded_by', 'receipt_uploaded_at'])
                && $payment->receiptEvidences()->exists()) {
                throw new \LogicException('Os campos de comprovante legado estão preservados. Crie uma nova evidência.');
            }

            if ($payment->isDirty(['receipt_path', 'receipt_disk']) && filled($payment->receipt_path)) {
                app(MeasurementFileValidationService::class)->validateReceipt(
                    (string) $payment->receipt_path,
                    $payment->resolved_receipt_disk,
                );
            }
        });
    }

    protected function casts(): array
    {
        return [
            'pay_date' => 'date',
            'amount' => 'decimal:2',
            'financial_assessment' => 'array',
            'receipt_size' => 'integer',
            'receipt_uploaded_at' => 'datetime',
        ];
    }

    /**
     * `measurement_payments` já era categoria protegida por sete anos e não
     * tinha nenhum produtor: o registro do pagamento e do comprovante -- valor,
     * data, checksum -- caía em `default` e seria descartado em um ano, apesar
     * de ser a evidência financeira do ciclo de medição.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('measurement_payments')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    public function measurement(): BelongsTo
    {
        return $this->belongsTo(Measurement::class);
    }

    public function planSet(): BelongsTo
    {
        return $this->belongsTo(MeasurementPlanSet::class, 'plan_set_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function receiptUploadedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'receipt_uploaded_by');
    }

    public function fileMigrationJournal(): MorphOne
    {
        return $this->morphOne(MeasurementFileMigration::class, 'migratable');
    }

    public function hasReceipt(): bool
    {
        return $this->currentReceiptEvidence !== null || filled($this->receipt_path);
    }

    /** @param Builder<self> $query */
    public function scopeWithReceipt(Builder $query): Builder
    {
        return $query->where(fn (Builder $receipts): Builder => $receipts->whereHas('currentReceiptEvidence')
            ->orWhere(fn (Builder $legacy): Builder => $legacy->whereDoesntHave('receiptEvidences')
                ->whereNotNull('receipt_path')->where('receipt_path', '!=', '')));
    }

    /** @param Builder<self> $query */
    public function scopeWithoutReceipt(Builder $query): Builder
    {
        return $query->whereDoesntHave('receiptEvidences')
            ->where(fn (Builder $legacy): Builder => $legacy->whereNull('receipt_path')->orWhere('receipt_path', ''));
    }

    public function receiptEvidences(): HasMany
    {
        return $this->hasMany(MeasurementPaymentReceiptEvidence::class)->orderByDesc('version');
    }

    public function currentReceiptEvidence(): HasOne
    {
        return $this->hasOne(MeasurementPaymentReceiptEvidence::class)->ofMany('version', 'max');
    }

    public function getResolvedReceiptDiskAttribute(): string
    {
        return $this->receipt_disk ?: 'public';
    }

    protected function storedFilePathColumn(): string
    {
        return 'receipt_path';
    }

    protected function storedFileMimeColumn(): string
    {
        return 'receipt_mime_type';
    }

    protected function storedFileSizeColumn(): string
    {
        return 'receipt_size';
    }

    protected function storedFileChecksumColumn(): ?string
    {
        return 'receipt_sha256';
    }

    protected function storedFileNameColumn(): ?string
    {
        return null;
    }

    protected function storedFileMetadataDisk(): string
    {
        return $this->resolved_receipt_disk;
    }
}

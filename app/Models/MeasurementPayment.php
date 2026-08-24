<?php

namespace App\Models;

use App\Concerns\DerivesStoredFileMetadata;
use App\Services\DocumentStorageService;
use Database\Factories\MeasurementPaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
    ];

    protected function casts(): array
    {
        return [
            'pay_date' => 'date',
            'amount' => 'decimal:2',
            'receipt_size' => 'integer',
            'receipt_uploaded_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
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

    public function hasReceipt(): bool
    {
        return filled($this->receipt_path);
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

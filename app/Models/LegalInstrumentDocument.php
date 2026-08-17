<?php

namespace App\Models;

use App\Enums\LegalInstrumentDocumentRole;
use App\Enums\LegalInstrumentDocumentStatus;
use Database\Factories\LegalInstrumentDocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Vínculo entre um documento do acervo e o dossiê de um instrumento (§3).
 *
 * O arquivo continua sendo um `Document` — mesmas policies, mesmo storage
 * privado, mesma varredura. O que esta entidade acrescenta é o papel do
 * documento na cadeia e o estado do seu processamento.
 */
class LegalInstrumentDocument extends Model
{
    /** @use HasFactory<LegalInstrumentDocumentFactory> */
    use HasFactory;

    protected $fillable = [
        'legal_instrument_id',
        'document_id',
        'role',
        'sequence',
        'document_date',
        'signed_at',
        'effect_summary',
        'processing_status',
        'current_step',
        'message',
        'error_message',
        'extraction_attempts',
        'processing_started_at',
        'processed_at',
        'added_by',
    ];

    protected function casts(): array
    {
        return [
            'role' => LegalInstrumentDocumentRole::class,
            'processing_status' => LegalInstrumentDocumentStatus::class,
            'document_date' => 'date:Y-m-d',
            'signed_at' => 'date:Y-m-d',
            'sequence' => 'integer',
            'extraction_attempts' => 'integer',
            'processing_started_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(LegalInstrument::class, 'legal_instrument_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    public function fields(): HasMany
    {
        return $this->hasMany(LegalInstrumentField::class, 'legal_instrument_document_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(LegalInstrumentEvent::class, 'legal_instrument_document_id');
    }

    /**
     * Rótulo do documento na cadeia: "2º Aditamento", "Documento original".
     */
    public function getRoleLabelAttribute(): string
    {
        if ($this->sequence !== null && $this->role === LegalInstrumentDocumentRole::Amendment) {
            return "{$this->sequence}º Aditamento";
        }

        return $this->role->label();
    }

    public function getTitleAttribute(): string
    {
        return $this->document?->title ?? $this->role_label;
    }

    public function isProcessing(): bool
    {
        return $this->processing_status->isActive();
    }

    public function canRetry(): bool
    {
        return $this->processing_status->canRetry();
    }
}

<?php

namespace App\Models;

use App\Enums\GuaranteeConfidenceLevel;
use App\Enums\GuaranteeDocumentReferenceType;
use App\Enums\LegalDocumentType;
use Database\Factories\GuaranteeDocumentReferenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Onde uma garantia está prevista juridicamente: documento, cláusula, página e
 * trecho, com a confiança da extração e quem confirmou (§6 do escopo).
 */
class GuaranteeDocumentReference extends Model
{
    /** @use HasFactory<GuaranteeDocumentReferenceFactory> */
    use HasFactory;

    protected $fillable = [
        'guarantee_id',
        'document_id',
        'reference_type',
        'document_title',
        'document_name',
        'document_type',
        'document_date',
        'signed_at',
        'version',
        'page',
        'clause',
        'item',
        'excerpt',
        'confidence_level',
        'confidence_score',
        'extraction_method',
        'extracted_at',
        'confirmed_by',
        'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'reference_type' => GuaranteeDocumentReferenceType::class,
            'document_type' => LegalDocumentType::class,
            'confidence_level' => GuaranteeConfidenceLevel::class,
            'document_date' => 'date',
            'signed_at' => 'date',
            'page' => 'integer',
            'confidence_score' => 'float',
            'extracted_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    public function guarantee(): BelongsTo
    {
        return $this->belongsTo(Guarantee::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(GuaranteeEvent::class);
    }

    /**
     * Rótulo curto da localização no documento: "Cláusula 8.3.1 · Página 42".
     */
    public function getLocationLabelAttribute(): ?string
    {
        $parts = array_filter([
            filled($this->clause) ? "Cláusula {$this->clause}" : null,
            filled($this->item) ? "Item {$this->item}" : null,
            $this->page !== null ? "Página {$this->page}" : null,
        ]);

        return $parts === [] ? null : implode(' · ', $parts);
    }

    public function getDocumentLabelAttribute(): string
    {
        return $this->document_title
            ?? $this->document?->title
            ?? $this->document_type?->label()
            ?? 'Documento não identificado';
    }

    /**
     * O documento de origem ainda está acessível para abrir o trecho?
     *
     * A referência sobrevive à exclusão do documento no acervo (os metadados
     * são copiados), então a ação "Ver no documento" precisa checar isto antes
     * de se oferecer.
     */
    public function hasOpenableDocument(): bool
    {
        return $this->document_id !== null && $this->document !== null;
    }
}

<?php

namespace App\Models;

use App\Enums\GuaranteeEvidenceLevel;
use App\Enums\LegalInstrumentFieldKey;
use App\Enums\LegalInstrumentFieldStatus;
use App\Enums\LegalInstrumentFieldValueType;
use Database\Factories\LegalInstrumentFieldFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Route;

/**
 * Uma versão de um campo do instrumento, com proveniência e vigência.
 *
 * Registros nunca são editados no lugar: uma alteração cria uma linha nova, e a
 * anterior passa a `superseded`. É o que permite responder, para qualquer campo,
 * as seis perguntas do escopo — valor vigente, valor anterior, documento que
 * alterou, cláusula/página, desde quando vale e quem confirmou.
 */
class LegalInstrumentField extends Model
{
    /** @use HasFactory<LegalInstrumentFieldFactory> */
    use HasFactory;

    protected $fillable = [
        'legal_instrument_id',
        'guarantee_id',
        'field_key',
        'value_type',
        'value',
        'value_numeric',
        'value_date',
        'effective_date',
        'status',
        'evidence_level',
        'confidence_score',
        'legal_instrument_document_id',
        'document_id',
        'clause',
        'page',
        'excerpt',
        'supersedes_id',
        'has_conflict',
        'conflict_reason',
        'review_notes',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'field_key' => LegalInstrumentFieldKey::class,
            'value_type' => LegalInstrumentFieldValueType::class,
            'status' => LegalInstrumentFieldStatus::class,
            'evidence_level' => GuaranteeEvidenceLevel::class,
            'value_numeric' => 'float',
            'value_date' => 'date:Y-m-d',
            'effective_date' => 'date:Y-m-d',
            'confidence_score' => 'float',
            'page' => 'integer',
            'has_conflict' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(LegalInstrument::class, 'legal_instrument_id');
    }

    public function guarantee(): BelongsTo
    {
        return $this->belongsTo(Guarantee::class);
    }

    public function instrumentDocument(): BelongsTo
    {
        return $this->belongsTo(LegalInstrumentDocument::class, 'legal_instrument_document_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Valor formatado conforme o tipo do campo. Ausência é dita, não convertida
     * em zero nem em "Não" (§7 do escopo).
     */
    public function getFormattedValueAttribute(): string
    {
        return $this->value_type->format($this->value, $this->value_numeric, $this->value_date);
    }

    /**
     * Rótulo curto da localização no documento: "Cláusula 4.2 · Página 7".
     */
    public function getSourceLabelAttribute(): ?string
    {
        $parts = array_filter([
            filled($this->clause) ? "Cláusula {$this->clause}" : null,
            $this->page !== null ? "Página {$this->page}" : null,
        ]);

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /**
     * URL que abre o documento no ponto citado.
     *
     * A âncora `#page=` é entendida pelo visualizador de PDF do navegador, e só
     * funciona porque a rota de preview serve o arquivo inline — o download
     * força `attachment` e o navegador nunca chega a abrir o documento.
     */
    public function getSourceUrlAttribute(): ?string
    {
        $document = $this->document ?? $this->instrumentDocument?->document;

        if ($document === null || ! Route::has('admin.documents.preview')) {
            return null;
        }

        $url = route('admin.documents.preview', $document);

        return $this->page === null ? $url : $url.'#page='.$this->page;
    }

    public function getDocumentLabelAttribute(): string
    {
        return $this->document?->title
            ?? $this->instrumentDocument?->document?->title
            ?? 'Documento não identificado';
    }

    /**
     * Dois valores representam a mesma informação?
     *
     * A comparação usa o valor tipado quando existe: sem isso, uma reextração
     * que devolvesse "R$ 30.000.000,00" onde antes havia "30000000" apareceria
     * como alteração na tela de revisão, e o usuário aprovaria ruído.
     */
    public function hasSameValueAs(?self $other): bool
    {
        if ($other === null) {
            return false;
        }

        if ($this->value_type->isNumeric() || $other->value_type->isNumeric()) {
            if ($this->value_numeric !== null && $other->value_numeric !== null) {
                return abs($this->value_numeric - $other->value_numeric) < 0.000001;
            }
        }

        if ($this->value_type === LegalInstrumentFieldValueType::Date) {
            return $this->value_date?->toDateString() === $other->value_date?->toDateString();
        }

        return $this->normalizedText() === $other->normalizedText();
    }

    private function normalizedText(): string
    {
        $value = mb_strtolower(trim((string) $this->value));

        return (string) preg_replace('/\s+/u', ' ', $value);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('status'), LegalInstrumentFieldStatus::Confirmed->value);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePendingReview(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('status'), LegalInstrumentFieldStatus::PendingReview->value);
    }
}

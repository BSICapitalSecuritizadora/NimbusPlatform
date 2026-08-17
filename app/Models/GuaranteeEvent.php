<?php

namespace App\Models;

use App\Enums\GuaranteeEventType;
use Database\Factories\GuaranteeEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um acontecimento na vida jurídica da garantia (§8 do escopo).
 *
 * Append-only: constituição, alteração, reforço, substituição e liberação são
 * registrados em sequência, nunca sobrescritos. `effective_date` é a data em
 * que o efeito jurídico começa — distinta de `created_at`, que é quando o
 * sistema soube.
 */
class GuaranteeEvent extends Model
{
    /** @use HasFactory<GuaranteeEventFactory> */
    use HasFactory;

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_DOCUMENT = 'document';

    public const SOURCE_SYSTEM = 'system';

    protected $fillable = [
        'guarantee_id',
        'guarantee_document_reference_id',
        'event_type',
        'effective_date',
        'title',
        'description',
        'previous_values',
        'new_values',
        'source',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => GuaranteeEventType::class,
            'effective_date' => 'date',
            'previous_values' => 'array',
            'new_values' => 'array',
        ];
    }

    public function guarantee(): BelongsTo
    {
        return $this->belongsTo(Guarantee::class);
    }

    public function documentReference(): BelongsTo
    {
        return $this->belongsTo(GuaranteeDocumentReference::class, 'guarantee_document_reference_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * Resumo das mudanças de valor, no formato "120% → 130%" usado na linha do
     * tempo. Só compara chaves presentes nos dois lados: um campo que não
     * existia antes é inclusão, não alteração.
     *
     * @return array<int, array{field: string, from: mixed, to: mixed}>
     */
    public function getChangeSummaryAttribute(): array
    {
        $previous = $this->previous_values ?? [];
        $new = $this->new_values ?? [];

        $summary = [];

        foreach ($new as $field => $value) {
            if (! array_key_exists($field, $previous)) {
                continue;
            }

            if ($previous[$field] === $value) {
                continue;
            }

            $summary[] = [
                'field' => $field,
                'from' => $previous[$field],
                'to' => $value,
            ];
        }

        return $summary;
    }
}

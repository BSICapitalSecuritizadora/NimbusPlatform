<?php

namespace App\Models;

use App\Enums\GuaranteeEventType;
use BackedEnum;
use Database\Factories\GuaranteeEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

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

    /** Campo cujo conteúdo é um JSON de sub-campos, e não um valor só. */
    private const IDENTIFICATION_FIELD = 'identification';

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
     * `identification` é aberto chave a chave. Guardado como um único JSON, ele
     * apareceria na linha do tempo como "Identification: Array → Array", que
     * não responde a pergunta que o histórico existe para responder — de onde
     * veio esta conta bancária (§4 do escopo de consolidação).
     *
     * `from`/`to` continuam sendo os valores crus — é o que permite comparar
     * números no histórico. `from_display`/`to_display` são a versão segura
     * para a tela, porque nem todo valor gravado é escalar.
     *
     * @return array<int, array{field: string, label: string, from: mixed, to: mixed, from_display: string|null, to_display: string|null}>
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

            if ($field === self::IDENTIFICATION_FIELD) {
                $summary = array_merge($summary, $this->identificationChanges($previous[$field], $value));

                continue;
            }

            $summary[] = [
                'field' => $field,
                'label' => $this->labelForField($field),
                'from' => $previous[$field],
                'to' => $value,
                'from_display' => $this->displayValue($previous[$field]),
                'to_display' => $this->displayValue($value),
            ];
        }

        return $summary;
    }

    /**
     * Diferenças dentro do JSON de identificação, uma linha por chave.
     *
     * @return array<int, array{field: string, label: string, from: mixed, to: mixed, from_display: string|null, to_display: string|null}>
     */
    private function identificationChanges(mixed $previous, mixed $new): array
    {
        $previous = is_array($previous) ? $previous : [];
        $new = is_array($new) ? $new : [];

        $labels = $this->guarantee?->type?->category()?->identificationFields() ?? [];
        $changes = [];

        foreach (array_keys($previous + $new) as $key) {
            $from = $previous[$key] ?? null;
            $to = $new[$key] ?? null;

            if ($from === $to) {
                continue;
            }

            $changes[] = [
                'field' => (string) $key,
                'label' => $labels[(string) $key] ?? $this->labelForField((string) $key),
                'from' => $from,
                'to' => $to,
                'from_display' => $this->displayValue($from),
                'to_display' => $this->displayValue($to),
            ];
        }

        return $changes;
    }

    private function labelForField(string $field): string
    {
        return Str::of($field)->replace('_', ' ')->title()->value();
    }

    /**
     * Valor pronto para a tela. Estrutura aninhada vira null em vez de quebrar
     * a renderização — o histórico não é o lugar de despejar JSON.
     */
    private function displayValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return is_scalar($value) ? (string) $value : null;
    }
}
